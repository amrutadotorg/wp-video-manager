<?php
/**
 * Minimal $wpdb replacement for sync queue unit tests.
 *
 * @package VideoChaptersManager
 */

class SyncQueueTestDatabase {

	public $prefix = 'wp_';

	public $prepared_queries = array();

	public $queries = array();

	public function prepare( $query, ...$arguments ) {
		$this->prepared_queries[] = array(
			'query'     => $query,
			'arguments' => $arguments,
		);

		return $query;
	}

	public function query( $query ) {
		$this->queries[] = $query;

		return false;
	}
}
