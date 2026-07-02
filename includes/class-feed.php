<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits Meta (Facebook) catalog feeds built from the accommodation listings:
 *
 *  - Hotels feed       /bushbreaks-feed/facebook.xml      (?bbm_feed=facebook)
 *    Meta travel XML: hotel_id, name, address, lat/long, base_price, image…
 *  - Destinations feed /bushbreaks-feed/destinations.xml  (?bbm_feed=destinations)
 *    Meta travel XML: destination_id, name, address, lat/long, price, image…
 *  - Products feed     /bushbreaks-feed/products.xml      (?bbm_feed=products)
 *    RSS 2.0 + Google product namespace, enriched with product_type
 *    (Holiday Destinations) and custom_label_0-4 (province, reserve,
 *    categories, features, break type).
 *
 * Each lodge is one entry in every feed. Paste a URL into Commerce Manager →
 * the matching catalog type → Data sources → "Scheduled feed".
 */
class Feed {

	public const QUERY_VAR = 'bbm_feed';

	private const REWRITE_FLAG = 'bushbreaks_maps_feed_rewrites';
	private const REWRITE_VER  = '4';

	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		// Cancel WordPress' canonical trailing-slash redirect for the feed:
		// /facebook.xml must NOT 301 to /facebook.xml/ or crawlers that don't
		// follow the redirect (and our own non-slashed rule) end up on a 404.
		add_filter( 'redirect_canonical', [ $this, 'prevent_canonical_redirect' ], 10, 2 );
		// Run before redirect_canonical (priority 10) so we render and exit
		// before any other handler can redirect the request.
		add_action( 'template_redirect', [ $this, 'maybe_render' ], 1 );
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^bushbreaks-feed/facebook\.xml/?$',
			'index.php?' . self::QUERY_VAR . '=facebook',
			'top'
		);
		add_rewrite_rule(
			'^bushbreaks-feed/destinations\.xml/?$',
			'index.php?' . self::QUERY_VAR . '=destinations',
			'top'
		);
		add_rewrite_rule(
			'^bushbreaks-feed/products\.xml/?$',
			'index.php?' . self::QUERY_VAR . '=products',
			'top'
		);

		// Flush once when the rule set changes so the pretty URLs resolve
		// without forcing the admin to re-save permalinks.
		if ( get_option( self::REWRITE_FLAG ) !== self::REWRITE_VER ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_FLAG, self::REWRITE_VER );
		}
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Bail out of WordPress' canonical redirect when the feed is being
	 * requested, so the .xml URL is served directly instead of 301'd to a
	 * trailing-slash variant.
	 */
	public function prevent_canonical_redirect( $redirect_url, $requested_url ) {
		if ( (string) get_query_var( self::QUERY_VAR ) !== '' ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Public, copy-pasteable feed URL for a given catalog type: 'facebook'
	 * (Hotels), 'destinations' (Destinations) or 'products' (Products). Uses
	 * the pretty permalink when available, otherwise the query arg.
	 */
	public static function feed_url( string $type = 'facebook' ): string {
		$slug = in_array( $type, [ 'destinations', 'products' ], true ) ? $type : 'facebook';
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/bushbreaks-feed/' . $slug . '.xml' );
		}
		return add_query_arg( self::QUERY_VAR, $slug, home_url( '/' ) );
	}

	public function maybe_render(): void {
		$type = (string) get_query_var( self::QUERY_VAR );
		if ( $type === '' && isset( $_GET[ self::QUERY_VAR ] ) ) {
			$type = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		}

		if ( $type === 'facebook' ) {
			$this->render_hotels();
			exit;
		}
		if ( $type === 'destinations' ) {
			$this->render_destinations();
			exit;
		}
		if ( $type === 'products' ) {
			$this->render_products();
			exit;
		}
	}

	private function render_hotels(): void {
		$opts     = Settings::all();
		$currency = $this->currency( $opts );
		$brand    = trim( (string) ( $opts['feed_brand'] ?? '' ) );
		if ( $brand === '' ) {
			$brand = (string) get_bloginfo( 'name' );
		}
		$country = trim( (string) ( $opts['feed_country'] ?? '' ) );

		$rows = Repository::listing_rows();
		$this->begin_xml();

		foreach ( $rows as $row ) {
			// Meta requires every hotel to carry a location (latitude/longitude),
			// an image and a price. Skip rows that can't form a valid listing so
			// the whole feed isn't rejected.
			if ( $row['image'] === '' || $row['lat'] === null || $row['lng'] === null ) {
				continue;
			}
			$price = $this->base_price( $row );
			if ( $price === null ) {
				continue;
			}

			$description = $row['description'] !== '' ? $row['description'] : $row['name'];

			echo "<listing>\n";
			printf( "<hotel_id>%d</hotel_id>\n", (int) $row['id'] );
			printf( "<name>%s</name>\n", $this->cdata( $row['name'] ) );
			printf( "<description>%s</description>\n", $this->cdata( $description ) );
			printf( "<brand>%s</brand>\n", $this->cdata( $brand ) );
			printf( "<latitude>%s</latitude>\n", esc_html( (string) $row['lat'] ) );
			printf( "<longitude>%s</longitude>\n", esc_html( (string) $row['lng'] ) );
			$this->echo_address( $row, $country );
			if ( $row['reserve'] !== '' ) {
				printf( "<neighborhood>%s</neighborhood>\n", $this->cdata( $row['reserve'] ) );
			}
			printf( "<base_price>%s</base_price>\n", esc_html( $this->money( $price, $currency ) ) );
			printf( "<url>%s</url>\n", esc_url( $row['url'] ) );
			echo "<image>\n";
			printf( "<url>%s</url>\n", esc_url( $row['image'] ) );
			echo "</image>\n";
			if ( $row['star_rating'] !== null ) {
				printf( "<star_rating>%s</star_rating>\n", esc_html( $this->format_star( (float) $row['star_rating'] ) ) );
			}
			echo "</listing>\n";
		}

		echo "</listings>\n";
	}

	private function render_destinations(): void {
		$opts     = Settings::all();
		$currency = $this->currency( $opts );
		$country  = trim( (string) ( $opts['feed_country'] ?? '' ) );

		$rows = Repository::listing_rows();
		$this->begin_xml();

		foreach ( $rows as $row ) {
			// A destination needs a location and an image; price is optional but
			// included when available.
			if ( $row['image'] === '' || $row['lat'] === null || $row['lng'] === null ) {
				continue;
			}
			$price       = $this->base_price( $row );
			$description = $row['description'] !== '' ? $row['description'] : $row['name'];

			echo "<listing>\n";
			printf( "<destination_id>%d</destination_id>\n", (int) $row['id'] );
			printf( "<name>%s</name>\n", $this->cdata( $row['name'] ) );
			printf( "<description>%s</description>\n", $this->cdata( $description ) );
			printf( "<latitude>%s</latitude>\n", esc_html( (string) $row['lat'] ) );
			printf( "<longitude>%s</longitude>\n", esc_html( (string) $row['lng'] ) );
			$this->echo_address( $row, $country );
			if ( $row['reserve'] !== '' ) {
				printf( "<neighborhood>%s</neighborhood>\n", $this->cdata( $row['reserve'] ) );
			}
			if ( $price !== null ) {
				printf( "<price>%s</price>\n", esc_html( $this->money( $price, $currency ) ) );
			}
			printf( "<url>%s</url>\n", esc_url( $row['url'] ) );
			echo "<image>\n";
			printf( "<url>%s</url>\n", esc_url( $row['image'] ) );
			echo "</image>\n";
			echo "</listing>\n";
		}

		echo "</listings>\n";
	}

	private function render_products(): void {
		$opts     = Settings::all();
		$currency = $this->currency( $opts );
		$brand    = trim( (string) ( $opts['feed_brand'] ?? '' ) );
		if ( $brand === '' ) {
			$brand = (string) get_bloginfo( 'name' );
		}
		$product_type = trim( (string) ( $opts['feed_product_type'] ?? '' ) );

		$rows = Repository::listing_rows();
		$this->flush_and_headers();

		echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
		echo "<channel>\n";
		printf( "<title>%s</title>\n", $this->cdata( $this->feed_title() ) );
		printf( "<link>%s</link>\n", esc_url( home_url( '/' ) ) );
		printf( "<description>%s</description>\n", $this->cdata( __( 'Accommodation product feed', 'bushbreaks-maps' ) ) );

		foreach ( $rows as $row ) {
			// A product needs an image and a price; coordinates are not required.
			if ( $row['image'] === '' ) {
				continue;
			}
			$price = $row['price'];   // normal
			$sale  = $row['sale_price']; // special
			if ( $price === null && $sale === null ) {
				continue;
			}
			$sale_out = null;
			if ( $price === null ) {
				// Only a special exists -> it is the headline price.
				$price = $sale;
			} elseif ( $sale !== null && $sale < $price ) {
				// Genuine discount.
				$sale_out = $sale;
			}

			$description = $row['description'] !== '' ? $row['description'] : $row['name'];

			echo "<item>\n";
			printf( "<g:id>%d</g:id>\n", (int) $row['id'] );
			// Unique group per item: declares every lodge a standalone product so
			// Meta's automatic item grouping can't merge similarly-named lodges
			// into variants of one product.
			printf( "<g:item_group_id>%d</g:item_group_id>\n", (int) $row['id'] );
			printf( "<g:title>%s</g:title>\n", $this->cdata( $row['name'] ) );
			printf( "<g:description>%s</g:description>\n", $this->cdata( $description ) );
			printf( "<g:link>%s</g:link>\n", esc_url( $row['url'] ) );
			printf( "<g:image_link>%s</g:image_link>\n", esc_url( $row['image'] ) );
			echo "<g:availability>in stock</g:availability>\n";
			echo "<g:condition>new</g:condition>\n";
			// Lodges have no GTIN/MPN barcodes; declare that so Meta doesn't
			// flag the items for missing identifiers.
			echo "<g:identifier_exists>no</g:identifier_exists>\n";
			printf( "<g:price>%s</g:price>\n", esc_html( $this->money( (float) $price, $currency ) ) );
			if ( $sale_out !== null ) {
				printf( "<g:sale_price>%s</g:sale_price>\n", esc_html( $this->money( (float) $sale_out, $currency ) ) );
			}
			printf( "<g:brand>%s</g:brand>\n", $this->cdata( $brand ) );

			// Fixed catalogue product type (e.g. "Holiday Destinations").
			if ( $product_type !== '' ) {
				printf( "<g:product_type>%s</g:product_type>\n", $this->cdata( $product_type ) );
			}

			// Province, reserve, categories and features as custom labels for
			// ad-set filters.
			if ( $row['province'] !== '' ) {
				printf( "<g:custom_label_0>%s</g:custom_label_0>\n", $this->cdata( $this->clamp_label( $row['province'] ) ) );
			}
			if ( $row['reserve'] !== '' ) {
				printf( "<g:custom_label_1>%s</g:custom_label_1>\n", $this->cdata( $this->clamp_label( $row['reserve'] ) ) );
			}
			if ( ! empty( $row['categories'] ) ) {
				printf( "<g:custom_label_2>%s</g:custom_label_2>\n", $this->cdata( $this->clamp_label( implode( ', ', (array) $row['categories'] ) ) ) );
			}
			if ( ! empty( $row['features'] ) ) {
				printf( "<g:custom_label_3>%s</g:custom_label_3>\n", $this->cdata( $this->clamp_label( (string) $row['features'] ) ) );
			}
			if ( ! empty( $row['break_type'] ) ) {
				printf( "<g:custom_label_4>%s</g:custom_label_4>\n", $this->cdata( $this->clamp_label( (string) $row['break_type'] ) ) );
			}
			echo "</item>\n";
		}

		echo "</channel>\n";
		echo "</rss>\n";
	}

	/**
	 * Open a Meta travel <listings> document (Hotels / Destinations).
	 */
	private function begin_xml(): void {
		$this->flush_and_headers();
		echo "<listings>\n";
		printf( "<title>%s</title>\n", $this->cdata( $this->feed_title() ) );
	}

	/** Configured feed title, falling back to the site name. */
	private function feed_title(): string {
		$title = trim( (string) Settings::get( 'feed_title' ) );
		return $title !== '' ? $title : (string) get_bloginfo( 'name' );
	}

	/**
	 * Discard any buffered output (stray whitespace would make XML parsers
	 * reject the feed with "XML declaration allowed only at the start of the
	 * document"), send headers and emit the XML declaration.
	 */
	private function flush_and_headers(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		// Version stamp so the generating plugin version is visible in the feed
		// (helps confirm an update/cache purge actually took effect).
		echo '<!-- Bushbreaks Maps ' . esc_html( BUSHBREAKS_MAPS_VERSION ) . ' -->' . "\n";
	}

	private function echo_address( array $row, string $country ): void {
		// Prefer the country from the destination taxonomy; fall back to the
		// configured default.
		if ( ! empty( $row['country'] ) ) {
			$country = (string) $row['country'];
		}

		echo "<address format=\"simple\">\n";
		if ( $row['addr1'] !== '' ) {
			printf( "<component name=\"addr1\">%s</component>\n", $this->cdata( $row['addr1'] ) );
		}
		if ( $row['city'] !== '' ) {
			printf( "<component name=\"city\">%s</component>\n", $this->cdata( $row['city'] ) );
		}
		if ( $row['province'] !== '' ) {
			printf( "<component name=\"region\">%s</component>\n", $this->cdata( $row['province'] ) );
		}
		if ( $country !== '' ) {
			printf( "<component name=\"country\">%s</component>\n", $this->cdata( $country ) );
		}
		echo "</address>\n";
	}

	/** Lowest available rate for a row: special when it undercuts normal. */
	private function base_price( array $row ): ?float {
		$price = $row['price'];
		$sale  = $row['sale_price'];
		if ( $price === null && $sale === null ) {
			return null;
		}
		if ( $price === null || ( $sale !== null && $sale < $price ) ) {
			$price = $sale;
		}
		return $price !== null ? (float) $price : null;
	}

	private function currency( array $opts ): string {
		$currency = strtoupper( trim( (string) ( $opts['feed_currency'] ?? 'ZAR' ) ) );
		return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'ZAR';
	}

	private function money( float $amount, string $currency ): string {
		return number_format( $amount, 2, '.', '' ) . ' ' . $currency;
	}

	private function format_star( float $rating ): string {
		// Whole numbers without decimals, half-steps with one.
		return ( floor( $rating ) === $rating )
			? (string) (int) $rating
			: number_format( $rating, 1, '.', '' );
	}

	/**
	 * Meta caps custom_label_0-4 at 100 characters; longer values trigger
	 * feed warnings. Cut at the limit, on a word boundary when possible.
	 */
	private function clamp_label( string $value ): string {
		if ( mb_strlen( $value, 'UTF-8' ) <= 100 ) {
			return $value;
		}
		$cut = mb_substr( $value, 0, 100, 'UTF-8' );
		$pos = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( $pos !== false && $pos > 60 ) {
			$cut = mb_substr( $cut, 0, $pos, 'UTF-8' );
		}
		return rtrim( $cut, " ,;" );
	}

	private function cdata( string $value ): string {
		// Defuse any literal CDATA terminator inside the value.
		$value = str_replace( ']]>', ']]]]><![CDATA[>', $value );
		return '<![CDATA[' . $value . ']]>';
	}
}
