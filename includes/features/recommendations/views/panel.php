<?php
/**
 * Dashboard panel view: AI Recommendations.
 *
 * Rendered by Shopwalk_Recommendations_Feature::dashboard_panel()'s
 * callback. Form posts to admin-post.php with action
 * `shopwalk_recommendations_save`.
 *
 * @package ShopwalkWooCommerce\Features\Recommendations
 */

defined( 'ABSPATH' ) || exit;

$settings    = Shopwalk_Recommendations_Feature::settings();
$nonce_meta  = Shopwalk_Recommendations_Admin::nonce_meta();
$is_enabled  = Shopwalk_Recommendations_Feature::is_enabled();
$degraded    = Shopwalk_Recommendations_Feature::degraded_reason();

$enabled_types = isset( $settings['enabled_types'] ) && is_array( $settings['enabled_types'] )
	? array_values( $settings['enabled_types'] )
	: array( 'also_viewed', 'related', 'fbt' );

$all_types = array(
	'also_viewed'  => __( 'Customers also viewed (collaborative filtering)', 'shopwalk-for-woocommerce' ),
	'related'      => __( 'Related products (embedding + category similarity)', 'shopwalk-for-woocommerce' ),
	'fbt'          => __( 'Frequently bought together (market-basket)', 'shopwalk-for-woocommerce' ),
	'personalized' => __( 'Personalized for the shopper (session signals)', 'shopwalk-for-woocommerce' ),
);
?>
<div class="shopwalk-recs-panel">
	<h2><?php esc_html_e( 'AI Recommendations', 'shopwalk-for-woocommerce' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Show shopper-tuned product blocks on your storefront — driven by Shopwalk\'s ranker over your own catalog and order signals.', 'shopwalk-for-woocommerce' ); ?>
	</p>

	<?php if ( ! $is_enabled ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Pro required.', 'shopwalk-for-woocommerce' ); ?></strong>
				<?php
				printf(
					/* translators: %s: upgrade URL */
					esc_html__( 'AI Recommendations are part of the Pro tier. %s to enable.', 'shopwalk-for-woocommerce' ),
					'<a href="' . esc_url( defined( 'SHOPWALK_PARTNERS_URL' ) ? SHOPWALK_PARTNERS_URL . '/upgrade' : 'https://shopwalk.com/partners/upgrade' ) . '">' . esc_html__( 'Upgrade your plan', 'shopwalk-for-woocommerce' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php elseif ( '' !== $degraded ) : ?>
		<div class="notice notice-info inline">
			<p><?php echo esc_html( $degraded ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="shopwalk_recommendations_save" />
		<?php wp_nonce_field( $nonce_meta['action'], $nonce_meta['field'] ); ?>

		<h3><?php esc_html_e( 'Placements', 'shopwalk-for-woocommerce' ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Single product page', 'shopwalk-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="inject_single_product" value="1" <?php checked( ! empty( $settings['inject_single_product'] ) ); ?> />
							<?php esc_html_e( 'Auto-inject "Customers also viewed" below the product summary', 'shopwalk-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cart page', 'shopwalk-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="inject_cart_fbt" value="1" <?php checked( ! empty( $settings['inject_cart_fbt'] ) ); ?> />
							<?php esc_html_e( 'Auto-inject "Frequently bought together" in cart collaterals', 'shopwalk-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Gutenberg block', 'shopwalk-for-woocommerce' ); ?></th>
					<td>
						<code>Shopwalk &rarr; Recommendations</code>
						<p class="description"><?php esc_html_e( 'Drop a "Shopwalk Recommendations" block into any page, product template, or pattern.', 'shopwalk-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shortcode', 'shopwalk-for-woocommerce' ); ?></th>
					<td>
						<code>[shopwalk_recommendations type="related" product_id="123" count="6"]</code>
						<p class="description"><?php esc_html_e( 'Use in classic-editor pages or theme template files.', 'shopwalk-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Recommendation types', 'shopwalk-for-woocommerce' ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled types', 'shopwalk-for-woocommerce' ); ?></th>
					<td>
						<?php foreach ( $all_types as $slug => $label ) : ?>
							<label style="display:block;margin:.25em 0;">
								<input
									type="checkbox"
									name="enabled_types[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $enabled_types, true ) ); ?>
								/>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="shopwalk_recs_count"><?php esc_html_e( 'Default item count', 'shopwalk-for-woocommerce' ); ?></label></th>
					<td>
						<input
							type="number"
							id="shopwalk_recs_count"
							name="default_count"
							min="1"
							max="24"
							value="<?php echo esc_attr( (string) ( $settings['default_count'] ?? 6 ) ); ?>"
							class="small-text"
						/>
						<p class="description"><?php esc_html_e( 'Used when a block/shortcode doesn\'t override count.', 'shopwalk-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="shopwalk_recs_layout"><?php esc_html_e( 'Default layout', 'shopwalk-for-woocommerce' ); ?></label></th>
					<td>
						<select id="shopwalk_recs_layout" name="default_layout">
							<?php
							$layout = (string) ( $settings['default_layout'] ?? 'carousel' );
							foreach ( array(
								'carousel' => __( 'Carousel', 'shopwalk-for-woocommerce' ),
								'grid'     => __( 'Grid', 'shopwalk-for-woocommerce' ),
								'list'     => __( 'List', 'shopwalk-for-woocommerce' ),
							) as $value => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $value ),
									selected( $layout, $value, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save recommendation settings', 'shopwalk-for-woocommerce' ); ?></button>
		</p>
	</form>
</div>
