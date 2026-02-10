<?php
/**
 * Elementor Forms Integration Class
 * 
 * Captures webinar attendee data (name + email) from Elementor forms
 * and stores them in the lcpp_attendees table for later matching.
 * 
 * This does NOT create WordPress users - it only stores attendee records.
 * When a WordPress user views the certificate shortcodes, their email
 * is compared against this attendee list to determine eligibility.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_Elementor_Integration {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook into Elementor Pro form submissions
        add_action('elementor_pro/forms/new_record', array($this, 'handle_elementor_form'), 10, 2);
        
        // Also hook into the older action for compatibility
        add_action('elementor/forms/new_record', array($this, 'handle_elementor_form'), 10, 2);
        
        // Add admin settings for form field mapping
        add_action('admin_init', array($this, 'register_elementor_settings'));
    }
    
    /**
     * Register Elementor-specific settings
     */
    public function register_elementor_settings() {
        register_setting('lcpp_settings_group', 'lcpp_elementor_settings');
        
        add_settings_section(
            'lcpp_elementor_section',
            __('Elementor Form Integration', 'linkedin-certificate-publisher'),
            array($this, 'render_section_description'),
            'lcpp-elementor-settings'
        );
    }
    
    /**
     * Render section description
     */
    public function render_section_description() {
        echo '<p>' . __('Configure which Elementor forms should register attendees and map form fields.', 'linkedin-certificate-publisher') . '</p>';
    }
    
    /**
     * Handle Elementor form submission
     */
    public function handle_elementor_form($record, $handler) {
        // Get form settings
        $form_name = $record->get_form_settings('form_name');
        $form_id = $record->get_form_settings('id');
        
        // Get plugin settings
        $settings = get_option('lcpp_settings', array());
        $target_form_names = $settings['elementor_form_names'] ?? '';
        
        // Check if this form should register attendees
        // Option 1: Check form ID in settings
        // Option 2: Check form name contains keywords
        // Option 3: Check for specific form fields
        
        $should_process = false;
        
        // Check by form name (case-insensitive match)
        if (!empty($target_form_names)) {
            $form_name_patterns = array_map('trim', explode(',', strtolower($target_form_names)));
            $form_name_lower = strtolower($form_name);
            
            foreach ($form_name_patterns as $pattern) {
                if (!empty($pattern) && strpos($form_name_lower, $pattern) !== false) {
                    $should_process = true;
                    break;
                }
            }
        }
        
        // Auto-detect: If form has fields named "name" and "email", process it
        // This is a fallback if no specific forms are configured
        $raw_fields = $record->get('fields');
        $fields = array();
        
        foreach ($raw_fields as $id => $field) {
            $fields[strtolower($field['id'])] = $field['value'];
            $fields[strtolower($id)] = $field['value'];
        }
        
        // Check if form has required fields (name and email)
        $has_email = isset($fields['email']) || isset($fields['user_email']) || isset($fields['your_email']) || isset($fields['attendee_email']);
        $has_name = isset($fields['name']) || isset($fields['full_name']) || isset($fields['your_name']) || isset($fields['attendee_name']) || isset($fields['fullname']);
        
        // If form has email and name fields and we have specific form names configured, 
        // OR if we have no forms configured and it looks like an event registration form
        if (!$should_process) {
            // Check for event-related keywords in form name
            $event_keywords = array('webinar', 'event', 'registration', 'masterclass', 'workshop', 'seminar', 'conference', 'training', 'certificate', 'attendee');
            $form_name_lower = strtolower($form_name);
            
            foreach ($event_keywords as $keyword) {
                if (strpos($form_name_lower, $keyword) !== false) {
                    $should_process = true;
                    break;
                }
            }
        }
        
        if (!$should_process || !$has_email || !$has_name) {
            return; // Not a target form or missing required fields
        }
        
        // Extract attendee data
        $email = $this->get_field_value($fields, array('email', 'user_email', 'your_email', 'attendee_email', 'e-mail'));
        $name = $this->get_field_value($fields, array('name', 'full_name', 'your_name', 'attendee_name', 'fullname', 'full name'));
        $event_id = $this->get_field_value($fields, array('event', 'event_id', 'event_name', 'webinar', 'webinar_id'), 'ai-masterclass');
        
        if (empty($email) || empty($name)) {
            return; // Required fields are empty
        }
        
        // Register the attendee
        $attendees = LCPP_Attendees::get_instance();
        $result = $attendees->register_attendee($email, $name, $event_id);
        
        // Log the registration (optional - for debugging)
        if (!is_wp_error($result)) {
            do_action('lcpp_attendee_registered_from_elementor', $result, $email, $name, $event_id, $form_name);
        }
    }
    
    /**
     * Get field value from multiple possible field names
     */
    private function get_field_value($fields, $possible_names, $default = '') {
        foreach ($possible_names as $name) {
            $name_lower = strtolower($name);
            if (isset($fields[$name_lower]) && !empty($fields[$name_lower])) {
                return sanitize_text_field($fields[$name_lower]);
            }
        }
        return $default;
    }
    
    /**
     * Get list of Elementor forms (for admin UI)
     */
    public static function get_elementor_forms() {
        $forms = array();
        
        // Query all Elementor-enabled posts/pages
        $posts = get_posts(array(
            'post_type' => array('page', 'post', 'elementor_library'),
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_elementor_data',
                    'compare' => 'EXISTS',
                ),
            ),
        ));
        
        foreach ($posts as $post) {
            $data = get_post_meta($post->ID, '_elementor_data', true);
            if (!empty($data)) {
                $elements = json_decode($data, true);
                if (is_array($elements)) {
                    $forms = array_merge($forms, self::find_forms_in_elements($elements, $post->ID));
                }
            }
        }
        
        return $forms;
    }
    
    /**
     * Recursively find form widgets in Elementor data
     */
    private static function find_forms_in_elements($elements, $post_id) {
        $forms = array();
        
        foreach ($elements as $element) {
            if (isset($element['widgetType']) && $element['widgetType'] === 'form') {
                $form_name = $element['settings']['form_name'] ?? 'Unnamed Form';
                $form_id = $element['id'] ?? '';
                
                $forms[] = array(
                    'id' => $form_id,
                    'name' => $form_name,
                    'post_id' => $post_id,
                    'post_title' => get_the_title($post_id),
                );
            }
            
            // Check nested elements
            if (isset($element['elements']) && is_array($element['elements'])) {
                $forms = array_merge($forms, self::find_forms_in_elements($element['elements'], $post_id));
            }
        }
        
        return $forms;
    }
}
