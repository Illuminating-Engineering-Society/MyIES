/**
 * Wicket Company - Change Organization Modal
 * 
 * Handles the modal interface for changing user's active organization.
 * Requires: jQuery, wicketCompanyConfig (ajaxUrl, nonce)
 * 
 * Usage in Bricks:
 * 1. Add Code element with: <?php wicket_company_enqueue_assets(); ?>
 * 2. Add button with class: wicket-change-org-btn
 * 3. Use Dynamic Data {wicket_company_name} for company name display
 */

(function($) {
    'use strict';

    // Configuration
    var config = {
        minSearchLength: 2,
        debounceDelay: 300,
        maxResults: 10,
        selectors: {
            triggerBtn: '.wicket-change-org-btn',
            modal: '#wicket-change-org-modal',
            closeBtn: '.wicket-modal-close',
            searchInput: '#wicket-org-search',
            resultsContainer: '#wicket-org-results',
            selectedDisplay: '#wicket-selected-org',
            saveBtn: '#wicket-save-org-btn',
            cancelBtn: '#wicket-cancel-org-btn',
            currentCompanyName: '.wicket-current-company-name'
        }
    };

    // State
    var state = {
        isOpen: false,
        searchTimeout: null,
        selectedOrg: null,
        lastSearchResults: []
    };

    /**
     * Initialize the module
     */
    function init() {
        console.log('[Wicket Company] Initializing change org module');
        
        // Check if config exists
        if (typeof wicketCompanyConfig === 'undefined') {
            console.error('[Wicket Company] wicketCompanyConfig not found. Did you call wicket_company_enqueue_assets()?');
            return;
        }

        bindEvents();
        console.log('[Wicket Company] Module initialized');
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Open modal
        $(document).on('click', config.selectors.triggerBtn, function(e) {
            e.preventDefault();
            console.log('[Wicket Company] Open modal clicked');
            openModal();
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

        // Select organization from results
        $(document).on('click', '.wicket-org-result-item', function() {
            var uuid = $(this).data('uuid');
            var name = $(this).data('name');
            selectOrganization(uuid, name);
        });

        // Add new company click
        $(document).on('click', '.wicket-add-new-company', function() {
            showAddNewForm();
        });

        // Save button
        $(document).on('click', config.selectors.saveBtn, function() {
            saveSelectedOrganization();
        });

        // Create new company form submit
        $(document).on('click', '#wicket-create-company-btn', function() {
            createNewCompany();
        });

        // Back from add new form
        $(document).on('click', '#wicket-back-to-search', function() {
            hideAddNewForm();
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
        state.selectedOrg = null;
        
        $('body').addClass('wicket-modal-open');
    }

    /**
     * Close the modal
     */
    function closeModal() {
        $(config.selectors.modal).fadeOut(200);
        state.isOpen = false;
        state.selectedOrg = null;
        $('body').removeClass('wicket-modal-open');
    }

    /**
     * Create modal HTML
     */
    function createModal() {
        var modalHtml = `
            <div id="wicket-change-org-modal" class="wicket-modal-overlay">
                <div class="wicket-modal-content">
                    <button class="wicket-modal-close" aria-label="Close">&times;</button>
                    
                    <div class="wicket-modal-header">
                        <h2>Change Organization</h2>
                    </div>
                    
                    <div class="wicket-modal-body">
                        <!-- Search Section -->
                        <div id="wicket-search-section">
                            <div class="wicket-search-wrapper">
                                <input type="text" 
                                       id="wicket-org-search" 
                                       placeholder="Enter company name..."
                                       autocomplete="off">
                                <span class="wicket-search-icon">🔍</span>
                            </div>
                            
                            <div id="wicket-org-results"></div>
                            
                            <div id="wicket-selected-org" style="display: none;">
                                <div class="wicket-selected-label">Selected:</div>
                                <div class="wicket-selected-name"></div>
                                <button type="button" class="wicket-clear-selection">&times;</button>
                            </div>
                        </div>
                        
                        <!-- Add New Section (hidden by default) -->
                        <div id="wicket-add-new-section" style="display: none;">
                            <button type="button" id="wicket-back-to-search" class="wicket-back-btn">
                                ← Back to search
                            </button>
                            
                            <h3>Add New Company</h3>
                            
                            <div class="wicket-form-group">
                                <label for="wicket-new-company-name">Company Name *</label>
                                <input type="text" id="wicket-new-company-name" required>
                            </div>
                            
                            <div class="wicket-form-group">
                                <label for="wicket-new-company-type">Company Type</label>
                                <select id="wicket-new-company-type">
                                    <option value="company">Company</option>
                                    <option value="nonprofit">Nonprofit</option>
                                    <option value="government">Government</option>
                                    <option value="education">Education</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <button type="button" id="wicket-create-company-btn" class="wicket-btn wicket-btn-primary">
                                Create Company
                            </button>
                        </div>
                    </div>
                    
                    <div class="wicket-modal-footer">
                        <button type="button" id="wicket-cancel-org-btn" class="wicket-btn wicket-btn-secondary">
                            Cancel
                        </button>
                        <button type="button" id="wicket-save-org-btn" class="wicket-btn wicket-btn-primary" disabled>
                            Save
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);

        // Clear selection handler
        $(document).on('click', '.wicket-clear-selection', function() {
            state.selectedOrg = null;
            $(config.selectors.selectedDisplay).hide();
            $(config.selectors.saveBtn).prop('disabled', true);
            $(config.selectors.searchInput).val('').focus();
        });
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
        $(config.selectors.resultsContainer).html('<div class="wicket-loading">Searching...</div>');

        state.searchTimeout = setTimeout(function() {
            performSearch(term);
        }, config.debounceDelay);
    }

    /**
     * Perform AJAX search
     */
    function performSearch(term) {
        console.log('[Wicket Company] Searching for:', term);
        
        $.ajax({
            url: wicketCompanyConfig.ajaxUrl,
            type: 'GET',
            data: {
                action: 'wicket_search_companies',
                nonce: wicketCompanyConfig.nonce,
                search: term
            },
            success: function(response) {
                console.log('[Wicket Company] Search response:', response);
                
                if (response.success) {
                    state.lastSearchResults = response.data.results;
                    displaySearchResults(response.data.results, term);
                } else {
                    $(config.selectors.resultsContainer).html(
                        '<div class="wicket-error">Search failed: ' + (response.data.message || 'Unknown error') + '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('[Wicket Company] Search error:', error);
                $(config.selectors.resultsContainer).html(
                    '<div class="wicket-error">Connection error. Please try again.</div>'
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
            var $list = $('<ul class="wicket-results-list"></ul>');
            
            limitedResults.forEach(function(org, index) {
                var displayName = org.legal_name || 'Unknown';
                var altName = org.alternate_name ? ' (' + org.alternate_name + ')' : '';
                var orgType = org.org_type || '';
                var orgUuid = org.wicket_uuid;
                
                var $item = $(`
                    <li class="wicket-org-result-item" 
                        data-uuid="${orgUuid}" 
                        data-name="${escapeHtml(displayName)}"
                        data-index="${index}">
                        <div class="wicket-org-name">${highlightMatch(displayName, searchTerm)}${altName}</div>
                        ${orgType ? '<div class="wicket-org-type">' + escapeHtml(orgType) + '</div>' : ''}
                    </li>
                `);
                
                $list.append($item);
            });
            
            $container.append($list);
        } else {
            $container.html('<div class="wicket-no-results">No companies found matching "' + escapeHtml(searchTerm) + '"</div>');
        }

        // Always show "Add new" option
        var $addNew = $(`
            <div class="wicket-add-new-company">
                <span class="wicket-add-new-icon">+</span>
                Can't find your company? <strong>Add new →</strong>
            </div>
        `);
        $container.append($addNew);
    }

    /**
     * Select an organization
     */
    function selectOrganization(uuid, name) {
        console.log('[Wicket Company] Selected:', uuid, name);
        
        state.selectedOrg = {
            uuid: uuid,
            name: name
        };

        // Show selected
        $(config.selectors.selectedDisplay).show();
        $(config.selectors.selectedDisplay).find('.wicket-selected-name').text(name);
        
        // Enable save button
        $(config.selectors.saveBtn).prop('disabled', false);
        
        // Clear search
        $(config.selectors.resultsContainer).empty();
        $(config.selectors.searchInput).val('');
    }

    /**
     * Save the selected organization
     */
    function saveSelectedOrganization() {
        if (!state.selectedOrg || !state.selectedOrg.uuid) {
            alert('Please select an organization first');
            return;
        }

        console.log('[Wicket Company] Saving organization:', state.selectedOrg);

        var $saveBtn = $(config.selectors.saveBtn);
        var originalText = $saveBtn.text();
        
        $saveBtn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: wicketCompanyConfig.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wicket_set_primary_company',
                nonce: wicketCompanyConfig.nonce,
                org_uuid: state.selectedOrg.uuid
            },
            success: function(response) {
                console.log('[Wicket Company] Save response:', response);
                
                if (response.success) {
                    // Update any displayed company name on the page
                    $(config.selectors.currentCompanyName).text(state.selectedOrg.name);
                    
                    closeModal();
                    showNotification('Organization updated successfully!', 'success');
                    
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
                console.error('[Wicket Company] Save error:', error, xhr.responseText);
                $saveBtn.prop('disabled', false).text(originalText);
                showNotification('Connection error. Please try again.', 'error');
            }
        });
    }

    /**
     * Show add new company form
     */
    function showAddNewForm() {
        $('#wicket-search-section').hide();
        $('#wicket-add-new-section').show();
        $(config.selectors.saveBtn).hide();
        $('#wicket-new-company-name').val('').focus();
    }

    /**
     * Hide add new company form
     */
    function hideAddNewForm() {
        $('#wicket-add-new-section').hide();
        $('#wicket-search-section').show();
        $(config.selectors.saveBtn).show();
        $(config.selectors.searchInput).focus();
    }

    /**
     * Create new company
     */
    function createNewCompany() {
        var name = $('#wicket-new-company-name').val().trim();
        var type = $('#wicket-new-company-type').val();

        if (!name) {
            alert('Please enter a company name');
            return;
        }

        console.log('[Wicket Company] Creating new company:', name, type);

        var $btn = $('#wicket-create-company-btn');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Creating...');

        $.ajax({
            url: wicketCompanyConfig.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wicket_create_new_company',
                nonce: wicketCompanyConfig.nonce,
                legal_name: name,
                type: type
            },
            success: function(response) {
                console.log('[Wicket Company] Create response:', response);
                
                if (response.success) {
                    $(config.selectors.currentCompanyName).text(name);
                    closeModal();
                    showNotification('Company created successfully!', 'success');
                    
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    $btn.prop('disabled', false).text(originalText);
                    showNotification(response.data.message || 'Error creating company.', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('[Wicket Company] Create error:', error);
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
        $('.wicket-notification').remove();
        
        var $notification = $(`
            <div class="wicket-notification wicket-notification-${type}">
                ${escapeHtml(message)}
            </div>
        `);
        
        $('body').append($notification);
        
        setTimeout(function() {
            $notification.addClass('wicket-notification-show');
        }, 10);
        
        setTimeout(function() {
            $notification.removeClass('wicket-notification-show');
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