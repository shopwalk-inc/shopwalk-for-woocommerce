<?php
/**
 * Shopwalk_Catalog_Sync_Batcher — collects WC entities into sync batches.
 *
 * Reads via WC's in-process data-store layer (HPOS-compatible) per
 * `platform/woocommerce/wc-rest-consumption.md`. Never calls the public
 * WC REST API — in-process reads avoid the HTTP roundtrip + auth overhead
 * and reuse the WC object cache.
 *
 * Delta detection: uses WC's own modification timestamps (`post_modified_gmt`
 * for products, `date_modified` on orders) rather than maintaining a parallel
 * change-log. WC owns the source-of-truth timestamp; mirroring it would just
 * be a second place to keep in sync. Trade-off: any write path that doesn't
 * touch `post_modified_gmt` (rare — only direct $wpdb writes that bypass
 * `wp_update_post()`) is invisible to delta scans. Those edge cases are
 * caught by the daily reconciliation pass (out of scope for v1.0; tracked
 * separately).
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Catalog_Sync_Batcher — read-side of the sync pipeline.
 */
final class Shopwalk_Catalog_Sync_Batcher {

	/**
	 * Max product IDs returned per delta scan. Bounds the per-tick work
	 * — anything beyond this rolls into the next delta tick. Sized so a
	 * shop with churn well above normal still drains within a couple of
	 * 5-minute windows.
	 */
	private const DELTA_PRODUCT_CAP = 1000;

	/**
	 * Max order IDs returned per delta scan.
	 */
	private const DELTA_ORDER_CAP = 1000;

	/**
	 * Collect a batch of products into the sync payload shape.
	 *
	 * @param int[] $product_ids Product post IDs to include.
	 * @return array{partner_id:string,checksum:string,items:array<int,array<string,mixed>>}
	 */
	public function collect_products( array $product_ids ): array {
		$items = array();
		foreach ( $product_ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}
			$payload = $this->serialize_product( $pid );
			if ( null !== $payload ) {
				/**
				 * Filter the per-product payload before it's added to the batch.
				 * Mirrors the documented `shopwalk_product_sync_payload` filter
				 * surface so themes/integrations can extend.
				 */
				if ( function_exists( 'apply_filters' ) ) {
					$payload = apply_filters( 'shopwalk_product_sync_payload', $payload, $pid );
				}
				$items[] = $payload;
			}
		}

