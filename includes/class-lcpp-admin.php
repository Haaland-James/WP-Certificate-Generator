<?php
/**
 * Admin Settings Class
 * 
 * Handles the plugin settings page and options
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Enqueue admin scripts on settings page
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings page
        if ($hook !== 'settings_page_lcpp-settings') {
            return;
        }
        
        // Enqueue WordPress media library
        wp_enqueue_media();
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('LinkedIn Certificates', 'linkedin-certificate-publisher'),
            __('LinkedIn Certificates', 'linkedin-certificate-publisher'),
            'manage_options',
            'lcpp-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('lcpp_settings_group', 'lcpp_settings', array($this, 'sanitize_settings'));
        
        // Announcement Settings Section
        add_settings_section(
            'lcpp_announcement_section',
            __('Default Announcement', 'linkedin-certificate-publisher'),
            array($this, 'render_announcement_section'),
            'lcpp-settings'
        );
        
        add_settings_field(
            'default_announcement',
            __('Announcement Template', 'linkedin-certificate-publisher'),
            array($this, 'render_announcement_field'),
            'lcpp-settings',
            'lcpp_announcement_section'
        );
        
        // Webhook Settings Section
        add_settings_section(
            'lcpp_webhook_section',
            __('Webhook Settings', 'linkedin-certificate-publisher'),
            array($this, 'render_webhook_section'),
            'lcpp-settings'
        );
        
        add_settings_field(
            'webhook_api_key',
            __('API Key (Optional)', 'linkedin-certificate-publisher'),
            array($this, 'render_webhook_api_key_field'),
            'lcpp-settings',
            'lcpp_webhook_section'
        );
        
        // Elementor Settings Section
        add_settings_section(
            'lcpp_elementor_section',
            __('Elementor Form Integration', 'linkedin-certificate-publisher'),
            array($this, 'render_elementor_section'),
            'lcpp-settings'
        );
        
        add_settings_field(
            'elementor_form_names',
            __('Form Names to Track', 'linkedin-certificate-publisher'),
            array($this, 'render_elementor_form_names_field'),
            'lcpp-settings',
            'lcpp_elementor_section'
        );
        
        // Certificate Design Section
        add_settings_section(
            'lcpp_certificate_section',
            __('Certificate Design', 'linkedin-certificate-publisher'),
            array($this, 'render_certificate_section'),
            'lcpp-settings'
        );
        
        add_settings_field(
            'certificate_template',
            __('Certificate Template', 'linkedin-certificate-publisher'),
            array($this, 'render_certificate_template_field'),
            'lcpp-settings',
            'lcpp_certificate_section'
        );
        
        add_settings_field(
            'custom_font_file',
            __('Custom Font File (.ttf)', 'linkedin-certificate-publisher'),
            array($this, 'render_custom_font_field'),
            'lcpp-settings',
            'lcpp_certificate_section'
        );
        
        add_settings_field(
            'name_position_x',
            __('Name Position X (%)', 'linkedin-certificate-publisher'),
            array($this, 'render_name_position_x_field'),
            'lcpp-settings',
            'lcpp_certificate_section'
        );
        
        add_settings_field(
            'name_position_y',
            __('Name Position Y (%)', 'linkedin-certificate-publisher'),
            array($this, 'render_name_position_y_field'),
            'lcpp-settings',
            'lcpp_certificate_section'
        );
        
        add_settings_field(
            'name_font_size',
            __('Font Size (pt)', 'linkedin-certificate-publisher'),
            array($this, 'render_font_size_field'),
            'lcpp-settings',
            'lcpp_certificate_section'
        );
        
        add_settings_field(
            'name_font_color',
            __('Font Color', 'linkedin-certificate-publisher'),
            array($this, 'render_font_color_field'),
            'lcpp-settings',
            'lcpp_certificate_section'
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        $sanitized['default_announcement'] = sanitize_textarea_field($input['default_announcement'] ?? '');
        $sanitized['webhook_api_key'] = sanitize_text_field($input['webhook_api_key'] ?? '');
        $sanitized['elementor_form_names'] = sanitize_text_field($input['elementor_form_names'] ?? '');
        
        // Certificate design settings
        $sanitized['certificate_template'] = esc_url_raw($input['certificate_template'] ?? '');
        $sanitized['custom_font_file'] = esc_url_raw($input['custom_font_file'] ?? '');
        $sanitized['name_position_x'] = max(0, min(100, intval($input['name_position_x'] ?? 50)));
        $sanitized['name_position_y'] = max(0, min(100, intval($input['name_position_y'] ?? 40)));
        $sanitized['name_font_size'] = max(12, min(500, intval($input['name_font_size'] ?? 108)));
        $sanitized['name_font_color'] = sanitize_hex_color($input['name_font_color'] ?? '#1a1a1a');
        
        return $sanitized;
    }
    
    /**
     * Render API section description
     */

    
    /**
     * Render announcement section description
     */
    public function render_announcement_section() {
        echo '<p>' . __('Customize the default announcement text. Available placeholders:', 'linkedin-certificate-publisher') . '</p>';
        echo '<ul>';
        echo '<li><code>{certificate_name}</code> - ' . __('Name of the certificate', 'linkedin-certificate-publisher') . '</li>';
        echo '<li><code>{issuer}</code> - ' . __('Issuing organization', 'linkedin-certificate-publisher') . '</li>';
        echo '<li><code>{credential_url}</code> - ' . __('URL to the certificate page', 'linkedin-certificate-publisher') . '</li>';
        echo '<li><code>{issue_date}</code> - ' . __('Date the certificate was issued', 'linkedin-certificate-publisher') . '</li>';
        echo '</ul>';
    }
    
    /**
     * Render Client ID field
     */

    
    /**
     * Render Announcement field
     */
    public function render_announcement_field() {
        $options = get_option('lcpp_settings');
        $value = $options['default_announcement'] ?? '';
        echo '<textarea name="lcpp_settings[default_announcement]" rows="6" class="large-text">' . esc_textarea($value) . '</textarea>';
    }
    
    /**
     * Render webhook section description
     */
    public function render_webhook_section() {
        echo '<p>' . __('Configure webhook authentication for the attendee registration API.', 'linkedin-certificate-publisher') . '</p>';
        echo '<p><strong>' . __('Endpoint:', 'linkedin-certificate-publisher') . '</strong> <code>' . rest_url('lcpp/v1/register-attendee') . '</code></p>';
    }
    
    /**
     * Render Webhook API Key field
     */
    public function render_webhook_api_key_field() {
        $options = get_option('lcpp_settings');
        $value = $options['webhook_api_key'] ?? '';
        echo '<input type="text" name="lcpp_settings[webhook_api_key]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Leave empty to allow unauthenticated requests. If set, include this key in your n8n request as header: X-API-Key', 'linkedin-certificate-publisher') . '</p>';
    }
    
    /**
     * Render Elementor section description
     */
    public function render_elementor_section() {
        echo '<p>' . __('The plugin automatically detects Elementor form submissions. Forms with names containing keywords like "webinar", "event", "registration", "masterclass", etc. will automatically register attendees.', 'linkedin-certificate-publisher') . '</p>';
        echo '<p>' . __('You can also specify custom form names below to ensure they are tracked.', 'linkedin-certificate-publisher') . '</p>';
    }
    
    /**
     * Render Elementor form names field
     */
    public function render_elementor_form_names_field() {
        $options = get_option('lcpp_settings');
        $value = $options['elementor_form_names'] ?? '';
        echo '<input type="text" name="lcpp_settings[elementor_form_names]" value="' . esc_attr($value) . '" class="large-text" />';
        echo '<p class="description">' . __('Comma-separated list of form name keywords to match. Example: webinar, ai masterclass, event registration', 'linkedin-certificate-publisher') . '</p>';
        echo '<p class="description">' . __('Forms must have fields named "name" (or "full_name") and "email" to register attendees.', 'linkedin-certificate-publisher') . '</p>';
    }
    
    /**
     * Render certificate section description
     */
    public function render_certificate_section() {
        echo '<p>' . __('Customize how the attendee name appears on certificates. Use the preview button to test your settings.', 'linkedin-certificate-publisher') . '</p>';
    }
    
    /**
     * Render certificate template field with media upload
     */
    public function render_certificate_template_field() {
        $options = get_option('lcpp_settings');
        $value = $options['certificate_template'] ?? '';
        ?>
        <div class="lcpp-template-upload">
            <input type="text" 
                   name="lcpp_settings[certificate_template]" 
                   id="lcpp_certificate_template" 
                   value="<?php echo esc_attr($value); ?>" 
                   class="regular-text" />
            <button type="button" class="button" id="lcpp_upload_template">
                <?php _e('Upload Template', 'linkedin-certificate-publisher'); ?>
            </button>
            <?php if ($value): ?>
                <div style="margin-top: 10px;">
                    <img src="<?php echo esc_url($value); ?>" style="max-width: 300px; border: 1px solid #ddd;" />
                </div>
            <?php endif; ?>
            <p class="description"><?php _e('Upload a PNG certificate template. If empty, uses the default template from the plugin.', 'linkedin-certificate-publisher'); ?></p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#lcpp_upload_template').on('click', function(e) {
                e.preventDefault();
                var frame = wp.media({
                    title: '<?php _e('Select Certificate Template', 'linkedin-certificate-publisher'); ?>',
                    button: { text: '<?php _e('Use Template', 'linkedin-certificate-publisher'); ?>' },
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#lcpp_certificate_template').val(attachment.url);
                    location.reload(); // Refresh to show preview
                });
                frame.open();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render custom font field
     */
    public function render_custom_font_field() {
        $options = get_option('lcpp_settings');
        $value = $options['custom_font_file'] ?? '';
        ?>
        <div class="lcpp-font-upload">
            <input type="text" 
                   name="lcpp_settings[custom_font_file]" 
                   id="lcpp_custom_font_file" 
                   value="<?php echo esc_attr($value); ?>" 
                   class="regular-text" />
            <button type="button" class="button" id="lcpp_upload_font">
                <?php _e('Upload Font', 'linkedin-certificate-publisher'); ?>
            </button>
            <p class="description"><?php _e('Upload a .ttf font file. If empty, tries to use Montserrat Bold.', 'linkedin-certificate-publisher'); ?></p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#lcpp_upload_font').on('click', function(e) {
                e.preventDefault();
                var frame = wp.media({
                    title: '<?php _e('Select Font File', 'linkedin-certificate-publisher'); ?>',
                    button: { text: '<?php _e('Use Font', 'linkedin-certificate-publisher'); ?>' },
                    multiple: false,
                    // library: { type: 'application/x-font-ttf' } // MIME type filtering can be tricky, leaving open
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#lcpp_custom_font_file').val(attachment.url);
                });
                frame.open();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render name position X field
     */
    public function render_name_position_x_field() {
        $options = get_option('lcpp_settings');
        $value = $options['name_position_x'] ?? 50;
        echo '<input type="range" name="lcpp_settings[name_position_x]" value="' . esc_attr($value) . '" min="0" max="100" step="1" id="lcpp_pos_x" style="width: 300px;" />';
        echo ' <span id="lcpp_pos_x_val">' . esc_html($value) . '%</span>';
        echo '<p class="description">' . __('Horizontal position from left edge (50 = centered)', 'linkedin-certificate-publisher') . '</p>';
        ?>
        <script>
        jQuery('#lcpp_pos_x').on('input', function() {
            jQuery('#lcpp_pos_x_val').text(this.value + '%');
        });
        </script>
        <?php
    }
    
    /**
     * Render name position Y field
     */
    public function render_name_position_y_field() {
        $options = get_option('lcpp_settings');
        $value = $options['name_position_y'] ?? 40;
        echo '<input type="range" name="lcpp_settings[name_position_y]" value="' . esc_attr($value) . '" min="0" max="100" step="1" id="lcpp_pos_y" style="width: 300px;" />';
        echo ' <span id="lcpp_pos_y_val">' . esc_html($value) . '%</span>';
        echo '<p class="description">' . __('Vertical position from top (40 = below "awarded to" text)', 'linkedin-certificate-publisher') . '</p>';
        ?>
        <script>
        jQuery('#lcpp_pos_y').on('input', function() {
            jQuery('#lcpp_pos_y_val').text(this.value + '%');
        });
        </script>
        <?php
    }
    
    /**
     * Render font size field
     */
    public function render_font_size_field() {
        $options = get_option('lcpp_settings');
        $value = $options['name_font_size'] ?? 108;
        echo '<input type="number" name="lcpp_settings[name_font_size]" value="' . esc_attr($value) . '" min="12" max="500" step="1" style="width: 80px;" />';
        echo '<p class="description">' . __('Font size in points. For high-res certificates, try 200-400.', 'linkedin-certificate-publisher') . '</p>';
    }
    
    /**
     * Render font color field
     */
    public function render_font_color_field() {
        $options = get_option('lcpp_settings');
        $value = $options['name_font_color'] ?? '#1a1a1a';
        ?>
        <input type="color" 
               name="lcpp_settings[name_font_color]" 
               value="<?php echo esc_attr($value); ?>" 
               id="lcpp_font_color" />
        <input type="text" 
               id="lcpp_font_color_hex" 
               value="<?php echo esc_attr($value); ?>" 
               style="width: 80px;" 
               readonly />
        <p class="description"><?php _e('Color of the attendee name text', 'linkedin-certificate-publisher'); ?></p>
        <script>
        jQuery('#lcpp_font_color').on('input', function() {
            jQuery('#lcpp_font_color_hex').val(this.value);
        });
        </script>
        
        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #0a66c2;">
            <h4 style="margin-top: 0;"><?php _e('Preview Certificate', 'linkedin-certificate-publisher'); ?></h4>
            <p><?php _e('Save settings first, then click to generate a preview with a sample name:', 'linkedin-certificate-publisher'); ?></p>
            <button type="button" class="button button-primary" id="lcpp_preview_cert">
                <?php _e('Generate Preview', 'linkedin-certificate-publisher'); ?>
            </button>
            <div id="lcpp_preview_result" style="margin-top: 15px;"></div>
        </div>
        
        <script>
        jQuery('#lcpp_preview_cert').on('click', function() {
            var $btn = jQuery(this);
            var $result = jQuery('#lcpp_preview_result');
            $btn.prop('disabled', true).text('<?php _e('Generating...', 'linkedin-certificate-publisher'); ?>');
            
            jQuery.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'lcpp_preview_certificate',
                    nonce: '<?php echo wp_create_nonce('lcpp_preview_nonce'); ?>',
                    name: 'John Doe Sample'
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<img src="' + response.data.url + '?t=' + Date.now() + '" style="max-width: 100%; border: 1px solid #ddd;" />');
                    } else {
                        $result.html('<p style="color: red;">' + (response.data.message || 'Preview failed') + '</p>');
                    }
                    $btn.prop('disabled', false).text('<?php _e('Generate Preview', 'linkedin-certificate-publisher'); ?>');
                },
                error: function() {
                    $result.html('<p style="color: red;"><?php _e('Network error', 'linkedin-certificate-publisher'); ?></p>');
                    $btn.prop('disabled', false).text('<?php _e('Generate Preview', 'linkedin-certificate-publisher'); ?>');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('lcpp_settings'); ?>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('lcpp_settings_group');
                do_settings_sections('lcpp-settings');
                submit_button(__('Save Settings', 'linkedin-certificate-publisher'));
                ?>
            </form>
            
            <hr>
            

        </div>
        <?php
    }
    
    /**
     * Get settings
     */
    public static function get_settings() {
        return get_option('lcpp_settings', array());
    }
}
