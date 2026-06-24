<?php
/**
 * The Content Signals feature module.
 *
 * Release 4's feature: it declares the site's AI-usage preferences as a
 * Content-Signal directive in robots.txt (docs/spec/content-signals.md). boot()
 * registers the "AI usage" settings section and the robots.txt decorator. It
 * registers no provider, serve pattern, capability column, cache or rewrite rule
 * — it reads three settings and decorates robots.txt (docs/adr/0012).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Signals;

use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Module as Module_Contract;

/**
 * Boots and wires the Content Signals module.
 *
 * @since 0.4.0
 */
final class Module implements Module_Contract {

	/**
	 * Registers the settings section and the robots.txt decorator.
	 *
	 * @since 0.4.0
	 *
	 * @param Core $core The Core service facade.
	 * @return void
	 */
	public function boot( Core $core ): void {
		$settings = new Settings();
		$core->settings()->register_section( $settings->section() );
		( new Robots_Decorator( static fn(): Policy => $settings->policy() ) )->register();
	}

}
