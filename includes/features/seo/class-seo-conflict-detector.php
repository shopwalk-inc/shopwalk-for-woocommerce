<?php
/**
 * Shopwalk_Seo_Conflict_Detector — detect adjacent SEO plugins and adapt.
 *
 * The plugin must coexist with the three dominant WP SEO plugins (Yoast,
 * RankMath, AIOSEO). When any of them is active, this detector reports it
 * and the generator writes meta title / meta description into the detected
 * plugin's canonical postmeta keys instead of Shopwalk fallback meta. This
 * keeps the merchant's existing SEO plugin in charge of the meta surface —
 * Shopwalk supplies AI generation; the SEO plugin supplies rendering,
 * analysis, and sitemap.
 *
 * Image alt text always writes to `_wp_attachment_image_alt` regardless of
 * which SEO plugin is present, because that is the WordPress-standard field
 * every theme and every SEO plugin reads.
 *
 * ── Behavior matrix ─────────────────────────────────────────────────────────
 *
 * | Detected plugin     | Meta title field          | Meta desc field             |
 * |---------------------|---------------------------|-----------------------------|
 * | Yoast SEO           | _yoast_wpseo_title        | _yoast_wpseo_metadesc       |
 * | RankMath            | rank_math_title           | rank_math_description       |
 * | All In One SEO      | _aioseo_title             | _aioseo_description         |
 * | (none)              | _shopwalk_seo_title       | _shopwalk_seo_metadesc      |
 *
 * Detection precedence when multiple are active (rare but possible during a
 * merchant migration): Yoast → RankMath → AIOSEO → fallback. Whichever wins
 * is the one Shopwalk writes to; the merchant is expected to resolve the
 * multi-plugin conflict on their side.
 *
 * Focus keyphrase is written to the active SEO plugin's focus-kw field where
 * one exists (Yoast: `_yoast_wpseo_focuskw`; RankMath: `rank_math_focus_keyword`;
 * AIOSEO: `_aioseo_keyphrases`). Skipped silently when no SEO plugin is
 * detected — there is no widely-used WP-native focus-keyphrase field.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Seo_Conflict_Detector — adapter between Shopwalk-generated SEO
 * content and the merchant's installed SEO plugin (if any).
 */
final class Shopwalk_Seo_Conflict_Detector {

	public const TARGET_YOAST    = 'yoast';
	public const TARGET_RANKMATH = 'rankmath';
	public const TARGET_AIOSEO   = 'aioseo';
	public const TARGET_FALLBACK = 'shopwalk';

	/**
	 * Field-key map. Public so tests can assert against it without having to
	 * hand-construct the matrix.
	 *
	 * @var array<string,array{title:string,description:string,focus:?string,label:string}>
	 */
	public const TARGETS = array(
		self::TARGET_YOAST    => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'focus'       => '_yoast_wpseo_focuskw',
			'label'       => 'Yoast SEO',
		),
		self::TARGET_RANKMATH => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'focus'       => 'rank_math_focus_keyword',
			'label'       => 'Rank Math',
		),
		self::TARGET_AIOSEO   => array(
			'title'       => '_aioseo_title',
			'description' => '_aioseo_description',
			'focus'       => '_aioseo_keyphrases',
			'label'       => 'All in One SEO',
		),
		self::TARGET_FALLBACK => array(
			'title'       => '_shopwalk_seo_title',
			'description' => '_shopwalk_seo_metadesc',
			'focus'       => null,
			'label'       => 'Shopwalk (fallback)',
		),
	);

	/**
	 * Returns the active SEO plugin slug. Detection is by class/function/
	 * constant existence rather than by `is_plugin_active()` so it works
	 * regardless of plugin file naming and works in must-use installs.
	 *
	 * Override hook: `shopwalk_seo_active_target` filter lets tests + the
	 * merchant override the auto-detected target.
	 *
	 * @return string One of the TARGET_* constants.
	 */
	public static function active_target(): string {
		$detected = self::detect();

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'shopwalk_seo_active_target', $detected );
			if ( is_string( $filtered ) && isset( self::TARGETS[ $filtered ] ) ) {
				return $filtered;
			}
		}
		return $detected;
	}

	/**
	 * Raw detection — exposed so tests can assert it without going through
	 * the filterable wrapper.
	 */
	public static function detect(): string {
		// Yoast SEO: ships the `WPSEO_VERSION` constant and the
		// `WPSEO_Options` class. Either is a reliable signal.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return self::TARGET_YOAST;
		}
		// Rank Math: defines `RANK_MATH_VERSION` and the `RankMath` class.
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return self::TARGET_RANKMATH;
		}
		// All in One SEO: defines `AIOSEO_VERSION` and the
		// `AIOSEO\\Plugin\\Common\\Main` namespace; we look for the version
		// constant which is the most stable signal across plugin versions.
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return self::TARGET_AIOSEO;
		}
		return self::TARGET_FALLBACK;
	}

	/**
	 * Returns the postmeta key the generator should write a given field to,
	 * scoped to the currently active target.
	 *
	 * @param string $field One of "title" | "description" | "focus".
	 * @return string|null  Null when the target has no field for this slot
	 *                      (e.g. fallback has no focus keyphrase key).
	 */
	public static function field_key( string $field ): ?string {
		$target = self::active_target();
		return self::TARGETS[ $target ][ $field ] ?? null;
	}

	/**
	 * Human label for the detected target — used in the meta box header
	 * ("Writing to: Yoast SEO") so the merchant knows where the meta lands.
	 */
	public static function active_target_label(): string {
		$target = self::active_target();
		return self::TARGETS[ $target ]['label'] ?? 'Unknown';
	}

	/**
	 * Returns true iff the active target is the Shopwalk fallback. The
	 * dashboard surfaces an upsell when this is true ("Install Yoast or
	 * RankMath to get full meta rendering in the page head").
	 */
	public static function is_fallback(): bool {
		return self::TARGET_FALLBACK === self::active_target();
	}
}
