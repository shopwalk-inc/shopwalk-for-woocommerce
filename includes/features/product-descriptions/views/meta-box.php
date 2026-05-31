<?php
/**
 * Meta-box view — rendered by Shopwalk_Product_Descriptions_Meta_Box::render().
 *
 * Locals (set by the renderer):
 *
 *  - $product_id  int
 *  - $is_stale    bool
 *  - $brand_id    string
 *  - $history     array<int,array{long:string,short:string,generated_at:int}>
 *  - $pending     array<string,mixed>
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/** @var int    $product_id */
/** @var bool   $is_stale */
/** @var string $brand_id */
/** @var array  $history */
/** @var array  $pending */
?>
<div class="shopwalk-pd-meta-box" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>">
	<?php if ( '' === $brand_id ) : ?>
		<p class="shopwalk-pd-banner shopwalk-pd-banner-info">
			<?php
			printf(
				/* translators: %s: link to brand-voice training */
				wp_kses_post( __( 'Tip: train your brand voice for descriptions that sound like %1$syou%2$s — not generic e-commerce.', 'shopwalk-for-woocommerce' ) ),
				'<em>',
				'</em>'
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $is_stale ) : ?>
		<p class="shopwalk-pd-banner shopwalk-pd-banner-warn">
			<?php esc_html_e( 'This product has unsynced changes. The AI may not see the latest WC edits yet.', 'shopwalk-for-woocommerce' ); ?>
			<a href="#" class="shopwalk-pd-sync-now"><?php esc_html_e( 'Sync now', 'shopwalk-for-woocommerce' ); ?></a>
		</p>
	<?php endif; ?>

	<div class="shopwalk-pd-controls">
		<p>
			<label for="shopwalk-pd-fields"><strong><?php esc_html_e( 'Generate', 'shopwalk-for-woocommerce' ); ?></strong></label>
			<select id="shopwalk-pd-fields" class="widefat">
				<option value="long,short" selected><?php esc_html_e( 'Both descriptions', 'shopwalk-for-woocommerce' ); ?></option>
				<option value="long"><?php esc_html_e( 'Long description only', 'shopwalk-for-woocommerce' ); ?></option>
				<option value="short"><?php esc_html_e( 'Short description only', 'shopwalk-for-woocommerce' ); ?></option>
			</select>
		</p>

		<p>
			<label for="shopwalk-pd-tone"><strong><?php esc_html_e( 'Tone', 'shopwalk-for-woocommerce' ); ?></strong></label>
			<select id="shopwalk-pd-tone" class="widefat">
				<option value="brand_voice" <?php disabled( '' === $brand_id ); ?>>
					<?php esc_html_e( 'Brand voice (recommended)', 'shopwalk-for-woocommerce' ); ?>
				</option>
				<option value="friendly"><?php esc_html_e( 'Friendly', 'shopwalk-for-woocommerce' ); ?></option>
				<option value="professional"><?php esc_html_e( 'Professional', 'shopwalk-for-woocommerce' ); ?></option>
				<option value="technical"><?php esc_html_e( 'Technical', 'shopwalk-for-woocommerce' ); ?></option>
				<option value="playful"><?php esc_html_e( 'Playful', 'shopwalk-for-woocommerce' ); ?></option>
			</select>
		</p>

		<p>
			<label for="shopwalk-pd-length"><strong><?php esc_html_e( 'Length', 'shopwalk-for-woocommerce' ); ?></strong></label>
			<select id="shopwalk-pd-length" class="widefat">
				<option value="short"><?php esc_html_e( 'Short (~50 words)', 'shopwalk-for-woocommerce' ); ?></option>
				<option value="medium" selected><?php esc_html_e( 'Medium (~150 words)', 'shopwalk-for-woocommerce' ); ?></option>
				<option value="long"><?php esc_html_e( 'Long (~300 words)', 'shopwalk-for-woocommerce' ); ?></option>
			</select>
		</p>

		<p>
			<label for="shopwalk-pd-keyphrase"><strong><?php esc_html_e( 'Focus keyphrase', 'shopwalk-for-woocommerce' ); ?></strong></label>
			<input type="text" id="shopwalk-pd-keyphrase" class="widefat" placeholder="<?php esc_attr_e( 'Optional', 'shopwalk-for-woocommerce' ); ?>" />
		</p>

		<p>
			<label>
				<input type="checkbox" id="shopwalk-pd-include-images" checked />
				<?php esc_html_e( 'Include product images', 'shopwalk-for-woocommerce' ); ?>
			</label>
		</p>

		<p>
			<button type="button" class="button button-primary widefat shopwalk-pd-generate-btn">
				<?php esc_html_e( 'Generate with Shopwalk', 'shopwalk-for-woocommerce' ); ?>
			</button>
		</p>
	</div>

	<div class="shopwalk-pd-preview" hidden>
		<h4><?php esc_html_e( 'Preview', 'shopwalk-for-woocommerce' ); ?></h4>
		<div class="shopwalk-pd-preview-long" hidden>
			<strong><?php esc_html_e( 'Long', 'shopwalk-for-woocommerce' ); ?></strong>
			<div class="shopwalk-pd-preview-body shopwalk-pd-preview-body-long"></div>
		</div>
		<div class="shopwalk-pd-preview-short" hidden>
			<strong><?php esc_html_e( 'Short', 'shopwalk-for-woocommerce' ); ?></strong>
			<div class="shopwalk-pd-preview-body shopwalk-pd-preview-body-short"></div>
		</div>
		<p>
			<button type="button" class="button button-primary shopwalk-pd-accept-btn">
				<?php esc_html_e( 'Use', 'shopwalk-for-woocommerce' ); ?>
			</button>
			<button type="button" class="button shopwalk-pd-discard-btn">
				<?php esc_html_e( 'Discard', 'shopwalk-for-woocommerce' ); ?>
			</button>
		</p>
	</div>

	<?php if ( ! empty( $pending ) && isset( $pending['result'] ) && is_array( $pending['result'] ) ) : ?>
		<div class="shopwalk-pd-pending-review">
			<h4><?php esc_html_e( 'Pending bulk-job result', 'shopwalk-for-woocommerce' ); ?></h4>
			<?php if ( ! empty( $pending['result']['long'] ) ) : ?>
				<div><strong><?php esc_html_e( 'Long', 'shopwalk-for-woocommerce' ); ?>:</strong>
					<?php echo wp_kses_post( (string) $pending['result']['long'] ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $pending['result']['short'] ) ) : ?>
				<div><strong><?php esc_html_e( 'Short', 'shopwalk-for-woocommerce' ); ?>:</strong>
					<?php echo wp_kses_post( (string) $pending['result']['short'] ); ?>
				</div>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary shopwalk-pd-apply-pending-btn">
					<?php esc_html_e( 'Apply', 'shopwalk-for-woocommerce' ); ?>
				</button>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $history ) ) : ?>
		<details class="shopwalk-pd-history">
			<summary><?php esc_html_e( 'History', 'shopwalk-for-woocommerce' ); ?> (<?php echo (int) count( $history ); ?>)</summary>
			<ul>
				<?php foreach ( $history as $idx => $entry ) : ?>
					<li>
						<span class="shopwalk-pd-history-when">
							<?php
							$ts = (int) ( $entry['generated_at'] ?? 0 );
							echo esc_html( $ts > 0 ? human_time_diff( $ts ) . ' ' . esc_html__( 'ago', 'shopwalk-for-woocommerce' ) : '—' );
							?>
						</span>
						<button type="button" class="button-link shopwalk-pd-revert-btn" data-index="<?php echo esc_attr( (string) $idx ); ?>">
							<?php esc_html_e( 'Revert', 'shopwalk-for-woocommerce' ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>

	<div class="shopwalk-pd-status" aria-live="polite"></div>
</div>
