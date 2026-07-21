<?php
/**
 * Plugin Name: Video Chapters Manager 2
 * Description: Manage chapters for videos stored in a custom database table.
 * Version: 2.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include Sync Queue class
if (!class_exists('Sync_Queue')) {
    require_once __DIR__ . '/class-sync-queue.php';
}

// At the top of your PHP file
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

class VideoChaptersManager {
    private $youtube_meta_prefix = '_youtube_chapters_';

    public function __construct() {
        // Admin menu and scripts
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX actions
        add_action('wp_ajax_save_chapters', [$this, 'save_chapters']);
        add_action('wp_ajax_search_video', [$this, 'search_video']);
        add_action('wp_ajax_get_chapter_titles', [$this, 'get_chapter_titles']);
    }

    /**
     * Enqueue JavaScript and CSS
     */
    public function enqueue_assets($hook) {
        if ('toplevel_page_video-chapters' !== $hook) {
            return;
        }
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style('wp-jquery-ui-dialog');

        // Load Webpack-bundled JavaScript
        wp_enqueue_script(
            'video-chapters-script',
            plugin_dir_url(__FILE__) . 'dist/video-chapters.min.js',
            ['jquery', 'jquery-ui-autocomplete'],
            '2.0.0',
            true
        );

        // Pass AJAX URL and nonce to the script
        wp_localize_script('video-chapters-script', 'videoChapters', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('video-chapters-nonce'),
        ]);

            wp_enqueue_style(
        'video-chapters-style',
        plugin_dir_url(__FILE__) . 'dist/video-chapters.min.css',
        [],
        '2.0.0'
    );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        $allowed_user_ids = [11116, 10];
        $current_user_id = get_current_user_id();

        if (in_array($current_user_id, $allowed_user_ids)) {
            add_menu_page(
                'Video Chapters',
                'Video Chapters',
                'manage_options',
                'video-chapters',
                [$this, 'render_admin_page'],
                'dashicons-video-alt3'
            );
        }
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <div id="app"></div> <!-- React/JS App Mount Point -->
        </div>
        <?php
    }

    /**
     * Get chapter titles (AJAX)
     */
