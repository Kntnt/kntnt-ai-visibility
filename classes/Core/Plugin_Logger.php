<?php
/**
 * The default logger: writes formatted lines to a sink.
 *
 * In production the sink is PHP's error_log(); tests inject a capturing sink so
 * logging stays free of global side effects. info() and debug() are suppressed
 * unless the logger is constructed verbose (Core passes WP_DEBUG), keeping the
 * production log to warnings and errors.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Levelled logger that formats one line per record and hands it to a sink.
 *
 * @since 0.1.0
 */
final class Plugin_Logger implements Logger {

	/**
	 * The destination for each formatted line.
	 *
	 * @since 0.1.0
	 *
	 * @var callable
	 */
	private $sink;

	/**
	 * Binds the logger to a sink and a verbosity level.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(string): void|null $sink    Line destination; defaults to error_log().
	 * @param bool                        $verbose When true, info() and debug() also emit.
	 */
	public function __construct( ?callable $sink = null, private readonly bool $verbose = false ) {
		$this->sink = $sink ?? 'error_log';
	}

	/**
	 * Logs an error record.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function error( string $message, array $context = [] ): void {
		$this->emit( 'ERROR', $message, $context );
	}

	/**
	 * Logs a warning record.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function warning( string $message, array $context = [] ): void {
		$this->emit( 'WARNING', $message, $context );
	}

	/**
	 * Logs an informational record, when verbose.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function info( string $message, array $context = [] ): void {

		// Informational records are noise in production; gate them on verbosity.
		if ( $this->verbose ) {
			$this->emit( 'INFO', $message, $context );
		}

	}

	/**
	 * Logs a debug record, when verbose.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $message The message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public function debug( string $message, array $context = [] ): void {

		// Debug traces are gated on verbosity for the same reason as info().
		if ( $this->verbose ) {
			$this->emit( 'DEBUG', $message, $context );
		}

	}

	/**
	 * Formats one record and hands it to the sink.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $level   The upper-cased level label.
	 * @param string               $message The message.
	 * @param array<string, mixed> $context The structured context.
	 * @return void
	 */
	private function emit( string $level, string $message, array $context ): void {

		// Build a single line: a fixed plugin tag, the level, the message, and
		// the context encoded as JSON only when there is context to show.
		$line = sprintf( '[Kntnt AI Visibility] [%s] %s', $level, $message );
		if ( $context !== [] ) {
			$line .= ' ' . (string) wp_json_encode( $context );
		}

		( $this->sink )( $line );

	}

}
