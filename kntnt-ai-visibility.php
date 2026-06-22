<?php
/**
 * Plugin Name:       Kntnt AI Visibility
 * Plugin URI:        https://github.com/Kntnt/kntnt-ai-visibility
 * Description:       Makes content-rich WordPress sites discoverable, visible and readable by AI agents.
 * Version:           0.2.0
 * Requires at least: 7.0
 * Requires PHP:      8.5
 * Author:            Kntnt
 * Author URI:        https://www.kntnt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kntnt-ai-visibility
 * Domain Path:       /languages
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards against running on a PHP version older than the 8.5 floor.
 *
 * The bundled Markdown converter requires PHP 8.5 (see docs/adr/0001), and the
 * plugin header already makes WordPress block activation on older installs.
 * This is a second line of defence for environments that load the plugin
 * outside the normal activation path: it shows an admin notice and deactivates
 * the plugin so it never reaches code that would fatally error.
 *
 * @since 0.1.0
 *
 * @return bool True when PHP is 8.5 or newer; false when the guard fires.
 */
function kntnt_ai_visibility_requirements_check(): bool {

	// Nothing to do when the runtime meets the requirement.
	if ( version_compare( PHP_VERSION, '8.5', '>=' ) ) {
		return true;
	}

	// Surface the problem as a dismissible admin notice.
	add_action(
		'admin_notices',
		static function (): void {
			$message = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Kntnt AI Visibility requires PHP %1$s or later. This server runs PHP %2$s. The plugin has been deactivated.', 'kntnt-ai-visibility' ),
				'8.5',
				PHP_VERSION,
			);
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		},
	);

	// Deactivate the plugin so WordPress does not try to load it again.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		},
	);

	return false;

}

// Abort the rest of the bootstrap when the PHP version guard fires.
if ( ! kntnt_ai_visibility_requirements_check() ) {
	return;
}

// Load the PSR-4 autoloader (delegates to vendor/autoload.php).
require_once __DIR__ . '/autoloader.php';

// Run the activation script on activation and the deactivation cleanup on
// deactivation. uninstall.php is invoked automatically by WordPress on delete.
register_activation_hook(
	__FILE__,
	static function (): void {
		require __DIR__ . '/install.php';
	},
);
register_deactivation_hook( __FILE__, [ \Kntnt\Ai_Visibility\Plugin::class, 'deactivate' ] );

// Bootstrap the plugin singleton inside a safety net, then run the early serve
// router before WordPress routing: a cache-grade `.md` hit emits its bytes and
// exits here, skipping the WordPress lifecycle (docs/adr/0007); a miss returns
// and WordPress proceeds. A fatal error during initialisation would otherwise
// take down the whole site; the try/catch logs it and surfaces an admin notice
// while the rest of WordPress keeps running.
try {
	\Kntnt\Ai_Visibility\Plugin::get_instance( __FILE__ )->serve_early();
} catch ( \Throwable $e ) {
	error_log(
		sprintf(
			'Kntnt AI Visibility: fatal error during initialization — %s in %s on line %d',
			$e->getMessage(),
			$e->getFile(),
			$e->getLine(),
		),
	);
	add_action(
		'admin_notices',
		static function () use ( $e ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: error message from the caught exception. */
						__( 'Kntnt AI Visibility failed to initialize: %s', 'kntnt-ai-visibility' ),
						$e->getMessage(),
					),
				),
			);
		},
	);
}
