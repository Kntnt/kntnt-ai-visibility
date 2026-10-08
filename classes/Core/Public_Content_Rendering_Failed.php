<?php
/**
 * Distinguishes a failed public HTML integration from valid empty content.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Prevents publishing a metadata-only success after public rendering fails.
 *
 * @since 0.5.2
 */
final class Public_Content_Rendering_Failed extends \RuntimeException {}
