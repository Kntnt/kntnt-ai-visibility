<?php
/**
 * The content-type capability matrix.
 *
 * Composes the columns the modules register into one row-per-viewable-type,
 * column-per-artifact matrix and resolves each cell (docs/spec/llms-txt.md §3.1,
 * §6). A cell is the saved value, or the column's zero-config default when unset,
 * and is forced off when the column it requires is off — so a type can never be
 * indexed or concatenated without the `.md` it links to (the subset guarantee).
 * Rows are the front-end-viewable, non-attachment post types — the same hard
 * guard `Eligibility` enforces, so the matrix never offers a row that could not
 * be served. The matrix owns its own sanitiser and table renderer, both of which
 * walk the registered rows x columns rather than any submitted shape.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Content;

/**
 * Composes capability columns and resolves the content-type matrix.
 *
 * @since 0.2.0
 */
final class Content_Matrix implements Content_Types {

	/**
	 * The registered columns, in registration order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Capability_Column>
	 */
	private array $columns = [];

	/**
	 * Returns the saved matrix cells: [ type => [ column => bool ] ].
	 *
	 * Typed loosely because the slice comes from a stored option that no static
	 * shape can guarantee; cell_value() re-checks each row is an array.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(): array<string, mixed>
	 */
	private $saved_reader;

	/**
	 * Binds the matrix to the reader of its saved cell values.
	 *
	 * @since 0.2.0
	 *
	 * @param (callable(): array<string, mixed>)|null $saved_reader Returns the saved `content_types`
	 *        slice of the option; defaults to an empty matrix (pure zero-config).
	 */
	public function __construct( ?callable $saved_reader = null ) {
		$this->saved_reader = $saved_reader ?? static fn(): array => [];
	}

	/**
	 * Registers a module's capability column.
	 *
	 * @since 0.2.0
	 *
	 * @param Capability_Column $column The column to register.
	 * @return void
	 */
	public function register_column( Capability_Column $column ): void {
		$this->columns[] = $column;
	}

	/**
	 * Returns the registered columns, in registration order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Capability_Column>
	 */
	public function columns(): array {
		return $this->columns;
	}

	/**
	 * Returns the matrix rows — front-end-viewable, non-attachment post types.
	 *
	 * Keys on viewability rather than publicly_queryable: the built-in `page` type
	 * is viewable but not publicly_queryable, and the matrix must offer it. This is
	 * the same hard guard `Eligibility::is_servable()` enforces at serve time.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string>
	 */
	public function rows(): array {

		// Keep every registered type a visitor can view on the front end, minus
		// attachments (media listings, not singular content).
		$types = array_filter(
			get_post_types( [], 'names' ),
			static fn( string $type ): bool => $type !== 'attachment' && is_post_type_viewable( $type ),
		);

		return array_values( $types );

	}

	/**
	 * Reports whether a cell is enabled: saved value or default, dependency forced.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type The post type (matrix row).
	 * @param string $key  The column key.
	 * @return bool
	 */
	public function is_enabled( string $type, string $key ): bool {

		// An unregistered column has no cell to enable.
		$column = $this->column( $key );
		if ( $column === null ) {
			return false;
		}

		// The subset guarantee: a cell can never be on when the column it requires
		// is off, regardless of what was saved.
		if ( $column->requires !== '' && ! $this->is_enabled( $type, $column->requires ) ) {
			return false;
		}

		return $this->cell_value( $type, $key, $column );

	}

	/**
	 * Returns the rows enabled for a column.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The column key.
	 * @return list<string>
	 */
	public function types_for( string $key ): array {
		return array_values( array_filter( $this->rows(), fn( string $type ): bool => $this->is_enabled( $type, $key ) ) );
	}

