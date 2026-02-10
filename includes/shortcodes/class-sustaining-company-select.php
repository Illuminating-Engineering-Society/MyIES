<?php
/**
 * Sustaining Membership — Company Selection Shortcode
 *
 * Renders a company selection / confirmation widget to be placed on
 * sustaining (organizational) membership product pages.
 *
 * Shortcode: [myies_sustaining_company_select]
 *
 * Behaviour:
 *  - Logged-in user with a company: shows their primary company and lets
 *    them confirm or change it.  On confirm the org_uuid is saved to a
 *    transient that the checkout handler reads post-purchase.
 *  - Logged-in user without a company: shows a search / create form.
 *  - Not logged in: shows a login prompt.
 *
 * The selected org_uuid is stored in transient `myies_checkout_org_{user_id}`
 * with a 2-hour TTL, which is consumed by `sync_sustaining_membership()`.
 *
 * @package MyIES_Integration
 * @since   1.0.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyIES_Sustaining_Company_Select {

	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'myies_sustaining_company_select', array( $this, 'render' ) );

		// AJAX: confirm company selection (saves transient)
		add_action( 'wp_ajax_myies_confirm_sustaining_org', array( $this, 'ajax_confirm_org' ) );
	}

	// =========================================================================
	// Shortcode output
	// =========================================================================

	public function render( $atts ) {
		$atts = shortcode_atts( array(
			'login_url' => wp_login_url( get_permalink() ),
		), $atts, 'myies_sustaining_company_select' );

		ob_start();

		// --- Not logged in -----------------------------------------------------------
		if ( ! is_user_logged_in() ) {
			$this->render_login_prompt( $atts['login_url'] );
			return ob_get_clean();
		}

		// --- Logged in — load company data ------------------------------------------
		$user_id      = get_current_user_id();
		$person_uuid  = get_user_meta( $user_id, 'wicket_person_uuid', true );
		if ( empty( $person_uuid ) ) {
			$person_uuid = get_user_meta( $user_id, 'wicket_uuid', true );
		}

		$companies       = function_exists( 'wicket_get_user_companies' ) ? wicket_get_user_companies( $user_id ) : array();
		$primary         = null;
		foreach ( $companies as $c ) {
			if ( ! empty( $c['is_primary'] ) ) {
				$primary = $c;
				break;
			}
		}
		if ( ! $primary && ! empty( $companies ) ) {
			$primary = $companies[0];
		}

		// Check if an org was already confirmed this session
		$confirmed_uuid = get_transient( 'myies_checkout_org_' . $user_id );

		$this->render_widget( $user_id, $companies, $primary, $confirmed_uuid, $person_uuid );

		return ob_get_clean();
	}

	// =========================================================================
	// Render helpers
	// =========================================================================

	private function render_login_prompt( $login_url ) {
		?>
		<div class="myies-sustaining-select myies-sustaining-login">
			<h4><?php esc_html_e( 'Sustaining Membership — Company Required', 'wicket-integration' ); ?></h4>
			<p><?php esc_html_e( 'A sustaining membership is tied to an organization. If you are an existing member, please log in to continue.', 'wicket-integration' ); ?></p>
			<a class="button" href="<?php echo esc_url( $login_url ); ?>">
				<?php esc_html_e( 'Log In', 'wicket-integration' ); ?>
			</a>
		</div>
		<?php
	}

	private function render_widget( $user_id, $companies, $primary, $confirmed_uuid, $person_uuid ) {
		// Enqueue company assets (search / create use the same AJAX endpoints)
		if ( function_exists( 'wicket_company_enqueue_assets' ) ) {
			wicket_company_enqueue_assets();
		}

		$nonce    = wp_create_nonce( 'myies_sustaining_org_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<div class="myies-sustaining-select" id="myies-sustaining-company-select"
		     data-nonce="<?php echo esc_attr( $nonce ); ?>"
		     data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
		     data-org-confirmed="<?php echo $confirmed_uuid ? '1' : '0'; ?>">

			<h4><?php esc_html_e( 'Select Your Organization', 'wicket-integration' ); ?></h4>
			<p class="description"><?php esc_html_e( 'This sustaining membership will be applied to the organization you select below.', 'wicket-integration' ); ?></p>

			<?php if ( $confirmed_uuid ) : ?>
				<?php
				$confirmed_name = '';
				foreach ( $companies as $c ) {
					if ( $c['org_uuid'] === $confirmed_uuid ) {
						$confirmed_name = $c['legal_name'];
						break;
					}
				}
				?>
				<div class="myies-sustaining-confirmed">
					<span class="dashicons dashicons-yes-alt" style="color:green;"></span>
					<?php
					printf(
						esc_html__( 'Organization confirmed: %s', 'wicket-integration' ),
						'<strong>' . esc_html( $confirmed_name ?: $confirmed_uuid ) . '</strong>'
					);
					?>
					<button type="button" class="button button-small myies-sustaining-change">
						<?php esc_html_e( 'Change', 'wicket-integration' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<!-- Selection form (hidden if already confirmed) -->
			<div class="myies-sustaining-form" <?php echo $confirmed_uuid ? 'style="display:none;"' : ''; ?>>

				<?php if ( ! empty( $companies ) ) : ?>
				<!-- Existing companies -->
				<div class="myies-sustaining-existing">
					<label><?php esc_html_e( 'Your organizations:', 'wicket-integration' ); ?></label>
					<select id="myies-sustaining-org-select">
						<?php foreach ( $companies as $c ) : ?>
						<option value="<?php echo esc_attr( $c['org_uuid'] ); ?>"
							<?php selected( $primary ? $primary['org_uuid'] : '', $c['org_uuid'] ); ?>>
							<?php echo esc_html( $c['legal_name'] ); ?>
						</option>
						<?php endforeach; ?>
						<option value="__new"><?php esc_html_e( '— Add a new organization —', 'wicket-integration' ); ?></option>
					</select>

					<button type="button" class="myies-sustaining-confirm myies-btn myies-btn-primary">
						<?php esc_html_e( 'Confirm Organization', 'wicket-integration' ); ?>
					</button>
				</div>
				<?php endif; ?>

				<!-- New company form (shown when no companies or "Add new" selected) -->
				<div class="myies-sustaining-new" <?php echo ! empty( $companies ) ? 'style="display:none;"' : ''; ?>>
					<label><?php esc_html_e( 'Search for your organization or create a new one:', 'wicket-integration' ); ?></label>

					<div class="myies-sustaining-search-wrap">
						<input type="text" id="myies-sustaining-search" placeholder="<?php esc_attr_e( 'Start typing to search...', 'wicket-integration' ); ?>" autocomplete="off">
						<div id="myies-sustaining-search-results" style="display:none;"></div>
					</div>

					<p style="margin-top:10px;"><strong><?php esc_html_e( 'Or create a new organization:', 'wicket-integration' ); ?></strong></p>
					<input type="text" id="myies-sustaining-new-name" placeholder="<?php esc_attr_e( 'Organization name', 'wicket-integration' ); ?>">
					<select id="myies-sustaining-new-type">
						<option value=""><?php esc_html_e( '-- Organization Type --', 'wicket-integration' ); ?></option>
						<?php
						$types = function_exists( 'wicket_get_org_types' ) ? wicket_get_org_types() : array();
						foreach ( $types as $t ) :
						?>
						<option value="<?php echo esc_attr( $t['value'] ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button myies-sustaining-create">
						<?php esc_html_e( 'Create & Select', 'wicket-integration' ); ?>
					</button>
				</div>
			</div>

			<!-- Status message -->
			<div class="myies-sustaining-message" style="display:none;"></div>
		</div>

		<script>
		(function(){
			var wrap     = document.getElementById('myies-sustaining-company-select');
			if (!wrap) return;
			var nonce    = wrap.dataset.nonce;
			var ajaxUrl  = wrap.dataset.ajaxUrl;
			var orgConfirmed = wrap.dataset.orgConfirmed === '1';
			var companyNonce = typeof wicketCompanyConfig !== 'undefined' ? wicketCompanyConfig.nonce : '';

			// ----- SureCart checkout button gate -----
			// Common SureCart web-component selectors for checkout/buy buttons
			var scButtonSelectors = [
				'sc-order-submit',
				'sc-buy-button',
				'sc-checkout-button',
				'sc-product-buy-button',
				'.sc-buy-button',
				'[data-sc-buy-button]'
			].join(',');

			function setCheckoutButtons(enabled) {
				var buttons = document.querySelectorAll(scButtonSelectors);
				buttons.forEach(function(btn) {
					if (enabled) {
						btn.removeAttribute('disabled');
						btn.style.opacity = '';
						btn.style.pointerEvents = '';
						// Remove overlay if we added one
						var overlay = btn.parentElement ? btn.parentElement.querySelector('.myies-checkout-gate-overlay') : null;
						if (overlay) overlay.remove();
					} else {
						btn.setAttribute('disabled', 'true');
						btn.style.opacity = '0.5';
						btn.style.pointerEvents = 'none';
						// Add a message overlay
						if (btn.parentElement && !btn.parentElement.querySelector('.myies-checkout-gate-overlay')) {
							var overlay = document.createElement('div');
							overlay.className = 'myies-checkout-gate-overlay';
							overlay.textContent = 'Please confirm your organization above before checking out.';
							overlay.style.cssText = 'color:#666; font-size:13px; font-style:italic; margin-top:6px;';
							btn.parentElement.appendChild(overlay);
						}
					}
				});
			}

			// Run on load, and also after a short delay (SureCart components may render late)
			function gateCheckoutOnLoad() {
				if (!orgConfirmed) {
					setCheckoutButtons(false);
				}
			}
			gateCheckoutOnLoad();
			setTimeout(gateCheckoutOnLoad, 500);
			setTimeout(gateCheckoutOnLoad, 1500);

			// Also observe the DOM for late-rendered SureCart buttons
			if (!orgConfirmed && typeof MutationObserver !== 'undefined') {
				var observer = new MutationObserver(function(mutations) {
					var found = document.querySelectorAll(scButtonSelectors);
					if (found.length) {
						setCheckoutButtons(false);
					}
				});
				observer.observe(document.body, { childList: true, subtree: true });
				// Stop observing after 10 seconds
				setTimeout(function(){ observer.disconnect(); }, 10000);
			}

			// Toggle new-company form based on dropdown
			var selectEl = document.getElementById('myies-sustaining-org-select');
			if (selectEl) {
				selectEl.addEventListener('change', function(){
					wrap.querySelector('.myies-sustaining-new').style.display = this.value === '__new' ? '' : 'none';
				});
			}

			// "Change" button
			var changeBtn = wrap.querySelector('.myies-sustaining-change');
			if (changeBtn) {
				changeBtn.addEventListener('click', function(){
					wrap.querySelector('.myies-sustaining-confirmed').style.display = 'none';
					wrap.querySelector('.myies-sustaining-form').style.display = '';
					orgConfirmed = false;
					setCheckoutButtons(false);
				});
			}

			// Confirm existing company
			var confirmBtn = wrap.querySelector('.myies-sustaining-confirm');
			if (confirmBtn) {
				confirmBtn.addEventListener('click', function(){
					var uuid = selectEl.value;
					if (!uuid || uuid === '__new') return;
					saveSelection(uuid);
				});
			}

			// Search autocomplete
			var searchInput  = document.getElementById('myies-sustaining-search');
			var resultsDiv   = document.getElementById('myies-sustaining-search-results');
			var searchTimer  = null;

			if (searchInput) {
				searchInput.addEventListener('input', function(){
					clearTimeout(searchTimer);
					var term = this.value.trim();
					if (term.length < 2) { resultsDiv.style.display = 'none'; return; }
					searchTimer = setTimeout(function(){
						var fd = new FormData();
						fd.append('action', 'wicket_search_companies');
						fd.append('nonce', companyNonce);
						fd.append('search', term);
						fetch(ajaxUrl, {method:'POST', body: fd})
							.then(function(r){ return r.json(); })
							.then(function(d){
								if (!d.success || !d.data.results.length) {
									resultsDiv.style.display = 'none'; return;
								}
								resultsDiv.innerHTML = '';
								d.data.results.forEach(function(org){
									var row = document.createElement('div');
									row.className = 'myies-sustaining-result-item';
									row.textContent = org.legal_name;
									row.style.cursor = 'pointer';
									row.style.padding = '6px 8px';
									row.addEventListener('click', function(){ saveSelection(org.wicket_uuid); });
									resultsDiv.appendChild(row);
								});
								resultsDiv.style.display = '';
							});
					}, 300);
				});
			}

			// Create new company then confirm
			var createBtn = wrap.querySelector('.myies-sustaining-create');
			if (createBtn) {
				createBtn.addEventListener('click', function(){
					var name = document.getElementById('myies-sustaining-new-name').value.trim();
					var type = document.getElementById('myies-sustaining-new-type').value;
					if (!name || !type) { showMsg('Please enter a name and select a type.', true); return; }

					var fd = new FormData();
					fd.append('action', 'wicket_create_new_company');
					fd.append('nonce', companyNonce);
					fd.append('legal_name', name);
					fd.append('type', type);
					fetch(ajaxUrl, {method:'POST', body:fd})
						.then(function(r){ return r.json(); })
						.then(function(d){
							if (d.success && d.data.org_uuid) {
								saveSelection(d.data.org_uuid);
							} else {
								showMsg(d.data && d.data.message ? d.data.message : 'Failed to create organization.', true);
							}
						});
				});
			}

			function saveSelection(uuid){
				var fd = new FormData();
				fd.append('action', 'myies_confirm_sustaining_org');
				fd.append('nonce', nonce);
				fd.append('org_uuid', uuid);
				fetch(ajaxUrl, {method:'POST', body:fd})
					.then(function(r){ return r.json(); })
					.then(function(d){
						if (d.success) {
							orgConfirmed = true;
							// Enable checkout buttons
							setCheckoutButtons(true);
							// Stop MutationObserver if still running
							if (typeof observer !== 'undefined') observer.disconnect();
							// Update UI inline instead of reloading
							showMsg(d.data.message, false);
							var form = wrap.querySelector('.myies-sustaining-form');
							if (form) form.style.display = 'none';
							// Show confirmed banner
							var existing = wrap.querySelector('.myies-sustaining-confirmed');
							if (existing) {
								existing.style.display = '';
							} else {
								var banner = document.createElement('div');
								banner.className = 'myies-sustaining-confirmed';
								banner.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color:green;"></span> Organization confirmed. <button type="button" class="button button-small myies-sustaining-change">Change</button>';
								wrap.insertBefore(banner, form);
								banner.querySelector('.myies-sustaining-change').addEventListener('click', function(){
									banner.style.display = 'none';
									form.style.display = '';
									orgConfirmed = false;
									setCheckoutButtons(false);
								});
							}
						} else {
							showMsg(d.data && d.data.message ? d.data.message : 'Error saving selection.', true);
						}
					});
			}

			function showMsg(text, isError){
				var el = wrap.querySelector('.myies-sustaining-message');
				el.textContent = text;
				el.style.display = '';
				el.style.color = isError ? '#a00' : '#080';
			}
		})();
		</script>

		<style>
		.myies-sustaining-select { max-width: 600px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; margin: 20px 0; }
		.myies-sustaining-select h4 { margin-top: 0; }
		.myies-sustaining-select label { display: block; font-weight: 600; margin-bottom: 6px; }
		.myies-sustaining-select select,
		.myies-sustaining-select input[type="text"] { width: 100%; margin-bottom: 10px; padding: 8px; }
		.myies-sustaining-confirmed { padding: 12px; background: #eaffea; border: 1px solid #8c8; border-radius: 4px; margin-bottom: 12px; }
		.myies-sustaining-confirmed .button { margin-left: 10px; }
		.myies-sustaining-message { margin-top: 10px; font-weight: 600; }
		.myies-sustaining-search-wrap { position: relative; }
		#myies-sustaining-search-results { position: absolute; z-index: 100; background: #fff; border: 1px solid #ccc; width: 100%; max-height: 200px; overflow-y: auto; }
		#myies-sustaining-search-results .myies-sustaining-result-item:hover { background: #f0f0f0; }
		/* Button styles */
		.myies-btn { display: inline-block; padding: 10px 24px; font-size: 14px; font-weight: 600; line-height: 1.4; text-align: center; white-space: nowrap; vertical-align: middle; cursor: pointer; border: 1px solid transparent; border-radius: 4px; text-decoration: none; transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out; }
		.myies-btn-primary { color: #fff; background-color: #0073aa; border-color: #0073aa; }
		.myies-btn-primary:hover { background-color: #005a87; border-color: #005a87; color: #fff; }
		.myies-btn-primary:focus { outline: 2px solid #0073aa; outline-offset: 2px; }
		.myies-sustaining-confirm { margin-top: 4px; }
		</style>
		<?php
	}

	// =========================================================================
	// AJAX handler — save the selected org to a transient
	// =========================================================================

	public function ajax_confirm_org() {
		check_ajax_referer( 'myies_sustaining_org_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Not logged in' ) );
		}

		$org_uuid = isset( $_POST['org_uuid'] ) ? sanitize_text_field( $_POST['org_uuid'] ) : '';
		if ( empty( $org_uuid ) ) {
			wp_send_json_error( array( 'message' => 'Organization UUID required' ) );
		}

		$user_id = get_current_user_id();

		// Store for 2 hours — consumed by sync_sustaining_membership()
		set_transient( 'myies_checkout_org_' . $user_id, $org_uuid, 2 * HOUR_IN_SECONDS );

		// Also persist in user meta as a permanent fallback
		update_user_meta( $user_id, 'wicket_primary_org_uuid', $org_uuid );

		wp_send_json_success( array(
			'message'  => __( 'Organization confirmed.', 'wicket-integration' ),
			'org_uuid' => $org_uuid,
		) );
	}
}

// Initialize
add_action( 'init', array( 'MyIES_Sustaining_Company_Select', 'get_instance' ) );
