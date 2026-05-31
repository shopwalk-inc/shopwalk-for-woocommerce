<?php
/**
 * WP Admin Dashboard — v4.0 tabbed shell.
 *
 * v4.0 replaces the v3 cards-stack with a tabbed dashboard:
 *
 *   - "Overview" tab — UCP status, license + connection state, Pro upgrade
 *     CTA. Always rendered.
 *   - One tab per registered feature — descriptions, search, recommendations,
 *     brand voice, SEO, etc. Rendered via panel descriptors returned by
 *     each feature's `panel()` method (see Shopwalk_Feature_Registry).
 *
 * The dashboard does not own feature UI. Feature code lives under
 * includes/features/<name>/ and registers via the
 * `shopwalk_register_feature()` global on the `shopwalk_features_register`
 * action. The `shopwalk_dashboard_panels` filter lets third-party code
 * mutate the final tab list.
 *
 * Free-tier behaviour: when no license is present, every Pro feature tab
 * still appears in the nav but its body is replaced with an upgrade CTA
 * — features remain discoverable (per dashboard.md), not hidden.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class WooCommerce_Shopwalk_Admin_Dashboard {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers — kept on the v4 dashboard for the Overview tab's
		// license + UCP-connectivity actions. Per-feature AJAX handlers
		// belong on the feature class, not here.
		add_action( 'wp_ajax_shopwalk_self_test', array( $this, 'ajax_self_test' ) );
		add_action( 'wp_ajax_shopwalk_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_shopwalk_test_license', array( $this, 'ajax_test_license' ) );
		add_action( 'wp_ajax_shopwalk_disconnect', array( $this, 'ajax_disconnect' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Shopwalk for WooCommerce', 'shopwalk-for-woocommerce' ),
			__( 'Shopwalk', 'shopwalk-for-woocommerce' ),
			'manage_woocommerce',
			'shopwalk-for-woocommerce',
			array( $this, 'render_page' ),
			'dashicons-share-alt2',
			58
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_shopwalk-for-woocommerce' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'shopwalk-for-woocommerce-dashboard',
			WOOCOMMERCE_SHOPWALK_PLUGIN_URL . 'assets/dashboard.css',
			array(),
			WOOCOMMERCE_SHOPWALK_VERSION
		);
		wp_register_script( 'shopwalk-for-woocommerce-admin', '', array(), WOOCOMMERCE_SHOPWALK_VERSION, true );
		wp_enqueue_script( 'shopwalk-for-woocommerce-admin' );
		wp_add_inline_script(
			'shopwalk-for-woocommerce-admin',
			'window.swAdmin = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonces'  => array(
						'self_test'    => wp_create_nonce( 'shopwalk_self_test' ),
						'activate'     => wp_create_nonce( 'shopwalk_activate' ),
						'test_license' => wp_create_nonce( 'shopwalk_test_license' ),
						'disconnect'   => wp_create_nonce( 'shopwalk_disconnect' ),
					),
				)
			) . ';'
		);
	}

	// ── Tier detection ─────────────────────────────────────────────────────

	/**
	 * Which tier the current install is on. Drives what each tab renders.
	 *
	 *   - "unlicensed" → free, no license key entered. UCP-only Free tier.
	 *   - "free"       → license present but plan == free.
	 *   - "pro"        → license present and plan == pro (or pro_lite/pro_plus).
	 */
	private function get_tier(): string {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return 'unlicensed';
		}
		$key = Shopwalk_License::key();
		if ( '' === $key ) {
			return 'unlicensed';
		}
		$plan = (string) get_option( 'shopwalk_plan', 'free' );
		// Anything non-free counts as Pro for gating purposes. The plan_label
		// option carries the human-readable tier name.
		return 'free' === $plan ? 'free' : 'pro';
	}

	// ── Page render ────────────────────────────────────────────────────────

	public function render_page(): void {
		$tier = $this->get_tier();

		// Build the panel list: Overview is always first, then every panel
		// returned by the feature registry. The `shopwalk_dashboard_panels`
		// filter (applied inside Shopwalk_Feature_Registry::panels()) lets
		// third-party code mutate this.
		$panels = array(
			array(
				'slug'            => 'overview',
				'label'           => __( 'Overview', 'shopwalk-for-woocommerce' ),
				'render_callback' => array( $this, 'render_overview_panel' ),
				'tier'            => 'free',
			),
		);
		if ( class_exists( 'Shopwalk_Feature_Registry' ) ) {
			$panels = array_merge( $panels, Shopwalk_Feature_Registry::instance()->panels() );
		}

		// Active tab — sticky in the URL via ?tab=<slug>. Falls back to
		// "overview" if the requested tab isn't registered.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$active    = 'overview';
		foreach ( $panels as $p ) {
			if ( $p['slug'] === $requested ) {
				$active = $p['slug'];
				break;
			}
		}

		?>
		<div class="wrap sw-wrap">
			<h1>
				<?php esc_html_e( 'Shopwalk for WooCommerce', 'shopwalk-for-woocommerce' ); ?>
				<?php $this->render_tier_badge( $tier ); ?>
			</h1>

			<?php $this->render_connect_notice(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $panels as $p ) : ?>
					<?php
					$url       = add_query_arg(
						array(
							'page' => 'shopwalk-for-woocommerce',
							'tab'  => $p['slug'],
						),
						admin_url( 'admin.php' )
					);
					$is_active = $p['slug'] === $active;
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo $is_active ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $p['label'] ); ?>
						<?php if ( 'pro' === ( $p['tier'] ?? 'pro' ) && 'pro' !== $tier ) : ?>
							<span class="sw-badge sw-badge-pro" style="margin-left:6px;">PRO</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="sw-tab-body">
				<?php
				foreach ( $panels as $p ) {
					if ( $p['slug'] !== $active ) {
						continue;
					}
					$panel_tier = $p['tier'] ?? 'pro';
					// Free-tier gate: if the panel is Pro-only and the install
					// is not on Pro, render the upgrade CTA instead of the
					// panel body. Discoverability stays — the tab is still
					// in the nav, just the body is gated.
					if ( 'pro' === $panel_tier && 'pro' !== $tier ) {
						$this->render_pro_gate( $p['label'] );
					} else {
						call_user_func( $p['render_callback'] );
					}
					break;
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_tier_badge( string $tier ): void {
		if ( 'pro' === $tier ) {
			echo '<span class="sw-badge sw-badge-pro">PRO</span>';
		} elseif ( 'free' === $tier ) {
			echo '<span class="sw-badge sw-badge-free">FREE</span>';
		} else {
			echo '<span class="sw-badge sw-badge-free">FREE</span>';
		}
	}

	private function render_connect_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only redirect-result notice.
		$state = isset( $_GET['sw_connect'] ) ? sanitize_text_field( wp_unslash( $_GET['sw_connect'] ) ) : '';
		if ( '' === $state ) {
			return;
		}
		$reason = isset( $_GET['sw_reason'] ) ? sanitize_text_field( wp_unslash( $_GET['sw_reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$map = array(
			'ok'              => array( 'notice-success', __( 'Connected to Shopwalk.', 'shopwalk-for-woocommerce' ) ),
			'declined'        => array( 'notice-warning', __( 'Connection cancelled. No changes made.', 'shopwalk-for-woocommerce' ) ),
			'state_mismatch'  => array( 'notice-error', __( 'Connection failed: state mismatch. Please try again.', 'shopwalk-for-woocommerce' ) ),
			'exchange_failed' => array( 'notice-error', __( 'Connection failed while exchanging the code.', 'shopwalk-for-woocommerce' ) ),
		);
		if ( ! isset( $map[ $state ] ) ) {
			return;
		}
		list( $class, $msg ) = $map[ $state ];
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p>
				<?php echo esc_html( $msg ); ?>
				<?php if ( '' !== $reason ) : ?>
					<code><?php echo esc_html( $reason ); ?></code>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	// ── Overview panel ─────────────────────────────────────────────────────

	/**
	 * The always-on Overview tab.
	 *
	 * Renders:
	 *   - The UCP-setup status panel (Free-tier value)
	 *   - The connection state (license + partner ID + plan)
	 *   - A Pro upgrade CTA when the install is on the Free tier
	 *   - The license-key input (entry point for hosting-partner pre-installs
	 *     and merchants pasting a key they already have)
	 *
	 * This method is the panel's render_callback in the panel descriptor.
	 */
	public function render_overview_panel(): void {
		$tier          = $this->get_tier();
		$product_count = wp_count_posts( 'product' )->publish ?? 0;
		?>
		<div class="sw-card">
			<h2><?php esc_html_e( 'Google UCP setup', 'shopwalk-for-woocommerce' ); ?></h2>
			<p>
				<?php esc_html_e( 'Your store exposes Google\'s Universal Commerce Protocol (UCP) endpoints. AI shopping agents (Google Search, ChatGPT shopping, future agentic surfaces) can discover your catalog and link buyers directly to your existing WooCommerce checkout. Shopwalk never sits in the payment path.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p class="sw-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: published product count, 2: plugin version. */
						__( '%1$d products published · Plugin v%2$s', 'shopwalk-for-woocommerce' ),
						(int) $product_count,
						WOOCOMMERCE_SHOPWALK_VERSION
					)
				);
				?>
			</p>
			<p>
				<button type="button" class="button" id="sw-self-test-btn">
					<?php esc_html_e( 'Run self-test', 'shopwalk-for-woocommerce' ); ?>
				</button>
				<span id="sw-self-test-result" class="sw-muted"></span>
			</p>
		</div>

		<?php $this->render_license_card( $tier ); ?>

		<?php if ( 'pro' !== $tier ) : ?>
			<?php $this->render_upgrade_card(); ?>
		<?php endif; ?>

		<script>
			(function () {
				if ( ! window.swAdmin ) { return; }
				var s = window.swAdmin;
				function postAjax( action, body ) {
					var data = new URLSearchParams();
					data.append( 'action', action );
					Object.keys( body || {} ).forEach( function ( k ) { data.append( k, body[ k ] ); } );
					return fetch( s.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
						.then( function ( r ) { return r.json(); } );
				}
				var st = document.getElementById( 'sw-self-test-btn' );
				if ( st ) {
					st.addEventListener( 'click', function () {
						var out = document.getElementById( 'sw-self-test-result' );
						st.disabled = true;
						out.textContent = '<?php echo esc_js( __( 'Running…', 'shopwalk-for-woocommerce' ) ); ?>';
						postAjax( 'shopwalk_self_test', { nonce: s.nonces.self_test } ).then( function ( resp ) {
							st.disabled = false;
							if ( ! resp || ! resp.success ) {
								out.textContent = '<?php echo esc_js( __( 'Self-test failed.', 'shopwalk-for-woocommerce' ) ); ?>';
								return;
							}
							var checks = ( resp.data && resp.data.checks ) || [];
							var failed = checks.filter( function ( c ) { return c.status === 'fail'; } );
							out.textContent = failed.length === 0
								? '<?php echo esc_js( __( 'All checks passed.', 'shopwalk-for-woocommerce' ) ); ?>'
								: failed.length + ' <?php echo esc_js( __( 'check(s) failed — see browser console.', 'shopwalk-for-woocommerce' ) ); ?>';
							if ( failed.length ) {
								console.warn( 'Shopwalk self-test failures:', failed );
							}
						} );
					} );
				}
				var act = document.getElementById( 'sw-activate-btn' );
				if ( act ) {
					act.addEventListener( 'click', function () {
						var input = document.getElementById( 'sw-license-input' );
						var status = document.getElementById( 'sw-activate-status' );
						if ( ! input || ! input.value.trim() ) {
							status.textContent = '<?php echo esc_js( __( 'License key is required.', 'shopwalk-for-woocommerce' ) ); ?>';
							return;
						}
						act.disabled = true;
						status.textContent = '<?php echo esc_js( __( 'Validating…', 'shopwalk-for-woocommerce' ) ); ?>';
						postAjax( 'shopwalk_activate', { nonce: s.nonces.activate, license_key: input.value.trim() } ).then( function ( resp ) {
							act.disabled = false;
							if ( resp && resp.success ) {
								status.textContent = ( resp.data && resp.data.message ) || '<?php echo esc_js( __( 'License activated.', 'shopwalk-for-woocommerce' ) ); ?>';
								setTimeout( function () { window.location.reload(); }, 800 );
							} else {
								status.textContent = ( resp && resp.data && resp.data.message ) || '<?php echo esc_js( __( 'Activation failed.', 'shopwalk-for-woocommerce' ) ); ?>';
							}
						} );
					} );
				}
				var dc = document.getElementById( 'sw-disconnect-btn' );
				if ( dc ) {
					dc.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						if ( ! confirm( '<?php echo esc_js( __( 'Disconnect from Shopwalk?', 'shopwalk-for-woocommerce' ) ); ?>' ) ) { return; }
						postAjax( 'shopwalk_disconnect', { nonce: s.nonces.disconnect } ).then( function () {
							window.location.reload();
						} );
					} );
				}
			})();
		</script>
		<?php
	}

	private function render_license_card( string $tier ): void {
		$license_key   = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::key() : '';
		$partner_id    = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::partner_id() : '';
		$license_state = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::status() : 'unlicensed';
		$plan_label    = (string) get_option( 'shopwalk_plan_label', '' );
		if ( '' === $plan_label ) {
			$plan_label = 'pro' === $tier ? 'Pro' : 'Free';
		}
		?>
		<div class="sw-card">
			<h2><?php esc_html_e( 'License', 'shopwalk-for-woocommerce' ); ?></h2>

			<?php if ( 'unlicensed' === $tier ) : ?>
				<p>
					<?php esc_html_e( 'Connect this store to Shopwalk to unlock Pro AI features. Free UCP setup is already active — no license needed for AI-shopper discoverability.', 'shopwalk-for-woocommerce' ); ?>
				</p>
				<?php if ( class_exists( 'Shopwalk_Connect' ) ) : ?>
					<p>
						<a href="<?php echo esc_url( Shopwalk_Connect::connect_url() ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
							<?php esc_html_e( 'Connect to Shopwalk', 'shopwalk-for-woocommerce' ); ?>
						</a>
					</p>
				<?php endif; ?>
				<h3><?php esc_html_e( 'Already have a license?', 'shopwalk-for-woocommerce' ); ?></h3>
				<p>
					<input type="text" id="sw-license-input" class="regular-text" placeholder="sw_site_..." value="" />
					<button type="button" class="button" id="sw-activate-btn">
						<?php esc_html_e( 'Activate', 'shopwalk-for-woocommerce' ); ?>
					</button>
				</p>
				<p id="sw-activate-status" class="sw-muted"></p>
			<?php else : ?>
				<table class="sw-details">
					<tr>
						<td><?php esc_html_e( 'License key', 'shopwalk-for-woocommerce' ); ?></td>
						<td><code><?php echo esc_html( $license_key ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Partner ID', 'shopwalk-for-woocommerce' ); ?></td>
						<td><code><?php echo esc_html( $partner_id ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Plan', 'shopwalk-for-woocommerce' ); ?></td>
						<td><?php echo esc_html( $plan_label ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Status', 'shopwalk-for-woocommerce' ); ?></td>
						<td><?php echo esc_html( ucfirst( $license_state ) ); ?></td>
					</tr>
				</table>
				<p>
					<a href="#" id="sw-disconnect-btn"><?php esc_html_e( 'Disconnect from Shopwalk', 'shopwalk-for-woocommerce' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_upgrade_card(): void {
		?>
		<div class="sw-card sw-upgrade-cta">
			<h2><?php esc_html_e( 'Upgrade to Shopwalk Pro', 'shopwalk-for-woocommerce' ); ?></h2>
			<p>
				<?php esc_html_e( 'Pro unlocks Shopwalk\'s AI feature suite for your store: semantic search, recommendations, AI product descriptions, brand-voice content authoring, SEO + image optimization, and (in later releases) AI translation and a knowledge-base-backed support chat. All powered by Shopwalk\'s own AI stack — no external LLM passthrough.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/subscribe' ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'See Pro plans →', 'shopwalk-for-woocommerce' ); ?>
				</a>
			</p>
			<p class="sw-muted" style="font-size:12px;">
				<?php esc_html_e( '14-day free trial on first paid signup. Stores keep 100% of their sales — Shopwalk never touches checkout.', 'shopwalk-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Free-tier gate body shown when a Pro feature tab is opened on a non-Pro
	 * install. Features remain discoverable in the nav per dashboard.md
	 * ("Pro Lite users see Pro / Pro+ tiles with an inline upgrade CTA (not
	 * hidden)"); only the body is replaced.
	 *
	 * @param string $feature_label Human-readable feature name for the headline.
	 */
	private function render_pro_gate( string $feature_label ): void {
		?>
		<div class="sw-card sw-upgrade-cta">
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: feature name. */
						__( '%s is a Pro feature', 'shopwalk-for-woocommerce' ),
						$feature_label
					)
				);
				?>
			</h2>
			<p>
				<?php esc_html_e( 'This feature is included in Shopwalk Pro. Upgrade to unlock it and the rest of the AI feature suite.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/subscribe' ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Upgrade to Pro →', 'shopwalk-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ── AJAX handlers ──────────────────────────────────────────────────────

	public function ajax_self_test(): void {
		check_ajax_referer( 'shopwalk_self_test', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}

		$checks = array();

		// WooCommerce active?
		$wc_active = class_exists( 'WooCommerce' );
		$checks[]  = array(
			'check'   => 'WooCommerce',
			'status'  => $wc_active ? 'pass' : 'fail',
			'message' => $wc_active && function_exists( 'WC' ) ? 'v' . WC()->version : ( $wc_active ? 'active' : 'not active' ),
		);

		// Permalinks (REST API requires non-Plain).
		$permalink = (string) get_option( 'permalink_structure', '' );
		$checks[]  = array(
			'check'   => 'Permalinks',
			'status'  => '' !== $permalink ? 'pass' : 'fail',
			'message' => '' !== $permalink ? $permalink : 'Plain — REST API will not work',
		);

		// REST API enabled?
		$rest_url = get_rest_url();
		$checks[] = array(
			'check'   => 'REST API',
			'status'  => '' !== $rest_url ? 'pass' : 'fail',
			'message' => '' !== $rest_url ? 'enabled' : 'disabled',
		);

		// Published product count.
		$total    = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
		$checks[] = array(
			'check'   => 'Published products',
			'status'  => $total > 0 ? 'pass' : 'warn',
			'message' => (string) number_format( $total ),
		);

		// PHP version.
		$checks[] = array(
			'check'   => 'PHP version',
			'status'  => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'pass' : 'warn',
			'message' => PHP_VERSION,
		);

		// Loopback test (can WP reach its own UCP route).
		$loop_resp = wp_remote_get(
			rest_url( 'ucp/v1/store' ),
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);
		$loop_code = is_wp_error( $loop_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $loop_resp );
		$checks[]  = array(
			'check'   => 'UCP loopback (/wp-json/ucp/v1/store)',
			'status'  => 200 === $loop_code ? 'pass' : 'fail',
			'message' => 200 === $loop_code ? 'OK' : 'HTTP ' . $loop_code,
		);

		wp_send_json_success( array( 'checks' => $checks ) );
	}

	public function ajax_activate(): void {
		check_ajax_referer( 'shopwalk_activate', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}
		$new_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( '' === $new_key ) {
			wp_send_json_error( array( 'message' => __( 'License key is required.', 'shopwalk-for-woocommerce' ) ) );
		}
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			require_once WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-license.php';
		}
		$result = Shopwalk_License::activate( $new_key );
		if ( $result['ok'] ?? false ) {
			$plan = (string) ( $result['plan'] ?? '' );
			$msg  = '' !== $plan
				/* translators: %s: license plan name. */
				? sprintf( __( 'License activated. Plan: %s', 'shopwalk-for-woocommerce' ), ucfirst( $plan ) )
				: __( 'License activated.', 'shopwalk-for-woocommerce' );
			wp_send_json_success( array( 'message' => $msg ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ?? __( 'Activation failed.', 'shopwalk-for-woocommerce' ) ) );
	}

	public function ajax_test_license(): void {
		check_ajax_referer( 'shopwalk_test_license', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			wp_send_json_error( array( 'message' => __( 'License module not loaded.', 'shopwalk-for-woocommerce' ) ) );
		}
		$key = Shopwalk_License::key();
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'No license key configured.', 'shopwalk-for-woocommerce' ) ) );
		}
		$result = Shopwalk_License::activate( $key );
		wp_send_json_success(
			array(
				'valid'   => (bool) ( $result['ok'] ?? false ),
				'plan'    => (string) ( $result['plan'] ?? 'free' ),
				'message' => ( $result['ok'] ?? false )
					? __( 'License is valid.', 'shopwalk-for-woocommerce' )
					: ( $result['message'] ?? __( 'Validation failed.', 'shopwalk-for-woocommerce' ) ),
			)
		);
	}

	public function ajax_disconnect(): void {
		check_ajax_referer( 'shopwalk_disconnect', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}
		if ( class_exists( 'Shopwalk_License' ) ) {
			Shopwalk_License::deactivate();
		}
		delete_option( 'shopwalk_plan' );
		delete_option( 'shopwalk_plan_label' );
		delete_option( 'shopwalk_next_billing' );
		delete_option( 'shopwalk_next_billing_at' );
		delete_option( 'shopwalk_sync_state' );
		delete_option( 'shopwalk_sync_history' );
		wp_send_json_success( array( 'message' => __( 'Disconnected from Shopwalk.', 'shopwalk-for-woocommerce' ) ) );
	}
}
