<?php
/**
 * The module boot contract.
 *
 * Plugin sees a feature module only through this thin contract and boots modules
 * in a fixed dependency order, after Core services exist (docs/adr/0006). A
 * module's boot() registers its artifact provider(s), settings section and
 * WordPress hooks; it never reaches into another module.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * A bootable feature module.
 *
 * @since 0.1.0
 */
interface Module {

	/**
	 * Boots the module against Core.
	 *
	 * Called once by Plugin, in dependency order, after Core services exist.
	 *
	 * @since 0.1.0
	 *
	 * @param Core $core The Core service facade.
	 * @return void
	 */
	public function boot( Core $core ): void;

}
