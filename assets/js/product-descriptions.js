/* global jQuery, ShopwalkProductDescriptions */
( function ( $, cfg ) {
	'use strict';

	if ( ! cfg || ! cfg.restRoot ) {
		return;
	}

	var $box   = $( '.shopwalk-pd-meta-box' );
	var $panel = $( '.shopwalk-pd-job-panel' );

	function setStatus( $ctx, msg, kind ) {
		var $s = $ctx.find( '.shopwalk-pd-status' );
		$s.removeClass( 'is-error is-ok' );
		if ( kind ) {
			$s.addClass( 'is-' + kind );
		}
		$s.text( msg || '' );
	}

	function apiPost( path, body ) {
		return $.ajax( {
			url:        cfg.restRoot + path,
			method:     'POST',
			data:       JSON.stringify( body ),
			contentType:'application/json',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', cfg.restNonce );
			},
		} );
	}

	// ── Per-product meta box ─────────────────────────────────────────────
	if ( $box.length ) {
		var productId = parseInt( $box.attr( 'data-product-id' ), 10 ) || 0;
		var lastResult = null;

		$box.on( 'click', '.shopwalk-pd-generate-btn', function () {
			var fields = ( $( '#shopwalk-pd-fields' ).val() || 'long,short' ).split( ',' );
			var body = {
				product_id:      productId,
				fields:          fields,
				tone:            $( '#shopwalk-pd-tone' ).val(),
				length:          $( '#shopwalk-pd-length' ).val(),
				focus_keyphrase: $( '#shopwalk-pd-keyphrase' ).val(),
				include_images:  $( '#shopwalk-pd-include-images' ).is( ':checked' ),
			};

			setStatus( $box, cfg.i18n.generating );
			$box.find( '.shopwalk-pd-generate-btn' ).prop( 'disabled', true );
			$box.find( '.shopwalk-pd-preview' ).attr( 'hidden', true );

			apiPost( 'generate', body )
				.done( function ( res ) {
					$box.find( '.shopwalk-pd-generate-btn' ).prop( 'disabled', false );
					if ( ! res || ! res.ok ) {
						setStatus( $box, ( res && res.error ) || cfg.i18n.error, 'error' );
						return;
					}
					lastResult = ( res.body || {} );
					var $prev = $box.find( '.shopwalk-pd-preview' );
					$prev.removeAttr( 'hidden' );
					if ( lastResult.long ) {
						$prev.find( '.shopwalk-pd-preview-long' ).removeAttr( 'hidden' );
						$prev.find( '.shopwalk-pd-preview-body-long' ).text( lastResult.long );
					} else {
						$prev.find( '.shopwalk-pd-preview-long' ).attr( 'hidden', true );
					}
					if ( lastResult.short ) {
						$prev.find( '.shopwalk-pd-preview-short' ).removeAttr( 'hidden' );
						$prev.find( '.shopwalk-pd-preview-body-short' ).text( lastResult.short );
					} else {
						$prev.find( '.shopwalk-pd-preview-short' ).attr( 'hidden', true );
					}
					setStatus( $box, '' );
				} )
				.fail( function () {
					$box.find( '.shopwalk-pd-generate-btn' ).prop( 'disabled', false );
					setStatus( $box, cfg.i18n.error, 'error' );
				} );
		} );

		$box.on( 'click', '.shopwalk-pd-accept-btn', function () {
			if ( ! lastResult || ( ! lastResult.long && ! lastResult.short ) ) {
				return;
			}
			if ( ! window.confirm( cfg.i18n.confirmApply ) ) { // eslint-disable-line no-alert
				return;
			}
			apiPost( 'apply', {
				product_id: productId,
				long:       lastResult.long || '',
				short:      lastResult.short || '',
			} ).done( function ( res ) {
				if ( res && res.ok ) {
					setStatus( $box, '✓ ' + cfg.i18n.confirmApply, 'ok' );
					// Reload so the WC editor re-reads post_content.
					window.location.reload();
				} else {
					setStatus( $box, cfg.i18n.error, 'error' );
				}
			} );
		} );

		$box.on( 'click', '.shopwalk-pd-discard-btn', function () {
			lastResult = null;
			$box.find( '.shopwalk-pd-preview' ).attr( 'hidden', true );
			setStatus( $box, '' );
		} );

		$box.on( 'click', '.shopwalk-pd-revert-btn', function () {
			var idx = parseInt( $( this ).attr( 'data-index' ), 10 );
			if ( isNaN( idx ) ) { return; }
			apiPost( 'revert', { product_id: productId, history_index: idx } )
				.done( function ( res ) {
					if ( res && res.ok ) {
						setStatus( $box, cfg.i18n.reverted, 'ok' );
						window.location.reload();
					} else {
						setStatus( $box, cfg.i18n.error, 'error' );
					}
				} );
		} );
	}

	// ── Dashboard panel — poll job progress ──────────────────────────────
	if ( $panel.length ) {
		var jobId = $panel.attr( 'data-job-id' );
		if ( jobId ) {
			var pollHandle = window.setInterval( function () {
				$.ajax( {
					url:        cfg.restRoot + 'job/' + encodeURIComponent( jobId ),
					method:     'GET',
					beforeSend: function ( xhr ) { xhr.setRequestHeader( 'X-WP-Nonce', cfg.restNonce ); },
				} ).done( function ( res ) {
					if ( ! res || ! res.ok || ! res.job ) { return; }
					var job = res.job;
					var total = parseInt( job.total, 10 ) || 0;
					var done  = ( parseInt( job.completed, 10 ) || 0 ) + ( parseInt( job.failed, 10 ) || 0 );
					var pct   = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
					$panel.find( '.shopwalk-pd-progress-bar' ).css( 'width', pct + '%' );
					$panel.find( '.shopwalk-pd-progress-text' ).text(
						( job.completed || 0 ) + ' completed · ' +
						( job.failed || 0 ) + ' failed · ' +
						total + ' total'
					);
					$panel.find( '.shopwalk-pd-status-label' ).text( '— ' + ( job.status || '' ) );
					if ( job.status && job.status !== 'running' ) {
						window.clearInterval( pollHandle );
					}
				} );
			}, 4000 );
		}
	}
}( jQuery, window.ShopwalkProductDescriptions ) );
