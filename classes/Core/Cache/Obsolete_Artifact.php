<?php
/**
 * Refusal of work whose source generation was revoked.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

/**
 * Obsolete bytes are never a valid storage-outage fallback.
 *
 * @since 0.5.2
 */
final class Obsolete_Artifact extends \DomainException {}
