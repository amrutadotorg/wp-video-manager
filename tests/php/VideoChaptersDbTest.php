<?php
/**
 * Unit tests for transaction handling in the chapter database layer.
 *
 * @package VideoChaptersManager
 */

use PHPUnit\Framework\TestCase;

class VideoChaptersDbTest extends TestCase {

	private $original_wpdb;

	private $wpdb;

	private $db;

	protected function setUp(): void {
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$this->wpdb          = new VideoChaptersDbTestDatabase();
		$this->db            = new Video_Chapters_DB();
		$wpdb                = $this->wpdb;
	}

	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
	}

	public function test_save_chapters_rolls_back_when_deleting_existing_chapters_fails(): void {
		$this->wpdb->delete_result = false;

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to remove existing chapters' );

		try {
			$this->db->save_chapters( 7, $this->chapters() );
		} finally {
			$this->assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $this->wpdb->queries );
		}
	}

	public function test_save_chapters_rolls_back_when_commit_fails(): void {
		$this->wpdb->query_results['COMMIT'] = false;

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to commit chapter save transaction' );

		try {
			$this->db->save_chapters( 7, $this->chapters() );
		} finally {
			$this->assertSame( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), $this->wpdb->queries );
		}
	}

	public function test_save_chapters_commits_when_all_database_operations_succeed(): void {
		$this->assertSame( 3, $this->db->save_chapters( 7, $this->chapters() ) );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->wpdb->queries );
	}

	private function chapters() {
		return array(
			array(
				'startChapter' => '0:00',
				'title'        => 'Start',
			),
			array(
				'startChapter' => '0:10',
				'title'        => 'Middle',
			),
			array(
				'startChapter' => '0:20',
				'title'        => 'End',
			),
		);
	}
}
