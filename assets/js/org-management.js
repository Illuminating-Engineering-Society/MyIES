/**
 * Organization Management — frontend logic
 *
 * Depends on the `myiesOrgMgmt` object localized by the shortcode:
 *   { ajaxUrl, nonce, orgUuid, canManage, i18n }
 */
(function ($) {
	'use strict';

	var cfg          = window.myiesOrgMgmt || {};
	var $wrap        = $('#myies-orgmgmt');
	if (!$wrap.length || !cfg.nonce) return;

	var canManage    = !!cfg.canManage;

	// Cache DOM
	var $toggleAdd    = $('#myies-orgmgmt-toggle-add');
	var $addSection   = $('#myies-orgmgmt-add-section');
	var $search       = $('#myies-orgmgmt-search');
	var $results      = $('#myies-orgmgmt-search-results');
	var $selected     = $('#myies-orgmgmt-selected');
	var $selName      = $('#myies-orgmgmt-selected-name');
	var $selEmail     = $('#myies-orgmgmt-selected-email');
	var $roleSelect   = $('#myies-orgmgmt-role');
	var $addBtn       = $('#myies-orgmgmt-add-btn');
	var $cancelBtn    = $('#myies-orgmgmt-cancel-btn');
	var $addMsg       = $('#myies-orgmgmt-add-message');
	var $members      = $('#myies-orgmgmt-members');
	var $filter       = $('#myies-orgmgmt-filter');

	// New person form elements
	var $newPerson    = $('#myies-orgmgmt-new-person');
	var $newFirst     = $('#myies-orgmgmt-new-first');
	var $newLast      = $('#myies-orgmgmt-new-last');
	var $newEmail     = $('#myies-orgmgmt-new-email');
	var $newRole      = $('#myies-orgmgmt-new-role');
	var $createBtn    = $('#myies-orgmgmt-create-btn');
	var $newCancelBtn = $('#myies-orgmgmt-new-cancel-btn');
	var $newMsg       = $('#myies-orgmgmt-new-message');

	var selectedUser  = null;
	var searchTimer   = null;
	var allMembers    = [];
	var currentPage   = 1;
	var perPage       = 20;

	// =========================================================================
	// Init — load members
	// =========================================================================
	loadMembers();

	// =========================================================================
	// Toggle Add Member panel
	// =========================================================================
	$toggleAdd.on('click', function () {
		$addSection.slideDown(200);
		$toggleAdd.hide();
		$search.focus();
	});

	// Close Add Member panel
	$('#myies-orgmgmt-close-add').on('click', function () {
		hideAddSection();
	});

	function hideAddSection() {
		selectedUser = null;
		$selected.hide();
		$search.val('').show();
		$results.empty().hide();
		$addMsg.hide();
		hideNewPersonForm();
		$addSection.slideUp(200, function () {
			$toggleAdd.show();
		});
	}

	function showNewPersonForm(prefillEmail) {
		$search.hide();
		$results.empty().hide();
		$newFirst.val('');
		$newLast.val('');
		$newEmail.val(prefillEmail || '');
		$newRole.val('employee');
		$newMsg.hide();
		$newPerson.show();
		$newFirst.focus();
	}

	function hideNewPersonForm() {
		$newPerson.hide();
		$newFirst.val('');
		$newLast.val('');
		$newEmail.val('');
		$newMsg.hide();
	}

	// =========================================================================
	// Email search (predictive, 5+ chars)
	// =========================================================================
	$search.on('input', function () {
		clearTimeout(searchTimer);
		var val = $.trim(this.value);
		if (val.length < 5) {
			$results.empty().hide();
			return;
		}
		searchTimer = setTimeout(function () {
			$.post(cfg.ajaxUrl, {
				action: 'myies_orgmgmt_search_users',
				nonce:  cfg.nonce,
				email:  val
			}, function (res) {
				$results.empty();
				if (!res.success || !res.data.results.length) {
					var noResultHtml = '<div class="myies-orgmgmt__no-result">' + cfg.i18n.no_results + '</div>';
					if (canManage) {
						noResultHtml += '<div class="myies-orgmgmt__add-new-btn" data-email="' + escAttr(val) + '">' +
							escHtml(cfg.i18n.add_new_person) + '</div>';
					}
					$results.html(noResultHtml).show();
					return;
				}
				res.data.results.forEach(function (u) {
					var $row = $('<div class="myies-orgmgmt__result-item">')
						.data('user', u)
						.html(
							'<strong>' + escHtml(u.display_name) + '</strong>' +
							'<span class="myies-orgmgmt__email">' + escHtml(u.email) + '</span>'
						);
					$results.append($row);
				});
				$results.show();
			});
		}, 300);
	});

	// Select a user from results
	$results.on('click', '.myies-orgmgmt__result-item', function () {
		selectedUser = $(this).data('user');
		$selName.text(selectedUser.display_name);
		$selEmail.text(selectedUser.email);
		$results.empty().hide();
		$search.val('').hide();
		$selected.show();
		$addMsg.hide();
	});

	// Cancel selection — collapse the entire add section
	$cancelBtn.on('click', function () {
		hideAddSection();
	});

	// Click "+ Add New Person" in search results
	$results.on('click', '.myies-orgmgmt__add-new-btn', function () {
		var prefillEmail = $(this).data('email') || '';
		showNewPersonForm(prefillEmail);
	});

	// Cancel new person form — return to search
	$newCancelBtn.on('click', function () {
		hideNewPersonForm();
		$search.show().val('').focus();
	});

	// Create & Add new person
	$createBtn.on('click', function () {
		var firstName = $.trim($newFirst.val());
		var lastName  = $.trim($newLast.val());
		var email     = $.trim($newEmail.val());
		var role      = $newRole.val();

		if (!firstName || !lastName || !email) {
			showMsg($newMsg, 'Please fill in all fields.', true);
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text(cfg.i18n.creating);

		$.post(cfg.ajaxUrl, {
			action:     'myies_orgmgmt_create_and_add',
			nonce:      cfg.nonce,
			first_name: firstName,
			last_name:  lastName,
			email:      email,
			role:       role
		}, function (res) {
			$btn.prop('disabled', false).text($createBtn.data('orig') || 'Create & Add to Organization');
			if (res.success) {
				showMsg($newMsg, res.data.message, false);
				loadMembers();
				setTimeout(function () { hideAddSection(); }, 1500);
			} else {
				showMsg($newMsg, res.data.message || 'Error', true);
			}
		}).fail(function () {
			$btn.prop('disabled', false).text($createBtn.data('orig') || 'Create & Add to Organization');
			showMsg($newMsg, 'Request failed.', true);
		});
	});

	// Store original create button text
	$createBtn.data('orig', $createBtn.text());

	// Add member
	$addBtn.on('click', function () {
		if (!selectedUser) return;
		var $btn = $(this);
		$btn.prop('disabled', true).text(cfg.i18n.adding);

		$.post(cfg.ajaxUrl, {
			action:  'myies_orgmgmt_add_member',
			nonce:   cfg.nonce,
			user_id: selectedUser.user_id,
			role:    $roleSelect.val()
		}, function (res) {
			$btn.prop('disabled', false).text($addBtn.data('orig') || 'Add to Organization');
			if (res.success) {
				showMsg($addMsg, res.data.message, false);
				selectedUser = null;
				$selected.hide();
				$search.show().val('');
				loadMembers(); // refresh list
				// Auto-hide the add section after a short delay
				setTimeout(function () { hideAddSection(); }, 1500);
			} else {
				showMsg($addMsg, res.data.message || 'Error', true);
			}
		}).fail(function () {
			$btn.prop('disabled', false).text($addBtn.data('orig') || 'Add to Organization');
			showMsg($addMsg, 'Request failed.', true);
		});
	});

	// Store original button text
	$addBtn.data('orig', $addBtn.text());

	// =========================================================================
	// Members list
	// =========================================================================
	function loadMembers() {
		$members.html('<p class="myies-orgmgmt__loading">Loading members...</p>');
		$.post(cfg.ajaxUrl, {
			action: 'myies_orgmgmt_get_members',
			nonce:  cfg.nonce
		}, function (res) {
			if (!res.success) {
				$members.html('<p class="myies-orgmgmt__error">Failed to load members.</p>');
				return;
			}
			allMembers = res.data.members;
			renderMembers(allMembers, 1);
		}).fail(function () {
			$members.html('<p class="myies-orgmgmt__error">Request failed.</p>');
		});
	}

	function getFilteredMembers() {
		var term = $.trim($filter.val()).toLowerCase();
		if (!term) return allMembers;
		return allMembers.filter(function (m) {
			return (m.name && m.name.toLowerCase().indexOf(term) !== -1) ||
			       (m.email && m.email.toLowerCase().indexOf(term) !== -1);
		});
	}

	function renderMembers(list, page) {
		if (!list.length) {
			$members.html('<p>No members found.</p>');
			return;
		}

		page = page || 1;
		var totalPages = Math.ceil(list.length / perPage);
		if (page > totalPages) page = totalPages;
		if (page < 1) page = 1;
		currentPage = page;

		var start = (page - 1) * perPage;
		var pageItems = list.slice(start, start + perPage);

		var html = '<table class="myies-orgmgmt__table">' +
			'<thead><tr>' +
			'<th>Name</th><th>Email</th><th>Role</th>' +
			(canManage ? '<th></th>' : '') +
			'</tr></thead><tbody>';

		pageItems.forEach(function (m) {
			var roles = [];
			if (m.connection_type) {
				roles.push(formatRole(m.connection_type));
			}
			if (m.roles && m.roles.length) {
				m.roles.forEach(function (r) { roles.push(r); });
			}
			var roleStr = roles.join(', ') || '&mdash;';

			html += '<tr data-connection="' + escAttr(m.connection_uuid) + '">' +
				'<td>' + escHtml(m.name) + (m.is_self ? ' <em>(you)</em>' : '') + '</td>' +
				'<td>' + escHtml(m.email) + '</td>' +
				'<td>' + roleStr + '</td>';

			if (canManage) {
				html += '<td>';
				if (!m.is_self) {
					html += '<button type="button" class="myies-orgmgmt__btn myies-orgmgmt__btn--danger myies-orgmgmt__remove-btn" ' +
						'data-connection="' + escAttr(m.connection_uuid) + '">Remove</button>';
				}
				html += '</td>';
			}

			html += '</tr>';
		});

		html += '</tbody></table>';

		// Pagination controls
		if (totalPages > 1) {
			html += '<div class="myies-orgmgmt__pagination">';
			html += '<button type="button" class="myies-orgmgmt__page-btn" data-page="' + (page - 1) + '"' +
				(page <= 1 ? ' disabled' : '') + '>&laquo; Prev</button>';
			html += '<span class="myies-orgmgmt__page-info">Page ' + page + ' of ' + totalPages +
				' (' + list.length + ' members)</span>';
			html += '<button type="button" class="myies-orgmgmt__page-btn" data-page="' + (page + 1) + '"' +
				(page >= totalPages ? ' disabled' : '') + '>Next &raquo;</button>';
			html += '</div>';
		}

		$members.html(html);
	}

	// Pagination click handler
	$members.on('click', '.myies-orgmgmt__page-btn', function () {
		var page = parseInt($(this).data('page'), 10);
		if (!page) return;
		renderMembers(getFilteredMembers(), page);
	});

	// Filter members list — searches across ALL members, resets to page 1
	$filter.on('input', function () {
		currentPage = 1;
		renderMembers(getFilteredMembers(), 1);
	});

	// Remove member (soft-end) — only available for Primary Contacts
	$members.on('click', '.myies-orgmgmt__remove-btn', function () {
		if (!confirm(cfg.i18n.confirm_remove)) return;

		var $btn     = $(this);
		var connUuid = $btn.data('connection');
		$btn.prop('disabled', true).text(cfg.i18n.removing);

		$.post(cfg.ajaxUrl, {
			action:          'myies_orgmgmt_remove_member',
			nonce:           cfg.nonce,
			connection_uuid: connUuid
		}, function (res) {
			if (res.success) {
				allMembers = allMembers.filter(function (m) {
					return m.connection_uuid !== connUuid;
				});
				renderMembers(getFilteredMembers(), currentPage);
			} else {
				alert(res.data.message || 'Error');
				$btn.prop('disabled', false).text('Remove');
			}
		}).fail(function () {
			alert('Request failed.');
			$btn.prop('disabled', false).text('Remove');
		});
	});

	// =========================================================================
	// Utility helpers
	// =========================================================================
	function showMsg($el, text, isError) {
		$el.text(text).css('color', isError ? '#a00' : '#080').show();
	}

	function formatRole(type) {
		// Capitalize first letter of connection type
		return type.charAt(0).toUpperCase() + type.slice(1);
	}

	function escHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	function escAttr(str) {
		return escHtml(str).replace(/"/g, '&quot;');
	}

	// Hide search results when clicking outside
	$(document).on('click', function (e) {
		if (!$(e.target).closest('.myies-orgmgmt__search-wrap').length) {
			$results.hide();
		}
	});

})(jQuery);
