<?php
/**
 * Resolves an llms artifact's post-type set from the Core matrix.
 *
 * The llms artifacts own no selection — each reads its type set from the Core
 * content-type matrix (docs/spec/llms-txt.md §4.2): the artifact's column through
 * its developer filter, intersected with the `.md` set after filtering so a
 * filter can never add a type with no alternate to link or concatenate, then
 * ordered page, post, then the rest. The order is the section order of the index
 * and the concatenation order of the full file.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Content\Content_Types;

/**
 * Resolves and orders the post-type set for an llms artifact column.
 *
 * @since 0.2.0
 */
final class Selected_Types {

	/**
	 * Binds the resolver to the content-type matrix it reads.
	 *
	 * @since 0.2.0
	 *
	 * @param Content_Types $types The content-type capability matrix.
	 */
	public function __construct( private readonly Content_Types $types ) {}

	/**
	 * Returns the ordered type set for a column, filtered and md-intersected.
	 *
	 * @since 0.2.0
	 *
	 * @param string $column The matrix column key ('llms' | 'llms_full').
	 * @return list<string>
	 */
	public function resolve( string $column ): array {

		// The column through its developer filter (literal per column), intersected
		// with the `.md` set so a filter can never add a type without an alternate.
		$types = $this->types->types_for( $column );
		$selected = match ( $column ) {
			'llms'      => apply_filters( 'kntnt_ai_visibility_llms_post_types', $types ),
			'llms_full' => apply_filters( 'kntnt_ai_visibility_llms_full_post_types', $types ),
			default     => $types,
		};
		$selected = is_array( $selected ) ? array_values( array_filter( $selected, 'is_string' ) ) : [];
		$md = $this->types->types_for( 'md' );

		return $this->order( array_values( array_intersect( $selected, $md ) ) );

	}

	/**
	 * Orders a type list as page, then post, then the rest in their original order.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $types The type list.
	 * @return list<string>
	 */
	private function order( array $types ): array {

		// Pull the cornerstone types to the front, keep the rest in order.
		$ordered = [];
		foreach ( [ 'page', 'post' ] as $first ) {
			if ( in_array( $first, $types, true ) ) {
				$ordered[] = $first;
			}
		}
		foreach ( $types as $type ) {
			if ( ! in_array( $type, $ordered, true ) ) {
				$ordered[] = $type;
			}
		}

		return $ordered;

	}

}
