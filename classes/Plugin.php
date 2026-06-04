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
	 * Clears the plugin's transient caches on deactivation.
	 *
	 * Removes the Markdown render cache (and any future module caches) while
	 * preserving persistent options, so reactivation keeps the site's settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {

		// Remove every transient the plugin may have cached, including timeouts.
		// The %i placeholder safely interpolates the table identifier and keeps
		// prepare()'s format a literal string.
		global $wpdb;
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var for the WP global.
		/** @var \wpdb $wpdb */
		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_kntnt_ai_visibility_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_kntnt_ai_visibility_' ) . '%',
		);
		if ( is_string( $sql ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the $wpdb->prepare() result above.
			$wpdb->query( $sql );
		}

	}

	/**
	 * Wires the plugin's components and registers their WordPress hooks.
	 *
	 * Instantiated once by get_instance(). The Updater is wired here because
	 * GitHub-hosted self-updates are core infrastructure (see docs/adr/0003),
	 * not a content module. The four content modules are wired in below as they
	 * are implemented in later steps.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {

		// Wire the GitHub-release update checker into the WordPress update
		// transient so installs can self-update from the project's releases.
		// The four content modules — (a) Markdown alternate, (b) llms.txt,
		// (c) Link headers, (d) Content Signals — are wired in below this line
		// as they land in later steps; the 1.1 scaffold ships only the updater.
		$updater = new Updater();
		add_filter( 'pre_set_site_transient_update_plugins', [ $updater, 'check_for_updates' ] );

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
