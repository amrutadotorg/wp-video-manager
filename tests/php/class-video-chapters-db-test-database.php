<?php
/**
 * Minimal $wpdb replacement for video chapter database unit tests.
 *
 * @package VideoChaptersManager
 */

class VideoChaptersDbTestDatabase {

	public $prefix = 'wp_';

	public $last_error = 'Database test error';

	public $query_results = array();

	public $delete_result = 1;

	public $insert_results = array();

	public $queries = array();

	public $delete_calls = array();

	public $insert_calls = array();

	public function query( $query ) {
		$this->queries[] = $query;

		return $this->query_results[ $query ] ?? 1;
	}

	public function delete( $table, $where ) {
		$this->delete_calls[] = array(
			'table' => $table,
			'where' => $where,
		);

		return $this->delete_result;
	}

	public function insert( $table, $data ) {
		$this->insert_calls[] = array(
			'table' => $table,
			'data'  => $data,
		);

		if ( empty( $this->insert_results ) ) {
			return 1;
		}

		return array_shift( $this->insert_results );
	}
}
