/**
 * Editor preview for the Shopwalk Recommendations block.
 *
 * The block server-renders identically in the editor and on the
 * storefront — what the editor user sees is the same skeleton + title
 * the shopper will see for ~80ms before the carousel hydrates. We use
 * ServerSideRender so the same PHP render path drives both.
 *
 * Bare-minimum inspector controls: type, count, layout, optional
 * title, optional explicit product id (defaults to "the page's
 * product" for product templates).
 */

( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var el          = wp.element.createElement;
	var __          = wp.i18n.__;
	var ServerSide  = wp.serverSideRender;
	var InspectorControls = wp.blockEditor && wp.blockEditor.InspectorControls;
	var PanelBody   = wp.components && wp.components.PanelBody;
	var SelectControl = wp.components && wp.components.SelectControl;
	var RangeControl  = wp.components && wp.components.RangeControl;
	var TextControl   = wp.components && wp.components.TextControl;

	wp.blocks.registerBlockType( 'shopwalk/recommendations', {
		title:       __( 'Shopwalk Recommendations', 'shopwalk-for-woocommerce' ),
		description: __( 'AI-driven product recommendations from your own catalog and order signals.', 'shopwalk-for-woocommerce' ),
		category:    'woocommerce',
		icon:        'grid-view',
		edit: function ( props ) {
			var attrs = props.attributes;
			var children = [];

			if ( InspectorControls && PanelBody ) {
				children.push( el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Recommendation', 'shopwalk-for-woocommerce' ), initialOpen: true },
						SelectControl && el( SelectControl, {
							label: __( 'Type', 'shopwalk-for-woocommerce' ),
							value: attrs.type,
							options: [
								{ value: 'also_viewed',  label: __( 'Customers also viewed', 'shopwalk-for-woocommerce' ) },
								{ value: 'related',      label: __( 'Related products', 'shopwalk-for-woocommerce' ) },
								{ value: 'fbt',          label: __( 'Frequently bought together', 'shopwalk-for-woocommerce' ) },
								{ value: 'personalized', label: __( 'Personalized', 'shopwalk-for-woocommerce' ) }
							],
							onChange: function ( v ) { props.setAttributes( { type: v } ); }
						} ),
						RangeControl && el( RangeControl, {
							label: __( 'Count', 'shopwalk-for-woocommerce' ),
							value: attrs.count,
							min: 1,
							max: 24,
							onChange: function ( v ) { props.setAttributes( { count: v } ); }
						} ),
						SelectControl && el( SelectControl, {
							label: __( 'Layout', 'shopwalk-for-woocommerce' ),
							value: attrs.layout,
							options: [
								{ value: 'carousel', label: __( 'Carousel', 'shopwalk-for-woocommerce' ) },
								{ value: 'grid',     label: __( 'Grid', 'shopwalk-for-woocommerce' ) },
								{ value: 'list',     label: __( 'List', 'shopwalk-for-woocommerce' ) }
							],
							onChange: function ( v ) { props.setAttributes( { layout: v } ); }
						} ),
						TextControl && el( TextControl, {
							label: __( 'Title (optional)', 'shopwalk-for-woocommerce' ),
							value: attrs.title || '',
							onChange: function ( v ) { props.setAttributes( { title: v } ); }
						} ),
						TextControl && el( TextControl, {
							label: __( 'Context product id (0 = use current product)', 'shopwalk-for-woocommerce' ),
							type:  'number',
							value: String( attrs.productId || 0 ),
							onChange: function ( v ) { props.setAttributes( { productId: parseInt( v, 10 ) || 0 } ); }
						} )
					)
				) );
			}

			children.push( ServerSide
				? el( ServerSide, { key: 'ssr', block: 'shopwalk/recommendations', attributes: attrs } )
				: el( 'div', { key: 'fallback', className: 'shopwalk-rec-editor-fallback' },
					__( 'Shopwalk Recommendations — preview unavailable in this editor build.', 'shopwalk-for-woocommerce' )
				)
			);

			return children;
		},
		save: function () {
			// Server-rendered — nothing to save in the post content.
			return null;
		}
	} );
} )( window.wp );
