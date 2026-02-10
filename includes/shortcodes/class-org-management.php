<?php
/**
 * Organization Management Shortcode
 *
 * Allows a user with "Company - Primary Contact" role and an active sustaining
 * membership to manage the people connected to their organization.
 *
 * Shortcode: [myies_org_management]
 *
 * Capabilities:
 *  - View all people connected to the organization (with roles)
 *  - Add an existing WordPress user to the organization via email search
 *  - Soft-remove a person from the organization (sets ends_at = now)
 *
 * @package MyIES_Integration
 * @since   1.0.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyIES_Org_Management {

	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'myies_org_management', array( $this, 'render' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_myies_orgmgmt_get_members', array( $this, 'ajax_get_members' ) );
		add_action( 'wp_ajax_myies_orgmgmt_search_users', array( $this, 'ajax_search_users' ) );
		add_action( 'wp_ajax_myies_orgmgmt_add_member', array( $this, 'ajax_add_member' ) );
		add_action( 'wp_ajax_myies_orgmgmt_remove_member', array( $this, 'ajax_remove_member' ) );

		// Register assets
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style(
			'myies-org-management',
			WICKET_INTEGRATION_PLUGIN_URL . 'assets/css/org-management.css',
			array(),
			WICKET_INTEGRATION_VERSION
		);
		wp_register_script(
			'myies-org-management',
			WICKET_INTEGRATION_PLUGIN_URL . 'assets/js/org-management.js',
			array( 'jquery' ),
			WICKET_INTEGRATION_VERSION,
			true
		);
	}

	// =========================================================================
	// Authorization helpers
	// =========================================================================

	/**
	 * Check if the current user is authorized to manage the given org.
	 *
	 * Requirements:
	 *  1. User has "Company - Primary Contact" role in Wicket.
	 *  2. The organization has an active sustaining (org) membership.
	 *
	 * @return array ['authorized' => bool, 'reason' => string, 'org_uuid' => string, 'person_uuid' => string]
	 */
	private function check_authorization() {
		$result = array(
			'authorized'  => false,
			'reason'      => '',
			'org_uuid'    => '',
			'person_uuid' => '',
			'org_name'    => '',
		);

		if ( ! is_user_logged_in() ) {
			$result['reason'] = 'not_logged_in';
			return $result;
		}

		$user_id     = get_current_user_id();
		$person_uuid = get_user_meta( $user_id, 'wicket_person_uuid', true );
		if ( empty( $person_uuid ) ) {
			$person_uuid = get_user_meta( $user_id, 'wicket_uuid', true );
		}
		if ( empty( $person_uuid ) ) {
			$result['reason'] = 'no_wicket_account';
			return $result;
		}
		$result['person_uuid'] = $person_uuid;

		// Get primary organization
		$org_uuid = get_user_meta( $user_id, 'wicket_primary_org_uuid', true );
		if ( empty( $org_uuid ) ) {
			$result['reason'] = 'no_organization';
			return $result;
		}
		$result['org_uuid'] = $org_uuid;
		$result['org_name'] = get_user_meta( $user_id, 'wicket_org_name', true ) ?: $org_uuid;

		// 1. Check role
		$api = wicket_api();
		$has_role = $api->person_has_role( $person_uuid, 'Company - Primary Contact' );
		if ( ! $has_role ) {
			$result['reason'] = 'no_role';
			return $result;
		}

		// 2. Check active org membership
		if ( class_exists( 'Wicket_Memberships' ) ) {
			$memberships = Wicket_Memberships::get_instance();
			$active_org  = $memberships->get_active_org_membership( $user_id );
			if ( empty( $active_org ) ) {
				$result['reason'] = 'no_active_membership';
				return $result;
			}
		}

		$result['authorized'] = true;
		return $result;
	}

	// =========================================================================
	// Shortcode output
	// =========================================================================

	public function render( $atts ) {
		$auth = $this->check_authorization();

		ob_start();

		if ( ! $auth['authorized'] ) {
			$this->render_unauthorized( $auth );
			return ob_get_clean();
		}

		// Enqueue assets
		wp_enqueue_style( 'myies-org-management' );
		wp_enqueue_script( 'myies-org-management' );
		wp_localize_script( 'myies-org-management', 'myiesOrgMgmt', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'myies_orgmgmt_nonce' ),
			'orgUuid' => $auth['org_uuid'],
			'i18n'    => array(
				'confirm_remove' => __( 'Remove this person from the organization?', 'wicket-integration' ),
				'adding'         => __( 'Adding...', 'wicket-integration' ),
				'removing'       => __( 'Removing...', 'wicket-integration' ),
				'no_results'     => __( 'No users found.', 'wicket-integration' ),
				'min_chars'      => __( 'Type at least 5 characters to search.', 'wicket-integration' ),
				'search_placeholder' => __( 'Search by email address...', 'wicket-integration' ),
			),
		) );

		$this->render_management_ui( $auth );

		return ob_get_clean();
	}

	private function render_unauthorized( $auth ) {
		?>
		<div class="myies-orgmgmt myies-orgmgmt--unauthorized">
		<?php
		switch ( $auth['reason'] ) {
			case 'not_logged_in':
				echo '<p>' . esc_html__( 'Please log in to manage your organization.', 'wicket-integration' ) . '</p>';
				echo '<a class="button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log In', 'wicket-integration' ) . '</a>';
				break;
			case 'no_wicket_account':
				echo '<p>' . esc_html__( 'Your account is not yet linked to the member directory.', 'wicket-integration' ) . '</p>';
				break;
			case 'no_organization':
				echo '<p>' . esc_html__( 'You are not associated with an organization.', 'wicket-integration' ) . '</p>';
				break;
			case 'no_role':
				echo '<p>' . esc_html__( 'You do not have the required role to manage this organization. The Primary Contact role is required.', 'wicket-integration' ) . '</p>';
				break;
			case 'no_active_membership':
				echo '<p>' . esc_html__( 'Your organization does not have an active sustaining membership.', 'wicket-integration' ) . '</p>';
				break;
		}
		?>
		</div>
		<?php
	}

	private function render_management_ui( $auth ) {
		?>
		<div class="myies-orgmgmt" id="myies-orgmgmt" data-org="<?php echo esc_attr( $auth['org_uuid'] ); ?>">
			<div class="myies-orgmgmt__header">
				<h3><?php printf( esc_html__( 'Organization: %s', 'wicket-integration' ), esc_html( $auth['org_name'] ) ); ?></h3>
			</div>

			<!-- Add member form -->
			<div class="myies-orgmgmt__add">
				<h4><?php esc_html_e( 'Add a Member', 'wicket-integration' ); ?></h4>
				<div class="myies-orgmgmt__search-wrap">
					<input type="text" id="myies-orgmgmt-search" autocomplete="off"
					       placeholder="<?php esc_attr_e( 'Search by email address...', 'wicket-integration' ); ?>"
					       minlength="5">
					<div id="myies-orgmgmt-search-results" class="myies-orgmgmt__search-results"></div>
				</div>
				<!-- Selected user confirmation -->
				<div id="myies-orgmgmt-selected" class="myies-orgmgmt__selected" style="display:none;">
					<div class="myies-orgmgmt__selected-info">
						<span id="myies-orgmgmt-selected-name"></span>
						<span id="myies-orgmgmt-selected-email" class="myies-orgmgmt__email"></span>
					</div>
					<div class="myies-orgmgmt__selected-actions">
						<select id="myies-orgmgmt-role">
							<option value="employee"><?php esc_html_e( 'Company - Employee', 'wicket-integration' ); ?></option>
						</select>
						<button type="button" class="button button-primary" id="myies-orgmgmt-add-btn">
							<?php esc_html_e( 'Add to Organization', 'wicket-integration' ); ?>
						</button>
						<button type="button" class="button" id="myies-orgmgmt-cancel-btn">
							<?php esc_html_e( 'Cancel', 'wicket-integration' ); ?>
						</button>
					</div>
				</div>
				<div id="myies-orgmgmt-add-message" class="myies-orgmgmt__message" style="display:none;"></div>
			</div>

			<!-- Members list -->
			<div class="myies-orgmgmt__list">
				<h4><?php esc_html_e( 'Current Members', 'wicket-integration' ); ?></h4>
				<div id="myies-orgmgmt-search-members-wrap" class="myies-orgmgmt__filter">
					<input type="text" id="myies-orgmgmt-filter" autocomplete="off"
					       placeholder="<?php esc_attr_e( 'Filter members...', 'wicket-integration' ); ?>">
				</div>
				<div id="myies-orgmgmt-members" class="myies-orgmgmt__members">
					<p class="myies-orgmgmt__loading"><?php esc_html_e( 'Loading members...', 'wicket-integration' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX: Get members
	// =========================================================================

	public function ajax_get_members() {
		check_ajax_referer( 'myies_orgmgmt_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$api     = wicket_api();
		$members = $api->get_organization_members( $auth['org_uuid'] );

		$formatted = array();
		foreach ( $members as $conn ) {
			// Skip inactive connections (already ended)
			$ends_at = $conn['attributes']['ends_at'] ?? null;
			if ( $ends_at && strtotime( $ends_at ) < time() ) {
				continue;
			}

			$person      = $conn['_person'] ?? null;
			$person_uuid = $conn['relationships']['from']['data']['id'] ?? '';
			$given_name  = $person['attributes']['given_name'] ?? '';
			$family_name = $person['attributes']['family_name'] ?? '';
			$full_name   = trim( $given_name . ' ' . $family_name );

			// Get primary email from person data
			$email = '';
			if ( $person && ! empty( $person['attributes']['primary_email_address'] ) ) {
				$email = $person['attributes']['primary_email_address'];
			}

			// Connection role
			$conn_type = $conn['attributes']['type'] ?? 'member';

			$formatted[] = array(
				'connection_uuid' => $conn['id'],
				'person_uuid'     => $person_uuid,
				'name'            => $full_name ?: __( 'Unknown', 'wicket-integration' ),
				'email'           => $email,
				'connection_type' => $conn_type,
				'roles'           => array(), // Will be enriched below
				'is_self'         => ( $person_uuid === $auth['person_uuid'] ),
			);
		}

		// Batch-fetch roles for the first 50 people (reasonable limit)
		$batch = array_slice( $formatted, 0, 50 );
		foreach ( $batch as $i => $m ) {
			if ( empty( $m['person_uuid'] ) ) {
				continue;
			}
			$roles      = $api->get_person_roles( $m['person_uuid'] );
			$role_names = array();
			foreach ( $roles as $r ) {
				$name = $r['attributes']['name'] ?? '';
				if ( $name && strpos( $name, 'Company' ) === 0 ) {
					$role_names[] = $name;
				}
			}
			$formatted[ $i ]['roles'] = $role_names;
		}

		wp_send_json_success( array( 'members' => $formatted ) );
	}

	// =========================================================================
	// AJAX: Search WordPress users by email
	// =========================================================================

	public function ajax_search_users() {
		check_ajax_referer( 'myies_orgmgmt_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_text_field( $_POST['email'] ) : '';
		if ( strlen( $email ) < 5 ) {
			wp_send_json_success( array( 'results' => array() ) );
			return;
		}

		// Search WP users by email LIKE
		global $wpdb;
		$like    = '%' . $wpdb->esc_like( $email ) . '%';
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, user_email, display_name FROM {$wpdb->users} WHERE user_email LIKE %s ORDER BY user_email ASC LIMIT 10",
			$like
		) );

		$formatted = array();
		foreach ( $results as $row ) {
			$person_uuid = get_user_meta( $row->ID, 'wicket_person_uuid', true );
			$formatted[] = array(
				'user_id'      => (int) $row->ID,
				'email'        => $row->user_email,
				'display_name' => $row->display_name,
				'person_uuid'  => $person_uuid ?: null,
			);
		}

		wp_send_json_success( array( 'results' => $formatted ) );
	}

	// =========================================================================
	// AJAX: Add member to organization
	// =========================================================================

	public function ajax_add_member() {
		check_ajax_referer( 'myies_orgmgmt_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$wp_user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$role       = isset( $_POST['role'] ) ? sanitize_text_field( $_POST['role'] ) : 'employee';

		if ( ! $wp_user_id ) {
			wp_send_json_error( array( 'message' => __( 'User ID required.', 'wicket-integration' ) ) );
		}

		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'User not found.', 'wicket-integration' ) ) );
		}

		// Resolve person UUID — create in Wicket if needed
		$person_uuid = get_user_meta( $wp_user_id, 'wicket_person_uuid', true );
		if ( empty( $person_uuid ) ) {
			try {
				$svc         = new Wicket_Membership_Service();
				$person_uuid = $svc->find_or_create_person( $wp_user_id );
				if ( is_wp_error( $person_uuid ) ) {
					wp_send_json_error( array( 'message' => $person_uuid->get_error_message() ) );
				}
			} catch ( Exception $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ) );
			}
		}

		$api    = wicket_api();
		$result = $api->create_person_org_connection( $person_uuid, $auth['org_uuid'], $role );

		if ( $result['success'] ) {
			wp_send_json_success( array(
				'message' => $result['already_existed']
					? __( 'This person is already connected to the organization.', 'wicket-integration' )
					: __( 'Member added successfully.', 'wicket-integration' ),
			) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Failed to add member.', 'wicket-integration' ) ) );
		}
	}

	// =========================================================================
	// AJAX: Remove (soft-end) member from organization
	// =========================================================================

	public function ajax_remove_member() {
		check_ajax_referer( 'myies_orgmgmt_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$connection_uuid = isset( $_POST['connection_uuid'] ) ? sanitize_text_field( $_POST['connection_uuid'] ) : '';
		if ( empty( $connection_uuid ) ) {
			wp_send_json_error( array( 'message' => __( 'Connection UUID required.', 'wicket-integration' ) ) );
		}

		$api    = wicket_api();
		$result = $api->update_connection( $connection_uuid, array(
			'ends_at' => current_time( 'c' ),
		) );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => __( 'Member removed from organization.', 'wicket-integration' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Failed to remove member.', 'wicket-integration' ) ) );
		}
	}
}

// Initialize
add_action( 'init', array( 'MyIES_Org_Management', 'get_instance' ) );
