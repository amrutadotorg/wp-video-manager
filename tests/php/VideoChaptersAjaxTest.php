<?php
/**
 * Unit tests for chapter validation that does not require a WordPress runtime.
 *
 * @package VideoChaptersManager
 */

use PHPUnit\Framework\TestCase;

class VideoChaptersAjaxTest extends TestCase {

	private $ajax;

	protected function setUp(): void {
		$class      = new ReflectionClass( 'Video_Chapters_AJAX' );
		$this->ajax = $class->newInstanceWithoutConstructor();
	}

	public function test_time_to_seconds_converts_minute_and_hour_formats(): void {
		$this->assertSame( 65, $this->call_private_method( 'time_to_seconds', array( '1:05' ) ) );
		$this->assertSame( 3723, $this->call_private_method( 'time_to_seconds', array( '1:02:03' ) ) );
	}

	public function test_validate_chapters_returns_empty_array_when_no_chapters_are_submitted(): void {
		$this->assertSame( array(), $this->call_private_method( 'validate_chapters', array( array() ) ) );
	}

	public function test_validate_chapters_sanitizes_and_sorts_valid_chapters(): void {
		$chapters = array(
			array(
				'startChapter' => '0:20',
				'title'        => '<strong>Introduction</strong>',
			),
			array(
				'startChapter' => '0:00',
				'title'        => 'Start',
			),
			array(
				'startChapter' => '1:00',
				'title'        => 'Summary',
			),
		);

		$this->assertSame(
			array(
				array(
					'startChapter' => '0:00',
					'title'        => 'Start',
				),
				array(
					'startChapter' => '0:20',
					'title'        => 'Introduction',
				),
				array(
					'startChapter' => '1:00',
					'title'        => 'Summary',
				),
			),
			$this->call_private_method( 'validate_chapters', array( $chapters ) )
		);
	}

	public function test_validate_chapters_requires_three_complete_chapters(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'You must include at least three separate chapters.' );

		$this->call_private_method(
			'validate_chapters',
			array(
				array(
					array(
						'startChapter' => '0:00',
						'title'        => 'Start',
					),
					array(
						'startChapter' => '0:20',
						'title'        => 'Middle',
					),
				),
			),
		);
	}

	public function test_validate_chapters_requires_the_first_timestamp_to_be_zero(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'The very first timestamp on your list must be exactly 0:00.' );

		$this->call_private_method(
			'validate_chapters',
			array(
				array(
					array(
						'startChapter' => '0:10',
						'title'        => 'Start',
					),
					array(
						'startChapter' => '0:20',
						'title'        => 'Middle',
					),
					array(
						'startChapter' => '0:30',
						'title'        => 'End',
					),
				),
			),
		);
	}

	public function test_validate_chapters_rejects_chapters_less_than_ten_seconds_apart(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Chapters must be at least 10 seconds apart: 0:00 and 0:09 are only 9 seconds apart.' );

		$this->call_private_method(
			'validate_chapters',
			array(
				array(
					array(
						'startChapter' => '0:00',
						'title'        => 'Start',
					),
					array(
						'startChapter' => '0:09',
						'title'        => 'Too close',
					),
					array(
						'startChapter' => '0:20',
						'title'        => 'End',
					),
				),
			),
		);
	}

	public function test_validate_chapters_accepts_chapters_exactly_ten_seconds_apart(): void {
		$chapters = array(
			array(
				'startChapter' => '0:20',
				'title'        => 'End',
			),
			array(
				'startChapter' => '0:00',
				'title'        => 'Start',
			),
			array(
				'startChapter' => '0:10',
				'title'        => 'Middle',
			),
		);

		$this->assertCount( 3, $this->call_private_method( 'validate_chapters', array( $chapters ) ) );
	}

	public function test_validate_chapters_rejects_invalid_timestamp_format(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Invalid time format for chapter: invalid' );

		$this->call_private_method(
			'validate_chapters',
			array(
				array(
					array(
						'startChapter' => 'invalid',
						'title'        => 'Start',
					),
				),
			),
		);
	}

	public function test_validate_chapters_rejects_invalid_chapter_structure(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Invalid chapter data.' );

		$this->call_private_method( 'validate_chapters', array( 'not-an-array' ) );
	}

	public function test_validate_chapters_rejects_incomplete_chapter_data(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Each chapter must include a start time and title.' );

		$this->call_private_method(
			'validate_chapters',
			array(
				array(
					array(
						'startChapter' => '0:00',
						'title'        => '',
					),
				),
			),
		);
	}

	public function test_time_format_requires_minutes_and_seconds_below_sixty(): void {
		$this->assertFalse( $this->call_private_method( 'is_valid_time_format', array( '0:60' ) ) );
		$this->assertFalse( $this->call_private_method( 'is_valid_time_format', array( '60:00' ) ) );
		$this->assertFalse( $this->call_private_method( 'is_valid_time_format', array( '1:02:60' ) ) );
		$this->assertTrue( $this->call_private_method( 'is_valid_time_format', array( '1:02:03' ) ) );
	}

	public function test_safe_error_response_contains_no_exception_or_debug_data(): void {
		$error_id = $this->call_private_method( 'generate_error_id', array() );
		$response = $this->call_private_method( 'build_safe_error_response', array( $error_id ) );

		$this->assertSame( 'vcm-123e4567-e89b-12d3-a456-426614174000', $error_id );
		$this->assertSame( $error_id, $response['error_id'] );
		$this->assertSame(
			'Unable to save chapters. Please contact an administrator and provide error reference vcm-123e4567-e89b-12d3-a456-426614174000.',
			$response['message']
		);
		$this->assertArrayNotHasKey( 'debug', $response );
	}

	public function test_error_logger_accepts_all_throwables(): void {
		$method = new ReflectionMethod( 'Video_Chapters_AJAX', 'log_error' );
		$type   = $method->getParameters()[0]->getType();

		$this->assertInstanceOf( ReflectionNamedType::class, $type );
		$this->assertSame( Throwable::class, $type->getName() );
	}

	private function call_private_method( $method_name, $arguments ) {
		$method = new ReflectionMethod( 'Video_Chapters_AJAX', $method_name );

		return $method->invokeArgs( $this->ajax, $arguments );
	}
}
