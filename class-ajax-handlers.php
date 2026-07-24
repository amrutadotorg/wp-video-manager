<?php
/**
 * AJAX request handlers for Video Chapters Manager.
 *
 * Each method handles one wp_ajax_* action.
 * Validation, capability checks, and JSON responses live here.
 * DB calls are delegated to Video_Chapters_DB.
 */

if ( ! class_exists( 'Video_Chapters_AJAX' ) ) {

	class Video_Chapters_AJAX {

		private $db;
		private $access;

		public function __construct() {
			$this->db     = Video_Chapters_DB::get_instance();
			$this->access = Video_Chapters_Access::get_instance();
		}

		/**
		 * AJAX: Autocomplete chapter titles.
		 */
		public function get_chapter_titles() {
			check_ajax_referer( 'video-chapters-nonce', 'nonce' );

			if ( ! $this->access->user_has_access() ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$term   = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
			$titles = $this->db->get_chapter_titles( $term );

			wp_send_json( $titles );
		}

		/**
		 * AJAX: Search video by YouTube ID.
		 */
		public function search_video() {
			check_ajax_referer( 'video-chapters-nonce', 'nonce' );

			if ( ! $this->access->user_has_access() ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$youtube_id = isset( $_POST['youtube_id'] ) ? sanitize_text_field( $_POST['youtube_id'] ) : '';
			$video_data = $this->db->get_video_by_youtube_id( $youtube_id );

			if ( ! $video_data ) {
				wp_send_json_error( 'Video not found in wp_post_videos' );
			}

			$chapters_results = $this->db->get_chapters( $video_data->internal_id );

			wp_send_json_success(
				array(
					'id'       => $video_data->post_id,
					'title'    => $video_data->post_title,
					'ytid'     => $youtube_id,
					'chapters' => $chapters_results,
				)
			);
		}

		/**
		 * AJAX: Save chapters for a video.
		 */
		public function save_chapters() {
			global $wpdb;

			try {
				if ( ! check_ajax_referer( 'video-chapters-nonce', 'nonce', false ) ) {
					throw new Exception( 'Security verification failed' );
				}

				if ( ! $this->access->user_has_access() ) {
					wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
				}

				$post_id    = filter_input( INPUT_POST, 'video_id', FILTER_VALIDATE_INT );
				$youtube_id = isset( $_POST['youtube_id'] ) ? sanitize_text_field( wp_unslash( $_POST['youtube_id'] ) ) : '';
				$chapters   = isset( $_POST['chapters'] ) ? wp_unslash( $_POST['chapters'] ) : array();

				if ( ! $post_id || ! $youtube_id ) {
					throw new Exception(
						'Missing required data: ' .
						( ! $post_id ? 'post_id ' : '' ) .
						( ! $youtube_id ? 'youtube_id' : '' )
					);
				}

				if ( ! get_post( $post_id ) ) {
					throw new Exception( "Post $post_id not found" );
				}

				$validated_chapters = $this->validate_chapters( $chapters );
				$internal_id        = $this->db->get_internal_id( $youtube_id, $post_id );

				if ( ! $internal_id ) {
					throw new Exception( "Internal video record not found for YouTube ID $youtube_id" );
				}

				// Check if chapters actually changed
				$existing_chapters = $this->db->get_chapters( $internal_id );
				if ( wp_json_encode( $existing_chapters ) === wp_json_encode( $validated_chapters ) ) {
					wp_send_json_success(
						array(
							'message'       => 'No changes detected. Chapters are up to date.',
							'post_id'       => $post_id,
							'video_id'      => $internal_id,
							'chapter_count' => count( $validated_chapters ),
						)
					);
				}

				$count = $this->db->save_chapters( $internal_id, $validated_chapters );

				error_log( "Successfully saved $count chapters for video $internal_id (Post $post_id)" );

				$sync_status  = 'queued';
				$sync_message = empty( $chapters ) ? 'Chapters cleared successfully.' : 'Chapters saved successfully.';
				if ( class_exists( 'Sync_Queue' ) ) {
					$queue_result = Sync_Queue::queue_task( $post_id, 'youtube', 10, $youtube_id, 'video-chapters-manager' );
					if ( false === $queue_result ) {
						$sync_status  = 'failed';
						$sync_message = 'Chapters were saved, but synchronization could not be queued. Please contact an administrator.';
						error_log( "Failed to queue chapter synchronization for video $internal_id (Post $post_id)" );
					} elseif ( 0 === $queue_result ) {
						$sync_status  = 'already_pending';
						$sync_message = 'Chapters saved successfully. A synchronization task is already pending.';
					}
				} else {
					$sync_status  = 'failed';
					$sync_message = 'Chapters were saved, but the synchronization queue is unavailable. Please contact an administrator.';
					error_log( "Synchronization queue class is unavailable for video $internal_id (Post $post_id)" );
				}

				wp_send_json_success(
					array(
						'message'       => $sync_message,
						'post_id'       => $post_id,
						'video_id'      => $internal_id,
						'chapter_count' => $count,
						'sync_status'   => $sync_status,
					)
				);
			} catch ( Throwable $e ) {
				$error_id = $this->generate_error_id();
				$this->log_error( $e, $post_id ?? null, $youtube_id ?? null, $error_id );
				wp_send_json_error(
					$this->build_safe_error_response( $error_id )
				);
			}
		}

		/**
		 * AJAX: Search WP users, to help pick who to add to the allowed-users list.
		 * Restricted to manage_options — only admins manage the whitelist.
		 */
		public function search_users() {
			check_ajax_referer( 'video-chapters-users-nonce', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';

			if ( '' === $term ) {
				wp_send_json_success( array() );
			}

			$allowed = $this->access->get_allowed_users();
			$args    = array(
				'search'         => '*' . esc_attr( $term ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => 20,
				'role__not_in'   => array( 'administrator' ),
			);
			if ( ! empty( $allowed ) ) {
				$args['exclude'] = $allowed;
			}
			$query = new WP_User_Query( $args );

			$results = array();

			foreach ( $query->get_results() as $user ) {
				$results[] = array(
					'id'    => $user->ID,
					'label' => sprintf( '%s (%s)', $user->display_name, $user->user_login ),
					'email' => $user->user_email,
				);
			}

			wp_send_json_success( $results );
		}

		/**
		 * AJAX: Add or remove a user from the allowed-users list.
		 * Restricted to manage_options — someone on the list can never edit it.
		 */
		public function save_allowed_users() {
			check_ajax_referer( 'video-chapters-users-nonce', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions', 403 );
			}

			$action_type = isset( $_POST['user_action'] ) ? sanitize_text_field( $_POST['user_action'] ) : '';
			$user_id     = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

			if ( ! $user_id ) {
				wp_send_json_error( 'Missing user_id' );
			}

			if ( 'add' === $action_type ) {
				$result = $this->access->add_user( $user_id );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}
			} elseif ( 'remove' === $action_type ) {
				$this->access->remove_user( $user_id );
			} else {
				wp_send_json_error( 'Unknown action' );
			}

			$user = get_userdata( $user_id );

			wp_send_json_success(
				array(
					'user_id'      => $user_id,
					'display_name' => $user ? $user->display_name : '',
					'user_login'   => $user ? $user->user_login : '',
					'user_email'   => $user ? $user->user_email : '',
					'action'       => $action_type,
				)
			);
		}

		private function time_to_seconds( $time_str ) {
			$parts = array_map( 'intval', explode( ':', $time_str ) );
			if ( count( $parts ) === 3 ) {
				return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
			}
			return $parts[0] * 60 + $parts[1];
		}

		/**
		 * Check whether a timestamp uses the supported MM:SS or HH:MM:SS format.
		 */
		private function is_valid_time_format( $time_str ) {
			if ( ! preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', $time_str ) ) {
				return false;
			}

			$parts = array_map( 'intval', explode( ':', $time_str ) );
			if ( count( $parts ) === 2 ) {
				return $parts[0] < 60 && $parts[1] < 60;
			}

			return $parts[1] < 60 && $parts[2] < 60;
		}

		/**
		 * Sanitize and validate chapter data from POST.
		 */
		private function validate_chapters( $chapters ) {
			if ( ! is_array( $chapters ) ) {
				throw new Exception( 'Invalid chapter data.' );
			}

			if ( empty( $chapters ) ) {
				return array();
			}

			$validated = array();
			foreach ( $chapters as $chapter ) {
				if ( ! is_array( $chapter ) || ! isset( $chapter['startChapter'], $chapter['title'] ) ) {
					throw new Exception( 'Invalid chapter data.' );
				}

				$start_time = sanitize_text_field( $chapter['startChapter'] );
				$title      = sanitize_text_field( $chapter['title'] );

				if ( '' === $start_time || '' === $title ) {
					throw new Exception( 'Each chapter must include a start time and title.' );
				}

				// Vimeo chapter title limit: max 50 characters.
				$title_len = mb_strlen( $title );
				if ( $title_len > 50 ) {
					throw new Exception(
						sprintf(
							'Chapter title exceeds 50 characters (%1$d): %2$s',
							absint( $title_len ),
							esc_html( $title )
						)
					);
				}

				if ( ! $this->is_valid_time_format( $start_time ) ) {
					throw new Exception( 'Invalid time format for chapter: ' . esc_html( $start_time ) );
				}

				$validated[] = array(
					'startChapter' => $start_time,
					'title'        => $title,
				);
			}

			$chapter_count = count( $validated );
			if ( $chapter_count > 0 ) {
				if ( $chapter_count < 3 ) {
					throw new Exception( 'You must include at least three separate chapters.' );
				}

				usort(
					$validated,
					function ( $a, $b ) {
						return $this->time_to_seconds( $a['startChapter'] ) - $this->time_to_seconds( $b['startChapter'] );
					}
				);

				if ( $this->time_to_seconds( $validated[0]['startChapter'] ) !== 0 ) {
					throw new Exception( 'The very first timestamp on your list must be exactly 0:00.' );
				}

				for ( $index = 1; $index < $chapter_count; $index++ ) {
					$previous_time = $this->time_to_seconds( $validated[ $index - 1 ]['startChapter'] );
					$current_time  = $this->time_to_seconds( $validated[ $index ]['startChapter'] );
					$difference    = $current_time - $previous_time;

					if ( $difference < 10 ) {
						throw new Exception(
							sprintf(
								'Chapters must be at least 10 seconds apart: %1$s and %2$s are only %3$d seconds apart.',
								esc_html( $validated[ $index - 1 ]['startChapter'] ),
								esc_html( $validated[ $index ]['startChapter'] ),
								esc_html( $difference )
							)
						);
					}
				}
			}

			return $validated;
		}

		/**
		 * Create a non-sensitive error reference that can be matched to server logs.
		 *
		 * @return string Error reference.
		 */
		private function generate_error_id() {
			return 'vcm-' . wp_generate_uuid4();
		}

		/**
		 * Build the error payload exposed to AJAX clients.
		 *
		 * @param string $error_id Error reference.
		 * @return array<string, string> Safe error response data.
		 */
		private function build_safe_error_response( $error_id ) {
			return array(
				'message'  => sprintf( 'Unable to save chapters. Please contact an administrator and provide error reference %s.', $error_id ),
				'error_id' => $error_id,
			);
		}

		/**
		 * Log error with context. Detailed diagnostics must never be sent to AJAX clients.
		 *
		 * @param Throwable $e          Error or exception that was thrown.
		 * @param int|null  $post_id    WordPress post ID, when available.
		 * @param string|null $youtube_id YouTube ID, when available.
		 * @param string     $error_id  Safe error reference sent to the client.
		 */
		private function log_error( Throwable $e, $post_id = null, $youtube_id = null, $error_id = '' ) {
			global $wpdb;

			error_log( "Video Chapters Manager Error [$error_id]: " . $e->getMessage() );
			error_log(
				'Error context: ' . print_r(
					array(
						'error_id'        => $error_id,
						'post_id'         => $post_id ?? 'not set',
						'youtube_id'      => $youtube_id ?? 'not set',
						'wpdb_last_error' => $wpdb->last_error ?? 'no error',
						'wpdb_last_query' => $wpdb->last_query ?? 'no query',
						'exception_trace' => $e->getTraceAsString(),
						'php_error'       => error_get_last(),
					),
					true
				)
			);
		}
	}
}
