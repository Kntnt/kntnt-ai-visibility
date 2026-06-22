<?php
/**
 * Plugin activation script.
 *
 * Runs on activation via register_activation_hook(), in the loaded plugin
 * context (the autoloader is already required by the main file). The Markdown
 * module routes `.md` requests and the llms module routes `/llms.txt` and
 * `/llms-full.txt` through rewrite rules (docs/spec §4.2, docs/spec/llms-txt.md
 * §7); those rules are registered on `init` for normal requests, but `init` has
 * already fired by the time the activation hook runs, so this script registers
 * them directly and flushes the rewrite cache once. Reusing the modules' own rule
 * registration keeps the activation and runtime rule sets identical.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Llms\Request_Handler as Llms_Request_Handler;
use Kntnt\Ai_Visibility\Markdown\Request_Handler as Markdown_Request_Handler;

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register the Markdown `.md` and the llms singleton rewrite rules, then flush
// the rewrite cache once so the new rules take effect without a manual resave.
Markdown_Request_Handler::register_rewrite_rules();
Llms_Request_Handler::register_rewrite_rules();
flush_rewrite_rules();
