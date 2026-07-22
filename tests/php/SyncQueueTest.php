<?php
/**
 * Unit tests for sync queue SQL preparation.
 *
 * @package VideoChaptersManager
 */

use PHPUnit\Framework\TestCase;

class SyncQueueTest extends TestCase {

	private $original_wpdb;

	private $wpdb;

	protected function setUp(): void {
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$this->wpdb          = new SyncQueueTestDatabase();
		$wpdb                = $this->wpdb;
	}

	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
	}

	public function test_queue_task_prepares_percent_characters_only_once(): void {
		Sync_Queue::queue_task( 123, 'youtube', 10, 'video%id', 'source%name' );

		$this->assertCount( 1, $this->wpdb->prepared_queries );
		$this->assertSame(
			array( 123, 'video%id', 'youtube', 10, 'source%name', 123, 'youtube', 'video%id' ),
			$this->wpdb->prepared_queries[0]['arguments']
		);
		$this->assertStringContainsString( 'INSERT INTO nvp_sync_queue', $this->wpdb->queries[0] );
	}

	public function test_queue_task_keeps_null_optional_values_as_sql_null(): void {
		Sync_Queue::queue_task( 123, 'youtube' );

		$this->assertSame(
			array( 123, 'youtube', 10, 123, 'youtube' ),
			$this->wpdb->prepared_queries[0]['arguments']
		);
		$this->assertStringContainsString( 'SELECT %d, NULL, %s, %d', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'AND ytid <=> NULL', $this->wpdb->queries[0] );
	}
}
