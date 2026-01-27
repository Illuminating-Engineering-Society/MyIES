/**
 * Wicket Membership History Modal
 *
 * Handles the modal interface for viewing membership history.
 * Requires: jQuery, wicketMembershipConfig (ajaxUrl, nonce)
 *
 * Usage in Bricks:
 * 1. Add Code element with: <?php wicket_membership_enqueue_assets(); ?>
 * 2. Add button/link with class: wicket-view-membership-history
 *    Required data attribute: data-type="person" or data-type="organization"
 *
 * @package MyIES_Integration
 * @since 1.0.4
 */

(function($) {
    'use strict';

    // Configuration
    var config = {
        selectors: {
            triggerBtn: '.wicket-view-membership-history',
            modal: '#wicket-membership-history-modal',
            closeBtn: '.wicket-membership-modal-close',
            cancelBtn: '#wicket-membership-cancel-btn',
            tableContainer: '#wicket-membership-table-container',
            modalTitle: '#wicket-membership-modal-title'
        }
    };

    // State
    var state = {
        isOpen: false,
        currentType: null
    };

    /**
     * Initialize the module
     */
    function init() {
        console.log('[Wicket Membership] Initializing membership history modal');

        if (typeof wicketMembershipConfig === 'undefined') {
            console.error('[Wicket Membership] wicketMembershipConfig not found. Did you call wicket_membership_enqueue_assets()?');
            return;
        }

        bindEvents();
        console.log('[Wicket Membership] Module initialized');
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Open modal
        $(document).on('click', config.selectors.triggerBtn, function(e) {
            e.preventDefault();
            var type = $(this).data('type') || 'person';
            console.log('[Wicket Membership] Open modal clicked, type:', type);
            openModal(type);
        });

        // Close modal
        $(document).on('click', config.selectors.closeBtn + ', ' + config.selectors.cancelBtn, function(e) {
            e.preventDefault();
            closeModal();
        });

        // Close on overlay click
        $(document).on('click', config.selectors.modal, function(e) {
            if ($(e.target).is(config.selectors.modal)) {
                closeModal();
            }
        });

        // Close on ESC
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && state.isOpen) {
                closeModal();
            }
        });
    }

    /**
     * Open the modal
     */
    function openModal(type) {
        state.currentType = type;

        // Create modal if doesn't exist
        if ($(config.selectors.modal).length === 0) {
            createModal();
        }

        // Set title based on type
        var title = type === 'organization' ? 'Organization Membership History' : 'Individual Membership History';
        $(config.selectors.modalTitle).text(title);

        // Show loading
        $(config.selectors.tableContainer).html('<div class="wicket-membership-loading">Loading...</div>');

        $(config.selectors.modal).fadeIn(200);
        state.isOpen = true;

        $('body').addClass('wicket-membership-modal-open');

        // Load content
        loadMembershipHistory(type);
    }

    /**
     * Close the modal
     */
    function closeModal() {
        $(config.selectors.modal).fadeOut(200);
        state.isOpen = false;
        state.currentType = null;
        $('body').removeClass('wicket-membership-modal-open');
    }

    /**
     * Create modal HTML
     */
    function createModal() {
        var modalHtml = `
            <div id="wicket-membership-history-modal" class="wicket-membership-modal-overlay">
                <div class="wicket-membership-modal-content">
                    <button class="wicket-membership-modal-close" aria-label="Close">&times;</button>

                    <div class="wicket-membership-modal-header">
                        <h2 id="wicket-membership-modal-title">Membership History</h2>
                    </div>

                    <div class="wicket-membership-modal-body">
                        <div id="wicket-membership-table-container">
                            <div class="wicket-membership-loading">Loading...</div>
                        </div>
                    </div>

                    <div class="wicket-membership-modal-footer">
                        <button type="button" id="wicket-membership-cancel-btn" class="wicket-membership-btn wicket-membership-btn-secondary">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
    }

    /**
     * Load membership history via AJAX (renders shortcode server-side)
     */
    function loadMembershipHistory(type) {
        console.log('[Wicket Membership] Loading history for type:', type);

        $.ajax({
            url: wicketMembershipConfig.ajaxUrl,
            type: 'GET',
            data: {
                action: 'wicket_render_membership_history',
                nonce: wicketMembershipConfig.nonce,
                type: type
            },
            success: function(response) {
                console.log('[Wicket Membership] Load response:', response);

                if (response.success) {
                    $(config.selectors.tableContainer).html(response.data.html);
                } else {
                    $(config.selectors.tableContainer).html(
                        '<div class="wicket-membership-error">' +
                        (response.data.message || 'Failed to load membership history.') +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('[Wicket Membership] Load error:', error);
                $(config.selectors.tableContainer).html(
                    '<div class="wicket-membership-error">Connection error. Please try again.</div>'
                );
            }
        });
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);