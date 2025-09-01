<?php
/**
 * Nano Banana Helper Functions
 * Additional utility functions for the Nano Banana plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class NanoBananaHelpers {
    
    public function __construct() {
        // Add AJAX handler for image ID lookup
        add_action('wp_ajax_nano_banana_get_image_id', array($this, 'ajax_get_image_id'));
        
        // Add image ID to editor images via filter
        add_filter('wp_get_attachment_image_attributes', array($this, 'add_image_id_attribute'), 10, 3);
        
        // Add support for getting image ID from URL
        add_action('wp_ajax_nano_banana_url_to_id', array($this, 'ajax_url_to_id'));
    }
    
    /**
     * AJAX handler to get image ID from URL
     */
    public function ajax_get_image_id() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
            wp_die('Security check failed');
        }
        
        $image_url = esc_url_raw($_POST['image_url']);
        $image_id = $this->get_image_id_from_url($image_url);
        
        if ($image_id) {
            wp_send_json_success(array('image_id' => $image_id));
        } else {
            wp_send_json_error('Image ID not found');
        }
    }
    
    /**
     * Get WordPress attachment ID from image URL
     */
    public function get_image_id_from_url($image_url) {
        global $wpdb;
        
        // Remove query parameters and get just the base URL
        $image_url = strtok($image_url, '?');
        
        // Try direct lookup first
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s",
            $image_url
        ));
        
        if ($attachment_id) {
            return $attachment_id;
        }
        
        // Try without size suffix (e.g., remove -150x150 from filename)
        $image_url_without_size = preg_replace('/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $image_url);
        
        if ($image_url_without_size !== $image_url) {
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE guid = %s",
                $image_url_without_size
            ));
            
            if ($attachment_id) {
                return $attachment_id;
            }
        }
        
        // Try searching in post content and meta
        $filename = basename($image_url);
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wp_attached_file' 
             AND meta_value LIKE %s",
            '%' . $filename
        ));
        
        return $attachment_id;
    }
    
    /**
     * AJAX handler to convert URL to image ID
     */
    public function ajax_url_to_id() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
            wp_die('Security check failed');
        }
        
        $url = esc_url_raw($_POST['url']);
        $image_id = $this->get_image_id_from_url($url);
        
        wp_send_json_success(array('image_id' => $image_id));
    }
    
    /**
     * Add data-id attribute to images in content
     */
    public function add_image_id_attribute($attr, $attachment, $size) {
        $attr['data-id'] = $attachment->ID;
        return $attr;
    }
    
    /**
     * Enhanced image replacement in content
     */
    public static function replace_image_in_content($post_id, $old_image_id, $new_image_id) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        $content = $post->post_content;
        $old_url = wp_get_attachment_url($old_image_id);
        $new_url = wp_get_attachment_url($new_image_id);
        
        if (!$old_url || !$new_url) {
            return false;
        }
        
        // Replace URLs
        $content = str_replace($old_url, $new_url, $content);
        
        // Replace image IDs in classes
        $content = preg_replace(
            '/wp-image-' . $old_image_id . '\b/',
            'wp-image-' . $new_image_id,
            $content
        );
        
        // Replace data-id attributes
        $content = preg_replace(
            '/data-id=["\']' . $old_image_id . '["\']/',
            'data-id="' . $new_image_id . '"',
            $content
        );
        
        // Update post
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $content
        ));
        
        return true;
    }
    
    /**
     * Validate API key
     */
    public static function validate_api_key($api_key) {
        if (empty($api_key)) {
            return false;
        }
        
        // Test API call
        $test_url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
        
        $response = wp_remote_get($test_url, array(
            'timeout' => 10
        ));
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * Get supported image formats
     */
    public static function get_supported_formats() {
        return array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
    }
    
    /**
     * Check if image format is supported
     */
    public static function is_supported_format($image_path) {
        $mime_type = wp_get_image_mime($image_path);
        return in_array($mime_type, self::get_supported_formats());
    }
    
    /**
     * Optimize image for API
     */
    public static function optimize_image_for_api($image_path) {
        $max_size = 4 * 1024 * 1024; // 4MB limit for API
        
        if (filesize($image_path) <= $max_size) {
            return $image_path;
        }
        
        // Create optimized version
        $image_editor = wp_get_image_editor($image_path);
        
        if (is_wp_error($image_editor)) {
            return $image_path;
        }
        
        // Resize to reduce file size
        $image_editor->resize(1024, 1024, false);
        
        $optimized_path = $image_path . '.optimized.jpg';
        $saved = $image_editor->save($optimized_path, 'image/jpeg');
        
        if (is_wp_error($saved)) {
            return $image_path;
        }
        
        return $saved['path'];
    }
    
    /**
     * Log plugin activity
     */
    public static function log_activity($action, $details = array()) {
        if (!WP_DEBUG_LOG) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'action' => $action,
            'details' => $details,
            'user_id' => get_current_user_id()
        );
        
        error_log('Nano Banana: ' . json_encode($log_entry));
    }
    
    /**
     * Clean up temporary files
     */
    public static function cleanup_temp_files() {
        $upload_dir = wp_upload_dir();
        $pattern = $upload_dir['path'] . '/*.optimized.*';
        
        foreach (glob($pattern) as $file) {
            if (is_file($file) && (time() - filemtime($file)) > 3600) { // 1 hour old
                unlink($file);
            }
        }
    }
    
    /**
     * Get usage statistics
     */
    public static function get_usage_stats() {
        $stats = get_option('nano_banana_stats', array(
            'total_edits' => 0,
            'successful_edits' => 0,
            'failed_edits' => 0,
            'last_edit' => null
        ));
        
        return $stats;
    }
    
    /**
     * Update usage statistics
     */
    public static function update_usage_stats($success = true) {
        $stats = self::get_usage_stats();
        $stats['total_edits']++;
        
        if ($success) {
            $stats['successful_edits']++;
        } else {
            $stats['failed_edits']++;
        }
        
        $stats['last_edit'] = current_time('mysql');
        
        update_option('nano_banana_stats', $stats);
    }
}

// Initialize helpers
new NanoBananaHelpers();

// Schedule cleanup of temporary files
if (!wp_next_scheduled('nano_banana_cleanup')) {
    wp_schedule_event(time(), 'hourly', 'nano_banana_cleanup');
}

add_action('nano_banana_cleanup', array('NanoBananaHelpers', 'cleanup_temp_files'));

