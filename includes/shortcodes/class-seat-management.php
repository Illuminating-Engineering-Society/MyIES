<?php
/**
 * Seat Management Shortcode
 *
 * Allows a user with a "primary-contact" or "sustaining_benefits_contact"
 * connection type to view and manage membership seats assigned to people
 * in their organization.
 *
 * Shortcode: [myies_seat_management]
 *
 * Capabilities:
 *  - View seat capacity (used / total)
 *  - View all people currently assigned a seat
 *  - Assign a seat to an existing org member
 *  - Remove a seat from a person (without removing them from the org)
 *
 * @package MyIES_Integration
 * @since   1.0.19
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyIES_Seat_Management {

	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'myies_seat_management', array( $this, 'render' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_myies_seats_get_data', array( $this, 'ajax_get_data' ) );
		add_action( 'wp_ajax_myies_seats_get_org_members', array( $this, 'ajax_get_org_members' ) );
		add_action( 'wp_ajax_myies_seats_assign', array( $this, 'ajax_assign_seat' ) );
		add_action( 'wp_ajax_myies_seats_remove', array( $this, 'ajax_remove_seat' ) );

		// Register assets
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style(
			'myies-seat-management',
			WICKET_INTEGRATION_PLUGIN_URL . 'assets/css/seat-management.css',
			array(),
			WICKET_INTEGRATION_VERSION
		);
		wp_register_script(
			'myies-seat-management',
			WICKET_INTEGRATION_PLUGIN_URL . 'assets/js/seat-management.js',
			array( 'jquery' ),
			WICKET_INTEGRATION_VERSION,
			true
		);
	}

	// =========================================================================
	// Authorization (same logic as org management)
	// =========================================================================

	private function check_authorization() {
		$result = array(
			'authorized'  => false,
			'can_manage'  => false,
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

		$org_uuid = get_user_meta( $user_id, 'wicket_primary_org_uuid', true );
		if ( empty( $org_uuid ) ) {
			$result['reason'] = 'no_organization';
			return $result;
		}
		$result['org_uuid'] = $org_uuid;
		$result['org_name'] = get_user_meta( $user_id, 'wicket_org_name', true ) ?: $org_uuid;

		// Check connection type
		$api        = wicket_api();
		$connection = $this->person_has_org_connection( $api, $person_uuid, $org_uuid );

		if ( ! $connection ) {
			$result['reason'] = 'no_role';
			return $result;
		}

		$management_types = array( 'primary-contact', 'sustaining_benefits_contact' );
		if ( in_array( $connection['connection_type'], $management_types, true ) ) {
			$result['can_manage'] = true;
		}

		// Check active org membership
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

	private function person_has_org_connection( $api, $person_uuid, $org_uuid ) {
		$connections = $api->get_person_connections( $person_uuid );

		foreach ( $connections as $conn ) {
			$to_id   = $conn['relationships']['to']['data']['id'] ?? '';
			$to_type = $conn['relationships']['to']['data']['type'] ?? '';

			if ( $to_type !== 'organizations' || $to_id !== $org_uuid ) {
				continue;
			}

			$ends_at = $conn['attributes']['ends_at'] ?? null;
			if ( $ends_at && strtotime( $ends_at ) < time() ) {
				continue;
			}

			return array( 'connection_type' => $conn['attributes']['type'] ?? '' );
		}

		return false;
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

		if ( ! $auth['can_manage'] ) {
			echo '<div class="myies-seats myies-seats--unauthorized">';
			echo '<p>' . esc_html__( 'You do not have permission to manage membership seats.', 'wicket-integration' ) . '</p>';
			echo '</div>';
			return ob_get_clean();
		}

		wp_enqueue_style( 'myies-seat-management' );
		wp_enqueue_script( 'myies-seat-management' );
		wp_localize_script( 'myies-seat-management', 'myiesSeats', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'myies_seats_nonce' ),
			'orgUuid' => $auth['org_uuid'],
			'i18n'    => array(
				'confirm_remove'     => __( 'Remove this person\'s membership seat?', 'wicket-integration' ),
				'removing'           => __( 'Removing...', 'wicket-integration' ),
				'assigning'          => __( 'Assigning...', 'wicket-integration' ),
				'no_results'         => __( 'No eligible members found.', 'wicket-integration' ),
				'search_placeholder' => __( 'Search org members by name or email...', 'wicket-integration' ),
			),
		) );

		$this->render_ui( $auth );

		return ob_get_clean();
	}

	private function render_unauthorized( $auth ) {
		?>
		<div class="myies-seats myies-seats--unauthorized">
		<?php
		switch ( $auth['reason'] ) {
			case 'not_logged_in':
				echo '<p>' . esc_html__( 'Please log in to manage membership seats.', 'wicket-integration' ) . '</p>';
				echo '<a class="button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log In', 'wicket-integration' ) . '</a>';
				break;
			case 'no_wicket_account':
				echo '<p>' . esc_html__( 'Your account is not yet linked to the member directory.', 'wicket-integration' ) . '</p>';
				break;
			case 'no_organization':
				echo '<p>' . esc_html__( 'You are not associated with an organization.', 'wicket-integration' ) . '</p>';
				break;
			case 'no_role':
				echo '<p>' . esc_html__( 'You are not connected to this organization.', 'wicket-integration' ) . '</p>';
				break;
			case 'no_active_membership':
				echo '<p>' . esc_html__( 'Your organization does not have an active sustaining membership.', 'wicket-integration' ) . '</p>';
				break;
		}
		?>
		</div>
		<?php
	}

	private function render_ui( $auth ) {
		?>
		<div class="myies-seats" id="myies-seats">
			<div class="myies-seats__header">
				<h3><?php printf( esc_html__( 'Membership Seats: %s', 'wicket-integration' ), esc_html( $auth['org_name'] ) ); ?></h3>
			</div>

			<!-- Seat summary -->
			<div id="myies-seats-summary" class="myies-seats__summary">
				<p class="myies-seats__loading"><?php esc_html_e( 'Loading seat information...', 'wicket-integration' ); ?></p>
			</div>

			<!-- Assign seat panel (hidden by default) -->
			<div id="myies-seats-assign-section" class="myies-seats__assign" style="display:none;">
				<button type="button" class="myies-seats__close-btn" id="myies-seats-close-assign" aria-label="<?php esc_attr_e( 'Close', 'wicket-integration' ); ?>">&times;</button>
				<h4><?php esc_html_e( 'Assign a Seat', 'wicket-integration' ); ?></h4>
				<div class="myies-seats__search-wrap">
					<input type="text" id="myies-seats-search" autocomplete="off"
					       placeholder="<?php esc_attr_e( 'Search org members by name or email...', 'wicket-integration' ); ?>"
					       minlength="3">
					<div id="myies-seats-search-results" class="myies-seats__search-results"></div>
				</div>
				<div id="myies-seats-assign-message" class="myies-seats__message" style="display:none;"></div>
			</div>

			<!-- Seated people list -->
			<div class="myies-seats__list">
				<div id="myies-seats-members" class="myies-seats__members">
					<p class="myies-seats__loading"><?php esc_html_e( 'Loading...', 'wicket-integration' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX: Get seat data (summary + seated people)
	// =========================================================================

	public function ajax_get_data() {
		check_ajax_referer( 'myies_seats_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] || ! $auth['can_manage'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		try {
			$svc = new Wicket_Membership_Service();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Could not initialize membership service.' ) );
		}

		$active_memberships = $svc->find_all_active_org_memberships( $auth['org_uuid'] );
		if ( empty( $active_memberships ) ) {
			wp_send_json_success( array(
				'has_membership' => false,
				'seated'         => array(),
			) );
			return;
		}

		$org_membership          = $active_memberships[0];
		$org_membership_uuid     = $org_membership['id'];
		$max_assignments         = $org_membership['max_assignments'] ?? null;
		$unlimited_assignments   = ! empty( $org_membership['unlimited_assignments'] );

		// Get current seat assignments
		$assignments = $svc->get_org_membership_assignments( $org_membership_uuid );

		// Enrich assignments with person name/email from Wicket
		$api    = wicket_api();
		$seated = array();
		foreach ( $assignments as $a ) {
			$person_uuid = $a['person_uuid'] ?? '';
			if ( empty( $person_uuid ) ) {
				continue;
			}

			$name  = $person_uuid;
			$email = '';

			// Try to get person details from Wicket
			$person = $api->get_person( $person_uuid );
			if ( $person && ! is_wp_error( $person ) ) {
				$given  = $person['attributes']['given_name'] ?? '';
				$family = $person['attributes']['family_name'] ?? '';
				$name   = trim( $given . ' ' . $family ) ?: $person_uuid;
				$email  = $person['attributes']['primary_email_address'] ?? '';
			}

			$seated[] = array(
				'person_membership_uuid' => $a['id'],
				'person_uuid'            => $person_uuid,
				'name'                   => $name,
				'email'                  => $email,
				'starts_at'              => $a['starts_at'],
				'ends_at'                => $a['ends_at'],
			);
		}

		wp_send_json_success( array(
			'has_membership'        => true,
			'org_membership_uuid'   => $org_membership_uuid,
			'max_assignments'       => $max_assignments,
			'unlimited_assignments' => $unlimited_assignments,
			'total_seated'          => count( $seated ),
			'seated'                => $seated,
		) );
	}

	// =========================================================================
	// AJAX: Get org members eligible for seat assignment
	// =========================================================================

	public function ajax_get_org_members() {
		check_ajax_referer( 'myies_seats_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] || ! $auth['can_manage'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		if ( strlen( $search ) < 3 ) {
			wp_send_json_success( array( 'results' => array() ) );
			return;
		}

		// Get all org members
		$api     = wicket_api();
		$members = $api->get_organization_members( $auth['org_uuid'] );

		// Get current seat holders to mark them
		try {
			$svc                 = new Wicket_Membership_Service();
			$active_memberships  = $svc->find_all_active_org_memberships( $auth['org_uuid'] );
			$seated_person_uuids = array();

			if ( ! empty( $active_memberships ) ) {
				$assignments = $svc->get_org_membership_assignments( $active_memberships[0]['id'] );
				foreach ( $assignments as $a ) {
					if ( ! empty( $a['person_uuid'] ) ) {
						$seated_person_uuids[ $a['person_uuid'] ] = true;
					}
				}
			}
		} catch ( Exception $e ) {
			$seated_person_uuids = array();
		}

		$search_lower = strtolower( $search );
		$results      = array();

		foreach ( $members as $conn ) {
			// Skip ended connections
			$ends_at = $conn['attributes']['ends_at'] ?? null;
			if ( $ends_at && strtotime( $ends_at ) < time() ) {
				continue;
			}

			$person      = $conn['_person'] ?? null;
			$person_uuid = $conn['relationships']['from']['data']['id'] ?? '';
			$given_name  = $person['attributes']['given_name'] ?? '';
			$family_name = $person['attributes']['family_name'] ?? '';
			$full_name   = trim( $given_name . ' ' . $family_name );
			$email       = $person['attributes']['primary_email_address'] ?? '';

			// Skip people who already have a seat
			if ( isset( $seated_person_uuids[ $person_uuid ] ) ) {
				continue;
			}

			// Filter by search term
			if ( strpos( strtolower( $full_name ), $search_lower ) === false &&
			     strpos( strtolower( $email ), $search_lower ) === false ) {
				continue;
			}

			$results[] = array(
				'person_uuid' => $person_uuid,
				'name'        => $full_name ?: __( 'Unknown', 'wicket-integration' ),
				'email'       => $email,
			);

			if ( count( $results ) >= 10 ) {
				break;
			}
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	// =========================================================================
	// AJAX: Assign a seat
	// =========================================================================

	public function ajax_assign_seat() {
		check_ajax_referer( 'myies_seats_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] || ! $auth['can_manage'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$person_uuid = isset( $_POST['person_uuid'] ) ? sanitize_text_field( $_POST['person_uuid'] ) : '';
		if ( empty( $person_uuid ) ) {
			wp_send_json_error( array( 'message' => __( 'Person UUID required.', 'wicket-integration' ) ) );
		}

		try {
			$svc = new Wicket_Membership_Service();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Could not initialize membership service.' ) );
		}

		$active_memberships = $svc->find_all_active_org_memberships( $auth['org_uuid'] );
		if ( empty( $active_memberships ) ) {
			wp_send_json_error( array( 'message' => __( 'No active organization membership found.', 'wicket-integration' ) ) );
		}

		$org_membership = $active_memberships[0];

		// Check if already assigned
		$assignments = $svc->get_org_membership_assignments( $org_membership['id'] );
		foreach ( $assignments as $a ) {
			if ( $a['person_uuid'] === $person_uuid ) {
				wp_send_json_error( array( 'message' => __( 'This person already has a seat assigned.', 'wicket-integration' ) ) );
			}
		}

		// Check seat capacity
		$max       = $org_membership['max_assignments'] ?? null;
		$unlimited = ! empty( $org_membership['unlimited_assignments'] );
		if ( ! $unlimited && $max !== null && count( $assignments ) >= (int) $max ) {
			wp_send_json_error( array( 'message' => __( 'All seats are occupied. No seats available.', 'wicket-integration' ) ) );
		}

		$result = $svc->assign_person_to_org_membership(
			$person_uuid,
			$org_membership['id'],
			$org_membership['starts_at'],
			$org_membership['ends_at']
		);

		if ( is_wp_error( $result ) ) {
			error_log( '[SeatMgmt] Failed to assign seat to ' . $person_uuid . ': ' . $result->get_error_message() );
			wp_send_json_error( array( 'message' => __( 'Failed to assign seat.', 'wicket-integration' ) ) );
		}

		error_log( '[SeatMgmt] Assigned seat to person ' . $person_uuid . ' on org membership ' . $org_membership['id'] );
		wp_send_json_success( array( 'message' => __( 'Seat assigned successfully.', 'wicket-integration' ) ) );
	}

	// =========================================================================
	// AJAX: Remove a seat
	// =========================================================================

	public function ajax_remove_seat() {
		check_ajax_referer( 'myies_seats_nonce', 'nonce' );
		$auth = $this->check_authorization();
		if ( ! $auth['authorized'] || ! $auth['can_manage'] ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$person_membership_uuid = isset( $_POST['person_membership_uuid'] ) ? sanitize_text_field( $_POST['person_membership_uuid'] ) : '';
		if ( empty( $person_membership_uuid ) ) {
			wp_send_json_error( array( 'message' => __( 'Membership UUID required.', 'wicket-integration' ) ) );
		}

		try {
			$svc = new Wicket_Membership_Service();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => 'Could not initialize membership service.' ) );
		}

		$now    = current_time( 'c' );
		$result = $svc->update_membership( $person_membership_uuid, null, $now );

		if ( is_wp_error( $result ) ) {
			error_log( '[SeatMgmt] Failed to remove seat ' . $person_membership_uuid . ': ' . $result->get_error_message() );
			wp_send_json_error( array( 'message' => __( 'Failed to remove seat.', 'wicket-integration' ) ) );
		}

		error_log( '[SeatMgmt] Removed seat ' . $person_membership_uuid );
		wp_send_json_success( array( 'message' => __( 'Seat removed successfully.', 'wicket-integration' ) ) );
	}
}

// Initialize
add_action( 'init', array( 'MyIES_Seat_Management', 'get_instance' ) );
