<?php
/**
 * Minimal WordPress database type for the native SQL boundary adapter.
 *
 * @package Tests\Helpers
 * @since 0.5.2
 */

declare(strict_types=1);

if (!class_exists('wpdb')) {
    class wpdb {}
}