	/**
	 * Sanitises submitted matrix input into a clean [ type => [ column => bool ] ].
	 *
	 * Walks the registered rows x columns rather than the submitted shape, so an
	 * injected row or column can never leak into the stored option. Each cell is
	 * coerced to a bool — a checked checkbox submits a truthy value, an unchecked
	 * one submits nothing — and the requires-dependency is then applied so a
	 * dependent cell is forced off whenever its required column's cell is off.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $input The raw submitted matrix.
	 * @return array<string, array<string, bool>> The cleaned matrix.
	 */
	public function sanitize( mixed $input ): array {

		// Coerce each registered cell from the submitted shape, ignoring anything
		// the rows/columns do not name.
		$input = is_array( $input ) ? $input : [];
		$clean = [];
		foreach ( $this->rows() as $type ) {
			$row = isset( $input[ $type ] ) && is_array( $input[ $type ] ) ? $input[ $type ] : [];
			foreach ( $this->columns as $column ) {
				$clean[ $type ][ $column->key ] = ! empty( $row[ $column->key ] );
			}
		}

		// Apply the subset guarantee: a dependent cell cannot stay on when its
		// required column's cell is off. Columns register dependency-last (md
		// before llms/llms_full), so a single forward pass settles every cell.
		foreach ( $clean as $type => $cells ) {
			foreach ( $this->columns as $column ) {
				if ( $column->requires !== '' && empty( $clean[ $type ][ $column->requires ] ) ) {
					$clean[ $type ][ $column->key ] = false;
				}
			}
		}

		return $clean;

	}

	/**
	 * Renders the matrix as a server-side table of checkboxes (no JS).
	 *
	 * One header row of column labels, then one row per post type with a checkbox
	 * per column named `{$name_prefix}[{type}][{column}]`, checked to match the
	 * cell's effective enabled state. The `.md` dependency is not enforced in the
	 * browser — it is applied at save by sanitize() and explained in the help text.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name_prefix The form-name prefix, e.g. 'kntnt_ai_visibility[content_types]'.
	 * @return void
	 */
	public function render_table( string $name_prefix ): void {

		// Header: a blank corner cell, then each column's label.
		echo '<table class="widefat striped"><thead><tr><th scope="col"></th>';
		foreach ( $this->columns as $column ) {
			printf( '<th scope="col">%s</th>', esc_html( $column->label() ) );
		}
		echo '</tr></thead><tbody>';

		// One row per post type: a label cell, then a checkbox per column.
		foreach ( $this->rows() as $type ) {
			printf( '<tr><th scope="row">%s</th>', esc_html( $this->row_label( $type ) ) );
			foreach ( $this->columns as $column ) {
				$name = sprintf( '%s[%s][%s]', $name_prefix, $type, $column->key );
				printf(
					'<td><input type="checkbox" name="%s" value="1" %s /></td>',
					esc_attr( $name ),
					checked( $this->is_enabled( $type, $column->key ), true, false ),
				);
			}
			echo '</tr>';
		}

		echo '</tbody></table>';

	}

	/**
	 * Returns a post type's display label for the matrix row header.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type The post type slug.
	 * @return string The plural label, falling back to the slug.
	 */
	private function row_label( string $type ): string {

		// Prefer the registered plural label; fall back to the slug when the type
		// object or its labels are unavailable.
		$object = get_post_type_object( $type );
		$name = $object->labels->name ?? '';

		return is_string( $name ) && $name !== '' ? $name : $type;

	}

	/**
	 * Resolves a single cell to its saved value, else the column default.
	 *
	 * @since 0.2.0
	 *
	 * @param string            $type   The post type.
	 * @param string            $key    The column key.
	 * @param Capability_Column $column The column.
	 * @return bool
	 */
	private function cell_value( string $type, string $key, Capability_Column $column ): bool {

		// A saved cell overrides the default; otherwise the column's zero-config
		// default decides.
		$saved = ( $this->saved_reader )();
		if ( isset( $saved[ $type ] ) && is_array( $saved[ $type ] ) && array_key_exists( $key, $saved[ $type ] ) ) {
			return (bool) $saved[ $type ][ $key ];
		}

		return ( $column->default )( $type );

	}

	/**
	 * Returns a registered column by key, or null.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The column key.
	 * @return Capability_Column|null
	 */
	private function column( string $key ): ?Capability_Column {

		// Linear search: the matrix holds only a handful of columns.
		foreach ( $this->columns as $column ) {
			if ( $column->key === $key ) {
				return $column;
			}
		}

		return null;

	}

}
