<?php
/**
 * Certificate Generator Class
 * 
 * Generates certificate images with dynamic name overlay using PHP GD
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_Generator {
    
    private static $instance = null;
    
    // Certificate template path
    private $template_path;
    
    // Cache directory for generated certificates
    private $cache_dir;
    
    // Name positioning - percentage based (0.40 = 40% from top of image)
    // Adjust this if the name doesn't appear in the right spot on your certificate
    private $name_x_percent = 0.50; // Horizontal position (50 = centered)
    private $name_y_percent = 0.40; // Position below "This Certificate is proudly awarded to"
    private $name_font_size = 108;   // Font size in points
    private $name_color = array(26, 26, 26); // Dark gray #1a1a1a
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Load settings from database
        $options = get_option('lcpp_settings', array());
        
        // Apply custom template if set
        if (!empty($options['certificate_template'])) {
            // Convert URL to local path
            $upload_dir = wp_upload_dir();
            $template_url = $options['certificate_template'];
            if (strpos($template_url, $upload_dir['baseurl']) !== false) {
                $this->template_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $template_url);
            } else {
                $this->template_path = $template_url;
            }
        } else {
            $this->template_path = LCPP_PLUGIN_DIR . 'assets/images/certificate-template.png';
        }
        
        $this->cache_dir = wp_upload_dir()['basedir'] . '/lcpp-certificates/';
        
        // Apply position settings (convert percentage to decimal)
        if (isset($options['name_position_y'])) {
            $this->name_y_percent = intval($options['name_position_y']) / 100;
        }
        if (isset($options['name_position_x'])) {
            $this->name_x_percent = intval($options['name_position_x']) / 100;
        }
        
        // Apply font settings
        if (!empty($options['name_font_size'])) {
            $this->name_font_size = intval($options['name_font_size']);
        }
        if (!empty($options['name_font_color'])) {
            $this->name_color = $this->hex_to_rgb($options['name_font_color']);
        }
        
        // Create cache directory if it doesn't exist
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            
            // Add .htaccess to protect directory but allow image access
            file_put_contents($this->cache_dir . '.htaccess', "Options -Indexes\n");
        }
        
        // Register AJAX handlers
        add_action('wp_ajax_lcpp_download_certificate', array($this, 'ajax_download_certificate'));
        add_action('wp_ajax_lcpp_preview_certificate', array($this, 'ajax_preview_certificate'));
    }
    
    /**
     * Convert hex color to RGB array
     */
    private function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
    }
    
    /**
     * Check if GD extension is available
     */
    public function is_gd_available() {
        return extension_loaded('gd') && function_exists('imagecreatefrompng');
    }
    
    /**
     * Reload settings from database (called before each generation)
     */
    private function reload_settings() {
        $options = get_option('lcpp_settings', array());
        
        // Apply custom template if set
        if (!empty($options['certificate_template'])) {
            $upload_dir = wp_upload_dir();
            $template_url = $options['certificate_template'];
            if (strpos($template_url, $upload_dir['baseurl']) !== false) {
                $this->template_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $template_url);
            } else {
                $this->template_path = $template_url;
            }
        } else {
            $this->template_path = LCPP_PLUGIN_DIR . 'assets/images/certificate-template.png';
        }
        
        // Apply position settings (convert percentage to decimal)
        $this->name_x_percent = isset($options['name_position_x']) ? intval($options['name_position_x']) / 100 : 0.50;
        $this->name_y_percent = isset($options['name_position_y']) ? intval($options['name_position_y']) / 100 : 0.40;
        
        // Apply font settings
        $this->name_font_size = !empty($options['name_font_size']) ? intval($options['name_font_size']) : 108;
        $this->name_color = !empty($options['name_font_color']) ? $this->hex_to_rgb($options['name_font_color']) : array(26, 26, 26);
    }
    
    /**
     * Generate certificate image with name overlay
     */
    public function generate_certificate($attendee_name, $force_regenerate = false) {
        // Always reload settings to get latest values
        $this->reload_settings();
        
        if (!$this->is_gd_available()) {
            return new WP_Error('gd_not_available', 'PHP GD extension is required for certificate generation');
        }
        
        if (!file_exists($this->template_path)) {
            return new WP_Error('template_not_found', 'Certificate template not found. Please upload it to: ' . $this->template_path);
        }
        
        // Create cache filename based on name AND settings hash (so changes in settings regenerate)
        $settings_hash = md5(serialize(array(
            $this->name_x_percent,
            $this->name_y_percent,
            $this->name_font_size,
            $this->name_color,
            $this->template_path
        )));
        $cache_filename = sanitize_file_name(strtolower(str_replace(' ', '-', $attendee_name))) . '-' . md5($attendee_name . $settings_hash) . '.png';
        $cache_path = $this->cache_dir . $cache_filename;
        
        // Return cached version if exists
        if (!$force_regenerate && file_exists($cache_path)) {
            return $cache_path;
        }
        
        // Load template image
        $image = imagecreatefrompng($this->template_path);
        
        if (!$image) {
            return new WP_Error('image_load_failed', 'Failed to load certificate template');
        }
        
        // Enable alpha blending
        imagealphablending($image, true);
        imagesavealpha($image, true);
        
        // Get image dimensions
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Create text color
        $text_color = imagecolorallocate($image, $this->name_color[0], $this->name_color[1], $this->name_color[2]);
        
        // Get font path (use built-in or custom TTF)
        $font_path = $this->get_font_path();
        
        if ($font_path && file_exists($font_path)) {
            // Use TrueType font
            $this->render_text_ttf($image, $attendee_name, $width, $height, $text_color, $font_path);
        } else {
            // Fallback to built-in GD font
            $this->render_text_builtin($image, $attendee_name, $width, $height, $text_color);
        }
        
        // Save generated image
        $result = imagepng($image, $cache_path, 9);
        imagedestroy($image);
        
        if (!$result) {
            return new WP_Error('save_failed', 'Failed to save generated certificate');
        }
        
        return $cache_path;
    }
    
    /**
     * Render text using TrueType font
     */
    private function render_text_ttf($image, $text, $image_width, $image_height, $color, $font_path) {
        // Calculate optimal font size
        $font_size = $this->get_optimal_font_size($image_width);
        
        // Calculate text bounding box
        $bbox = imagettfbbox($font_size, 0, $font_path, $text);
        $text_width = abs($bbox[2] - $bbox[0]);
        $text_height = abs($bbox[7] - $bbox[1]);
        
        // Calculate X position (centered around the position percentage)
        $x = ($image_width * $this->name_x_percent) - ($text_width / 2);
        
        // Calculate Y position based on percentage of image height
        $y = ($image_height * $this->name_y_percent) + ($text_height / 2);
        
        // Render text
        imagettftext($image, $font_size, 0, $x, $y, $color, $font_path, $text);
    }
    
    /**
     * Render text using built-in GD font (fallback)
     */
    private function render_text_builtin($image, $text, $image_width, $image_height, $color) {
        // Use largest built-in font (5)
        $font = 5;
        
        $font_width = imagefontwidth($font);
        $font_height = imagefontheight($font);
        $text_width = strlen($text) * $font_width;
        
        // Calculate X position (centered around the position percentage)
        $x = ($image_width * $this->name_x_percent) - ($text_width / 2);
        
        // Calculate Y position based on percentage of image height
        $y = ($image_height * $this->name_y_percent) - ($font_height / 2);
        
        // For built-in fonts, render directly
        imagestring($image, $font, $x, $y, $text, $color);
    }
    
    /**
     * Get font path (checks for custom font or downloads one automatically)
     */
    private function get_font_path() {
        // 1. Check for user-uploaded custom font (via settings)
        $options = get_option('lcpp_settings', array());
        if (!empty($options['custom_font_file'])) {
            $font_url = $options['custom_font_file'];
            $upload_dir = wp_upload_dir();
            
            // Convert URL to local path if possible
            if (strpos($font_url, $upload_dir['baseurl']) !== false) {
                $custom_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $font_url);
                if (file_exists($custom_path)) {
                    return $custom_path;
                }
            } else {
                // If external URL or absolute path already
                if (file_exists($font_url)) {
                    return $font_url;
                }
            }
        }

        // 2. Check for custom font in plugin directory
        $custom_font = LCPP_PLUGIN_DIR . 'assets/fonts/certificate-font.ttf';
        if (file_exists($custom_font)) {
            return $custom_font;
        }
        
        // 3. Check for Montserrat variants in assets/fonts
        $montserrat_bold = LCPP_PLUGIN_DIR . 'assets/fonts/Montserrat-Bold.ttf';
        if (file_exists($montserrat_bold)) {
            return $montserrat_bold;
        }
        
        $montserrat_semibold = LCPP_PLUGIN_DIR . 'assets/fonts/Montserrat-SemiBold.ttf';
        if (file_exists($montserrat_semibold)) {
            return $montserrat_semibold;
        }
        
        // 4. Check uploads directory for auto-downloaded font
        $upload_dir = wp_upload_dir()['basedir'] . '/lcpp-fonts/';
        $upload_font = $upload_dir . 'Montserrat-Bold.ttf';
        
        if (file_exists($upload_font)) {
            return $upload_font;
        }
        
        // 5. Try to auto-download a font
        return $this->download_font($upload_dir, $upload_font);
    }
    
    /**
     * Calculate optimal font size based on image width
     */
    private function get_optimal_font_size($image_width) {
        // If user set a custom size > 108 (default), respect it
        if ($this->name_font_size > 108) {
            return $this->name_font_size;
        }
        
        // Otherwise, scale relative to image width (approx 5% of width)
        // Reference: 2000px width -> ~100pt font
        return max(40, intval($image_width * 0.05));
    }
    
    /**
     * Download a TTF font for certificate generation
     */
    private function download_font($upload_dir, $target_path) {
        // Create directory if needed
        if (!file_exists($upload_dir)) {
            wp_mkdir_p($upload_dir);
        }
        
        // Google Fonts API URL for Montserrat Bold
        $font_url = 'https://fonts.gstatic.com/s/montserrat/v26/JTUHjIg1_i6t8kCHKm4532VJOt5-QNFgpCuM73w5aXp-p7K4KLg.ttf';
        
        // Try to download
        $response = wp_remote_get($font_url, array(
            'timeout' => 30,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            error_log('LCPP: Failed to download font: ' . $response->get_error_message());
            return null;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            error_log('LCPP: Font download returned HTTP ' . $http_code);
            return null;
        }
        
        $font_data = wp_remote_retrieve_body($response);
        
        if (strlen($font_data) < 10000) {
            error_log('LCPP: Downloaded font file too small, may be invalid');
            return null;
        }
        
        // Save the font
        $result = file_put_contents($target_path, $font_data);
        
        if ($result === false) {
            error_log('LCPP: Failed to save font to ' . $target_path);
            return null;
        }
        
        error_log('LCPP: Successfully downloaded Montserrat font to ' . $target_path);
        return $target_path;
    }
    
    /**
     * Get certificate URL for an attendee
     */
    public function get_certificate_url($attendee_name) {
        $certificate_path = $this->generate_certificate($attendee_name);
        
        if (is_wp_error($certificate_path)) {
            return $certificate_path;
        }
        
        $upload_dir = wp_upload_dir();
        $cache_filename = basename($certificate_path);
        
        return $upload_dir['baseurl'] . '/lcpp-certificates/' . $cache_filename;
    }
    
    /**
     * AJAX handler for downloading certificate
     */
    public function ajax_download_certificate() {
        check_ajax_referer('lcpp_nonce', 'nonce');
        
        // Get current user's email
        $user = wp_get_current_user();
        
        if (!$user->ID) {
            wp_send_json_error(array('message' => 'You must be logged in'));
        }
        
        // Check if user is an attendee
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_email($user->user_email);
        
        if (!$attendee) {
            wp_send_json_error(array('message' => 'You are not registered as an event attendee'));
        }
        
        // Generate certificate
        $certificate_path = $this->generate_certificate($attendee->name);
        
        if (is_wp_error($certificate_path)) {
            wp_send_json_error(array('message' => $certificate_path->get_error_message()));
        }
        
        // Mark as downloaded
        $attendees->mark_downloaded($attendee->id);
        
        // Return download URL
        $certificate_url = $this->get_certificate_url($attendee->name);
        
        wp_send_json_success(array(
            'download_url' => $certificate_url,
            'filename' => 'GoFunditNow-AI-Masterclass-Certificate-' . sanitize_file_name($attendee->name) . '.png',
        ));
    }
    
    /**
     * AJAX handler for previewing certificate (admin)
     */
    public function ajax_preview_certificate() {
        // Check nonce - using the one from admin settings page
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lcpp_preview_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
        }
        
        // Only admins can preview
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Get sample name from request or use default
        $sample_name = sanitize_text_field($_POST['name'] ?? 'John Doe Sample');
        
        // Force regenerate to show latest settings
        $certificate_path = $this->generate_certificate($sample_name, true);
        
        if (is_wp_error($certificate_path)) {
            wp_send_json_error(array('message' => $certificate_path->get_error_message()));
        }
        
        // Get URL
        $upload_dir = wp_upload_dir();
        $cache_filename = basename($certificate_path);
        $certificate_url = $upload_dir['baseurl'] . '/lcpp-certificates/' . $cache_filename;
        
        wp_send_json_success(array(
            'url' => $certificate_url,
            'name' => $sample_name,
        ));
    }
    
    /**
     * Get template info for admin display
     */
    public function get_template_info() {
        if (!file_exists($this->template_path)) {
            return array(
                'exists' => false,
                'path' => $this->template_path,
            );
        }
        
        $size = getimagesize($this->template_path);
        
        return array(
            'exists' => true,
            'path' => $this->template_path,
            'width' => $size[0],
            'height' => $size[1],
            'mime' => $size['mime'],
        );
    }
    
    /**
     * Update name position settings
     */
    public function set_name_position($y_position, $font_size = null) {
        $this->name_y_position = intval($y_position);
        if ($font_size) {
            $this->name_font_size = intval($font_size);
        }
    }
}