public function get_chapter_titles() {
    check_ajax_referer('video-chapters-nonce', 'nonce');

    global $wpdb;
    $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';

    $query = $wpdb->prepare("
        SELECT DISTINCT title 
        FROM {$wpdb->prefix}post_video_chapters 
        WHERE title LIKE %s 
        ORDER BY title ASC 
        LIMIT 10
    ", '%' . $wpdb->esc_like($term) . '%');

    $titles = $wpdb->get_col($query);

    wp_send_json($titles);
}


    /**
     * Search video (AJAX)
     */
    public function search_video() {
    check_ajax_referer('video-chapters-nonce', 'nonce');

    global $wpdb;
    $youtube_id = sanitize_text_field($_POST['youtube_id']);

    $video_data = $wpdb->get_row($wpdb->prepare("
        SELECT v.id as internal_id, v.post_id, p.post_title, v.video_id as ytid
        FROM {$wpdb->prefix}post_videos v
        JOIN {$wpdb->posts} p ON v.post_id = p.ID
        WHERE v.platform = 'youtube' AND v.video_id = %s
        LIMIT 1
    ", $youtube_id));

    if ($video_data) {
        $chapters_results = $wpdb->get_results($wpdb->prepare("
            SELECT start_time as startChapter, title 
            FROM {$wpdb->prefix}post_video_chapters 
            WHERE video_id = %d 
            ORDER BY sort_order ASC
        ", $video_data->internal_id), ARRAY_A);
        
        wp_send_json_success([
            'id'       => $video_data->post_id,
            'title'    => $video_data->post_title,
            'ytid'     => $youtube_id,
            'chapters' => json_encode($chapters_results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    } else {
        wp_send_json_error('Video not found in wp_post_videos');
    }
}


    /**
     * Save chapters (AJAX)
     */
public function save_chapters() {
    try {
        global $wpdb;

        // 1. Verify nonce
        if (!check_ajax_referer('video-chapters-nonce', 'nonce', false)) {
            throw new Exception('Security verification failed');
        }

        // 2. Get and validate inputs
        $post_id = filter_input(INPUT_POST, 'video_id', FILTER_VALIDATE_INT);
        $youtube_id = isset($_POST['youtube_id']) ? sanitize_text_field($_POST['youtube_id']) : '';
        
        // Allow empty chapters array
        $chapters = isset($_POST['chapters']) ? $_POST['chapters'] : [];

        if (!$post_id || !$youtube_id) {
            throw new Exception('Missing required data: ' . 
                (!$post_id ? 'post_id ' : '') . 
                (!$youtube_id ? 'youtube_id' : '')
            );
        }

        // 3. Validate post exists
        if (!get_post($post_id)) {
            throw new Exception("Post $post_id not found");
        }

        // 4. Process chapters - if we have any
        $validated_chapters = [];
        if (!empty($chapters)) {
            $validated_chapters = array_map(function($chapter) {
                return [
                    'startChapter' => sanitize_text_field($chapter['startChapter']),
                    'title' => sanitize_text_field(stripslashes($chapter['title']))
                ];
            }, $chapters);
        }

        // 5. Encode chapters - empty array will become '[]'
        $encoded_chapters = wp_json_encode($validated_chapters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded_chapters === false) {
            throw new Exception('JSON encoding failed: ' . json_last_error_msg());
        }

        // 6. Database update
        // Get internal video ID from wp_post_videos
        $internal_id = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}post_videos 
            WHERE platform = 'youtube' AND video_id = %s AND post_id = %d
        ", $youtube_id, $post_id));

        if (!$internal_id) {
            throw new Exception("Internal video record not found for YouTube ID $youtube_id");
        }

        // Delete existing chapters for this video
        $wpdb->delete("{$wpdb->prefix}post_video_chapters", ['video_id' => $internal_id]);

        // Insert new chapters
        foreach ($validated_chapters as $index => $chapter) {
            $result = $wpdb->insert("{$wpdb->prefix}post_video_chapters", [
                'video_id'   => $internal_id,
                'start_time' => $chapter['startChapter'],
                'title'      => $chapter['title'],
                'sort_order' => $index + 1
            ]);

            if ($result === false) {
                throw new Exception('Failed to insert chapter: ' . $wpdb->last_error);
            }
        }

        // Log success
        error_log("Successfully saved " . count($validated_chapters) . " chapters for video $internal_id (Post $post_id)");
        
        // Trigger sync worker for YouTube
        if (class_exists('Sync_Queue')) {
            Sync_Queue::queue_task($post_id, 'youtube', 10, $youtube_id, 'video-chapters-manager');
        }

        wp_send_json_success([
            'message' => empty($chapters) ? 'Chapters cleared successfully' : 'Chapters saved successfully',
            'post_id' => $post_id,
            'video_id' => $internal_id,
            'chapter_count' => count($validated_chapters)
        ]);

    } catch (Exception $e) {
        error_log('Video Chapters Manager Error: ' . $e->getMessage());
        error_log('Error context: ' . print_r([
            'post_id' => $post_id ?? 'not set',
            'youtube_id' => $youtube_id ?? 'not set',
            'wpdb_last_error' => $wpdb->last_error ?? 'no error',
            'wpdb_last_query' => $wpdb->last_query ?? 'no query',
            'php_error' => error_get_last()
        ], true));

        wp_send_json_error([
            'message' => $e->getMessage(),
            'debug' => WP_DEBUG ? [
                'db_error' => $wpdb->last_error,
                'db_query' => $wpdb->last_query,
                'trace' => $e->getTraceAsString()
            ] : null
        ]);
    }
}



}

// Initialize the plugin
new VideoChaptersManager();
