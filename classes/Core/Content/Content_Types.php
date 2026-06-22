<?php
/**
 * The content-type capability-matrix contract.
 *
 * Modules contribute one capability column each (register_column); Core composes
 * them into one row-per-viewable-type, column-per-artifact matrix (docs/adr/0010,
 * docs/spec/llms-txt.md §3.1). Consumers read which types get which artifact
 * through is_enabled()/types_for() — the saved cell value, or the column default
 * when unset, with the requires-dependency forced. Releases 3-4 add columns by
 * registering them, no rewrite. The selection that Release 1 kept as a Markdown
 * text field becomes this Core concept, so each module reads only its own column.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Content;

/**
 * Composes module capability columns into the content-type matrix.
 *
 * @since 0.2.0
 */
interface Content_Types {

	/**
	 * Registers a module's capability column.
	 *
	 * @since 0.2.0
	 *
	 * @param Capability_Column $column The column to register.
	 * @return void
	 */
	public function register_column( Capability_Column $column ): void;

	/**
	 * Returns the registered columns, in registration order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Capability_Column>
	 */
	public function columns(): array;

	/**
	 * Returns the matrix rows — front-end-viewable, non-attachment post types.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string>
	 */
	public function rows(): array;

	/**
	 * Reports whether a cell is enabled: saved value or default, dependency forced.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type The post type (matrix row).
	 * @param string $key  The column key.
	 * @return bool
	 */
	public function is_enabled( string $type, string $key ): bool;

	/**
	 * Returns the rows enabled for a column.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The column key.
	 * @return list<string>
	 */
	public function types_for( string $key ): array;

}
