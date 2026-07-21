<?php
/**
 * Plugin Name: Video Chapters Manager 2
 * Description: Manage chapters for videos stored in a custom database table.
 * Version: 2.0
 * Author: Your Name
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include Sync Queue class
if ( ! class_exists( 'Sync_Queue' ) ) {
	require_once __DIR__ . '/class-sync-queue.php';
}

class VideoChaptersManager {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_save_chapters', array( $this, 'save_chapters' ) );
		add_action( 'wp_ajax_search_video', array( $this, 'search_video' ) );
		add_action( 'wp_ajax_get_chapter_titles', array( $this, 'get_chapter_titles' ) );
	}

	/**
	 * Create custom tables on plugin activation.
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql_videos = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}post_videos (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            video_id varchar(255) NOT NULL,
            platform varchar(50) NOT NULL DEFAULT 'youtube',
            is_old tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY video_id (video_id),
            UNIQUE KEY platform_video (platform, video_id)
        ) $charset_collate;";

		$sql_chapters = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}post_video_chapters (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            video_id bigint(20) NOT NULL,
            start_time varchar(20) NOT NULL,
            title varchar(255) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY video_id (video_id),
            KEY sort_order (sort_order)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_videos );
		dbDelta( $sql_chapters );
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_video-chapters' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		wp_enqueue_script(
			'video-chapters-script',
			plugin_dir_url( __FILE__ ) . 'dist/video-chapters.min.js',
			array( 'jquery', 'jquery-ui-autocomplete' ),
			'2.0.0',
			true
		);

		wp_localize_script(
			'video-chapters-script',
			'videoChapters',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'video-chapters-nonce' ),
			)
		);

		wp_enqueue_style(
			'video-chapters-style',
			plugin_dir_url( __FILE__ ) . 'dist/video-chapters.min.css',
			array(),
			'2.0.0'
		);
	}

	public function add_admin_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_menu_page(
			'Video Chapters',
			'Video Chapters',
			'manage_options',
			'video-chapters',
			array( $this, 'render_admin_page' ),
			'dashicons-video-alt3'
		);
	}

	public function render_admin_page() {
		?>
		<div class="wrap">
			<div id="app"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: Autocomplete chapter titles
	 */
	public function get_chapter_titles() {
		check_ajax_referer( 'video-chapters-nonce', 'nonce' );

		global $wpdb;
		$term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';

		$query = $wpdb->prepare(
			"
            SELECT DISTINCT title
            FROM {$wpdb->prefix}post_video_chapters
            WHERE title LIKE %s
            ORDER BY title ASC
            LIMIT 10
        ",
			'%' . $wpdb->esc_like( $term ) . '%'
		);

		$titles = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_send_json( $titles );
	}

	/**
	 * AJAX: Search video by YouTube ID
	 */
	public function search_video() {
		check_ajax_referer( 'video-chapters-nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions', 403 );
		}

		global $wpdb;
		$youtube_id = sanitize_text_field( $_POST['youtube_id'] );

		$video_data = $wpdb->get_row(
			$wpdb->prepare(
				"
            SELECT v.id as internal_id, v.post_id, p.post_title, v.video_id as ytid
            FROM {$wpdb->prefix}post_videos v
            JOIN {$wpdb->posts} p ON v.post_id = p.ID
            WHERE v.platform = 'youtube' AND v.video_id = %s
            LIMIT 1
        ",
				$youtube_id
			)
		);

		if ( $video_data ) {
			$chapters_results = $wpdb->get_results(
				$wpdb->prepare(
					"
                SELECT start_time as startChapter, title
                FROM {$wpdb->prefix}post_video_chapters
                WHERE video_id = %d
                ORDER BY sort_order ASC
            ",
					$video_data->internal_id
				),
				ARRAY_A
			);

			wp_send_json_success(
				array(
					'id'       => $video_data->post_id,
					'title'    => esc_html( $video_data->post_title ),
					'ytid'     => esc_attr( $youtube_id ),
					'chapters' => wp_json_encode( $chapters_results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				)
			);
		} else {
			wp_send_json_error( 'Video not found in wp_post_videos' );
		}
	}

	/**
	 * AJAX: Save chapters for a video
	 */
	public function save_chapters() {
		try {
			global $wpdb;

			if ( ! check_ajax_referer( 'video-chapters-nonce', 'nonce', false ) ) {
				throw new Exception( 'Security verification failed' );
			}

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
			}

			$post_id    = filter_input( INPUT_POST, 'video_id', FILTER_VALIDATE_INT );
			$youtube_id = isset( $_POST['youtube_id'] ) ? sanitize_text_field( $_POST['youtube_id'] ) : '';
			$chapters   = isset( $_POST['chapters'] ) ? $_POST['chapters'] : array();

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

			$validated_chapters = array();
			if ( ! empty( $chapters ) ) {
				$validated_chapters = array_map(
					function ( $chapter ) {
						return array(
							'startChapter' => sanitize_text_field( $chapter['startChapter'] ),
							'title'        => sanitize_text_field( stripslashes( $chapter['title'] ) ),
						);
					},
					$chapters
				);
			}

			$internal_id = $wpdb->get_var(
				$wpdb->prepare(
					"
                SELECT id FROM {$wpdb->prefix}post_videos
                WHERE platform = 'youtube' AND video_id = %s AND post_id = %d
            ",
					$youtube_id,
					$post_id
				)
			);

			if ( ! $internal_id ) {
				throw new Exception( "Internal video record not found for YouTube ID $youtube_id" );
			}

			$wpdb->query( 'START TRANSACTION' );

			$wpdb->delete( "{$wpdb->prefix}post_video_chapters", array( 'video_id' => $internal_id ) );

			foreach ( $validated_chapters as $index => $chapter ) {
				$result = $wpdb->insert(
					"{$wpdb->prefix}post_video_chapters",
					array(
						'video_id'   => $internal_id,
						'start_time' => $chapter['startChapter'],
						'title'      => $chapter['title'],
						'sort_order' => $index + 1,
					)
				);

				if ( false === $result ) {
					$wpdb->query( 'ROLLBACK' );
					throw new Exception( 'Failed to insert chapter: ' . $wpdb->last_error );
				}
			}

			$wpdb->query( 'COMMIT' );

			error_log( 'Successfully saved ' . count( $validated_chapters ) . " chapters for video $internal_id (Post $post_id)" );

			if ( class_exists( 'Sync_Queue' ) ) {
				Sync_Queue::queue_task( $post_id, 'youtube', 10, $youtube_id, 'video-chapters-manager' );
			}

			wp_send_json_success(
				array(
					'message'       => empty( $chapters ) ? 'Chapters cleared successfully' : 'Chapters saved successfully',
					'post_id'       => $post_id,
					'video_id'      => $internal_id,
					'chapter_count' => count( $validated_chapters ),
				)
			);

		} catch ( Exception $e ) {
			error_log( 'Video Chapters Manager Error: ' . $e->getMessage() );
			error_log(
				'Error context: ' . print_r(
					array(
						'post_id'         => $post_id ?? 'not set',
						'youtube_id'      => $youtube_id ?? 'not set',
						'wpdb_last_error' => $wpdb->last_error ?? 'no error',
						'wpdb_last_query' => $wpdb->last_query ?? 'no query',
						'php_error'       => error_get_last(),
					),
					true
				)
			);

			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'debug'   => WP_DEBUG ? array(
						'db_error' => $wpdb->last_error,
						'db_query' => $wpdb->last_query,
						'trace'    => $e->getTraceAsString(),
					) : null,
				)
			);
		}
	}
}

// Initialize the plugin
new VideoChaptersManager();

// Register activation hook (must be called at plugin load time)
register_activation_hook( __FILE__, array( 'VideoChaptersManager', 'activate' ) );
