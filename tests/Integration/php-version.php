<?php
/**
 * Reports the serving Playground worker's runtime for the HTTP test harnesses.
 *
 * This fixture only exists in the mounted test checkout, never in a release.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

header( 'Content-Type: text/plain; charset=utf-8' );
echo PHP_VERSION;