		return $this->wrap_batch( $items );
	}

	/**
	 * Collect a batch of orders into the sync payload shape.
	 *
	 * @param int[]  $order_ids   Order IDs.
	 * @param string $event_type  Event-type tag for the batch (created|status_changed|refunded|delta).
	 * @return array{partner_id:string,checksum:string,items:array<int,array<string,mixed>>,event_type:string}
	 */
	public function collect_orders( array $order_ids, string $event_type = 'created' ): array {
		$items = array();
		foreach ( $order_ids as $oid ) {
			$oid     = (int) $oid;
			$payload = $this->serialize_order( $oid );
			if ( null !== $payload ) {
				$payload['event_type'] = $event_type;
				if ( function_exists( 'apply_filters' ) ) {
					$payload = apply_filters( 'shopwalk_order_sync_payload', $payload, $oid );
				}
				$items[] = $payload;
			}
		}

		$batch               = $this->wrap_batch( $items );
		$batch['event_type'] = $event_type;
		return $batch;
	}

	/**
	 * Find product post IDs modified since a given UNIX timestamp.
	 *
	 * @param int $since UNIX timestamp (UTC).
	 * @return int[] Product post IDs.
	 */
	public function find_products_modified_since( int $since ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$since_sql = gmdate( 'Y-m-d H:i:s', max( 0, $since ) );

		// Direct $wpdb query — per wc-rest-consumption.md, this is faster
		// than instantiating WC_Product objects just to get IDs. The query
		// hits an index on (post_type, post_status, post_modified_gmt).
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type IN ('product','product_variation')
				 AND post_status IN ('publish','draft','pending','private')
				 AND post_modified_gmt > %s
				 ORDER BY post_modified_gmt ASC
				 LIMIT %d",
				$since_sql,
				self::DELTA_PRODUCT_CAP
			)
		);

		// Roll variations up to their parents so a variation edit triggers
		// the parent product re-sync (per the WC hooks spec).
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$parent = (int) wp_get_post_parent_id( $id );
			$out[]  = $parent > 0 ? $parent : $id;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Find order IDs modified since a given UNIX timestamp.
	 *
	 * Uses `wc_get_orders()` (HPOS-compatible) rather than raw $wpdb so
	 * the query works whether HPOS is enabled or the merchant is still on
	 * the legacy `wp_posts` storage path.
	 *
	 * @param int $since UNIX timestamp (UTC).
	 * @return int[] Order IDs.
	 */
	public function find_orders_modified_since( int $since ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$ids = wc_get_orders(
			array(
				'date_modified' => '>' . max( 0, $since ),
				'limit'         => self::DELTA_ORDER_CAP,
				'orderby'       => 'date_modified',
				'order'         => 'ASC',
				'return'        => 'ids',
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Page through every published product ID — used by full sync.
	 *
	 * @param int $page      1-based page number.
	 * @param int $page_size Items per page.
	 * @return int[] Product post IDs for this page.
	 */
	public function find_all_product_ids( int $page, int $page_size ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$page      = max( 1, $page );
		$page_size = max( 1, min( 500, $page_size ) );
		$offset    = ( $page - 1 ) * $page_size;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'product'
				 AND post_status = 'publish'
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				$page_size,
				$offset
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	// ─── Serialization ──────────────────────────────────────────────────

	/**
	 * Serialize a single product into the wire shape.
	 *
	 * Field selection follows wc-rest-consumption.md "Read scope minimization".
	 *
	 * @param int $product_id Product post ID.
	 * @return array<string,mixed>|null Null if the product can't be loaded.
	 */
	public function serialize_product( int $product_id ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! is_object( $product ) ) {
			// Product may have been deleted between enqueue and run — emit
			// a deletion record so the backend can purge.
			return array(
				'external_id' => $product_id,
				'deleted'     => true,
			);
		}

		$data = array(
			'external_id'       => $product_id,
			'sku'               => (string) $product->get_sku(),
			'name'              => (string) $product->get_name(),
			'slug'              => (string) $product->get_slug(),
			'type'              => (string) $product->get_type(),
			'status'            => (string) $product->get_status(),
			'description'       => (string) $product->get_description(),
			'short_description' => (string) $product->get_short_description(),
			'permalink'         => (string) $product->get_permalink(),
			'price'             => (string) $product->get_price(),
			'regular_price'     => (string) $product->get_regular_price(),
			'sale_price'        => (string) $product->get_sale_price(),
			'in_stock'          => (bool) $product->is_in_stock(),
			'stock_status'      => (string) $product->get_stock_status(),
			'weight'            => (string) $product->get_weight(),
			'categories'        => $this->term_names( $product->get_category_ids(), 'product_cat' ),
			'tags'              => $this->term_names( $product->get_tag_ids(), 'product_tag' ),
			'images'            => $this->image_refs( $product ),
			'attributes'        => $this->attribute_map( $product ),
			'modified_at'       => (string) gmdate( 'c', (int) get_post_modified_time( 'U', true, $product_id ) ),
		);

		if ( 'variable' === $product->get_type() && method_exists( $product, 'get_children' ) ) {
			$variations = array();
			foreach ( (array) $product->get_children() as $vid ) {
				$variation = wc_get_product( (int) $vid );
				if ( ! $variation ) {
					continue;
				}
				$variations[] = array(
					'variation_id'  => (int) $vid,
					'sku'           => (string) $variation->get_sku(),
					'price'         => (string) $variation->get_price(),
					'regular_price' => (string) $variation->get_regular_price(),
					'sale_price'    => (string) $variation->get_sale_price(),
					'in_stock'      => (bool) $variation->is_in_stock(),
					'attributes'    => $this->variation_attributes( $variation ),
				);
			}
			if ( ! empty( $variations ) ) {
				$data['variations'] = $variations;
			}
		}

		return $data;
	}

	/**
	 * Serialize a single order into the wire shape.
	 *
	 * Customer email is hashed (SHA256 with a per-tenant salt) per
	 * wc-rest-consumption.md — never sent in plaintext.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,mixed>|null Null if the order can't be loaded.
	 */
	public function serialize_order( int $order_id ): ?array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_object( $order ) ) {
			return null;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
				'quantity'     => (int) ( method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 0 ),
				'line_total'   => (string) ( method_exists( $item, 'get_total' ) ? $item->get_total() : '0' ),
			);
		}

		$email = (string) $order->get_billing_email();
		$hash  = '' !== $email ? hash_hmac( 'sha256', $email, $this->customer_salt() ) : '';

		return array(
			'external_id'     => $order_id,
			'status'          => (string) $order->get_status(),
			'currency'        => (string) $order->get_currency(),
			'total'           => (string) $order->get_total(),
			'date_created'    => $this->iso( $order->get_date_created() ),
			'date_modified'   => $this->iso( $order->get_date_modified() ),
			'customer_hash'   => $hash,
			'billing_country' => (string) $order->get_billing_country(),
			'billing_state'   => (string) $order->get_billing_state(),
			'shipping_country'=> (string) $order->get_shipping_country(),
			'shipping_state'  => (string) $order->get_shipping_state(),
			'items'           => $items,
		);
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	/**
	 * Wrap items into the partner_id+checksum envelope the API expects.
	 *
	 * @param array<int,array<string,mixed>> $items Batch items.
	 * @return array{partner_id:string,checksum:string,items:array<int,array<string,mixed>>}
	 */
	private function wrap_batch( array $items ): array {
		$partner_id = $this->partner_id();
		// Canonical serialization for the checksum: JSON-encode the items
		// array with sort_keys + strict types. Backend recomputes with the
		// same algorithm and rejects on mismatch.
		$canonical = function_exists( 'wp_json_encode' )
			? wp_json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$checksum  = hash( 'sha256', (string) $canonical );

		return array(
			'partner_id' => $partner_id,
			'checksum'   => $checksum,
			'items'      => $items,
		);
	}

	/**
	 * Resolve the merchant's partner_id. Prefers the foundation's
	 * Shopwalk_License helper; falls back to the raw option.
	 *
	 * @return string
	 */
	private function partner_id(): string {
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'partner_id' ) ) {
			$pid = (string) Shopwalk_License::partner_id();
			if ( '' !== $pid ) {
				return $pid;
			}
		}
		return (string) get_option( 'shopwalk_partner_id', '' );
	}

	/**
	 * Per-tenant customer salt for the email hash. Generated on first use;
	 * pinned to the install so the same email always hashes the same way
	 * for this merchant (lets the backend correlate repeat buyers).
	 *
	 * @return string
	 */
	private function customer_salt(): string {
		$salt = (string) get_option( 'shopwalk_customer_hash_salt', '' );
		if ( '' === $salt ) {
			$salt = function_exists( 'wp_generate_password' )
				? wp_generate_password( 64, true, true )
				: bin2hex( random_bytes( 32 ) );
			update_option( 'shopwalk_customer_hash_salt', $salt, false );
		}
		return $salt;
	}

	/**
	 * Map term IDs to a [{id,name,slug}] list.
	 *
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int,array<string,mixed>>
	 */
	private function term_names( array $term_ids, string $taxonomy ): array {
		$out = array();
		foreach ( $term_ids as $tid ) {
			$term = function_exists( 'get_term' ) ? get_term( (int) $tid, $taxonomy ) : null;
			if ( $term && ! is_wp_error( $term ) ) {
				$out[] = array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				);
			}
		}
		return $out;
	}

	/**
	 * Build the image-reference list for a product (URLs + alt + position).
	 * Image binaries are not uploaded — only references.
	 *
	 * @param object $product WC_Product instance.
	 * @return array<int,array<string,mixed>>
	 */
	private function image_refs( object $product ): array {
		$out = array();
		$ids = array();
		if ( method_exists( $product, 'get_image_id' ) ) {
			$main = (int) $product->get_image_id();
			if ( $main > 0 ) {
				$ids[] = $main;
			}
		}
		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
				$ids[] = (int) $gid;
			}
		}
		$position = 0;
		foreach ( array_values( array_unique( $ids ) ) as $aid ) {
			$src = function_exists( 'wp_get_attachment_image_src' )
				? wp_get_attachment_image_src( (int) $aid, 'full' )
				: false;
			$alt = function_exists( 'get_post_meta' )
				? (string) get_post_meta( (int) $aid, '_wp_attachment_image_alt', true )
				: '';
			$out[] = array(
				'attachment_id' => (int) $aid,
				'url'           => is_array( $src ) && isset( $src[0] ) ? (string) $src[0] : '',
				'alt'           => $alt,
				'position'      => $position,
			);
			++$position;
		}
		return $out;
	}

	/**
	 * Build a simple {attribute_name: [values]} map for a product.
	 *
	 * @param object $product WC_Product instance.
	 * @return array<string,array<int,string>>
	 */
	private function attribute_map( object $product ): array {
		if ( ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $product->get_attributes() as $name => $attr ) {
			$key = is_string( $name ) ? $name : (string) $name;
			if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
				$out[ $key ] = array_map( 'strval', (array) $attr->get_options() );
			} elseif ( is_scalar( $attr ) ) {
				$out[ $key ] = array( (string) $attr );
			}
		}
		return $out;
	}

	/**
	 * Variations expose their selected attributes via get_attributes()
	 * returning a {pa_color: "red", size: "M"} map.
	 *
	 * @param object $variation WC_Product_Variation instance.
	 * @return array<string,string>
	 */
	private function variation_attributes( object $variation ): array {
		if ( ! method_exists( $variation, 'get_attributes' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $variation->get_attributes() as $name => $value ) {
			$out[ (string) $name ] = (string) $value;
		}
		return $out;
	}

	/**
	 * Normalize a WC DateTime to an ISO 8601 string (UTC).
	 *
	 * @param mixed $dt WC_DateTime|null.
	 * @return string
	 */
	private function iso( $dt ): string {
		if ( is_object( $dt ) && method_exists( $dt, 'getTimestamp' ) ) {
			return gmdate( 'c', (int) $dt->getTimestamp() );
		}
		return '';
	}
}
