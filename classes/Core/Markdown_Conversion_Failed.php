<?php
/**
 * Distinguishes failed Markdown generation from valid empty content or storage.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Prevents callers from publishing an incomplete successful artifact.
 *
 * @since 0.5.2
 */
final class Markdown_Conversion_Failed extends \RuntimeException {}
