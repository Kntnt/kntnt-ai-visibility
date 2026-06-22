<?php
/**
 * The logger contract.
 *
 * A minimal levelled logger Core and modules use for diagnostics. It is always
 * silent toward visitors — nothing it records ever reaches a response body
 * (docs/adr/0010, the WordPress security rules). Each level takes a message and
 * an optional structured context array.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Levelled, visitor-silent diagnostics.
 *
 * @since 0.1.0
 */
interface Logger {

	/**
	 * Logs an error: something failed and a behaviour was lost.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The human-readable message.
	 * @param array<string, mixed> $context Structured context for the message.
	 * @return void
	 */
	public function error( string $message, array $context = [] ): void;

	/**
	 * Logs a warning: something unexpected that did not break behaviour.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The human-readable message.
	 * @param array<string, mixed> $context Structured context for the message.
	 * @return void
	 */
	public function warning( string $message, array $context = [] ): void;

	/**
	 * Logs an informational message (verbose only).
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The human-readable message.
	 * @param array<string, mixed> $context Structured context for the message.
	 * @return void
	 */
	public function info( string $message, array $context = [] ): void;

	/**
	 * Logs a debug-level trace (verbose only).
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The human-readable message.
	 * @param array<string, mixed> $context Structured context for the message.
	 * @return void
	 */
	public function debug( string $message, array $context = [] ): void;

}
