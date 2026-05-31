<?php
/**
 * Storefront render of the recommendations container.
 *
 * Inputs come from Shopwalk_Recommendations_Block_Handler::render_container():
 *   $type       — also_viewed | related | fbt | personalized
 *   $product_id — int
 *   $count      — int
 *   $layout     — carousel | grid | list
 *   $title      — string (already trimmed)
 *
 * The output is intentionally minimal: a wrapper + a placeholder. The
 * lazy-load JS at assets/js/recommendations.js fetches the items and
 * replaces the placeholder with whatever WC's `content-product`
 * template produces (so the merchant's theme styling carries over).
 *
 * @package ShopwalkWooCommerce\Features\Recommendations
 */

defined( 'ABSPATH' ) || exit;

// Build a stable id so the placeholder can be addressed by JS without
// fragile DOM walking.
$placeholder_id = 'shopwalk-recs-' . wp_unique_id();
?>
<section
	class="shopwalk-recommendations shopwalk-recommendations--<?php echo esc_attr( $layout ); ?>"
	data-shopwalk-recs
	data-type="<?php echo esc_attr( $type ); ?>"
	data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
	data-count="<?php echo esc_attr( (string) $count ); ?>"
	data-layout="<?php echo esc_attr( $layout ); ?>"
	id="<?php echo esc_attr( $placeholder_id ); ?>"
	aria-busy="true"
	aria-live="polite"
>
	<?php if ( '' !== $title ) : ?>
		<h2 class="shopwalk-recommendations__title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>

	<ul class="shopwalk-recommendations__items products columns-<?php echo esc_attr( (string) min( 6, max( 2, (int) $count ) ) ); ?>" data-shopwalk-recs-items>
		<?php for ( $i = 0; $i < $count; $i++ ) : ?>
			<li class="shopwalk-recommendations__skeleton product" aria-hidden="true">
				<span class="shopwalk-recommendations__skeleton-image"></span>
				<span class="shopwalk-recommendations__skeleton-line"></span>
				<span class="shopwalk-recommendations__skeleton-line shopwalk-recommendations__skeleton-line--short"></span>
			</li>
		<?php endfor; ?>
	</ul>
</section>
