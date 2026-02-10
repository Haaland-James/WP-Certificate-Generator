<?php
/**
 * Certificates Custom Post Type Class
 * 
 * Handles certificate creation and management
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_Certificates {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_lcpp_certificate', array($this, 'save_meta'));
        add_filter('single_template', array($this, 'load_certificate_template'));
        add_filter('manage_lcpp_certificate_posts_columns', array($this, 'add_admin_columns'));
        add_action('manage_lcpp_certificate_posts_custom_column', array($this, 'render_admin_columns'), 10, 2);
    }
    
    /**
     * Register Certificate custom post type
     */
    public function register_post_type() {
        $labels = array(
            'name' => __('Certificates', 'linkedin-certificate-publisher'),
            'singular_name' => __('Certificate', 'linkedin-certificate-publisher'),
            'menu_name' => __('Certificates', 'linkedin-certificate-publisher'),
            'add_new' => __('Add New', 'linkedin-certificate-publisher'),
            'add_new_item' => __('Add New Certificate', 'linkedin-certificate-publisher'),
            'edit_item' => __('Edit Certificate', 'linkedin-certificate-publisher'),
            'new_item' => __('New Certificate', 'linkedin-certificate-publisher'),
            'view_item' => __('View Certificate', 'linkedin-certificate-publisher'),
            'search_items' => __('Search Certificates', 'linkedin-certificate-publisher'),
            'not_found' => __('No certificates found', 'linkedin-certificate-publisher'),
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'certificate', 'with_front' => false),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 25,
            'menu_icon' => 'dashicons-awards',
            'supports' => array('title', 'thumbnail', 'author'),
            'show_in_rest' => true,
        );
        
        register_post_type('lcpp_certificate', $args);
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'lcpp_certificate_details',
            __('Certificate Details', 'linkedin-certificate-publisher'),
            array($this, 'render_details_meta_box'),
            'lcpp_certificate',
            'normal',
            'high'
        );
        
        add_meta_box(
            'lcpp_certificate_share',
            __('LinkedIn Share', 'linkedin-certificate-publisher'),
            array($this, 'render_share_meta_box'),
            'lcpp_certificate',
            'side',
            'default'
        );
    }
    
    /**
     * Render details meta box
     */
    public function render_details_meta_box($post) {
        wp_nonce_field('lcpp_certificate_meta', 'lcpp_certificate_nonce');
        
        $issuer = get_post_meta($post->ID, '_lcpp_issuer', true);
        $issue_date = get_post_meta($post->ID, '_lcpp_issue_date', true);
        $expiry_date = get_post_meta($post->ID, '_lcpp_expiry_date', true);
        $credential_id = get_post_meta($post->ID, '_lcpp_credential_id', true);
        $recipient_user_id = get_post_meta($post->ID, '_lcpp_recipient_user_id', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="lcpp_issuer"><?php _e('Issuing Organization', 'linkedin-certificate-publisher'); ?></label></th>
                <td>
                    <input type="text" id="lcpp_issuer" name="lcpp_issuer" value="<?php echo esc_attr($issuer); ?>" class="regular-text" required>
                </td>
            </tr>
            <tr>
                <th><label for="lcpp_issue_date"><?php _e('Issue Date', 'linkedin-certificate-publisher'); ?></label></th>
                <td>
                    <input type="date" id="lcpp_issue_date" name="lcpp_issue_date" value="<?php echo esc_attr($issue_date); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="lcpp_expiry_date"><?php _e('Expiry Date', 'linkedin-certificate-publisher'); ?></label></th>
                <td>
                    <input type="date" id="lcpp_expiry_date" name="lcpp_expiry_date" value="<?php echo esc_attr($expiry_date); ?>">
                    <p class="description"><?php _e('Leave empty if no expiry', 'linkedin-certificate-publisher'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="lcpp_credential_id"><?php _e('Credential ID', 'linkedin-certificate-publisher'); ?></label></th>
                <td>
                    <input type="text" id="lcpp_credential_id" name="lcpp_credential_id" value="<?php echo esc_attr($credential_id); ?>" class="regular-text">
                    <p class="description"><?php _e('Unique identifier for this credential', 'linkedin-certificate-publisher'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="lcpp_recipient_user_id"><?php _e('Certificate Recipient', 'linkedin-certificate-publisher'); ?></label></th>
                <td>
                    <?php
                    wp_dropdown_users(array(
                        'name' => 'lcpp_recipient_user_id',
                        'id' => 'lcpp_recipient_user_id',
                        'selected' => $recipient_user_id,
                        'show_option_none' => __('Select a user', 'linkedin-certificate-publisher'),
                        'option_none_value' => '',
                    ));
                    ?>
                    <p class="description"><?php _e('The WordPress user who earned this certificate', 'linkedin-certificate-publisher'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Render share meta box
     */
    public function render_share_meta_box($post) {
        if ($post->post_status !== 'publish') {
            echo '<p>' . __('Publish this certificate first to enable LinkedIn sharing.', 'linkedin-certificate-publisher') . '</p>';
            return;
        }
        
        $certificate_url = get_permalink($post->ID);
        ?>
        <p>
            <strong><?php _e('Certificate URL:', 'linkedin-certificate-publisher'); ?></strong><br>
            <input type="text" value="<?php echo esc_url($certificate_url); ?>" class="widefat" readonly onclick="this.select()">
        </p>
        <p>
            <a href="<?php echo esc_url($certificate_url); ?>" class="button" target="_blank">
                <?php _e('View Certificate Page', 'linkedin-certificate-publisher'); ?>
            </a>
        </p>
        <?php
    }
    
    /**
     * Save meta data
     */
    public function save_meta($post_id) {
        // Verify nonce
        if (!isset($_POST['lcpp_certificate_nonce']) || !wp_verify_nonce($_POST['lcpp_certificate_nonce'], 'lcpp_certificate_meta')) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save meta fields
        $fields = array('issuer', 'issue_date', 'expiry_date', 'credential_id', 'recipient_user_id');
        
        foreach ($fields as $field) {
            $key = 'lcpp_' . $field;
            $meta_key = '_lcpp_' . $field;
            
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $meta_key, sanitize_text_field($_POST[$key]));
            }
        }
    }
    
    /**
     * Load custom template for certificate single page
     */
    public function load_certificate_template($template) {
        global $post;
        
        if ($post && $post->post_type === 'lcpp_certificate') {
            $plugin_template = LCPP_PLUGIN_DIR . 'templates/single-certificate.php';
            
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }
        
        return $template;
    }
    
    /**
     * Add admin columns
     */
    public function add_admin_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'title') {
                $new_columns['issuer'] = __('Issuer', 'linkedin-certificate-publisher');
                $new_columns['recipient'] = __('Recipient', 'linkedin-certificate-publisher');
                $new_columns['issue_date'] = __('Issue Date', 'linkedin-certificate-publisher');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render admin columns
     */
    public function render_admin_columns($column, $post_id) {
        switch ($column) {
            case 'issuer':
                echo esc_html(get_post_meta($post_id, '_lcpp_issuer', true));
                break;
            case 'recipient':
                $user_id = get_post_meta($post_id, '_lcpp_recipient_user_id', true);
                if ($user_id) {
                    $user = get_user_by('ID', $user_id);
                    if ($user) {
                        echo esc_html($user->display_name);
                    }
                }
                break;
            case 'issue_date':
                $date = get_post_meta($post_id, '_lcpp_issue_date', true);
                if ($date) {
                    echo esc_html(date_i18n(get_option('date_format'), strtotime($date)));
                }
                break;
        }
    }
    
    /**
     * Get certificates for a user
     */
    public static function get_user_certificates($user_id) {
        return get_posts(array(
            'post_type' => 'lcpp_certificate',
            'post_status' => 'publish',
            'meta_key' => '_lcpp_recipient_user_id',
            'meta_value' => $user_id,
            'numberposts' => -1,
        ));
    }
}
