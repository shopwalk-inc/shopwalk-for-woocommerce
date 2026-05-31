/* global jQuery, ShopwalkBrandVoice */
/**
 * Brand-voice admin module — wires the corpus picker, file/paste inputs,
 * the Train button, the recurring status poll, and the Reset action.
 *
 * Talks only to wp-admin AJAX endpoints registered by
 * Shopwalk_Brand_Voice_Admin. Never calls api.shopwalk.com directly —
 * the PHP side owns all backend traffic.
 */
( function ( $ ) {
	'use strict';

	if ( typeof ShopwalkBrandVoice === 'undefined' ) {
		return;
	}

	var cfg = ShopwalkBrandVoice;
	var pollTimer = null;
	var currentFilter = 'all';
	var candidates = [];

	function ajax( action, data, opts ) {
		opts = opts || {};
		var payload = $.extend( { action: action, nonce: cfg.nonce }, data || {} );
		return $.ajax( $.extend( {
			url: cfg.ajax_url,
			method: 'POST',
			data: payload,
			dataType: 'json'
		}, opts ) );
	}

	function setStatsFromResponse( data ) {
		if ( data && typeof data.word_count !== 'undefined' ) {
			$( '[data-sw-bv-word-count]' ).text( data.word_count );
		}
		if ( data && typeof data.doc_count !== 'undefined' ) {
			$( '[data-sw-bv-doc-count]' ).text( data.doc_count );
		}
		if ( data && typeof data.min_met !== 'undefined' ) {
			$( '#sw-bv-train-btn' ).prop( 'disabled', ! data.min_met );
		}
	}

	function renderPicker() {
		var $list = $( '#sw-bv-picker-list' );
		$list.empty();

		var rows = candidates.filter( function ( c ) {
			return currentFilter === 'all' || c.type === currentFilter;
		} );

		if ( rows.length === 0 ) {
			$list.append(
				$( '<p>' ).addClass( 'sw-muted' ).text( cfg.i18n.loading )
			);
			return;
		}

		rows.forEach( function ( c ) {
			var checked = c.approved === true;
			var $row = $( '<label class="sw-bv-row"></label>' );
			$row.append(
				$( '<input type="checkbox">' )
					.attr( 'data-source', c.source )
					.prop( 'checked', checked )
			);
			var $meta = $( '<div class="sw-bv-meta"></div>' );
			$meta.append(
				$( '<div class="sw-bv-title"></div>' ).text( c.title ).append(
					$( '<span class="sw-bv-type"></span>' ).text( c.type )
				)
			);
			$meta.append( $( '<div class="sw-bv-excerpt"></div>' ).text( c.excerpt ) );
			$row.append( $meta );
			$row.append(
				$( '<span class="sw-bv-words"></span>' ).text( c.word_count + ' words' )
			);
			$list.append( $row );
		} );
	}

	function loadCandidates() {
		ajax( 'shopwalk_brand_voice_list_corpus', {} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				candidates = resp.data.candidates || [];
				setStatsFromResponse( resp.data );
				renderPicker();
			}
		} );
	}

	function saveSelection() {
		var selection = {};
		$( '#sw-bv-picker-list input[type=checkbox]' ).each( function () {
			selection[ $( this ).attr( 'data-source' ) ] = $( this ).prop( 'checked' );
		} );
		ajax( 'shopwalk_brand_voice_save_selection', {
			selection: JSON.stringify( selection )
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				setStatsFromResponse( resp.data );
				Object.keys( selection ).forEach( function ( src ) {
					candidates.forEach( function ( c ) {
						if ( c.source === src ) {
							c.approved = selection[ src ];
						}
					} );
				} );
			}
		} );
	}

	function savePaste() {
		ajax( 'shopwalk_brand_voice_save_paste', {
			paste: $( '#sw-bv-paste' ).val() || ''
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				setStatsFromResponse( resp.data );
			}
		} );
	}

	function uploadFile( file ) {
		var fd = new FormData();
		fd.append( 'action', 'shopwalk_brand_voice_upload_file' );
		fd.append( 'nonce', cfg.nonce );
		fd.append( 'file', file );
		ajax( 'shopwalk_brand_voice_upload_file', {}, {
			data: fd,
			processData: false,
			contentType: false
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				setStatsFromResponse( resp.data );
				window.location.reload();
			} else {
				window.alert( ( resp && resp.data && resp.data.error ) || 'Upload failed' );
			}
		} );
	}

	function pollStatus() {
		ajax( 'shopwalk_brand_voice_status', {} ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				return;
			}
			var status = resp.data.status;
			$( '[data-sw-bv-status]' ).attr( 'class', 'sw-bv-status sw-bv-status-' + status );
			if ( status === 'ready' || status === 'failed' || status === 'stale' || status === 'untrained' ) {
				stopPolling();
				window.location.reload();
			}
		} );
	}

	function startPolling() {
		stopPolling();
		pollTimer = window.setInterval( pollStatus, ( cfg.poll_interval || 30 ) * 1000 );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function trainNow() {
		ajax( 'shopwalk_brand_voice_train', {} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				window.alert( cfg.i18n.training_started );
				$( '#sw-bv-train-btn' ).prop( 'disabled', true );
				startPolling();
			} else {
				window.alert( ( resp && resp.data && resp.data.error ) || cfg.i18n.training_failed );
			}
		} );
	}

	function resetVoice() {
		if ( ! window.confirm( cfg.i18n.confirm_reset ) ) {
			return;
		}
		ajax( 'shopwalk_brand_voice_reset', {} ).done( function () {
			window.location.reload();
		} );
	}

	$( function () {
		loadCandidates();

		$( '.sw-bv-picker-toolbar .button' ).on( 'click', function () {
			currentFilter = $( this ).attr( 'data-sw-bv-filter' ) || 'all';
			$( '.sw-bv-picker-toolbar .button' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			renderPicker();
		} );

		$( '#sw-bv-picker-save' ).on( 'click', saveSelection );
		$( '#sw-bv-paste-save' ).on( 'click', savePaste );
		$( '#sw-bv-upload' ).on( 'change', function () {
			var f = this.files && this.files[ 0 ];
			if ( f ) {
				uploadFile( f );
			}
		} );
		$( '#sw-bv-train-btn' ).on( 'click', trainNow );
		$( '#sw-bv-reset-btn' ).on( 'click', resetVoice );

		if ( $( '.sw-bv-status-training' ).length ) {
			startPolling();
		}
	} );
} )( jQuery );
