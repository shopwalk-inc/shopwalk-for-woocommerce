<?php
/**
 * Per-product meta box view.
 *
 * @var int    $product_id Current product id.
 * @var string $target     Label of the active SEO target plugin (e.g. "Yoast SEO").
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="shopwalk-seo-box" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
	<p class="description">
		<?php
		printf(
			/* translators: %s: name of the active SEO plugin (e.g. "Yoast SEO") */
			esc_html__( 'Writing to: %s', 'shopwalk-for-woocommerce' ),
			'<strong>' . esc_html( $target ) . '</strong>'
		);
		?>
	</p>

	<p>
		<label for="shopwalk-seo-focus"><?php esc_html_e( 'Focus keyphrase (optional)', 'shopwalk-for-woocommerce' ); ?></label>
		<input type="text" id="shopwalk-seo-focus" class="widefat" placeholder="<?php esc_attr_e( 'e.g. blue cotton t-shirt', 'shopwalk-for-woocommerce' ); ?>" />
	</p>

	<p>
		<button type="button" class="button button-primary" id="shopwalk-seo-generate">
			<?php esc_html_e( 'Generate SEO', 'shopwalk-for-woocommerce' ); ?>
		</button>
	</p>

	<div class="shopwalk-seo-preview" style="display:none;">
		<h4><?php esc_html_e( 'Preview', 'shopwalk-for-woocommerce' ); ?></h4>

		<p>
			<label><?php esc_html_e( 'Meta title', 'shopwalk-for-woocommerce' ); ?></label>
			<input type="text" class="widefat" data-field="meta_title" />
			<span class="shopwalk-seo-len" data-target-for="meta_title"></span>
		</p>

		<p>
			<label><?php esc_html_e( 'Meta description', 'shopwalk-for-woocommerce' ); ?></label>
			<textarea class="widefat" rows="3" data-field="meta_description"></textarea>
			<span class="shopwalk-seo-len" data-target-for="meta_description"></span>
		</p>

		<div class="shopwalk-seo-alts">
			<h4><?php esc_html_e( 'Image alt text', 'shopwalk-for-woocommerce' ); ?></h4>
			<ul class="shopwalk-seo-alt-list"></ul>
		</div>

		<div class="shopwalk-seo-checklist">
			<h4><?php esc_html_e( 'On-page SEO checklist', 'shopwalk-for-woocommerce' ); ?></h4>
			<ul class="shopwalk-seo-checklist-list"></ul>
		</div>

		<p>
			<label><input type="checkbox" id="shopwalk-seo-apply-title" checked /> <?php esc_html_e( 'Apply meta title', 'shopwalk-for-woocommerce' ); ?></label><br />
			<label><input type="checkbox" id="shopwalk-seo-apply-desc" checked /> <?php esc_html_e( 'Apply meta description', 'shopwalk-for-woocommerce' ); ?></label><br />
			<label><input type="checkbox" id="shopwalk-seo-apply-focus" /> <?php esc_html_e( 'Apply focus keyphrase', 'shopwalk-for-woocommerce' ); ?></label><br />
			<label><input type="checkbox" id="shopwalk-seo-apply-alt" checked /> <?php esc_html_e( 'Apply image alt text', 'shopwalk-for-woocommerce' ); ?></label>
		</p>

		<p>
			<button type="button" class="button button-primary" id="shopwalk-seo-apply">
				<?php esc_html_e( 'Apply', 'shopwalk-for-woocommerce' ); ?>
			</button>
			<button type="button" class="button" id="shopwalk-seo-reject">
				<?php esc_html_e( 'Reject', 'shopwalk-for-woocommerce' ); ?>
			</button>
		</p>
	</div>

	<div class="shopwalk-seo-status" aria-live="polite"></div>
</div>
