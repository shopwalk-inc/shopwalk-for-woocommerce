<?php
/**
 * Dashboard panel view — bulk SEO controls.
 *
 * @var bool   $tier_allowed Pro+ access?
 * @var array  $state        Current Shopwalk_Seo_Bulk state (empty if idle).
 * @var string $target_label Active SEO plugin label.
 * @var bool   $is_fallback  True when no third-party SEO plugin is detected.
 * @var array  $categories   product_cat terms (or empty).
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap shopwalk-seo-panel">
	<h1><?php esc_html_e( 'Shopwalk — AI SEO', 'shopwalk-for-woocommerce' ); ?></h1>

	<?php if ( ! $tier_allowed ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'AI SEO is a Pro feature. Upgrade to bulk-generate meta titles, meta descriptions, and image alt text across your catalog.', 'shopwalk-for-woocommerce' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'shopwalk-for-woocommerce' ); ?></a></p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<p>
		<?php
		printf(
			/* translators: %s: name of the active SEO target */
			esc_html__( 'Generated meta will be written to: %s', 'shopwalk-for-woocommerce' ),
			'<strong>' . esc_html( $target_label ) . '</strong>'
		);
		?>
	</p>

	<?php if ( $is_fallback ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'No third-party SEO plugin detected. Shopwalk will save meta to its own fallback fields. For best results, install Yoast SEO, Rank Math, or All in One SEO so the meta renders in your page head.', 'shopwalk-for-woocommerce' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $state ) && ! empty( $state['started_at'] ) ) : ?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Bulk run in progress', 'shopwalk-for-woocommerce' ); ?></strong>
				—
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: completed count, 2: enqueued count, 3: error count */
						__( 'completed %1$d / %2$d (errors: %3$d)', 'shopwalk-for-woocommerce' ),
						(int) ( $state['completed'] ?? 0 ),
						(int) ( $state['enqueued'] ?? 0 ),
						(int) ( $state['errors'] ?? 0 )
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'shopwalk_seo_cancel' ); ?>
				<input type="hidden" name="action" value="shopwalk_seo_cancel" />
				<button type="submit" class="button"><?php esc_html_e( 'Cancel bulk run', 'shopwalk-for-woocommerce' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'shopwalk_seo_start' ); ?>
		<input type="hidden" name="action" value="shopwalk_seo_start" />

		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><label for="shopwalk-seo-scope"><?php esc_html_e( 'Scope', 'shopwalk-for-woocommerce' ); ?></label></th>
					<td>
						<select id="shopwalk-seo-scope" name="scope">
							<option value="all"><?php esc_html_e( 'All products', 'shopwalk-for-woocommerce' ); ?></option>
							<option value="category"><?php esc_html_e( 'By category', 'shopwalk-for-woocommerce' ); ?></option>
							<option value="missing_meta_title"><?php esc_html_e( 'Missing meta title', 'shopwalk-for-woocommerce' ); ?></option>
							<option value="missing_meta_desc"><?php esc_html_e( 'Missing meta description', 'shopwalk-for-woocommerce' ); ?></option>
							<option value="missing_alt"><?php esc_html_e( 'Missing image alt text', 'shopwalk-for-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="shopwalk-seo-category"><?php esc_html_e( 'Category', 'shopwalk-for-woocommerce' ); ?></label></th>
					<td>
						<select id="shopwalk-seo-category" name="category_id">
							<option value="0">—</option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Fields to generate', 'shopwalk-for-woocommerce' ); ?></th>
					<td>
						<label><input type="checkbox" name="fields[]" value="meta_title" checked /> <?php esc_html_e( 'Meta titles', 'shopwalk-for-woocommerce' ); ?></label><br />
						<label><input type="checkbox" name="fields[]" value="meta_description" checked /> <?php esc_html_e( 'Meta descriptions', 'shopwalk-for-woocommerce' ); ?></label><br />
						<label><input type="checkbox" name="fields[]" value="focus_keyphrase" /> <?php esc_html_e( 'Focus keyphrases', 'shopwalk-for-woocommerce' ); ?></label><br />
						<label><input type="checkbox" name="fields[]" value="image_alt" /> <?php esc_html_e( 'Image alt text', 'shopwalk-for-woocommerce' ); ?></label><br />
						<label><input type="checkbox" name="fields[]" value="seo_checklist" checked /> <?php esc_html_e( 'On-page SEO checklist', 'shopwalk-for-woocommerce' ); ?></label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Image alt text', 'shopwalk-for-woocommerce' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="overwrite_alt" value="1" />
							<?php esc_html_e( 'Overwrite existing alt text (default: skip images that already have alt text)', 'shopwalk-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="shopwalk-seo-focus-bulk"><?php esc_html_e( 'Focus keyphrase (optional)', 'shopwalk-for-woocommerce' ); ?></label></th>
					<td>
						<input type="text" id="shopwalk-seo-focus-bulk" name="focus_keyphrase" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Per-product generation is usually better than a uniform keyphrase across the catalog.', 'shopwalk-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>

		<p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Start bulk SEO', 'shopwalk-for-woocommerce' ); ?></button>
		</p>
	</form>
</div>
