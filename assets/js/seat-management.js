/**
 * Seat Management — frontend logic
 *
 * Depends on the `myiesSeats` object localized by the shortcode:
 *   { ajaxUrl, nonce, orgUuid, i18n }
 */
(function ($) {
	'use strict';

	var cfg   = window.myiesSeats || {};
	var $wrap = $('#myies-seats');
	if (!$wrap.length || !cfg.nonce) return;

	// Cache DOM
	var $summary       = $('#myies-seats-summary');
	var $members       = $('#myies-seats-members');
	var $assignSection = $('#myies-seats-assign-section');
	var $search        = $('#myies-seats-search');
	var $results       = $('#myies-seats-search-results');
	var $assignMsg     = $('#myies-seats-assign-message');
	var $filterWrap    = $('#myies-seats-filter-wrap');
	var $filter        = $('#myies-seats-filter');

	var seatInfo       = null; // { max_assignments, unlimited_assignments, total_seated, seated[] }
	var allSeated      = [];
	var currentPage    = 1;
	var perPage        = 20;
	var searchTimer    = null;

	// =========================================================================
	// Init
	// =========================================================================
	loadSeatData();

	// =========================================================================
	// Load seat data
	// =========================================================================
	function loadSeatData() {
		$summary.html('<p class="myies-seats__loading">Loading seat information...</p>');
		$members.html('');

		$.post(cfg.ajaxUrl, {
			action: 'myies_seats_get_data',
			nonce:  cfg.nonce
		}, function (res) {
			if (!res.success) {
				$summary.html('<p class="myies-seats__error">Failed to load seat data.</p>');
				return;
			}

			seatInfo  = res.data;
			allSeated = seatInfo.seated || [];

			if (!seatInfo.has_membership) {
				$summary.html('<p>No active organization membership found.</p>');
				return;
			}

			renderSummary();
			$filter.val('');
			currentPage = 1;
			renderSeatedMembers(allSeated, 1);
		}).fail(function () {
			$summary.html('<p class="myies-seats__error">Request failed.</p>');
		});
	}

	// =========================================================================
	// Render seat summary
	// =========================================================================
	function renderSummary() {
		var html = '<div class="myies-seats__summary-bar">';

		if (seatInfo.unlimited_assignments) {
			html += '<span class="myies-seats__count">' + seatInfo.total_seated + ' seats assigned (unlimited)</span>';
		} else if (seatInfo.max_assignments) {
			html += '<span class="myies-seats__count">' + seatInfo.total_seated + ' / ' + seatInfo.max_assignments + ' seats assigned</span>';
		} else {
			html += '<span class="myies-seats__count">' + seatInfo.total_seated + ' seats assigned</span>';
		}

		// Show assign button if seats are available
		var seatsAvailable = seatInfo.unlimited_assignments ||
			seatInfo.max_assignments === null ||
			seatInfo.total_seated < seatInfo.max_assignments;

		if (seatsAvailable) {
			html += ' <button type="button" class="myies-seats__btn myies-seats__btn--primary" id="myies-seats-toggle-assign">+ Assign Seat</button>';
		} else {
			html += ' <span class="myies-seats__full">All seats occupied</span>';
		}

		html += '</div>';
		$summary.html(html);
	}

	// =========================================================================
	// Render seated members (paginated)
	// =========================================================================
	function getFilteredSeated() {
		var term = $.trim($filter.val()).toLowerCase();
		if (!term) return allSeated;
		return allSeated.filter(function (s) {
			return (s.name && s.name.toLowerCase().indexOf(term) !== -1) ||
			       (s.email && s.email.toLowerCase().indexOf(term) !== -1);
		});
	}

	function renderSeatedMembers(list, page) {
		if (!list.length) {
			$members.html('<p class="myies-seats__empty">No seats assigned yet.</p>');
			$filterWrap.hide();
			return;
		}

		$filterWrap.show();

		page = page || 1;
		var totalPages = Math.ceil(list.length / perPage);
		if (page > totalPages) page = totalPages;
		if (page < 1) page = 1;
		currentPage = page;

		var start     = (page - 1) * perPage;
		var pageItems = list.slice(start, start + perPage);

		var html = '<table class="myies-seats__table">' +
			'<thead><tr>' +
			'<th>Name</th><th>Email</th><th></th>' +
			'</tr></thead><tbody>';

		pageItems.forEach(function (s) {
			html += '<tr>' +
				'<td>' + escHtml(s.name) + '</td>' +
				'<td>' + escHtml(s.email) + '</td>' +
				'<td>' +
				'<button type="button" class="myies-seats__btn myies-seats__btn--danger myies-seats__remove-btn" ' +
				'data-pm-uuid="' + escAttr(s.person_membership_uuid) + '">Remove Seat</button>' +
				'</td>' +
				'</tr>';
		});

		html += '</tbody></table>';

		// Pagination controls
		if (totalPages > 1) {
			html += '<div class="myies-seats__pagination">';
			html += '<button type="button" class="myies-seats__page-btn" data-page="' + (page - 1) + '"' +
				(page <= 1 ? ' disabled' : '') + '>&laquo; Prev</button>';
			html += '<span class="myies-seats__page-info">Page ' + page + ' of ' + totalPages +
				' (' + list.length + ' seated)</span>';
			html += '<button type="button" class="myies-seats__page-btn" data-page="' + (page + 1) + '"' +
				(page >= totalPages ? ' disabled' : '') + '>Next &raquo;</button>';
			html += '</div>';
		}

		$members.html(html);
	}

	// Pagination click handler
	$members.on('click', '.myies-seats__page-btn', function () {
		var page = parseInt($(this).data('page'), 10);
		if (!page) return;
		renderSeatedMembers(getFilteredSeated(), page);
	});

	// Filter seated members — searches across ALL seated, resets to page 1
	$filter.on('input', function () {
		currentPage = 1;
		renderSeatedMembers(getFilteredSeated(), 1);
	});

	// =========================================================================
	// Toggle assign section
	// =========================================================================
	$summary.on('click', '#myies-seats-toggle-assign', function () {
		$assignSection.slideDown(200);
		$search.val('').focus();
		$results.empty().hide();
		$assignMsg.hide();
	});

	$('#myies-seats-close-assign').on('click', function () {
		$assignSection.slideUp(200);
		$search.val('');
		$results.empty().hide();
		$assignMsg.hide();
	});

	// =========================================================================
	// Search org members (3+ chars)
	// =========================================================================
	$search.on('input', function () {
		clearTimeout(searchTimer);
		var val = $.trim(this.value);
		if (val.length < 3) {
			$results.empty().hide();
			return;
		}
		searchTimer = setTimeout(function () {
			$.post(cfg.ajaxUrl, {
				action: 'myies_seats_get_org_members',
				nonce:  cfg.nonce,
				search: val
			}, function (res) {
				$results.empty();
				if (!res.success || !res.data.results.length) {
					$results.html('<div class="myies-seats__no-result">' + cfg.i18n.no_results + '</div>').show();
					return;
				}
				res.data.results.forEach(function (m) {
					var $row = $('<div class="myies-seats__result-item">')
						.data('member', m)
						.html(
							'<strong>' + escHtml(m.name) + '</strong>' +
							'<span class="myies-seats__email">' + escHtml(m.email) + '</span>'
						);
					$results.append($row);
				});
				$results.show();
			});
		}, 300);
	});

	// =========================================================================
	// Select a member to assign seat
	// =========================================================================
	$results.on('click', '.myies-seats__result-item', function () {
		var member = $(this).data('member');
		var $row   = $(this);

		$row.find('strong').text(cfg.i18n.assigning);
		$results.find('.myies-seats__result-item').css('pointer-events', 'none');

		$.post(cfg.ajaxUrl, {
			action:      'myies_seats_assign',
			nonce:       cfg.nonce,
			person_uuid: member.person_uuid
		}, function (res) {
			if (res.success) {
				showMsg($assignMsg, res.data.message, false);
				$assignSection.slideUp(200);
				loadSeatData();
			} else {
				showMsg($assignMsg, res.data.message || 'Error', true);
				$results.find('.myies-seats__result-item').css('pointer-events', '');
				$row.find('strong').text(member.name);
			}
		}).fail(function () {
			showMsg($assignMsg, 'Request failed.', true);
			$results.find('.myies-seats__result-item').css('pointer-events', '');
			$row.find('strong').text(member.name);
		});
	});

	// =========================================================================
	// Remove seat
	// =========================================================================
	$members.on('click', '.myies-seats__remove-btn', function () {
		if (!confirm(cfg.i18n.confirm_remove)) return;

		var $btn   = $(this);
		var pmUuid = $btn.data('pm-uuid');
		$btn.prop('disabled', true).text(cfg.i18n.removing);

		$.post(cfg.ajaxUrl, {
			action:                 'myies_seats_remove',
			nonce:                  cfg.nonce,
			person_membership_uuid: pmUuid
		}, function (res) {
			if (res.success) {
				// Remove locally and re-render without full reload
				allSeated = allSeated.filter(function (s) {
					return s.person_membership_uuid !== pmUuid;
				});
				seatInfo.total_seated = allSeated.length;
				renderSummary();
				renderSeatedMembers(getFilteredSeated(), currentPage);
			} else {
				alert(res.data.message || 'Error');
				$btn.prop('disabled', false).text('Remove Seat');
			}
		}).fail(function () {
			alert('Request failed.');
			$btn.prop('disabled', false).text('Remove Seat');
		});
	});

	// =========================================================================
	// Utility helpers
	// =========================================================================
	function showMsg($el, text, isError) {
		$el.text(text).css('color', isError ? '#a00' : '#080').show();
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
		if (!$(e.target).closest('.myies-seats__search-wrap').length) {
			$results.hide();
		}
	});

})(jQuery);
