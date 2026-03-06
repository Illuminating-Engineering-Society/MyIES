<?php
/**
 * My Awards Shortcode
 *
 * Shows the logged-in user's awards/accolades from Wicket data_fields.
 * Display-only — no management actions.
 *
 * Shortcode: [myies_my_awards]
 *
 * @package MyIES_Integration
 * @since   1.0.21
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyIES_My_Awards_Shortcode {

	private static $instance = null;

	/**
	 * Award slug → display name mapping.
	 */
	private static $award_labels = array(
		'distinguished_service'        => 'Distinguished Service Award',
		'fellow'                       => 'Fellow Award',
		'howard_brandston_stud_grant'  => 'Howard Brandston Student Grant',
		'louis_marks'                  => 'Louis B. Marks Award',
		'medal'                        => 'Medal Award',
		'president'                    => 'President Award',
		'regional_service'             => 'Regional Service Award',
		'regional_tech'                => 'Regional Technical Award',
		'salc_lifetime_service'        => 'SALC Lifetime Service Award',
		'section_meritorious_service'  => 'Section Meritorious Service Award',
		'section_service'              => 'Section Service Award',
		'taylor_tech_talent'           => 'Taylor Technical Talent Award',
	);

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'myies_my_awards', array( $this, 'render' ) );
		add_action( 'wp_head', array( $this, 'print_styles' ) );
	}

	/**
	 * Print inline styles for the awards list.
	 */
	public function print_styles() {
		?>
		<style id="myies-awards-styles">
			.myies-awards {
				max-width: 800px;
			}
			.myies-awards__list {
				list-style: none;
				margin: 0;
				padding: 0;
			}
			.myies-awards__item {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding: 12px 16px;
				border-bottom: 1px solid #e5e7eb;
			}
			.myies-awards__item:last-child {
				border-bottom: none;
			}
			.myies-awards__name {
				font-weight: 600;
				color: #1f2937;
			}
			.myies-awards__year {
				color: #6b7280;
				font-size: 0.9em;
				flex-shrink: 0;
				margin-left: 16px;
			}
		</style>
		<?php
	}

	/**
	 * Render the [myies_my_awards] shortcode.
	 */
	public function render( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="myies-awards"><p>' . esc_html__( 'Please log in to view your awards.', 'wicket-integration' ) . '</p></div>';
		}

		$user_id     = get_current_user_id();
		$person_uuid = get_user_meta( $user_id, 'wicket_person_uuid', true );
		if ( empty( $person_uuid ) ) {
			$person_uuid = get_user_meta( $user_id, 'wicket_uuid', true );
		}

		if ( empty( $person_uuid ) ) {
			return '<div class="myies-awards"><p>' . esc_html__( 'Your account is not linked to Wicket.', 'wicket-integration' ) . '</p></div>';
		}

		$api    = wicket_api();
		$person = $api->get_person( $person_uuid );

		if ( empty( $person ) ) {
			return '<div class="myies-awards"><p>' . esc_html__( 'Unable to retrieve your profile information.', 'wicket-integration' ) . '</p></div>';
		}

		$awards = $this->extract_awards( $person );

		if ( empty( $awards ) ) {
			return '<div class="myies-awards"><p>' . esc_html__( 'No awards or accolades yet.', 'wicket-integration' ) . '</p></div>';
		}

		// Sort by date descending (most recent first)
		usort( $awards, function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		} );

		ob_start();
		$this->render_awards( $awards );
		return ob_get_clean();
	}

	/**
	 * Extract awards from person data_fields.
	 *
	 * @param array $person Person data from Wicket API.
	 * @return array Array of [ 'slug' => string, 'name' => string, 'date' => string, 'year' => string ]
	 */
	private function extract_awards( $person ) {
		$data_fields = $person['attributes']['data_fields'] ?? array();
		$awards      = array();

		foreach ( $data_fields as $field ) {
			if ( ( $field['key'] ?? '' ) !== 'awards' ) {
				continue;
			}

			$repeats = $field['value']['award_repeat'] ?? array();
			foreach ( $repeats as $entry ) {
				$slug = $entry['award'] ?? '';
				$date = $entry['award_date'] ?? '';

				if ( empty( $slug ) ) {
					continue;
				}

				$awards[] = array(
					'slug' => $slug,
					'name' => $this->get_award_label( $slug ),
					'date' => $date,
					'year' => $date ? date( 'Y', strtotime( $date ) ) : '',
				);
			}
			break; // only one "awards" field expected
		}

		return $awards;
	}

	/**
	 * Get display name for an award slug.
	 */
	private function get_award_label( $slug ) {
		if ( isset( self::$award_labels[ $slug ] ) ) {
			return self::$award_labels[ $slug ];
		}
		// Fallback: title-case the slug
		return ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/**
	 * Render the awards list HTML.
	 */
	private function render_awards( $awards ) {
		?>
		<div class="myies-awards" id="myies-my-awards">
			<ul class="myies-awards__list">
				<?php foreach ( $awards as $award ) : ?>
				<li class="myies-awards__item">
					<span class="myies-awards__name"><?php echo esc_html( $award['name'] ); ?></span>
					<?php if ( ! empty( $award['year'] ) ) : ?>
						<span class="myies-awards__year"><?php echo esc_html( $award['year'] ); ?></span>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}

// Initialize
add_action( 'init', array( 'MyIES_My_Awards_Shortcode', 'get_instance' ) );
