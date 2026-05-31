<?php
/**
 * AI Semantic Search — dashboard panel view.
 *
 * Expects $ctx with:
 *   tier, pro_required, mode, scope, limit, synonyms, synonyms_count,
 *   catalog_ok, saved, form_action, nonce_field, nonce_action,
 *   embedded_in_dashboard
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string,mixed> $ctx */
$mode_options  = array(
	'off'     => __( 'Off — use WordPress\'s native search', 'shopwalk-for-woocommerce' ),
	'augment' => __( 'Augment — mix Shopwalk results with native', 'shopwalk-for-woocommerce' ),
	'replace' => __( 'Replace — Shopwalk results only (recommended)', 'shopwalk-for-woocommerce' ),
);
$scope_options = array(
	'product' => __( 'Products', 'shopwalk-for-woocommerce' ),
	'post'    => __( 'Blog posts', 'shopwalk-for-woocommerce' ),
	'page'    => __( 'Pages', 'shopwalk-for-woocommerce' ),
);
?>
<div class="sw-card sw-semsearch-panel">
	<h2>
		<?php esc_html_e( 'AI Semantic Search', 'shopwalk-for-woocommerce' ); ?>
		<?php if ( ! empty( $ctx['pro_required'] ) ) : ?>
			<span class="sw-badge sw-badge-pro"><?php esc_html_e( 'Pro required', 'shopwalk-for-woocommerce' ); ?></span>
		<?php endif; ?>
	</h2>

	<p class="sw-muted">
		<?php esc_html_e( 'Vector-search-backed product search on your storefront. Match on meaning, not just keywords — your existing theme renders the results.', 'shopwalk-for-woocommerce' ); ?>
	</p>

	<?php if ( ! empty( $ctx['saved'] ) ) : ?>
		<div class="notice notice-success inline">
			<p><?php esc_html_e( 'Search settings saved.', 'shopwalk-for-woocommerce' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $ctx['pro_required'] ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to upgrade page */
						__( 'AI Semantic Search is a Pro feature. <a href="%s" target="_blank" rel="noopener">Upgrade your Shopwalk plan</a> to enable it.', 'shopwalk-for-woocommerce' ),
						esc_url( SHOPWALK_PARTNERS_URL . '/upgrade' )
					)
				);
				?>
			</p>
		</div>
	<?php elseif ( empty( $ctx['catalog_ok'] ) ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Your catalog hasn\'t completed an initial sync yet. Semantic search will return zero results until embeddings are built. Once the first sync finishes, search starts working automatically — no further action needed.', 'shopwalk-for-woocommerce' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( (string) $ctx['form_action'] ); ?>" enctype="multipart/form-data" class="sw-semsearch-form">
		<?php wp_nonce_field( (string) $ctx['nonce_action'], (string) $ctx['nonce_field'] ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="shopwalk_semsearch_mode"><?php esc_html_e( 'Search mode', 'shopwalk-for-woocommerce' ); ?></label>
				</th>
				<td>
					<select
						id="shopwalk_semsearch_mode"
						name="shopwalk_semsearch_mode"
						<?php disabled( ! empty( $ctx['pro_required'] ) ); ?>
					>
						<?php foreach ( $mode_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $ctx['mode'] ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Off passes search through to WordPress unchanged. Augment merges Shopwalk-ranked results with native results. Replace shows Shopwalk results only.', 'shopwalk-for-woocommerce' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Scope', 'shopwalk-for-woocommerce' ); ?></th>
				<td>
					<fieldset>
						<?php foreach ( $scope_options as $value => $label ) : ?>
							<label>
								<input
									type="checkbox"
									name="shopwalk_semsearch_scope[]"
									value="<?php echo esc_attr( $value ); ?>"
									<?php checked( in_array( $value, (array) $ctx['scope'], true ) ); ?>
									<?php disabled( ! empty( $ctx['pro_required'] ) ); ?>
								>
								<?php echo esc_html( $label ); ?>
							</label><br>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'Which content types to include in semantic search.', 'shopwalk-for-woocommerce' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="shopwalk_semsearch_limit"><?php esc_html_e( 'Result count', 'shopwalk-for-woocommerce' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="shopwalk_semsearch_limit"
						name="shopwalk_semsearch_limit"
						value="<?php echo esc_attr( (string) (int) $ctx['limit'] ); ?>"
						min="1"
						max="100"
						<?php disabled( ! empty( $ctx['pro_required'] ) ); ?>
					>
					<p class="description">
						<?php esc_html_e( 'Maximum number of results returned per search.', 'shopwalk-for-woocommerce' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="shopwalk_semsearch_synonyms_csv"><?php esc_html_e( 'Synonym dictionary', 'shopwalk-for-woocommerce' ); ?></label>
				</th>
				<td>
					<input
						type="file"
						id="shopwalk_semsearch_synonyms_csv"
						name="shopwalk_semsearch_synonyms_csv"
						accept=".csv,text/csv"
						<?php disabled( ! empty( $ctx['pro_required'] ) ); ?>
					>
					<p class="description">
						<?php
						printf(
							/* translators: %d: current synonym pair count */
							esc_html__( 'Upload a CSV — one synonym row per line, e.g. %1$s. Currently loaded: %2$d row(s).', 'shopwalk-for-woocommerce' ),
							'<code>running shoes,sneakers,trainers</code>',
							(int) $ctx['synonyms_count']
						);
						?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary" <?php disabled( ! empty( $ctx['pro_required'] ) ); ?>>
				<?php esc_html_e( 'Save search settings', 'shopwalk-for-woocommerce' ); ?>
			</button>
		</p>
	</form>
</div>
