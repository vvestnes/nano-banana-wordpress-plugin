<?php
/**
 * Plugin Name: Nano Banana Image Editor
 * Description: Edit images in WordPress articles using Google's Gemini 2.5 Flash Image AI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Version: 1.0
 * Author: Vidar Vestnes
 */


// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class NanoBananaImageEditor {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_nano_banana_edit_image', array($this, 'ajax_edit_image'));
        add_action('wp_ajax_nano_banana_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_nano_banana_get_image_id_from_url', array($this, 'ajax_get_image_id_from_url'));
        add_action('wp_ajax_nano_banana_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_nano_banana_test_ajax', array($this, 'ajax_test_simple'));
        add_action('wp_ajax_nano_banana_replace_image', array($this, 'ajax_replace_image'));
        add_action('wp_ajax_nano_banana_discard_image', array($this, 'ajax_discard_image'));
        add_action('wp_ajax_nano_banana_get_image_url', array($this, 'ajax_get_image_url'));
        add_action('wp_ajax_nano_banana_get_image_url_ajax', array($this, 'ajax_get_image_url_ajax'));
        
        // Add custom button to media library
        add_filter('attachment_fields_to_edit', array($this, 'add_nano_banana_field'), 10, 2);
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Nano Banana Settings',
            'Nano Banana',
            'manage_options',
            'nano-banana-settings',
            array($this, 'settings_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        // Only load on post editor and media pages
        if (!in_array($hook, ['post.php', 'post-new.php', 'upload.php'])) {
            return;
        }
        
        wp_enqueue_script(
            'nano-banana-editor',
            plugin_dir_url(__FILE__) . 'nano-banana-editor.js',
            array('jquery'),
            '1.0',
            true
        );
        
        wp_enqueue_style(
            'nano-banana-editor',
            plugin_dir_url(__FILE__) . 'nano-banana-editor.css',
            array(),
            '1.0'
        );
        
        // Pass data to JavaScript
        wp_localize_script('nano-banana-editor', 'nanoBanana', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nano_banana_nonce'),
            'apiKey' => get_option('nano_banana_api_key', ''),
            'siteUrl' => get_site_url()
        ));
    }
    
    public function ajax_edit_image() {
        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
                error_log('Nonce verification failed');
                wp_send_json_error('Security check failed');
                return;
            }
            
            // Check permissions
            if (!current_user_can('upload_files')) {
                error_log('User lacks upload_files capability');
                wp_send_json_error('Insufficient permissions');
                return;
            }
            
            $image_id = intval($_POST['image_id']);
            $edit_prompt = sanitize_text_field($_POST['edit_prompt']);
            $api_key = get_option('nano_banana_api_key');
            
            error_log('AJAX request received - Image ID: ' . $image_id . ', Prompt: ' . $edit_prompt);
            
            if (!$api_key) {
                error_log('No API key configured');
                wp_send_json_error('API key not configured');
                return;
            }
            
            // Get original image
            $image_path = get_attached_file($image_id);
            $image_url = wp_get_attachment_url($image_id);
            
            if (!$image_path || !file_exists($image_path)) {
                error_log('Image not found - Path: ' . $image_path);
                wp_send_json_error('Image not found');
                return;
            }
            
            // Check if image format is supported
            $mime_type = wp_get_image_mime($image_path);
            $supported_formats = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
            
            if (!in_array($mime_type, $supported_formats)) {
                error_log('Unsupported format: ' . $mime_type);
                wp_send_json_error('Unsupported image format: ' . $mime_type);
                return;
            }
            
            // Check file size (API has limits)
            $file_size = filesize($image_path);
            if ($file_size > 4 * 1024 * 1024) { // 4MB limit
                error_log('File too large: ' . $file_size . ' bytes');
                wp_send_json_error('Image too large. Maximum size is 4MB.');
                return;
            }
            
            error_log('Starting API call...');
            
            // Call Gemini API
            $result = $this->call_gemini_api($image_path, $edit_prompt, $api_key);
            
            if (is_wp_error($result)) {
                error_log('API call failed: ' . $result->get_error_message());
                wp_send_json_error('API Error: ' . $result->get_error_message());
                return;
            }
            
            error_log('API call successful, saving image...');
            
            // Save new image to media library
            $new_image_id = $this->save_edited_image($result['image_data'], $image_id, $edit_prompt);
            
            if (is_wp_error($new_image_id)) {
                error_log('Save failed: ' . $new_image_id->get_error_message());
                wp_send_json_error('Save Error: ' . $new_image_id->get_error_message());
                return;
            }
            
            $new_image_url = wp_get_attachment_url($new_image_id);
            
            if (!$new_image_url) {
                error_log('Could not get URL for new image ID: ' . $new_image_id);
                wp_send_json_error('Could not generate URL for new image');
                return;
            }
            
            error_log('Image edit completed successfully. New image ID: ' . $new_image_id . ', URL: ' . $new_image_url);
            
            // Make sure we send a clean JSON response
            wp_send_json_success(array(
                'new_image_id' => intval($new_image_id),
                'new_image_url' => esc_url($new_image_url),
                'message' => 'Image edited successfully'
            ));
            
        } catch (Exception $e) {
            error_log('Exception in ajax_edit_image: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            wp_send_json_error('Server error: ' . $e->getMessage());
        } catch (Error $e) {
            error_log('Fatal error in ajax_edit_image: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            wp_send_json_error('Fatal server error: ' . $e->getMessage());
        }
    }
    
    private function call_gemini_api($image_path, $prompt, $api_key) {
        // Read image file
        $image_data = file_get_contents($image_path);
        if ($image_data === false) {
            return new WP_Error('file_error', 'Could not read image file: ' . $image_path);
        }
        
        $image_base64 = base64_encode($image_data);
        
        // Determine mime type
        $mime_type = wp_get_image_mime($image_path);
        if (!$mime_type) {
            return new WP_Error('mime_error', 'Could not determine MIME type for image');
        }
        
        // Validate image format
        $supported_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        if (!in_array($mime_type, $supported_types)) {
            return new WP_Error('format_error', 'Unsupported image format: ' . $mime_type);
        }
        
        // Log request details
        error_log('Nano Banana API Request - Image size: ' . strlen($image_data) . ' bytes, MIME: ' . $mime_type);
        error_log('Nano Banana API Request - Prompt: ' . $prompt);
        
        // Use the correct API endpoint from the documentation
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent';
        
        // Prepare request body according to the official documentation
        $request_data = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $prompt
                        ),
                        array(
                            'inline_data' => array(
                                'mime_type' => $mime_type,
                                'data' => $image_base64
                            )
                        )
                    )
                )
            )
        );
        
        $body = json_encode($request_data);
        
        // Log request structure (without the base64 data)
        $log_data = $request_data;
        $log_data['contents'][0]['parts'][1]['inline_data']['data'] = '[BASE64_DATA_' . strlen($image_base64) . '_CHARS]';
        error_log('Nano Banana API Request Structure: ' . json_encode($log_data, JSON_PRETTY_PRINT));
        
        // Make API request with proper headers matching the documentation
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-goog-api-key' => $api_key
            ),
            'body' => $body,
            'timeout' => 60,
            'data_format' => 'body'
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        
        // Log the response for debugging
        error_log('Gemini API Response Code: ' . $response_code);
        error_log('Gemini API Response Body: ' . substr($response_body, 0, 500) . '...'); // Log first 500 chars
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', 'API request failed (HTTP ' . $response_code . '): ' . $response_body);
        }
        
        $data = json_decode($response_body, true);
        
        // Check for JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('api_error', 'Invalid JSON response: ' . json_last_error_msg());
        }
        
        // Log the decoded response structure
        error_log('Gemini API Decoded Response: ' . json_encode($data, JSON_PRETTY_PRINT));
        
        // Check for API errors in response
        if (isset($data['error'])) {
            return new WP_Error('api_error', 'API Error: ' . $data['error']['message']);
        }
        
        // Check if we have candidates
        if (!isset($data['candidates']) || empty($data['candidates'])) {
            return new WP_Error('api_error', 'No candidates in API response. Full response: ' . json_encode($data));
        }
        
        // Check if candidates have content
        if (!isset($data['candidates'][0]['content'])) {
            return new WP_Error('api_error', 'No content in first candidate. Candidate: ' . json_encode($data['candidates'][0]));
        }
        
        // Check if content has parts
        if (!isset($data['candidates'][0]['content']['parts'])) {
            return new WP_Error('api_error', 'No content parts in API response. Content: ' . json_encode($data['candidates'][0]['content']));
        }
        
        // Find the image data in the response parts
        foreach ($data['candidates'][0]['content']['parts'] as $index => $part) {
            error_log('Checking part ' . $index . ': ' . json_encode(array_keys($part)));
            
            // Check for snake_case format (inline_data)
            if (isset($part['inline_data']['data'])) {
                error_log('Found image data in part ' . $index . ' (snake_case)');
                return array(
                    'image_data' => $part['inline_data']['data']
                );
            }
            
            // Check for camelCase format (inlineData) - this is what the API actually returns
            if (isset($part['inlineData']['data'])) {
                error_log('Found image data in part ' . $index . ' (camelCase)');
                return array(
                    'image_data' => $part['inlineData']['data']
                );
            }
        }
        
        return new WP_Error('api_error', 'No image data found in any response parts. Parts structure: ' . json_encode($data['candidates'][0]['content']['parts']));
    }
    
