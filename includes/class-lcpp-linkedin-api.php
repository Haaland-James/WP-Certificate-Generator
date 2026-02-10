<?php
/**
 * LinkedIn API Class
 * 
 * Handles communication with LinkedIn API
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCPP_LinkedIn_API {
    
    private static $instance = null;
    
    const API_BASE = 'https://api.linkedin.com/v2';
    const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_lcpp_share_certificate', array($this, 'ajax_share_certificate'));
        add_action('wp_ajax_lcpp_share_attendee_certificate', array($this, 'ajax_share_attendee_certificate'));
    }
    
    /**
     * Get user profile info
     */
    public function get_user_profile() {
        $oauth = LCPP_OAuth::get_instance();
        $access_token = $oauth->get_access_token();
        
        if (empty($access_token)) {
            return new WP_Error('no_token', 'Not authenticated');
        }
        
        $response = wp_remote_get(self::USERINFO_URL, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error_description'] ?? $body['error']);
        }
        
        return $body;
    }
    
    
    /**
     * Create a share/post on LinkedIn with optional image
     */
    public function create_share($text, $url = null, $title = null, $image_path = null) {
        $oauth = LCPP_OAuth::get_instance();
        $access_token = $oauth->get_access_token();
        
        if (empty($access_token)) {
            return new WP_Error('no_token', 'Not authenticated with LinkedIn');
        }
        
        // Get user profile to retrieve member ID
        $profile = $this->get_user_profile();
        
        if (is_wp_error($profile)) {
            return $profile;
        }
        
        $member_id = $profile['sub'] ?? null;
        
        if (empty($member_id)) {
            return new WP_Error('no_member_id', 'Could not retrieve LinkedIn member ID');
        }
        
        // Build the share content
        $share_content = array(
            'author' => 'urn:li:person:' . $member_id,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => array(
                'com.linkedin.ugc.ShareContent' => array(
                    'shareCommentary' => array(
                        'text' => $text,
                    ),
                    'shareMediaCategory' => 'NONE',
                ),
            ),
            'visibility' => array(
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ),
        );
        
        // Add article/link if URL is provided (but no image)
        if (!empty($url) && empty($image_path)) {
            $share_content['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'ARTICLE';
            $share_content['specificContent']['com.linkedin.ugc.ShareContent']['media'] = array(
                array(
                    'status' => 'READY',
                    'originalUrl' => $url,
                    'title' => array(
                        'text' => $title ?? 'View Certificate',
                    ),
                ),
            );
        }
        
        // If image path provided, upload image first
        if (!empty($image_path) && file_exists($image_path)) {
            $image_asset = $this->upload_image_to_linkedin($access_token, $member_id, $image_path);
            
            if (!is_wp_error($image_asset)) {
                $share_content['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] = 'IMAGE';
                $share_content['specificContent']['com.linkedin.ugc.ShareContent']['media'] = array(
                    array(
                        'status' => 'READY',
                        'media' => $image_asset,
                        'title' => array(
                            'text' => $title ?? 'Certificate',
                        ),
                    ),
                );
            }
        }
        
        $response = wp_remote_post(self::API_BASE . '/ugcPosts', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ),
            'body' => json_encode($share_content),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 201) {
            $error_message = $body['message'] ?? 'Failed to create share';
            return new WP_Error('share_failed', $error_message . ' (HTTP ' . $status_code . ')');
        }
        
        return $body;
    }
    
    /**
     * Upload image to LinkedIn (3-step process)
     */
    private function upload_image_to_linkedin($access_token, $member_id, $image_path) {
        // Step 1: Register the image upload
        $register_response = wp_remote_post(self::API_BASE . '/assets?action=registerUpload', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ),
            'body' => json_encode(array(
                'registerUploadRequest' => array(
                    'recipes' => array('urn:li:digitalmediaRecipe:feedshare-image'),
                    'owner' => 'urn:li:person:' . $member_id,
                    'serviceRelationships' => array(
                        array(
                            'relationshipType' => 'OWNER',
                            'identifier' => 'urn:li:userGeneratedContent',
                        ),
                    ),
                ),
            )),
        ));
        
        if (is_wp_error($register_response)) {
            return $register_response;
        }
        
        $register_body = json_decode(wp_remote_retrieve_body($register_response), true);
        
        if (empty($register_body['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'])) {
            return new WP_Error('register_failed', 'Failed to register image upload');
        }
        
        $upload_url = $register_body['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];
        $asset_id = $register_body['value']['asset'];
        
        // Step 2: Upload the actual image
        $image_data = file_get_contents($image_path);
        
        $upload_response = wp_remote_request($upload_url, array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'image/png',
            ),
            'body' => $image_data,
            'timeout' => 60,
        ));
        
        if (is_wp_error($upload_response)) {
            return $upload_response;
        }
        
        $upload_status = wp_remote_retrieve_response_code($upload_response);
        
        if ($upload_status < 200 || $upload_status >= 300) {
            return new WP_Error('upload_failed', 'Failed to upload image (HTTP ' . $upload_status . ')');
        }
        
        // Return the asset URN for use in share
        return $asset_id;
    }
    
    /**
     * Share a certificate
     */
    public function share_certificate($certificate_id) {
        $certificate = get_post($certificate_id);
        
        if (!$certificate || $certificate->post_type !== 'lcpp_certificate') {
            return new WP_Error('invalid_certificate', 'Certificate not found');
        }
        
        // Get certificate meta
        $issuer = get_post_meta($certificate_id, '_lcpp_issuer', true);
        $issue_date = get_post_meta($certificate_id, '_lcpp_issue_date', true);
        
        // Get settings for announcement template
        $settings = LCPP_Admin::get_settings();
        $template = $settings['default_announcement'] ?? '';
        
        // Get certificate URL
        $certificate_url = get_permalink($certificate_id);
        
        // Replace placeholders
        $text = str_replace(
            array('{certificate_name}', '{issuer}', '{credential_url}', '{issue_date}'),
            array($certificate->post_title, $issuer, $certificate_url, $issue_date),
            $template
        );
        
        return $this->create_share($text, $certificate_url, $certificate->post_title);
    }
    
    /**
     * AJAX handler for sharing certificate
     */
    public function ajax_share_certificate() {
        check_ajax_referer('lcpp_nonce', 'nonce');
        
        $certificate_id = intval($_POST['certificate_id'] ?? 0);
        
        if (!$certificate_id) {
            wp_send_json_error(array('message' => 'Invalid certificate ID'));
        }
        
        $result = $this->share_certificate($certificate_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => 'Successfully shared to LinkedIn!',
            'share_id' => $result['id'] ?? null,
        ));
    }
    
    /**
     * AJAX handler for sharing attendee certificate from dashboard
     */
    public function ajax_share_attendee_certificate() {
        check_ajax_referer('lcpp_nonce', 'nonce');
        
        // Get current user
        $user = wp_get_current_user();
        
        if (!$user->ID) {
            wp_send_json_error(array('message' => 'You must be logged in'));
        }
        
        // Get attendee record
        $attendees = LCPP_Attendees::get_instance();
        $attendee = $attendees->get_attendee_by_email($user->user_email);
        
        if (!$attendee) {
            wp_send_json_error(array('message' => 'Attendee record not found'));
        }
        
        // Get certificate path and URL
        $generator = LCPP_Generator::get_instance();
        $certificate_path = $generator->generate_certificate($attendee->name);
        
        if (is_wp_error($certificate_path)) {
            wp_send_json_error(array('message' => $certificate_path->get_error_message()));
        }
        
        // Get the public URL
        $upload_dir = wp_upload_dir();
        $certificate_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $certificate_path);
        
        // Get settings for announcement template
        $settings = LCPP_Admin::get_settings();
        $template = $settings['default_announcement'] ?? '';
        
        // Default template if empty
        if (empty($template)) {
            $template = "🎉 I'm thrilled to share that I've earned my {certificate_name} certificate from {issuer}!\n\nThis achievement represents my commitment to continuous learning and professional development.\n\n#Certification #Achievement #ProfessionalDevelopment";
        }
        
        // Replace placeholders
        $text = str_replace(
            array('{certificate_name}', '{issuer}', '{credential_url}', '{issue_date}'),
            array('AI For Funding Masterclass', 'GoFunditNow', $certificate_url, date('F Y')),
            $template
        );
        
        // Create LinkedIn share WITH the certificate image
        $result = $this->create_share($text, $certificate_url, 'AI Masterclass Certificate - ' . $attendee->name, $certificate_path);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Mark as shared
        $attendees->mark_linkedin_shared($attendee->id);
        
        // Generate Add to Profile URL for Licenses & Certifications section
        $add_to_profile_url = $this->get_add_to_profile_url(
            'AI For Funding Masterclass',           // Certificate name
            'GoFunditNow',                          // Organization name
            $certificate_url,                       // Credential URL
            'cert-' . $attendee->id                 // Unique credential ID
        );
        
        wp_send_json_success(array(
            'message' => 'Successfully shared to LinkedIn!',
            'share_id' => $result['id'] ?? null,
            'add_to_profile_url' => $add_to_profile_url,
        ));
    }
    
    /**
     * Generate LinkedIn "Add to Profile" URL for Licenses & Certifications
     * 
     * @param string $cert_name Certificate/course name
     * @param string $org_name Issuing organization name
     * @param string $cert_url URL to verify the certificate
     * @param string $cert_id Unique credential ID
     * @return string The LinkedIn Add to Profile URL
     */
    public function get_add_to_profile_url($cert_name, $org_name, $cert_url, $cert_id = '') {
        $params = array(
            'startTask' => 'CERTIFICATION_NAME',
            'name' => $cert_name,
            'organizationName' => $org_name,
            'issueYear' => date('Y'),
            'issueMonth' => date('n'),
            'certUrl' => $cert_url,
        );
        
        if (!empty($cert_id)) {
            $params['certId'] = $cert_id;
        }
        
        return 'https://www.linkedin.com/profile/add?' . http_build_query($params);
    }
}
