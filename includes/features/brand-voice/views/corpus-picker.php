<?php
/**
 * View — Brand Voice corpus picker (auto-discovered content checklist).
 *
 * Loaded by panel.php; the list itself is populated lazily over AJAX so the
 * initial admin-page render isn't blocked by a 500-row WP_Query.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sw-card sw-bv-corpus-picker">
	<h2><?php esc_html_e( 'Learn from existing content', 'shopwalk-for-woocommerce' ); ?></h2>
	<p class="sw-muted">
		<?php esc_html_e( 'Pick representative posts, pages, and product descriptions that sound like you. 5–20 well-chosen samples beat 200 average ones.', 'shopwalk-for-woocommerce' ); ?>
	</p>

	<div class="sw-bv-picker-toolbar">
		<button type="button" class="button" data-sw-bv-filter="all">
			<?php esc_html_e( 'All', 'shopwalk-for-woocommerce' ); ?>
		</button>
		<button type="button" class="button" data-sw-bv-filter="post">
			<?php esc_html_e( 'Posts', 'shopwalk-for-woocommerce' ); ?>
		</button>
		<button type="button" class="button" data-sw-bv-filter="page">
			<?php esc_html_e( 'Pages', 'shopwalk-for-woocommerce' ); ?>
		</button>
		<button type="button" class="button" data-sw-bv-filter="product">
			<?php esc_html_e( 'Products', 'shopwalk-for-woocommerce' ); ?>
		</button>
	</div>

	<div id="sw-bv-picker-list" class="sw-bv-picker-list" aria-live="polite">
		<p class="sw-muted">
			<?php esc_html_e( 'Loading existing content…', 'shopwalk-for-woocommerce' ); ?>
		</p>
	</div>

	<p>
		<button type="button" class="button" id="sw-bv-picker-save">
			<?php esc_html_e( 'Save selection', 'shopwalk-for-woocommerce' ); ?>
		</button>
	</p>
</div>
