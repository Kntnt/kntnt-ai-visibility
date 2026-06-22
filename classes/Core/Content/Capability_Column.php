<?php
/**
 * Value object describing one capability column in the content-type matrix.
 *
 * Each module contributes exactly one column per artifact kind it produces —
 * Markdown (`.md`), `llms.txt`, `llms-full.txt` — and Core composes them into the
 * settings matrix (docs/spec/llms-txt.md §3.1, docs/adr/0010). A column carries
 * its key, its header label, the key of a column it depends on (forced off when
 * that dependency is off, so the subset guarantee holds), and the zero-config
 * default closure that decides a cell when the owner has saved nothing.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Content;

/**
 * One artifact kind's column in the content-type capability matrix.
 *
 * @since 0.2.0
 */
final readonly class Capability_Column {

	/**
	 * The zero-config default for a cell in this column.
	 *
	 * Typed `mixed` natively (a `callable` is not a valid property type) with the
	 * precise call signature in the docblock, mirroring the settings Field.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(string): bool
	 */
	public mixed $default;

	/**
	 * Declares one capability column.
	 *
	 * @since 0.2.0
	 *
	 * @param string                 $key      The column key: 'md' | 'llms' | 'llms_full'.
	 * @param string                 $label    The column header, e.g. 'Markdown (.md)'.
	 * @param string                 $requires The key of a column this one depends on,
	 *                                          or '' when it stands alone. A cell is forced
	 *                                          off when its required column's cell is off.
	 * @param callable(string): bool $default  The default for a cell, given the post type.
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $requires,
		callable $default,
	) {
		$this->default = $default;
	}

}
