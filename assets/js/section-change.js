/**
 * Wicket Section - Change Section Modal
 *
 * Handles the modal interface for changing user's section.
 * Requires: jQuery, wicketSectionConfig (ajaxUrl, nonce)
 *
 * Usage in Bricks:
 * 1. Add Code element with: <?php wicket_section_enqueue_assets(); ?>
 * 2. Add button with class: wicket-change-section-btn
 * 3. Use Dynamic Data {user_meta:wicket_section_name} for section name display
 *
 * @package MyIES_Integration
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Configuration
    var config = {
        minSearchLength: 2,
        debounceDelay: 300,
        maxResults: 15,
        selectors: {
            triggerBtn: '.wicket-change-section-btn',
            leaveBtn: '.wicket-leave-section-btn',
            modal: '#wicket-change-section-modal',
            closeBtn: '.wicket-section-modal-close',
            searchInput: '#wicket-section-search',
            resultsContainer: '#wicket-section-results',
            selectedDisplay: '#wicket-section-selected',
            saveBtn: '#wicket-section-save-btn',
            cancelBtn: '#wicket-section-cancel-btn',
            currentSectionName: '.wicket-current-section-name'
        }
    };

    // State
    var state = {
        isOpen: false,
        searchTimeout: null,
        selectedSection: null,
        lastSearchResults: [],
        currentSection: null
    };

    /**
     * Initialize the module
     */
    function init() {
        console.log('[Wicket Section] Initializing change section module');

        // Check if config exists
        if (typeof wicketSectionConfig === 'undefined') {
            console.error('[Wicket Section] wicketSectionConfig not found. Did you call wicket_section_enqueue_assets()?');
            return;
        }

        // Get current section if displayed on page
        var currentSectionEl = $(config.selectors.currentSectionName);
        if (currentSectionEl.length && currentSectionEl.data('uuid')) {
            state.currentSection = {
                uuid: currentSectionEl.data('uuid'),
                name: currentSectionEl.text(),
                connection_uuid: currentSectionEl.data('connection-uuid')
            };
        }

        bindEvents();
        console.log('[Wicket Section] Module initialized');
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Open modal
        $(document).on('click', config.selectors.triggerBtn, function(e) {
            e.preventDefault();
            console.log('[Wicket Section] Open modal clicked');
            openModal();
        });

        // Leave section
        $(document).on('click', config.selectors.leaveBtn, function(e) {
            e.preventDefault();
            console.log('[Wicket Section] Leave section clicked');

            var connectionUuid = $(this).data('connection-uuid');
            var sectionUuid = $(this).data('section-uuid');
            var sectionName = $(this).data('section-name') || 'this section';

            if (confirm('Are you sure you want to leave ' + sectionName + '?')) {
                leaveSection(connectionUuid, sectionUuid);
            }
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

        // Search input
        $(document).on('input', config.selectors.searchInput, function() {
            var term = $(this).val().trim();
            handleSearch(term);
        });

        // Select section from results
        $(document).on('click', '.wicket-section-result-item', function() {
            var uuid = $(this).data('uuid');
            var name = $(this).data('name');
            selectSection(uuid, name);
        });

        // Save button
        $(document).on('click', config.selectors.saveBtn, function() {
            saveSelectedSection();
        });

        // Clear selection
        $(document).on('click', '.wicket-section-clear-selection', function() {
            state.selectedSection = null;
            $(config.selectors.selectedDisplay).hide();
            $(config.selectors.saveBtn).prop('disabled', true);
            $(config.selectors.searchInput).val('').focus();
        });
    }

    /**
     * Open the modal
     */
    function openModal() {
        // Create modal if doesn't exist
        if ($(config.selectors.modal).length === 0) {
            createModal();
        }

        $(config.selectors.modal).fadeIn(200);
        $(config.selectors.searchInput).val('').focus();
        $(config.selectors.resultsContainer).empty();
        $(config.selectors.selectedDisplay).hide();
        state.isOpen = true;
        state.selectedSection = null;

        $('body').addClass('wicket-section-modal-open');
    }

    /**
     * Close the modal
     */
    function closeModal() {
        $(config.selectors.modal).fadeOut(200);
        state.isOpen = false;
        state.selectedSection = null;
        $('body').removeClass('wicket-section-modal-open');
    }

    /**
     * Create modal HTML
     */
    function createModal() {
        var modalHtml = `
            <div id="wicket-change-section-modal" class="wicket-section-modal-overlay">
                <div class="wicket-section-modal-content">
                    <button class="wicket-section-modal-close" aria-label="Close">&times;</button>

                    <div class="wicket-section-modal-header">
                        <h2>Change Section</h2>
                    </div>

                    <div class="wicket-section-modal-body">
                        <div class="wicket-section-search-wrapper">
                            <input type="text"
                                   id="wicket-section-search"
                                   placeholder="Search for a section..."
                                   autocomplete="off">
                            <span class="wicket-search-icon">🔍</span>
                        </div>

                        <div id="wicket-section-results"></div>

                        <div id="wicket-section-selected" style="display: none;">
                            <div>
                                <div class="wicket-section-selected-label">Selected:</div>
                                <div class="wicket-section-selected-name"></div>
                            </div>
                            <button type="button" class="wicket-section-clear-selection">&times;</button>
                        </div>
                    </div>

                    <div class="wicket-section-modal-footer">
                        <button type="button" id="wicket-section-cancel-btn" class="wicket-section-btn wicket-section-btn-secondary">
                            Cancel
                        </button>
                        <button type="button" id="wicket-section-save-btn" class="wicket-section-btn wicket-section-btn-primary" disabled>
                            Save
                        </button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
    }

    /**
     * Handle search input
     */
    function handleSearch(term) {
        clearTimeout(state.searchTimeout);

        if (term.length < config.minSearchLength) {
            $(config.selectors.resultsContainer).empty();
            return;
        }

        // Show loading
        $(config.selectors.resultsContainer).html('<div class="wicket-section-loading">Searching...</div>');

        state.searchTimeout = setTimeout(function() {
            performSearch(term);
        }, config.debounceDelay);
    }

    /**
     * Perform AJAX search
     */
    function performSearch(term) {
        console.log('[Wicket Section] Searching for:', term);

        $.ajax({
            url: wicketSectionConfig.ajaxUrl,
            type: 'GET',
            data: {
                action: 'wicket_search_sections',
                nonce: wicketSectionConfig.nonce,
                search: term
            },
            success: function(response) {
                console.log('[Wicket Section] Search response:', response);

                if (response.success) {
                    state.lastSearchResults = response.data.results;
                    displaySearchResults(response.data.results, term);
                } else {
                    $(config.selectors.resultsContainer).html(
                        '<div class="wicket-section-error">Search failed: ' + (response.data.message || 'Unknown error') + '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('[Wicket Section] Search error:', error);
                $(config.selectors.resultsContainer).html(
                    '<div class="wicket-section-error">Connection error. Please try again.</div>'
                );
            }
        });
    }

    /**
     * Display search results
     */
    function displaySearchResults(results, searchTerm) {
        var $container = $(config.selectors.resultsContainer);
        $container.empty();

        var limitedResults = results.slice(0, config.maxResults);

        if (limitedResults.length > 0) {
            var $list = $('<ul class="wicket-section-results-list"></ul>');

            limitedResults.forEach(function(section, index) {
                var displayName = section.legal_name || 'Unknown';
                var altName = section.alternate_name ? ' (' + section.alternate_name + ')' : '';
                var description = section.description || '';
                var sectionUuid = section.wicket_uuid;

                var $item = $(`
                    <li class="wicket-section-result-item"
                        data-uuid="${sectionUuid}"
                        data-name="${escapeHtml(displayName)}"
                        data-index="${index}">
                        <div class="wicket-section-name">${highlightMatch(displayName, searchTerm)}${altName}</div>
                        ${description ? '<div class="wicket-section-description">' + escapeHtml(description) + '</div>' : ''}
                    </li>
                `);

                $list.append($item);
            });

            $container.append($list);
        } else {
            $container.html('<div class="wicket-section-no-results">No sections found matching "' + escapeHtml(searchTerm) + '"</div>');
        }
    }

    /**
     * Select a section
     */
    function selectSection(uuid, name) {
        console.log('[Wicket Section] Selected:', uuid, name);

        state.selectedSection = {
            uuid: uuid,
            name: name
        };

        // Show selected
        $(config.selectors.selectedDisplay).show();
        $(config.selectors.selectedDisplay).find('.wicket-section-selected-name').text(name);

        // Enable save button
        $(config.selectors.saveBtn).prop('disabled', false);

        // Clear search
        $(config.selectors.resultsContainer).empty();
        $(config.selectors.searchInput).val('');
    }

    /**
     * Save the selected section
     */
    function saveSelectedSection() {
        if (!state.selectedSection || !state.selectedSection.uuid) {
            alert('Please select a section first');
            return;
        }

        console.log('[Wicket Section] Saving section:', state.selectedSection);

        var $saveBtn = $(config.selectors.saveBtn);
        var originalText = $saveBtn.text();

        $saveBtn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: wicketSectionConfig.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wicket_set_section',
                nonce: wicketSectionConfig.nonce,
                section_uuid: state.selectedSection.uuid
            },
            success: function(response) {
                console.log('[Wicket Section] Save response:', response);

                if (response.success) {
                    // Update any displayed section name on the page
                    $(config.selectors.currentSectionName).text(state.selectedSection.name);

                    closeModal();
                    showNotification('Section updated successfully!', 'success');

                    // Reload page after 1 second to refresh dynamic data
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $saveBtn.prop('disabled', false).text(originalText);
                    showNotification(response.data.message || 'Error saving. Please try again.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('[Wicket Section] Save error:', error, xhr.responseText);
                $saveBtn.prop('disabled', false).text(originalText);
                showNotification('Connection error. Please try again.', 'error');
            }
        });
    }

    /**
     * Leave section (remove connection)
     */
    function leaveSection(connectionUuid, sectionUuid) {
        console.log('[Wicket Section] Leaving section:', connectionUuid, sectionUuid);

        // Find and disable the button
        var $btn = $('.wicket-leave-section-btn[data-connection-uuid="' + connectionUuid + '"]');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Leaving...');

        $.ajax({
            url: wicketSectionConfig.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wicket_leave_section',
                nonce: wicketSectionConfig.nonce,
                connection_uuid: connectionUuid,
                section_uuid: sectionUuid
            },
            success: function(response) {
                console.log('[Wicket Section] Leave response:', response);

                if (response.success) {
                    showNotification('You have left the section', 'success');

                    // Reload page after 1 second
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $btn.prop('disabled', false).text(originalText);
                    showNotification(response.data.message || 'Error leaving section.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('[Wicket Section] Leave error:', error);
                $btn.prop('disabled', false).text(originalText);
                showNotification('Connection error. Please try again.', 'error');
            }
        });
    }

    /**
     * Show notification
     */
    function showNotification(message, type) {
        // Remove existing notifications
        $('.wicket-section-notification').remove();

        var $notification = $(`
            <div class="wicket-section-notification wicket-section-notification-${type}">
                ${escapeHtml(message)}
            </div>
        `);

        $('body').append($notification);

        setTimeout(function() {
            $notification.addClass('wicket-section-notification-show');
        }, 10);

        setTimeout(function() {
            $notification.removeClass('wicket-section-notification-show');
            setTimeout(function() {
                $notification.remove();
            }, 300);
        }, 3000);
    }

    /**
     * Highlight search term in text
     */
    function highlightMatch(text, term) {
        if (!term) return escapeHtml(text);
        var regex = new RegExp('(' + escapeRegex(term) + ')', 'gi');
        return escapeHtml(text).replace(regex, '<mark>$1</mark>');
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Escape regex special characters
     */
    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
