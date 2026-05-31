<?php
/**
 * Panel view — rendered by Shopwalk_Product_Descriptions_Feature::render_dashboard_panel().
 *
 * Pro-only. Shows two cards:
 *  1. Bulk-generate form (scope + options)
 *  2. In-flight job progress (when query string carries shopwalk_bulk_job)
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

$active_job_id = isset( $_GET['shopwalk_bulk_job'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['shopwalk_bulk_job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$enqueued      = isset( $_GET['shopwalk_bulk_items_enqueued'] ) ? (int) $_GET['shopwalk_bulk_items_enqueued'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$bulk = new Shopwalk_Product_Descriptions_Bulk();
$job  = '' !== $active_job_id ? $bulk->get_job( $active_job_id ) : null;

$categories = function_exists( 'get_terms' )
	? (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) )
	: array();
$tags = function_exists( 'get_terms' )
	? (array) get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) )
	: array();
?>
<div class="ucp-card shopwalk-pd-card">
	<h2><?php esc_html_e( 'AI Product Descriptions', 'shopwalk-for-woocommerce' ); ?>
		<span class="status-pill ok"><?php esc_html_e( 'Pro', 'shopwalk-for-woocommerce' ); ?></span>
	</h2>
	<p><?php esc_html_e( 'Generate or rewrite WooCommerce product descriptions in your brand voice. Per-product, or in bulk via Action Scheduler.', 'shopwalk-for-woocommerce' ); ?></p>

	<?php if ( $job ) : ?>
		<div class="shopwalk-pd-job-panel" data-job-id="<?php echo esc_attr( $active_job_id ); ?>">
			<h3><?php esc_html_e( 'Bulk job', 'shopwalk-for-woocommerce' ); ?> <code><?php echo esc_html( $active_job_id ); ?></code></h3>
			<?php
			$total     = (int) ( $job['total'] ?? 0 );
			$completed = (int) ( $job['completed'] ?? 0 );
			$failed    = (int) ( $job['failed'] ?? 0 );
			$pct       = $total > 0 ? (int) round( ( ( $completed + $failed ) / $total ) * 100 ) : 0;
			?>
			<div class="shopwalk-pd-progress">
				<div class="shopwalk-pd-progress-bar" style="width:<?php echo esc_attr( (string) $pct ); ?>%"></div>
			</div>
			<p class="shopwalk-pd-progress-text">
				<?php
				printf(
					/* translators: 1: completed, 2: failed, 3: total */
					esc_html__( '%1$d completed · %2$d failed · %3$d total', 'shopwalk-for-woocommerce' ),
					$completed,
					$failed,
					$total
				);
				?>
				<span class="shopwalk-pd-status-label">— <?php echo esc_html( (string) ( $job['status'] ?? 'running' ) ); ?></span>
			</p>
			<?php if ( 'running' === (string) ( $job['status'] ?? '' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="shopwalk_pd_bulk_cancel" />
					<input type="hidden" name="job_id" value="<?php echo esc_attr( $active_job_id ); ?>" />
					<?php wp_nonce_field( Shopwalk_Product_Descriptions_Admin::NONCE_ACTION ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Cancel job', 'shopwalk-for-woocommerce' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php elseif ( $enqueued > 0 ) : ?>
		<p class="shopwalk-pd-banner shopwalk-pd-banner-info">
			<?php
			printf(
				/* translators: %d: number of products */
				esc_html__( 'Queued %d product(s) for description generation.', 'shopwalk-for-woocommerce' ),
				$enqueued
			);
			?>
		</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shopwalk-pd-bulk-form">
		<input type="hidden" name="action" value="shopwalk_pd_bulk_kickoff" />
		<?php wp_nonce_field( Shopwalk_Product_Descriptions_Admin::NONCE_ACTION ); ?>

		<h3><?php esc_html_e( 'Start a bulk job', 'shopwalk-for-woocommerce' ); ?></h3>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="shopwalk-pd-scope"><?php esc_html_e( 'Scope', 'shopwalk-for-woocommerce' ); ?></label></th>
				<td>
					<select name="scope" id="shopwalk-pd-scope">
						<option value="all"><?php esc_html_e( 'All products', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="empty"><?php esc_html_e( 'Products with empty descriptions', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="shorter_than:50"><?php esc_html_e( 'Descriptions shorter than 50 chars', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="shorter_than:200"><?php esc_html_e( 'Descriptions shorter than 200 chars', 'shopwalk-for-woocommerce' ); ?></option>
						<?php foreach ( $categories as $term ) : if ( ! isset( $term->term_id ) ) { continue; } ?>
							<option value="category:<?php echo esc_attr( (string) $term->term_id ); ?>">
								<?php
								/* translators: %s: category name */
								echo esc_html( sprintf( __( 'Category: %s', 'shopwalk-for-woocommerce' ), (string) $term->name ) );
								?>
							</option>
						<?php endforeach; ?>
						<?php foreach ( $tags as $term ) : if ( ! isset( $term->term_id ) ) { continue; } ?>
							<option value="tag:<?php echo esc_attr( (string) $term->term_id ); ?>">
								<?php
								/* translators: %s: tag name */
								echo esc_html( sprintf( __( 'Tag: %s', 'shopwalk-for-woocommerce' ), (string) $term->name ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fields', 'shopwalk-for-woocommerce' ); ?></th>
				<td>
					<label><input type="checkbox" name="fields[]" value="long" checked /> <?php esc_html_e( 'Long description', 'shopwalk-for-woocommerce' ); ?></label><br />
					<label><input type="checkbox" name="fields[]" value="short" checked /> <?php esc_html_e( 'Short description', 'shopwalk-for-woocommerce' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="shopwalk-pd-tone-bulk"><?php esc_html_e( 'Tone', 'shopwalk-for-woocommerce' ); ?></label></th>
				<td>
					<select name="tone" id="shopwalk-pd-tone-bulk">
						<option value="brand_voice"><?php esc_html_e( 'Brand voice', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="friendly"><?php esc_html_e( 'Friendly', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="professional"><?php esc_html_e( 'Professional', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="technical"><?php esc_html_e( 'Technical', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="playful"><?php esc_html_e( 'Playful', 'shopwalk-for-woocommerce' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="shopwalk-pd-length-bulk"><?php esc_html_e( 'Length', 'shopwalk-for-woocommerce' ); ?></label></th>
				<td>
					<select name="length" id="shopwalk-pd-length-bulk">
						<option value="short"><?php esc_html_e( 'Short (~50 words)', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="medium" selected><?php esc_html_e( 'Medium (~150 words)', 'shopwalk-for-woocommerce' ); ?></option>
						<option value="long"><?php esc_html_e( 'Long (~300 words)', 'shopwalk-for-woocommerce' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="shopwalk-pd-keyphrase-bulk"><?php esc_html_e( 'Focus keyphrase', 'shopwalk-for-woocommerce' ); ?></label></th>
				<td>
					<input type="text" name="focus_keyphrase" id="shopwalk-pd-keyphrase-bulk" class="regular-text" placeholder="<?php esc_attr_e( 'Optional', 'shopwalk-for-woocommerce' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Include images', 'shopwalk-for-woocommerce' ); ?></th>
				<td>
					<label><input type="checkbox" name="include_images" value="1" checked /> <?php esc_html_e( 'Send product images to the vision model', 'shopwalk-for-woocommerce' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Save mode', 'shopwalk-for-woocommerce' ); ?></th>
				<td>
					<label><input type="radio" name="mode" value="review_queue" checked /> <?php esc_html_e( 'Review queue — I will inspect each result before applying', 'shopwalk-for-woocommerce' ); ?></label><br />
					<label><input type="radio" name="mode" value="auto_save" /> <?php esc_html_e( 'Auto-save — write directly (the prior version is kept in history)', 'shopwalk-for-woocommerce' ); ?></label>
				</td>
			</tr>
		</table>

		<p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Start bulk generation', 'shopwalk-for-woocommerce' ); ?></button>
		</p>
	</form>
</div>
