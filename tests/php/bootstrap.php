<?php
/**
 * Minimal WordPress function replacements for unit tests that do not bootstrap WordPress.
 *
 * Integration tests should use a real WordPress environment instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function esc_html( $text ) {
	return $text;
}

function sanitize_text_field( $text ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This is a minimal WordPress test stub.
	return trim( strip_tags( $text ) );
}

require_once __DIR__ . '/class-sync-queue-test-database.php';
require_once __DIR__ . '/class-video-chapters-db-test-database.php';
require_once dirname( __DIR__, 2 ) . '/class-video-chapters-db.php';
require_once dirname( __DIR__, 2 ) . '/class-ajax-handlers.php';
require_once dirname( __DIR__, 2 ) . '/class-sync-queue.php';
