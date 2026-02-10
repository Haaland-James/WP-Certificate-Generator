/**
 * LinkedIn Certificate Publisher - Frontend JavaScript
 * 
 * Handles OAuth for CPT-based certificate sharing only.
 * Attendee sharing uses direct LinkedIn intent URLs (no JS needed).
 */

(function ($) {
    'use strict';

    // OAuth popup reference
    let oauthPopup = null;

    /**
     * Initialize share buttons (only for CPT certificate share buttons, NOT intent links)
     */
    function initShareButtons() {
        // Only attach to buttons inside .lcpp-share-wrapper (CPT certificates)
        // Do NOT intercept .lcpp-dashboard-share or .lcpp-linkedin-share links
        $(document).on('click', '.lcpp-share-wrapper .lcpp-share-button', handleShareClick);

        // Listen for OAuth callback messages
        window.addEventListener('message', handleOAuthMessage, false);
    }

    /**
     * Handle share button click (CPT certificates only)
     */
    function handleShareClick(e) {
        // Only prevent default for button elements, not <a> links
        if (this.tagName === 'BUTTON') {
            e.preventDefault();
        } else {
            // It's an <a> link (intent URL) — let it open normally
            return;
        }

        const $button = $(this);
        const $wrapper = $button.closest('.lcpp-share-wrapper');
        const certificateId = $wrapper.data('certificate-id');
        const isAuthenticated = $button.data('authenticated') === true || $button.data('authenticated') === 'true';

        if (!certificateId) {
            showStatus($wrapper, 'error', 'Certificate ID not found');
            return;
        }

        if (isAuthenticated) {
            shareToLinkedIn(certificateId, $button, $wrapper);
        } else {
            openOAuthPopup($button.data('auth-url'), certificateId);
        }
    }

    /**
     * Open OAuth popup window
     */
    function openOAuthPopup(authUrl, certificateId) {
        const width = 600;
        const height = 700;
        const left = (window.innerWidth - width) / 2 + window.screenX;
        const top = (window.innerHeight - height) / 2 + window.screenY;

        oauthPopup = window.open(
            authUrl,
            'LinkedInOAuth',
            `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
        );

        sessionStorage.setItem('lcpp_pending_certificate', certificateId);

        if (!oauthPopup || oauthPopup.closed) {
            alert('Please allow popups for this site to connect with LinkedIn.');
        }
    }

    /**
     * Handle OAuth callback message
     */
    function handleOAuthMessage(event) {
        if (event.data && event.data.type === 'lcpp_oauth_result') {
            const { success, certificateId } = event.data;

            if (success) {
                $('.lcpp-share-wrapper .lcpp-share-button').data('authenticated', true);

                const pendingCertificateId = certificateId || sessionStorage.getItem('lcpp_pending_certificate');
                sessionStorage.removeItem('lcpp_pending_certificate');

                if (pendingCertificateId) {
                    const $wrapper = $(`.lcpp-share-wrapper[data-certificate-id="${pendingCertificateId}"]`);
                    const $button = $wrapper.find('.lcpp-share-button');

                    if ($wrapper.length) {
                        setTimeout(function () {
                            shareToLinkedIn(pendingCertificateId, $button, $wrapper);
                        }, 500);
                    }
                }
            } else {
                const $wrapper = $('.lcpp-share-wrapper').first();
                showStatus($wrapper, 'error', 'Failed to connect to LinkedIn.');
            }
        }
    }

    /**
     * Share certificate to LinkedIn via AJAX (CPT certificates only)
     */
    function shareToLinkedIn(certificateId, $button, $wrapper) {
        $button.prop('disabled', true);
        const originalText = $button.find('.lcpp-button-text').text();
        $button.find('.lcpp-button-text').text('Sharing...');

        $.ajax({
            url: lcpp_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'lcpp_share_certificate',
                nonce: lcpp_ajax.nonce,
                certificate_id: certificateId
            },
            success: function (response) {
                if (response.success) {
                    showStatus($wrapper, 'success', response.data.message || 'Successfully shared to LinkedIn!');
                    $button.find('.lcpp-button-text').text('Shared!');

                    if (response.data.add_to_profile_url) {
                        setTimeout(function () {
                            window.open(response.data.add_to_profile_url, '_blank');
                        }, 1000);
                    }

                    setTimeout(function () {
                        $button.find('.lcpp-button-text').text(originalText);
                        $button.prop('disabled', false);
                    }, 3000);
                } else {
                    showStatus($wrapper, 'error', response.data.message || 'Failed to share');
                    $button.find('.lcpp-button-text').text(originalText);
                    $button.prop('disabled', false);
                }
            },
            error: function (xhr, status, error) {
                showStatus($wrapper, 'error', 'Network error. Please try again.');
                $button.find('.lcpp-button-text').text(originalText);
                $button.prop('disabled', false);
            }
        });
    }

    /**
     * Show status message
     */
    function showStatus($wrapper, type, message) {
        const $status = $wrapper.find('.lcpp-share-status');

        $status
            .removeClass('success error')
            .addClass(type)
            .text(message)
            .fadeIn();

        setTimeout(function () {
            $status.fadeOut();
        }, 5000);
    }

    // Initialize when DOM is ready
    $(document).ready(function () {
        initShareButtons();
    });

})(jQuery);
