<?php
/**
 * Plugin singleton — bootstrap and metadata access.
 *
 * Wires the plugin's components, holds the absolute path to the main plugin
 * file, and exposes the static helpers the Updater and other consumers need.
 * Module wiring is intentionally empty in the 1.1 scaffold; the four content
 * modules (Markdown, llms.txt, Link headers, Content Signals) are wired in as
 * they land in later steps.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility;

use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Http\Request_Factory;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Core\Settings\Settings;
use LogicException;

/**
 * Singleton entry point for the Kntnt AI Visibility plugin.
 *
 * Constructed once by get_instance(); the constructor instantiates every
 * component in dependency order and registers its WordPress hooks, so the
 * constructor stays the single authoritative place to trace the hook graph.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * The sole instance of this class.
	 *
	 * @since 0.1.0
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path to the main plugin file (kntnt-ai-visibility.php).
	 *
	 * Set once during bootstrap and consumed by get_plugin_file(),
	 * get_plugin_data(), get_slug(), and get_plugin_dir().
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Cached return value of get_plugin_data() / get_file_data().
	 *
	 * Populated lazily on the first call to get_plugin_data(). WordPress's
	 * get_plugin_data() returns a typed shape (mostly strings, Network as a
	 * bool); get_file_data() returns string[]. Both are accepted as
	 * array<mixed>.
	 *
	 * @since 0.1.0
	 *
	 * @var array<mixed>|null
	 */
	private static ?array $plugin_data = null;

	/**
	 * The Core service facade the feature modules are booted against.
	 *
	 * Built once in the constructor and consumed by serve_early(), which runs
	 * the early cache router before WordPress routing.
	 *
	 * @since 0.1.0
	 *
	 * @var Core
	 */
	private readonly Core $core;

	/**
	 * Returns (and on the first call, creates) the singleton instance.
	 *
	 * The first call must pass the absolute path to the main plugin file so the
	 * metadata helpers can work without globals. Subsequent calls ignore the
	 * argument and return the existing instance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin_file Absolute path to kntnt-ai-visibility.php.
	 *                            Ignored on calls after the first.
	 * @return self
	 */
	public static function get_instance( string $plugin_file = '' ): self {

		// Return early when already bootstrapped.
		if ( self::$instance !== null ) {
			return self::$instance;
		}

		// Capture the plugin file path and initialise the singleton.
		self::$plugin_file = $plugin_file;
		self::$instance    = new self();

		return self::$instance;

	}

	/**
	 * Returns the absolute path to the main plugin file.
	 *
	 * Required by Updater::check_for_updates() to build the plugin slug path
	 * via plugin_basename().
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to kntnt-ai-visibility.php.
	 */
	public static function get_plugin_file(): string {
		return self::$plugin_file;
	}

	/**
	 * Returns the parsed plugin header, cached after the first call.
	 *
	 * The array keys match what get_plugin_data() / get_file_data() return:
	 * 'Name', 'Version', 'PluginURI', 'Description', 'Author', 'AuthorURI',
	 * 'TextDomain', 'DomainPath', 'RequiresWP', 'RequiresPHP'. Required by
	 * Updater::check_for_updates() to read 'Version' and 'PluginURI'.
	 *
	 * @since 0.1.0
	 *
	 * @return array<mixed>
	 */
	public static function get_plugin_data(): array {

		// Return the cached result to avoid repeated file reads.
		if ( self::$plugin_data !== null ) {
			return self::$plugin_data;
		}

		// Header map for the get_file_data() fallback used before the full
		// plugin API is loaded (e.g. on plugins_loaded).
		$default_headers = [
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		];

		// Prefer the WordPress function when available; fall back to
		// get_file_data() so the header is still readable outside admin
		// context. Translation is disabled to avoid triggering a just-in-time
		// textdomain load before `init`.
		if ( function_exists( 'get_plugin_data' ) ) {
			self::$plugin_data = get_plugin_data( self::$plugin_file, false, false );
		} else {
			self::$plugin_data = get_file_data( self::$plugin_file, $default_headers );
		}

		return self::$plugin_data;

	}

	/**
	 * Returns the plugin version from the plugin header.
	 *
	 * @since 0.1.0
	 *
	 * @return string The version string, or '' when the header is unreadable.
	 */
	public static function get_version(): string {
		$data = self::get_plugin_data();
		return isset( $data['Version'] ) && is_string( $data['Version'] ) ? $data['Version'] : '';
	}

	/**
	 * Returns the plugin slug derived from the main plugin filename.
	 *
	 * @since 0.1.0
	 *
	 * @return string The slug, e.g. 'kntnt-ai-visibility'.
	 */
	public static function get_slug(): string {
		return basename( self::$plugin_file, '.php' );
	}

	/**
	 * Returns the absolute path to the plugin directory, with a trailing slash.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to the plugin directory.
	 */
	public static function get_plugin_dir(): string {
		return plugin_dir_path( self::$plugin_file );
	}

	/**
	 * Cleans up on deactivation: rewrite rules and the file cache.
	 *
	 * Drops the Markdown `.md` rewrite rules so a deactivated plugin leaves no
	 * dangling routes, and clears the file cache (docs/adr/0007). The settings
	 * option is deliberately preserved so reactivation keeps the site's
	 * configuration; only uninstall removes it (docs/spec §7).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {

		// Remove the plugin's rewrite rules from the rewrite cache.
		flush_rewrite_rules();

		// Clear the cached Markdown files; the settings option stays put.
		( new File_Store( static fn(): string => self::cache_dir() ) )->flush_all();

	}

	/**
	 * Wires the plugin's components and registers their WordPress hooks.
	 *
	 * Instantiated once by get_instance(). The Updater is wired here because
	 * GitHub-hosted self-updates are core infrastructure (see docs/adr/0003),
	 * not a content module. It then builds the Core service graph and boots the
	 * feature modules in dependency order; Release 1 ships one — the Markdown
	 * alternate. The remaining three (llms.txt, Link headers, Content Signals)
	 * are booted alongside it as they land.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {

		// Wire the GitHub-release update checker into the WordPress update
		// transient so installs can self-update from the project's releases.
		$updater = new Updater();
		add_filter( 'pre_set_site_transient_update_plugins', [ $updater, 'check_for_updates' ] );

		// Build the Core service graph — the shared services modules depend on
		// (docs/adr/0006). The cache base directory resolves lazily on first use,
		// so an ordinary HTML request pays nothing here. The TTL safety net is
		// filterable and bounds staleness from changes no hook catches.
		$logger = new Plugin_Logger( null, defined( 'WP_DEBUG' ) && WP_DEBUG );
		$settings = new Settings();
		$settings->register();
		$artifacts = new Artifact_Registry();
		$store = new File_Store( static fn(): string => self::cache_dir() );
		$page_markdown = new Page_Markdown_Service( new Front_Matter(), $store, $logger, static fn(): string => home_url() );
		$ttl = apply_filters( 'kntnt_ai_visibility_cache_ttl', WEEK_IN_SECONDS );
		$router = new Serve_Router(
			$store,
			$artifacts,
			$logger,
			is_numeric( $ttl ) ? (int) $ttl : WEEK_IN_SECONDS,
			base_path: static fn(): string => rtrim( (string) wp_parse_url( (string) home_url( '/' ), PHP_URL_PATH ), '/' ),
		);
		$this->core = new Core( $artifacts, $settings, $page_markdown, $logger, $store, $router );

		// Boot the feature modules against Core, in dependency order.
		( new Markdown\Module() )->boot( $this->core );

	}

	/**
	 * Serves a cache-grade artifact from the file cache, as early as possible.
	 *
	 * Called from the main plugin file right after bootstrap — before WordPress
	 * routing — so a cache hit emits its headers and bytes and exits, skipping
	 * the WordPress lifecycle entirely (docs/adr/0007). On a miss it returns and
	 * WordPress proceeds; the matching provider then generates and serves lazily.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function serve_early(): void {
		$this->core->router()->serve( Request_Factory::from_globals() );
	}

	/**
	 * Returns the absolute path to the Core-owned artifact cache directory.
	 *
	 * The single source of truth for the cache location, shared by the file
	 * store, deactivation cleanup and uninstall (docs/adr/0007).
	 *
	 * @since 0.1.0
	 *
	 * @return string The cache directory path, without a trailing slash.
	 */
	public static function cache_dir(): string {

		// Resolve the uploads base, falling back to the content directory when
		// the uploads array is unavailable (e.g. very early in the lifecycle).
		$uploads = wp_upload_dir();
		$base = is_array( $uploads ) && isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] )
			? $uploads['basedir']
			: WP_CONTENT_DIR . '/uploads';

		return $base . '/kntnt-ai-visibility-cache';

	}

	/**
	 * Prevents cloning of the singleton.
	 *
	 * @since 0.1.0
	 *
	 * @throws LogicException Always, because a singleton must not be cloned.
	 *
	 * @return void
	 */
	public function __clone() {
		throw new LogicException( 'Cannot clone a singleton.' );
	}

	/**
	 * Prevents unserialisation of the singleton.
	 *
	 * @since 0.1.0
	 *
	 * @throws LogicException Always, because a singleton must not be unserialised.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new LogicException( 'Cannot unserialize a singleton.' );
	}

}
