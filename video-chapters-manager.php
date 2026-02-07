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
        wp_enqueue_script('jquery'); // Enqueue jQuery
        wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css');
        wp_enqueue_script('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js', [], null, true);
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');
        wp_enqueue_script('jquery-ui', 'https://code.jquery.com/ui/1.13.2/jquery-ui.min.js', ['jquery'], null, true);


        // Load Webpack-bundled JavaScript
        wp_enqueue_script(
            'video-chapters-script',
            plugin_dir_url(__FILE__) . 'dist/video-chapters.min.js',
            ['jquery', 'jquery-ui'], // Add jQuery UI as a dependency
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

    // Simpler, more reliable query
    $query = $wpdb->prepare("
        SELECT DISTINCT meta_value 
        FROM {$wpdb->postmeta} 
        WHERE meta_key LIKE %s 
        AND meta_value IS NOT NULL
        AND meta_value != ''
    ", $this->youtube_meta_prefix . '%');

    $results = $wpdb->get_col($query);
    $titles = [];

    // Process JSON data
    foreach ($results as $json_data) {
        $chapters = json_decode($json_data, true);
        if (is_array($chapters)) {
            foreach ($chapters as $chapter) {
                if (isset($chapter['title']) && !empty($chapter['title'])) {
                    $titles[] = $chapter['title'];
                }
            }
        }
    }

    // Remove duplicates and sort
    $titles = array_unique($titles);
    sort($titles);

    // Filter by term if provided
    if (!empty($term)) {
        $term = strtolower($term);
        $titles = array_filter($titles, function($title) use ($term) {
            return str_contains(strtolower($title), $term);
        });
    }

    // Return only first 10 results
    $titles = array_values(array_slice($titles, 0, 10));

    // Add debug information
    if (WP_DEBUG) {
        error_log('Chapter Titles Query: ' . $query);
        error_log('Results: ' . print_r($titles, true));
    }

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
        SELECT p.ID, p.post_title, yt.meta_value as ytid
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} yt ON p.ID = yt.post_id
        WHERE yt.meta_key IN ('youtube', 'youtube_yogi')
        AND yt.meta_value = %s
        LIMIT 1
    ", $youtube_id));

    if ($video_data) {
        $chapters = get_post_meta($video_data->ID, $this->youtube_meta_prefix . $youtube_id, true);
        
        // Ensure proper JSON encoding
        $chapters_array = json_decode($chapters, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Re-encode with proper options to avoid escape issues
            $chapters = json_encode($chapters_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        
        wp_send_json_success([
            'id'       => $video_data->ID,
            'title'    => $video_data->post_title,
            'ytid'     => $youtube_id,
            'chapters' => $chapters ?: '[]',
        ]);
    } else {
        wp_send_json_error('Video not found');
    }
}


    /**
     * Save chapters (AJAX)
     */
public function save_chapters() {
    try {
        global $wpdb;
        $wpdb->show_errors(true);

        // 1. Verify nonce
        if (!check_ajax_referer('video-chapters-nonce', 'nonce', false)) {
            throw new Exception('Security verification failed');
        }

        // 2. Get and validate inputs
        $post_id = filter_input(INPUT_POST, 'video_id', FILTER_VALIDATE_INT);
        $youtube_id = filter_input(INPUT_POST, 'youtube_id', FILTER_SANITIZE_STRING);
        
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

        // 6. Database update with explicit error handling
        $meta_key = $this->youtube_meta_prefix . $youtube_id;
        
        // Delete existing meta first to ensure clean update
        delete_post_meta($post_id, $meta_key);
        
        // Now add the new meta
        $result = add_post_meta($post_id, $meta_key, $encoded_chapters, true);
        
        if ($result === false) {
            // Get the last MySQL error
            $db_error = $wpdb->last_error;
            error_log("Database Error in save_chapters: " . $db_error);
            error_log("Meta Key: " . $meta_key);
            error_log("Post ID: " . $post_id);
            error_log("Chapters Length: " . strlen($encoded_chapters));
            
            throw new Exception('Database update failed: ' . $db_error);
        }

        // Log success
        error_log("Successfully saved chapters for post $post_id with YouTube ID $youtube_id");
        update_post_meta($post_id, '_desc_synced_yt', '0');
        wp_send_json_success([
            'message' => empty($chapters) ? 'Chapters cleared successfully' : 'Chapters saved successfully',
            'post_id' => $post_id,
            'meta_key' => $meta_key,
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
