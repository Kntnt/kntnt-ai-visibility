<?php
/**
 * Plugin activation script.
 *
 * Runs on activation via register_activation_hook(). The 1.1 scaffold has no
 * activation work: there are no custom tables, capabilities, or cron events,
 * and no rewrite rules (the Markdown module caches with transients, not a
 * schema — see docs/adr/0001). This file is the single wiring point where
 * later modules register their activation needs; for example the Markdown
 * module will register its rewrite rules here and flush them once.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// No activation work in the scaffold. Module activation logic is wired in here
// as the modules land in steps 1.5 and beyond.