function getLastPartWithTimestamp($filename) {
    $lastUnderscorePos = strrpos($filename, '_');

    $timestamp =  current_time('timestamp');

    if ($lastUnderscorePos !== false) {
        // Replace everything after the last underscore with the new timestamp
        return substr($filename, 0, $lastUnderscorePos + 1) . $timestamp;
    } else {
        // If no underscore found, just append the timestamp
        return $filename . '_' . $timestamp;
    }
}

    private function save_edited_image($image_base64, $original_id, $prompt) {
        try {
            // Get original image info
            $original_file = get_attached_file($original_id);
            if (!$original_file) {
                return new WP_Error('original_error', 'Could not get original file path for ID: ' . $original_id);
            }
            
            $original_info = pathinfo($original_file);
            
            // Create new filename
            $timestamp = current_time('timestamp');
            //$new_filename = $original_info['filename'] .  . '_' . $timestamp . '.png';
            $new_filename = $this->getLastPartWithTimestamp($original_info['filename']). '.png';
            
            // Get upload directory
            $upload_dir = wp_upload_dir();
            if ($upload_dir['error']) {
                return new WP_Error('upload_error', 'Upload directory error: ' . $upload_dir['error']);
            }
            
            $new_file_path = $upload_dir['path'] . '/' . $new_filename;
            $new_file_url = $upload_dir['url'] . '/' . $new_filename;
            
            // Decode and save image
            $image_data = base64_decode($image_base64);
            if ($image_data === false) {
                return new WP_Error('decode_error', 'Failed to decode base64 image data');
            }
            
            error_log('Saving edited image to: ' . $new_file_path . ' (size: ' . strlen($image_data) . ' bytes)');
            
            if (file_put_contents($new_file_path, $image_data) === false) {
                return new WP_Error('save_error', 'Failed to save edited image to: ' . $new_file_path);
            }
            
            // Verify file was saved
            if (!file_exists($new_file_path)) {
                return new WP_Error('verify_error', 'File was not created at: ' . $new_file_path);
            }
            
            // Add to media library
            $attachment = array(
                'guid' => $new_file_url,
                'post_mime_type' => 'image/png',
                'post_title' => sanitize_file_name($original_info['filename']),
                'post_content' => '',
                'post_status' => 'inherit',
                'post_excerpt' => 'Edited with Nano Banana AI. Prompt: ' . $prompt
            );
            
            $attachment_id = wp_insert_attachment($attachment, $new_file_path);
            
            if (is_wp_error($attachment_id)) {
                // Clean up the file if attachment creation failed
                unlink($new_file_path);
                return $attachment_id;
            }
            
            if (!$attachment_id) {
                unlink($new_file_path);
                return new WP_Error('attachment_error', 'Failed to create attachment in database');
            }
            
            // Generate metadata
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_metadata = wp_generate_attachment_metadata($attachment_id, $new_file_path);
            
            if (is_wp_error($attachment_metadata)) {
                error_log('Metadata generation error: ' . $attachment_metadata->get_error_message());
                // Don't fail the whole process for metadata issues
                $attachment_metadata = array();
            }
            
            wp_update_attachment_metadata($attachment_id, $attachment_metadata);
            
            error_log('Successfully created attachment with ID: ' . $attachment_id);
            
            return $attachment_id;
            
        } catch (Exception $e) {
            error_log('Exception in save_edited_image: ' . $e->getMessage());
            return new WP_Error('exception_error', 'Exception occurred: ' . $e->getMessage());
        }
    }
    
    public function ajax_test_api() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $api_key = get_option('nano_banana_api_key');
        if (!$api_key) {
            wp_send_json_error('No API key configured');
            return;
        }
        
        // Test with a simple text-only request first
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
        
        $body = json_encode(array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => 'Hello, this is a test. Please respond with "API test successful".'
                        )
                    )
                )
            )
        ));
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-goog-api-key' => $api_key
            ),
            'body' => $body,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Network error: ' . $response->get_error_message());
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            wp_send_json_error('API returned HTTP ' . $response_code . ': ' . $response_body);
            return;
        }
        
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON response: ' . json_last_error_msg());
            return;
        }
        
        wp_send_json_success(array(
            'message' => 'API test successful',
            'response_structure' => array_keys($data),
            'has_candidates' => isset($data['candidates']),
            'candidates_count' => isset($data['candidates']) ? count($data['candidates']) : 0
        ));
    }
    
    public function ajax_test_simple() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
            wp_die('Security check failed');
        }
        
        error_log('Simple AJAX test called');
        wp_send_json_success(array(
            'message' => 'Simple AJAX test successful',
            'timestamp' => time()
        ));
    }
    
    public function ajax_replace_image() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $original_id = intval($_POST['original_id']);
        $new_id = intval($_POST['new_id']);
        
        error_log('Replacing image references: Original ID ' . $original_id . ' with New ID ' . $new_id);
        
        try {
            // Step 1: Update all post content that references the original image
            $this->update_posts_with_new_image($original_id, $new_id);
            
            // Step 2: Update any featured image references
            $this->update_featured_images($original_id, $new_id);
            
            // Step 3: Update custom fields and meta that might contain the image
            $this->update_custom_fields($original_id, $new_id);
            
            // Step 4: Copy metadata from original to new image (alt text, description, etc.)
            $this->copy_image_metadata($original_id, $new_id);
            
            // Step 5: The original image stays in media library but is now orphaned
            // Users can manually delete it later if they want
            
            error_log('Successfully updated all references from image ' . $original_id . ' to ' . $new_id);
            
            wp_send_json_success(array(
                'message' => 'Image references updated successfully',
                'original_id' => $original_id,
                'new_id' => $new_id,
                'new_url' => wp_get_attachment_url($new_id)
            ));
            
        } catch (Exception $e) {
            error_log('Exception in ajax_replace_image: ' . $e->getMessage());
            wp_send_json_error('Exception: ' . $e->getMessage());
        }
    }
    
    private function update_posts_with_new_image($original_id, $new_id) {
        global $wpdb;
        
        $original_url = wp_get_attachment_url($original_id);
        $new_url = wp_get_attachment_url($new_id);
        
        if (!$original_url || !$new_url) {
            error_log('Could not get URLs for images: ' . $original_id . ' -> ' . $new_id);
            return;
        }
        
        // Get URL variations (different sizes) for comprehensive replacement
        $original_metadata = wp_get_attachment_metadata($original_id);
        $new_metadata = wp_get_attachment_metadata($new_id);
        
        // Find all posts containing the original image
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} 
             WHERE post_content LIKE %s 
             OR post_content LIKE %s 
             OR post_content LIKE %s",
            '%' . $wpdb->esc_like($original_url) . '%',
            '%wp-image-' . $original_id . '%',
            '%"id":' . $original_id . '%'
        ));
        
        error_log('Found ' . count($posts) . ' posts to update with image references');
        
        foreach ($posts as $post) {
            $updated_content = $post->post_content;
            $changes_made = false;
            
            // 1. Replace direct URL references
            if (strpos($updated_content, $original_url) !== false) {
                $updated_content = str_replace($original_url, $new_url, $updated_content);
                $changes_made = true;
                error_log('Replaced direct URL in post ' . $post->ID);
            }
            
            // 2. Replace wp-image-XXX classes
            $pattern = '/\bwp-image-' . $original_id . '\b/';
            if (preg_match($pattern, $updated_content)) {
                $updated_content = preg_replace($pattern, 'wp-image-' . $new_id, $updated_content);
                $changes_made = true;
                error_log('Replaced wp-image class in post ' . $post->ID);
            }
            
            // 3. Replace data-id attributes
            $data_id_patterns = [
                '/data-id="' . $original_id . '"/',
                '/data-id=\'' . $original_id . '\'/',
                '/data-id=' . $original_id . '\b/'
            ];
            foreach ($data_id_patterns as $pattern) {
                if (preg_match($pattern, $updated_content)) {
                    $updated_content = preg_replace($pattern, 'data-id="' . $new_id . '"', $updated_content);
                    $changes_made = true;
                }
            }
            
            // 4. Replace shortcode IDs [gallery id="123"] [caption id="attachment_123"]
            $shortcode_patterns = [
                '/\[([^\]]*?)id="' . $original_id . '"([^\]]*?)\]/',
                '/\[([^\]]*?)id=\'' . $original_id . '\'([^\]]*?)\]/',
                '/\[([^\]]*?)id=' . $original_id . '\b([^\]]*?)\]/',
                '/\[([^\]]*?)attachment_' . $original_id . '\b([^\]]*?)\]/'
            ];
            foreach ($shortcode_patterns as $pattern) {
                if (preg_match($pattern, $updated_content)) {
                    $updated_content = preg_replace($pattern, '[$1id="' . $new_id . '"$2]', $updated_content);
                    $changes_made = true;
                }
            }
            
            // 5. Replace Gutenberg block references
            $gutenberg_patterns = [
                '/"id":' . $original_id . '\b/',
                '/"id":"' . $original_id . '"/',
                '/"mediaId":' . $original_id . '\b/',
                '/"attachmentId":' . $original_id . '\b/'
            ];
            foreach ($gutenberg_patterns as $pattern) {
                if (preg_match($pattern, $updated_content)) {
                    $replacement = str_replace($original_id, $new_id, $pattern);
                    $replacement = str_replace('/', '', $replacement); // Remove regex delimiters
                    $updated_content = preg_replace($pattern, '"id":' . $new_id, $updated_content);
                    $changes_made = true;
                }
            }
            
            // 6. Replace thumbnail and resized image URLs
            if ($original_metadata && isset($original_metadata['sizes']) && $new_metadata && isset($new_metadata['sizes'])) {
                $original_dir = dirname($original_url);
                $new_dir = dirname($new_url);
                
                foreach ($original_metadata['sizes'] as $size => $data) {
                    if (isset($new_metadata['sizes'][$size])) {
                        $old_thumb_url = $original_dir . '/' . $data['file'];
                        $new_thumb_url = $new_dir . '/' . $new_metadata['sizes'][$size]['file'];
                        
                        if (strpos($updated_content, $old_thumb_url) !== false) {
                            $updated_content = str_replace($old_thumb_url, $new_thumb_url, $updated_content);
                            $changes_made = true;
                            error_log('Replaced thumbnail URL in post ' . $post->ID);
                        }
                    }
                }
            }
            
            // 7. Update srcset attributes for responsive images
            $srcset_pattern = '/srcset="([^"]*' . preg_quote(basename($original_url), '/') . '[^"]*)"/';
            if (preg_match($srcset_pattern, $updated_content)) {
                // This is complex - for now, regenerate the srcset
                $updated_content = preg_replace($srcset_pattern, '', $updated_content);
                $changes_made = true;
            }
            
            // Only update if changes were made
            if ($changes_made) {
                $result = $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $updated_content),
                    array('ID' => $post->ID),
                    array('%s'),
                    array('%d')
                );
                
                if ($result === false) {
                    error_log('Failed to update post ' . $post->ID);
                } else {
                    error_log('Successfully updated post ' . $post->ID . ' content');
                    // Clear post cache
                    clean_post_cache($post->ID);
                }
            }
        }
        
        // Also update any widgets or theme options that might contain the image
        $this->update_widgets_and_options($original_id, $new_id, $original_url, $new_url);
    }
    
    private function update_widgets_and_options($original_id, $new_id, $original_url, $new_url) {
        global $wpdb;
        
        // Update widgets
        $widget_options = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_name LIKE 'widget_%' 
             AND (option_value LIKE %s OR option_value LIKE %s)",
            '%' . $wpdb->esc_like('"' . $original_id . '"') . '%',
            '%' . $wpdb->esc_like($original_url) . '%'
        ));
        
        foreach ($widget_options as $option) {
            $updated_value = $option->option_value;
            $updated_value = str_replace('"' . $original_id . '"', '"' . $new_id . '"', $updated_value);
            $updated_value = str_replace($original_url, $new_url, $updated_value);
            
            if ($updated_value !== $option->option_value) {
                update_option($option->option_name, $updated_value);
                error_log('Updated widget option: ' . $option->option_name);
            }
        }
    }
    
    private function update_featured_images($original_id, $new_id) {
        global $wpdb;
        
        // Update featured images (post thumbnails)
        $wpdb->update(
            $wpdb->postmeta,
            array('meta_value' => $new_id),
            array(
                'meta_key' => '_thumbnail_id',
                'meta_value' => $original_id
            )
        );
        
        error_log('Updated featured image references');
    }
    
    private function update_custom_fields($original_id, $new_id) {
        global $wpdb;
        
        // Find custom fields that might contain the image ID
        $meta_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value 
             FROM {$wpdb->postmeta} 
             WHERE meta_value LIKE %s OR meta_value = %s",
            '%' . $wpdb->esc_like('"' . $original_id . '"') . '%',
            $original_id
        ));
        
        foreach ($meta_entries as $meta) {
            $updated_value = $meta->meta_value;
            $original_value = $updated_value;
            
            // Replace direct ID references
            if ($updated_value == $original_id) {
                $updated_value = $new_id;
            }
            
            // Replace ID in serialized data or JSON
            $updated_value = str_replace('"' . $original_id . '"', '"' . $new_id . '"', $updated_value);
            $updated_value = str_replace(':' . $original_id . ';', ':' . $new_id . ';', $updated_value);
            $updated_value = str_replace('i:' . $original_id . ';', 'i:' . $new_id . ';', $updated_value);
            
            if ($updated_value !== $original_value) {
                $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => $updated_value),
                    array('meta_id' => $meta->meta_id)
                );
                
                error_log('Updated meta field ' . $meta->meta_key . ' for post ' . $meta->post_id);
            }
        }
        
        // Also check options table for theme settings, widgets, etc.
        $option_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value 
             FROM {$wpdb->options} 
             WHERE option_value LIKE %s",
            '%' . $wpdb->esc_like('"' . $original_id . '"') . '%'
        ));
        
        foreach ($option_entries as $option) {
            $updated_value = str_replace('"' . $original_id . '"', '"' . $new_id . '"', $option->option_value);
            $updated_value = str_replace(':' . $original_id . ';', ':' . $new_id . ';', $updated_value);
            
            if ($updated_value !== $option->option_value) {
                $wpdb->update(
                    $wpdb->options,
                    array('option_value' => $updated_value),
                    array('option_id' => $option->option_id)
                );
                
                error_log('Updated option ' . $option->option_name);
            }
        }
    }
    
    private function copy_image_metadata($original_id, $new_id) {
        // Copy important metadata from original to new image
        $original_post = get_post($original_id);
        $new_post = get_post($new_id);
        
        if ($original_post && $new_post) {
            // Update new image with original's metadata
            wp_update_post(array(
                'ID' => $new_id,
                'post_title' => $original_post->post_title,
                'post_content' => $original_post->post_content, // Description
                'post_excerpt' => $original_post->post_excerpt   // Caption
            ));
            
            // Copy alt text
            $alt_text = get_post_meta($original_id, '_wp_attachment_image_alt', true);
            if ($alt_text) {
                update_post_meta($new_id, '_wp_attachment_image_alt', $alt_text);
            }
            
            // Copy custom fields
            $custom_fields = get_post_meta($original_id);
            foreach ($custom_fields as $key => $values) {
                if (strpos($key, '_wp_') !== 0 || $key === '_wp_attachment_image_alt') {
                    // Copy non-WordPress internal fields and alt text
                    foreach ($values as $value) {
                        add_post_meta($new_id, $key, $value);
                    }
                }
            }
            
            error_log('Copied metadata from image ' . $original_id . ' to ' . $new_id);
        }
    }
    
    public function ajax_discard_image() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('delete_posts')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $image_id = intval($_POST['image_id']);
        
        error_log('Discarding image ID: ' . $image_id);
        
        // Delete the image and its file
        $result = wp_delete_attachment($image_id, true);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Image discarded successfully'));
        } else {
            wp_send_json_error('Failed to discard image');
        }
    }
    
    public function ajax_get_image_url() {
        $image_id = intval($_GET['id']);
        $image_url = wp_get_attachment_url($image_id);
        
        if ($image_url) {
            // Redirect to the image
            wp_redirect($image_url);
            exit;
        } else {
            wp_die('Image not found');
        }
    }
    
    public function ajax_get_image_url_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
            wp_die('Security check failed');
        }
        
        $image_id = intval($_POST['image_id']);
        $image_url = wp_get_attachment_url($image_id);
        
        if ($image_url) {
            wp_send_json_success(array('url' => $image_url));
        } else {
            wp_send_json_error('Image URL not found');
        }
    }
    
    public function ajax_save_settings() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $api_key = sanitize_text_field($_POST['api_key']);
        update_option('nano_banana_api_key', $api_key);
        
        wp_send_json_success('Settings saved successfully');
    }
    
    public function ajax_get_image_id_from_url() {
        if (!wp_verify_nonce($_POST['nonce'], 'nano_banana_nonce')) {
            wp_die('Security check failed');
        }
        
        $image_url = esc_url_raw($_POST['image_url']);
        $image_id = $this->get_image_id_from_url($image_url);
        
        if ($image_id) {
            wp_send_json_success(array('image_id' => $image_id));
        } else {
            wp_send_json_error('Could not find image ID for this URL');
        }
    }
    
    private function get_image_id_from_url($image_url) {
        global $wpdb;
        
        // Remove query parameters
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
        
        // Try searching in post meta
        $filename = basename($image_url);
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wp_attached_file' 
             AND meta_value LIKE %s",
            '%' . $filename
        ));
        
        return $attachment_id;
    }
    
    public function add_nano_banana_field($form_fields, $post) {
        if (strpos($post->post_mime_type, 'image') !== false) {
            $form_fields['nano_banana'] = array(
                'label' => 'Edit with Nano Banana',
                'input' => 'html',
                'html' => '<div class="nano-banana-field">' .
                          '<button type="button" class="button nano-banana-edit-btn" data-image-id="' . $post->ID . '">' .
                          '✨ Edit with Nano Banana AI</button>' .
                          '<p class="description">Click to edit this image using AI. Changes will create a new image in your media library.</p>' .
                          '</div>',
                'helps' => 'Use natural language to describe changes like "remove background" or "change shirt color to blue"'
            );
        }
        return $form_fields;
    }
    
    public function settings_page() {
        $api_key = get_option('nano_banana_api_key', '');
        ?>
        <div class="wrap">
            <h1>Nano Banana Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>Welcome to Nano Banana Image Editor!</strong> This plugin uses Google's Gemini 2.5 Flash Image AI to edit images with natural language prompts.</p>
            </div>
            
            <?php if (empty($api_key)): ?>
            <div class="notice notice-warning">
                <p><strong>Setup Required:</strong> Please add your Google AI API key below to start using the plugin.</p>
            </div>
            <?php endif; ?>
            
            <form id="nano-banana-settings-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">Google AI API Key</th>
                        <td>
                            <input type="password" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="AIza..." />
                            <button type="button" id="toggle-api-key" class="button button-secondary">Show</button>
                            <p class="description">
                                Get your API key from <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a><br>
                                The API costs approximately $0.039 (4 cents) per image edit.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button-primary">Save Settings</button>
                    <?php if (!empty($api_key)): ?>
                    <button type="button" id="test-api-key" class="button button-secondary" style="margin-left: 10px;">Test API Connection</button>
                    <?php endif; ?>
                </p>
            </form>
            
            <div id="test-result" style="margin-top: 20px;"></div>
            
            <hr>
            
            <h2>How to Use</h2>
            <ol>
                <li><strong>In Media Library:</strong> Click on any image and look for the "✨ Edit with Nano Banana AI" button</li>
                <li><strong>In Post Editor:</strong> Click on images in your posts to see the edit button</li>
                <li><strong>Describe Changes:</strong> Use natural language like:
                    <ul style="margin-top: 10px;">
                        <li>"Remove the background"</li>
                        <li>"Change the shirt color to blue"</li>
                        <li>"Add sunglasses to the person"</li>
                        <li>"Make it look like a painting"</li>
                    </ul>
                </li>
            </ol>
            
            <h2>Tips for Better Results</h2>
            <ul>
                <li><strong>Be specific:</strong> "Change the red car to a blue bicycle" works better than "change the car"</li>
                <li><strong>Describe the style:</strong> "Make it look like a watercolor painting" or "Add a vintage filter"</li>
                <li><strong>Character consistency:</strong> The AI maintains people's likeness across edits</li>
                <li><strong>Supported formats:</strong> JPEG, PNG, GIF, WebP (max 4MB)</li>
            </ul>
        </div>
        
        <script>
        document.getElementById('toggle-api-key').addEventListener('click', function() {
            const input = document.querySelector('input[name="api_key"]');
            const button = this;
            
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = 'Hide';
            } else {
                input.type = 'password';
                button.textContent = 'Show';
            }
        });
        
        document.getElementById('nano-banana-settings-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const button = this.querySelector('.button-primary');
            const originalText = button.textContent;
            button.textContent = 'Saving...';
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'nano_banana_save_settings');
            formData.append('nonce', '<?php echo wp_create_nonce('nano_banana_nonce'); ?>');
            formData.append('api_key', this.api_key.value);
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Settings saved successfully!');
                    location.reload(); // Refresh to show test button
                } else {
                    alert('Error saving settings: ' + data.data);
                }
            })
            .catch(error => {
                alert('Network error: ' + error.message);
            })
            .finally(() => {
                button.textContent = originalText;
                button.disabled = false;
            });
        });
        
        <?php if (!empty($api_key)): ?>
        document.getElementById('test-api-key').addEventListener('click', function() {
            const button = this;
            const resultDiv = document.getElementById('test-result');
            
            button.textContent = 'Testing...';
            button.disabled = true;
            resultDiv.innerHTML = '<p>Testing API connection...</p>';
            
            const formData = new FormData();
            formData.append('action', 'nano_banana_test_api');
            formData.append('nonce', '<?php echo wp_create_nonce('nano_banana_nonce'); ?>');
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = '<div class="notice notice-success"><p><strong>✓ API Connection successful!</strong> The Gemini API is responding correctly. You can now edit images with Nano Banana.</p></div>';
                } else {
                    resultDiv.innerHTML = '<div class="notice notice-error"><p><strong>✗ API test failed:</strong> ' + data.data + '</p></div>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="notice notice-error"><p><strong>✗ Network error:</strong> ' + error.message + '</p></div>';
            })
            .finally(() => {
                button.textContent = 'Test API Connection';
                button.disabled = false;
            });
        });
        <?php endif; ?>
        </script>
        <?php
    }
}

// Initialize plugin
new NanoBananaImageEditor();
?>
