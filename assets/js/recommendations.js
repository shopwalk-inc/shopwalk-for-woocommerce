/**
 * Shopwalk Recommendations — lazy-load hydrator.
 *
 * Finds every <section data-shopwalk-recs> on the page, fetches from
 * the WP-REST endpoint, and replaces the skeleton placeholders with
 * the WC `content-product` HTML the REST handler returns. We hold the
 * fetch until the section scrolls within 200px of the viewport so the
 * carousel below the fold never blocks the initial paint.
 *
 * No external runtime dependency — vanilla DOM + fetch.
 */

( function () {
	'use strict';

	var cfg = window.ShopwalkRecommendations || {};
	if ( ! cfg.endpoint ) {
		return;
	}

	function hydrate( section ) {
		if ( section.dataset.shopwalkHydrated === '1' ) {
			return;
		}
		section.dataset.shopwalkHydrated = '1';

		var type    = section.dataset.type || 'related';
		var product = section.dataset.productId || '0';
		var count   = section.dataset.count || '6';

		var url = cfg.endpoint
			+ ( cfg.endpoint.indexOf( '?' ) >= 0 ? '&' : '?' )
			+ 'type=' + encodeURIComponent( type )
			+ '&product_id=' + encodeURIComponent( product )
			+ '&count=' + encodeURIComponent( count );

		var headers = { 'Accept': 'application/json' };
		if ( cfg.nonce ) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}

		fetch( url, { headers: headers, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				renderInto( section, data );
			} )
			.catch( function () {
				// Network/JSON error — hide the slot rather than show
				// broken skeleton state forever.
				hideSection( section );
			} );
	}

	function renderInto( section, data ) {
		var list = section.querySelector( '[data-shopwalk-recs-items]' );
		if ( ! list ) {
			return;
		}

		if ( ! data || ! data.ok || ! Array.isArray( data.items ) || data.items.length === 0 ) {
			// Empty / error response — hide the whole section. We don't
			// surface "no recommendations" copy in the storefront
			// because there's no value in revealing an internal feature
			// is dark to the shopper.
			hideSection( section );
			return;
		}

		// Replace skeleton with real cards. We use innerHTML because
		// each item.html is a fully-rendered <li> from WC's template
		// (server-trusted, no shopper input on this surface).
		var html = '';
		for ( var i = 0; i < data.items.length; i++ ) {
			html += data.items[i].html || '';
		}
		list.innerHTML = html;
		section.setAttribute( 'aria-busy', 'false' );

		if ( data.fallback ) {
			section.dataset.fallback = '1';
		}
	}

	function hideSection( section ) {
		section.setAttribute( 'aria-busy', 'false' );
		section.style.display = 'none';
	}

	function init() {
		var sections = document.querySelectorAll( 'section[data-shopwalk-recs]' );
		if ( sections.length === 0 ) {
			return;
		}

		if ( typeof IntersectionObserver === 'function' ) {
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						hydrate( entry.target );
						io.unobserve( entry.target );
					}
				} );
			}, { rootMargin: '200px' } );
			for ( var i = 0; i < sections.length; i++ ) {
				io.observe( sections[i] );
			}
		} else {
			// Old browser — just hydrate everything immediately.
			for ( var j = 0; j < sections.length; j++ ) {
				hydrate( sections[j] );
			}
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
