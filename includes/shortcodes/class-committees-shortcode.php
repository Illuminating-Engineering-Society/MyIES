<?php
/**
 * Committees (Groups) Shortcode
 *
 * Fetches groups from Wicket API and displays them grouped by type
 * with client-side text search and type filtering.
 *
 * Shortcode: [myies_committees]
 *
 * Attributes:
 *   type        — Filter to specific group type(s). Default: '' (all types). Example: type="committee"
 *   active_only — Show only active groups. Default: "yes"
 *
 * @package MyIES_Integration
 * @since   1.0.19
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MyIES_Committees_Shortcode {

	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'myies_committees', array( $this, 'render' ) );
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
	 * Render the [myies_committees] shortcode.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( array(
			'type'             => '',
			'active_only'      => 'yes',
			'show_description' => 'yes',
		), $atts, 'myies_committees' );

		wp_enqueue_style( 'myies-committees' );

		// Build API filters
		$filters = array();
		if ( ! empty( $atts['type'] ) ) {
			$filters['type_eq'] = sanitize_text_field( $atts['type'] );
		}
		if ( 'yes' === $atts['active_only'] ) {
			$filters['active_eq'] = 'true';
		}

		$api      = wicket_api();
		$response = $api->get_groups( $filters );

		if ( empty( $response ) || empty( $response['data'] ) ) {
			return '<div class="myies-committees"><p>' . esc_html__( 'No committees or groups found.', 'wicket-integration' ) . '</p></div>';
		}

		// Build web_address lookup from included resources
		$web_addresses = array();
		if ( ! empty( $response['included'] ) ) {
			foreach ( $response['included'] as $inc ) {
				if ( ( $inc['type'] ?? '' ) === 'web_addresses' ) {
					$web_addresses[ $inc['id'] ] = $inc['attributes']['address'] ?? '';
				}
			}
		}

		// Group by type and sort
		$grouped = array();
		foreach ( $response['data'] as $group ) {
			$type = $group['attributes']['type'] ?? 'other';
			if ( ! isset( $grouped[ $type ] ) ) {
				$grouped[ $type ] = array();
			}

			// Resolve web address URL from sideloaded data
			$web_url = '';
			$wa_data = $group['relationships']['web_address']['data'] ?? null;
			if ( $wa_data && isset( $wa_data['id'], $web_addresses[ $wa_data['id'] ] ) ) {
				$web_url = $web_addresses[ $wa_data['id'] ];
			}

			$grouped[ $type ][] = array(
				'id'           => $group['id'],
				'name'         => $group['attributes']['name'] ?? '',
				'description'  => $group['attributes']['description'] ?? '',
				'active'       => ! empty( $group['attributes']['active'] ),
				'member_count' => $group['attributes']['active_members_count'] ?? $group['attributes']['members_count'] ?? 0,
				'web_url'      => $web_url,
			);
		}

		// Sort categories alphabetically
		ksort( $grouped );

		// Sort groups within each category alphabetically by name
		foreach ( $grouped as &$groups ) {
			usort( $groups, function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			} );
		}
		unset( $groups );

		// Collect available types for the dropdown
		$types = array_keys( $grouped );

		$show_desc = ( 'yes' === $atts['show_description'] );

		ob_start();
		$this->render_filters( $types );
		$this->render_groups( $grouped, $show_desc );
		$this->render_inline_js();
		return ob_get_clean();
	}

	/**
	 * Render the filter bar (text search + type dropdown).
	 */
	private function render_filters( $types ) {
		?>
		<div class="myies-committees" id="myies-committees">
			<div class="myies-committees__filters">
				<input
					type="text"
					id="myies-committees-search"
					class="myies-committees__search"
					placeholder="<?php esc_attr_e( 'Search committees...', 'wicket-integration' ); ?>"
					autocomplete="off"
				>
				<?php if ( count( $types ) > 1 ) : ?>
				<select id="myies-committees-type-filter" class="myies-committees__type-select">
					<option value=""><?php esc_html_e( 'All Types', 'wicket-integration' ); ?></option>
					<?php foreach ( $types as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>">
							<?php echo esc_html( $this->format_type_label( $type ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
			</div>
		<?php
	}

	/**
	 * Render grouped committee cards.
	 */
	private function render_groups( $grouped, $show_desc = true ) {
		foreach ( $grouped as $type => $groups ) :
			?>
			<div class="myies-committees__category" data-type="<?php echo esc_attr( $type ); ?>">
				<h3 class="myies-committees__category-heading">
					<?php echo esc_html( $this->format_type_label( $type ) ); ?>
					<span class="myies-committees__category-count">(<?php echo count( $groups ); ?>)</span>
				</h3>

				<div class="myies-committees__grid">
					<?php foreach ( $groups as $group ) : ?>
					<div class="myies-committees__card" data-name="<?php echo esc_attr( strtolower( $group['name'] ) ); ?>" data-desc="<?php echo esc_attr( strtolower( wp_strip_all_tags( $group['description'] ) ) ); ?>">
						<div class="myies-committees__card-header">
							<h4 class="myies-committees__card-name">
							<?php if ( ! empty( $group['web_url'] ) ) : ?>
								<a href="<?php echo esc_url( $group['web_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $group['name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $group['name'] ); ?>
							<?php endif; ?>
						</h4>
							<?php if ( $group['active'] ) : ?>
								<span class="myies-committees__badge myies-committees__badge--active"><?php esc_html_e( 'Active', 'wicket-integration' ); ?></span>
							<?php else : ?>
								<span class="myies-committees__badge myies-committees__badge--inactive"><?php esc_html_e( 'Inactive', 'wicket-integration' ); ?></span>
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
			<?php
		endforeach;
		?>
		<p class="myies-committees__no-results" id="myies-committees-no-results" style="display:none;">
			<?php esc_html_e( 'No committees match your search.', 'wicket-integration' ); ?>
		</p>
		</div>
		<?php
	}

	/**
	 * Format a group type slug into a readable label.
	 * e.g. "task_force" → "Task Force", "committee" → "Committee"
	 */
	private function format_type_label( $type ) {
		return ucwords( str_replace( array( '_', '-' ), ' ', $type ) );
	}

	/**
	 * Inline JS for client-side filtering (no external file needed).
	 */
	private function render_inline_js() {
		?>
		<script>
		(function() {
			var root       = document.getElementById('myies-committees');
			if (!root) return;

			var searchInput = document.getElementById('myies-committees-search');
			var typeSelect  = document.getElementById('myies-committees-type-filter');
			var noResults   = document.getElementById('myies-committees-no-results');
			var categories  = root.querySelectorAll('.myies-committees__category');

			function applyFilters() {
				var query     = (searchInput ? searchInput.value : '').toLowerCase().trim();
				var typeValue = (typeSelect ? typeSelect.value : '');
				var anyVisible = false;

				categories.forEach(function(cat) {
					var catType = cat.getAttribute('data-type');

					// Type filter: hide entire category if it doesn't match
					if (typeValue && catType !== typeValue) {
						cat.style.display = 'none';
						return;
					}

					var cards = cat.querySelectorAll('.myies-committees__card');
					var visibleInCat = 0;

					cards.forEach(function(card) {
						var name = card.getAttribute('data-name') || '';
						var desc = card.getAttribute('data-desc') || '';

						if (query && name.indexOf(query) === -1 && desc.indexOf(query) === -1) {
							card.style.display = 'none';
						} else {
							card.style.display = '';
							visibleInCat++;
						}
					});

					cat.style.display = (visibleInCat > 0) ? '' : 'none';
					if (visibleInCat > 0) anyVisible = true;
				});

				if (noResults) {
					noResults.style.display = anyVisible ? 'none' : '';
				}
			}

			if (searchInput) searchInput.addEventListener('input', applyFilters);
			if (typeSelect)  typeSelect.addEventListener('change', applyFilters);
		})();
		</script>
		<?php
	}
}

// Initialize
add_action( 'init', array( 'MyIES_Committees_Shortcode', 'get_instance' ) );
