<?php
/**
 * Plugin Name: Video Chapters Manager 2
 * Description: Manage chapters for videos stored in a custom database table.
 * Version: 2.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load dependencies
foreach ( array( 'class-video-chapters-db.php', 'class-sync-queue.php', 'class-ajax-handlers.php' ) as $dep_file ) {
	$dep_path = __DIR__ . '/' . $dep_file;
	if ( file_exists( $dep_path ) && ! class_exists( basename( $dep_file, '.php' ) ) ) {
		require_once $dep_path;
	}
}

class VideoChaptersManager {

	private $ajax;

	public function __construct() {
		$this->ajax = new Video_Chapters_AJAX();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_save_chapters', array( $this->ajax, 'save_chapters' ) );
		add_action( 'wp_ajax_search_video', array( $this->ajax, 'search_video' ) );
		add_action( 'wp_ajax_get_chapter_titles', array( $this->ajax, 'get_chapter_titles' ) );
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
}

new VideoChaptersManager();

register_activation_hook( __FILE__, array( 'Video_Chapters_DB', 'activate' ) );
