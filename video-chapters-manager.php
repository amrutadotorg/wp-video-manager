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
foreach ( array( 'class-video-chapters-db.php', 'class-video-chapters-access.php', 'class-sync-queue.php', 'class-ajax-handlers.php' ) as $dep_file ) {
	$dep_path = __DIR__ . '/' . $dep_file;
	if ( file_exists( $dep_path ) && ! class_exists( basename( $dep_file, '.php' ) ) ) {
		require_once $dep_path;
	}
}

class VideoChaptersManager {

	private $ajax;
	private $access;

	public function __construct() {
		$this->ajax   = new Video_Chapters_AJAX();
		$this->access = Video_Chapters_Access::get_instance();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_save_chapters', array( $this->ajax, 'save_chapters' ) );
		add_action( 'wp_ajax_search_video', array( $this->ajax, 'search_video' ) );
		add_action( 'wp_ajax_get_chapter_titles', array( $this->ajax, 'get_chapter_titles' ) );

		// Allowed-users management (restricted to manage_options inside the handlers).
		add_action( 'wp_ajax_video_chapters_search_users', array( $this->ajax, 'search_users' ) );
		add_action( 'wp_ajax_video_chapters_save_allowed_users', array( $this->ajax, 'save_allowed_users' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_video-chapters' === $hook ) {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'jquery-ui-autocomplete' );
			wp_enqueue_style( 'wp-jquery-ui-dialog' );

			wp_enqueue_script(
				'video-chapters-script',
				plugin_dir_url( __FILE__ ) . 'dist/video-chapters.min.js',
				array( 'jquery', 'jquery-ui-autocomplete' ),
				filemtime( __DIR__ . '/dist/video-chapters.min.js' ),
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
				filemtime( __DIR__ . '/dist/video-chapters.min.css' )
			);
		}

		// Submenu "Users" — slug is derived by WP from parent slug + page slug.
		if ( 'video-chapters_page_video-chapters-users' === $hook ) {
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'jquery-ui-autocomplete' );
			wp_enqueue_style( 'wp-jquery-ui-dialog' );

			wp_enqueue_script(
				'video-chapters-users-script',
				plugin_dir_url( __FILE__ ) . 'admin-users.js',
				array( 'jquery', 'jquery-ui-autocomplete' ),
				'1.0.0',
				true
			);

			wp_localize_script(
				'video-chapters-users-script',
				'videoChaptersUsers',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'video-chapters-users-nonce' ),
				)
			);
		}
	}

	public function add_admin_menu() {
		// Hide the whole menu tree from users who have neither manage_options
		// nor a spot on the allowed-users whitelist.
		if ( ! $this->access->user_has_access() ) {
			return;
		}

		add_menu_page(
			'Video Chapters',
			'Video Chapters',
			'read', // Real gating happens in user_has_access(), checked again in render callbacks.
			'video-chapters',
			array( $this, 'render_admin_page' ),
			'dashicons-video-alt3'
		);

		// The "Users" submenu (manage the whitelist itself) stays admin-only,
		// on purpose — someone on the list must never be able to add others.
		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				'video-chapters',
				'Video Chapters — Users',
				'Users',
				'manage_options',
				'video-chapters-users',
				array( $this, 'render_users_page' )
			);
		}
	}

	public function render_admin_page() {
		if ( ! $this->access->user_has_access() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'video-chapters-manager' ) );
		}
		?>
		<div class="wrap">
			<div id="app"></div>
		</div>
		<?php
	}

	public function render_users_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'video-chapters-manager' ) );
		}

		$allowed_users = $this->access->get_allowed_users_data();
		?>
		<style>
			#vcu-user-search.ui-autocomplete-loading {
				background-image: none !important;
			}
		</style>
		<div class="wrap">
			<h1>Video Chapters — Allowed Users</h1>
			<p>
				Administrators (with <code>manage_options</code> capability) always have access to the plugin.
				The list below allows you to grant access to additional users who do not have this capability.
			</p>

			<p>
				<input type="text" id="vcu-user-search" placeholder="Search for user by login or email…" style="width: 320px;" />
				<button type="button" class="button button-primary" id="vcu-add-user" disabled>Add</button>
			</p>

			<table class="widefat striped" id="vcu-users-table">
				<thead>
					<tr>
						<th>User</th>
						<th>Email</th>
						<th style="width: 100px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $allowed_users ) ) : ?>
						<tr class="vcu-empty-row">
							<td colspan="3">No additional users on the list.</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $allowed_users as $user ) : ?>
							<tr data-user-id="<?php echo esc_attr( $user->ID ); ?>">
								<td><?php echo esc_html( $user->display_name ); ?> (<?php echo esc_html( $user->user_login ); ?>)</td>
								<td><?php echo esc_html( $user->user_email ); ?></td>
								<td>
									<button type="button" class="button vcu-remove-user" data-user-id="<?php echo esc_attr( $user->ID ); ?>">
										Remove
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

new VideoChaptersManager();

register_activation_hook( __FILE__, array( 'Video_Chapters_DB', 'activate' ) );
register_activation_hook( __FILE__, array( 'Video_Chapters_Access', 'activate' ) );
