<?php
/**
 * My Committees Shortcode
 *
 * Shows the logged-in user's Wicket group memberships (committees they belong to).
 * Display-only — no management actions.
 *
 * Shortcode: [myies_my_committees]
 *
 * Attributes:
 *   show_description — Show group descriptions. Default: "yes"
 *
 * @package MyIES_Integration
 * @since   1.0.20
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyIES_My_Committees_Shortcode {

	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'myies_my_committees', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style(
			'myies-committees',
			WICKET_INTEGRATION_PLUGIN_URL . 'assets/css/committees.css',
			array(),
			WICKET_INTEGRATION_VERSION
		);
	}

	/**
	 * Render the [myies_my_committees] shortcode.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( array(
			'show_description' => 'yes',
		), $atts, 'myies_my_committees' );

		if ( ! is_user_logged_in() ) {
			return '<div class="myies-committees"><p>' . esc_html__( 'Please log in to view your committees.', 'wicket-integration' ) . '</p></div>';
		}

		$user_id     = get_current_user_id();
		$person_uuid = get_user_meta( $user_id, 'wicket_uuid', true );
		if ( empty( $person_uuid ) ) {
			$person_uuid = get_user_meta( $user_id, 'wicket_person_uuid', true );
		}

		if ( empty( $person_uuid ) ) {
			return '<div class="myies-committees"><p>' . esc_html__( 'Your account is not linked to Wicket.', 'wicket-integration' ) . '</p></div>';
		}

		wp_enqueue_style( 'myies-committees' );

		$api      = wicket_api();
		$response = $api->get_person_group_memberships( $person_uuid );

		if ( empty( $response ) || empty( $response['data'] ) ) {
			return '<div class="myies-committees"><p>' . esc_html__( 'You are not currently a member of any committees.', 'wicket-integration' ) . '</p></div>';
		}

		// Build a lookup of sideloaded groups keyed by ID
		$groups_lookup = array();
		if ( ! empty( $response['included'] ) ) {
			foreach ( $response['included'] as $inc ) {
				if ( ( $inc['type'] ?? '' ) === 'groups' ) {
					$groups_lookup[ $inc['id'] ] = $inc;
				}
			}
		}

		// Build list of committees from group_members data + sideloaded groups
		$grouped = array();
		foreach ( $response['data'] as $membership ) {
			$group_rel = $membership['relationships']['group']['data'] ?? null;
			if ( ! $group_rel || ! isset( $groups_lookup[ $group_rel['id'] ] ) ) {
				continue;
			}

			$group = $groups_lookup[ $group_rel['id'] ];
			$type  = $group['attributes']['type'] ?? 'other';

			if ( ! isset( $grouped[ $type ] ) ) {
				$grouped[ $type ] = array();
			}

			$grouped[ $type ][] = array(
				'name'         => $group['attributes']['name'] ?? '',
				'description'  => $group['attributes']['description'] ?? '',
				'active'       => ! empty( $group['attributes']['active'] ),
				'member_count' => $group['attributes']['active_members_count'] ?? $group['attributes']['members_count'] ?? 0,
				'role'         => $membership['attributes']['role'] ?? '',
			);
		}

		if ( empty( $grouped ) ) {
			return '<div class="myies-committees"><p>' . esc_html__( 'You are not currently a member of any committees.', 'wicket-integration' ) . '</p></div>';
		}

		// Sort categories alphabetically
		ksort( $grouped );

		// Sort groups within each category alphabetically
		foreach ( $grouped as &$groups ) {
			usort( $groups, function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			} );
		}
		unset( $groups );

		$show_desc = ( 'yes' === $atts['show_description'] );

		ob_start();
		$this->render_groups( $grouped, $show_desc );
		return ob_get_clean();
	}

	/**
	 * Render grouped committee cards.
	 */
	private function render_groups( $grouped, $show_desc = true ) {
		?>
		<div class="myies-committees" id="myies-my-committees">
		<?php foreach ( $grouped as $type => $groups ) : ?>
			<div class="myies-committees__category" data-type="<?php echo esc_attr( $type ); ?>">
				<h3 class="myies-committees__category-heading">
					<?php echo esc_html( $this->format_type_label( $type ) ); ?>
					<span class="myies-committees__category-count">(<?php echo count( $groups ); ?>)</span>
				</h3>

				<div class="myies-committees__grid">
					<?php foreach ( $groups as $group ) : ?>
					<div class="myies-committees__card">
						<div class="myies-committees__card-header">
							<h4 class="myies-committees__card-name">
								<?php echo esc_html( $group['name'] ); ?>
							</h4>
							<?php if ( ! empty( $group['role'] ) ) : ?>
								<span class="myies-committees__badge myies-committees__badge--active">
									<?php echo esc_html( $this->format_type_label( $group['role'] ) ); ?>
								</span>
							<?php endif; ?>
						</div>

						<?php if ( $show_desc && ! empty( $group['description'] ) ) : ?>
							<p class="myies-committees__card-desc"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $group['description'] ), 30, '...' ) ); ?></p>
						<?php endif; ?>

						<div class="myies-committees__card-footer">
							<?php if ( $group['member_count'] > 0 ) : ?>
								<span class="myies-committees__member-count">
									<?php
									printf(
										esc_html( _n( '%s member', '%s members', $group['member_count'], 'wicket-integration' ) ),
										esc_html( number_format_i18n( $group['member_count'] ) )
									);
									?>
								</span>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Format a group type slug into a readable label.
	 */
	private function format_type_label( $type ) {
		return ucwords( str_replace( array( '_', '-' ), ' ', $type ) );
	}
}

// Initialize
add_action( 'init', array( 'MyIES_My_Committees_Shortcode', 'get_instance' ) );
