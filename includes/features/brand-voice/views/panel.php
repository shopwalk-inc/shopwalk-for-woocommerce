<?php
/**
 * View — Brand Voice main panel.
 *
 * Expects the following in scope (set by Shopwalk_Brand_Voice_Admin::render_panel):
 *   $status   string   One of untrained|training|ready|failed|stale
 *   $profile  array    Shopwalk_Brand_Voice::get_profile_summary()
 *   $paste    string   Current pasted-text value
 *   $uploads  array    Current uploaded-files map
 *   $word_ct  int      Total approved-corpus word count
 *   $doc_ct   int      Total approved-corpus doc count
 *   $min_ok   bool     Whether corpus meets the minimum word count
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

$voice_id      = isset( $profile['voice_id'] ) ? (string) $profile['voice_id'] : '';
$trained_at    = isset( $profile['trained_at'] ) ? (string) $profile['trained_at'] : '';
$sample_output = isset( $profile['sample_output'] ) ? (string) $profile['sample_output'] : '';
?>
<div class="wrap sw-brand-voice-wrap">
	<h1><?php esc_html_e( 'AI Brand Voice', 'shopwalk-for-woocommerce' ); ?></h1>

	<div class="sw-bv-status sw-bv-status-<?php echo esc_attr( $status ); ?>" data-sw-bv-status>
		<?php
		switch ( $status ) {
			case 'ready':
				/* translators: %s: ISO date */
				printf( esc_html__( 'Voice trained · %s', 'shopwalk-for-woocommerce' ), esc_html( $trained_at ) );
				break;
			case 'training':
				esc_html_e( 'Training in progress — usually 1–3 minutes.', 'shopwalk-for-woocommerce' );
				break;
			case 'failed':
				esc_html_e( 'Last training run failed. See details below.', 'shopwalk-for-woocommerce' );
				break;
			case 'stale':
				esc_html_e( 'Corpus changed since last training — retrain to refresh your brand voice.', 'shopwalk-for-woocommerce' );
				break;
			default:
				esc_html_e( 'No brand voice trained yet.', 'shopwalk-for-woocommerce' );
		}
		?>
	</div>

	<?php include __DIR__ . '/corpus-picker.php'; ?>

	<div class="sw-card">
		<h2><?php esc_html_e( 'Add a brand brief or off-site samples', 'shopwalk-for-woocommerce' ); ?></h2>

		<h3><?php esc_html_e( 'Paste text', 'shopwalk-for-woocommerce' ); ?></h3>
		<p class="sw-muted">
			<?php esc_html_e( 'Paste any off-site content that represents your voice — newsletter archives, ad copy, About-page drafts. Up to 200KB.', 'shopwalk-for-woocommerce' ); ?>
		</p>
		<textarea
			id="sw-bv-paste"
			class="large-text code"
			rows="8"
			placeholder="<?php esc_attr_e( 'Paste sample text here…', 'shopwalk-for-woocommerce' ); ?>"
		><?php echo esc_textarea( $paste ); ?></textarea>
		<p>
			<button type="button" class="button" id="sw-bv-paste-save">
				<?php esc_html_e( 'Save pasted text', 'shopwalk-for-woocommerce' ); ?>
			</button>
		</p>

		<h3><?php esc_html_e( 'Upload text files', 'shopwalk-for-woocommerce' ); ?></h3>
		<p class="sw-muted">
			<?php
			/* translators: 1: max files, 2: max bytes (human-readable) */
			printf(
				esc_html__( 'Plain text only — .txt or .md, up to %1$d files, %2$s each.', 'shopwalk-for-woocommerce' ),
				(int) Shopwalk_Brand_Voice_Corpus_Manager::MAX_UPLOADED_FILES,
				esc_html( size_format( Shopwalk_Brand_Voice_Corpus_Manager::MAX_UPLOAD_BYTES ) )
			);
			?>
		</p>
		<input type="file" id="sw-bv-upload" accept=".txt,.md,text/plain,text/markdown" />
		<ul class="sw-bv-upload-list">
			<?php foreach ( $uploads as $source => $upload ) : ?>
				<li>
					<code><?php echo esc_html( (string) ( $upload['name'] ?? $source ) ); ?></code>
					<span class="sw-muted">
						<?php echo esc_html( size_format( (int) ( $upload['bytes'] ?? 0 ) ) ); ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="sw-card sw-bv-train">
		<h2><?php esc_html_e( 'Train your brand voice', 'shopwalk-for-woocommerce' ); ?></h2>

		<p class="sw-bv-corpus-stats">
			<strong data-sw-bv-doc-count><?php echo (int) $doc_ct; ?></strong>
			<?php esc_html_e( 'docs', 'shopwalk-for-woocommerce' ); ?>
			·
			<strong data-sw-bv-word-count><?php echo (int) $word_ct; ?></strong>
			<?php esc_html_e( 'words', 'shopwalk-for-woocommerce' ); ?>
			<?php if ( ! $min_ok ) : ?>
				·
				<span class="sw-warn">
					<?php
					/* translators: %d: minimum word count */
					printf( esc_html__( 'minimum %d', 'shopwalk-for-woocommerce' ), (int) Shopwalk_Brand_Voice_Corpus_Manager::MIN_WORD_COUNT );
					?>
				</span>
			<?php endif; ?>
		</p>

		<p>
			<button
				type="button"
				class="button button-primary"
				id="sw-bv-train-btn"
				<?php disabled( ! $min_ok || 'training' === $status ); ?>>
				<?php
				echo 'untrained' === $status
					? esc_html__( 'Train brand voice', 'shopwalk-for-woocommerce' )
					: esc_html__( 'Retrain brand voice', 'shopwalk-for-woocommerce' );
				?>
			</button>
			<button type="button" class="button button-link-delete" id="sw-bv-reset-btn">
				<?php esc_html_e( 'Reset brand voice', 'shopwalk-for-woocommerce' ); ?>
			</button>
		</p>
	</div>

	<?php if ( in_array( $status, array( 'ready', 'stale' ), true ) && '' !== $sample_output ) : ?>
		<div class="sw-card sw-bv-preview">
			<h2><?php esc_html_e( 'Voice preview', 'shopwalk-for-woocommerce' ); ?></h2>
			<p class="sw-muted">
				<?php esc_html_e( 'Sample output Shopwalk generated using your trained voice. Use this to sanity-check how your voice reads in the model before turning on other AI features.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<blockquote data-sw-bv-sample>
				<?php echo wp_kses_post( wpautop( $sample_output ) ); ?>
			</blockquote>
			<?php if ( '' !== $voice_id ) : ?>
				<p class="sw-muted">
					<code><?php echo esc_html( $voice_id ); ?></code>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
