/* Shopwalk — AI Catalog Sync admin panel
 *
 * Minimal JS: confirm before the "Pause sync" submission so an accidental
 * click on a busy store doesn't silently halt the pipeline. Everything
 * else on the panel is plain HTML forms.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.shopwalk-catalog-sync-form' );
		forms.forEach( function ( form ) {
			var actionInput = form.querySelector( 'input[name="action"]' );
			if ( ! actionInput ) {
				return;
			}
			if ( actionInput.value !== 'shopwalk_catalog_sync_toggle_pause' ) {
				return;
			}
			var button = form.querySelector( 'button[type="submit"]' );
			if ( ! button ) {
				return;
			}
			// Only confirm when entering the paused state (button label says "Pause").
			form.addEventListener( 'submit', function ( ev ) {
				if ( button.textContent && button.textContent.toLowerCase().indexOf( 'pause' ) === 0 ) {
					var ok = window.confirm(
						'Pause catalog sync? New product and order changes will queue but will not push to Shopwalk until you resume.'
					);
					if ( ! ok ) {
						ev.preventDefault();
					}
				}
			} );
		} );
	} );
} )();
