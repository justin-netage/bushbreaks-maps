<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits Meta (Facebook) and Google catalog feeds built from the accommodation
 * listings.
 *
 * Meta (Commerce Manager → the matching catalog type → Data sources):
 *
 *  - Hotels feed       /{slug}/facebook.xml      (?bbm_feed=facebook)
 *    Meta travel XML: hotel_id, name, address, lat/long, base_price, image…
 *  - Destinations feed /{slug}/destinations.xml  (?bbm_feed=destinations)
 *    Meta travel XML: destination_id, name, address, lat/long, price, image…
 *  - Products feed     /{slug}/products.xml      (?bbm_feed=products)
 *    RSS 2.0 + Google product namespace, enriched with product_type
 *    (Holiday Destinations) and custom_label_0-4 (province, reserve,
 *    categories, features, break type).
 *
 * Google:
 *
 *  - Shopping feed   /{slug}/google.xml        (?bbm_feed=google)
 *    Merchant Center product feed: RSS 2.0 + Google product namespace, using
 *    Google's own attribute values (availability "in_stock", google_product_
 *    category, title/description length caps). Feeds Shopping, Performance Max
 *    and dynamic remarketing that runs off Merchant Center.
 *  - Hotels feed     /{slug}/google-hotels.csv (?bbm_feed=google-hotels)
 *    Google Ads dynamic remarketing "Hotels and rentals" business data feed:
 *    CSV with Property ID / Property name / Final URL / Image URL / Price /
 *    Star rating / Contextual keywords… Add under Tools → Business data →
 *    Data feeds → "Scheduled upload".
 *
 * The Google feeds differ from the Meta ones only in attribute names and
 * accepted values; both are built from the same Repository::listing_rows().
 *
 * {slug} is the "Feed URL slug" setting (default "bushbreaks-feed"), so
 * multiple sites running this plugin can each have a distinct feed path.
 *
 * Each lodge is one entry in every feed.
 */
class Feed {

	public const QUERY_VAR = 'bbm_feed';

	private const REWRITE_FLAG = 'bushbreaks_maps_feed_rewrites';
	private const REWRITE_VER  = '6';

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

	/** The configured feed URL path segment, defensively re-sanitized. */
	private static function url_slug(): string {
		$slug = sanitize_title( (string) Settings::get( 'feed_url_slug' ) );
		return $slug !== '' ? $slug : 'bushbreaks-feed';
	}

	public function add_rewrite_rule(): void {
		$slug = self::url_slug();

		add_rewrite_rule(
			'^' . $slug . '/facebook\.xml/?$',
			'index.php?' . self::QUERY_VAR . '=facebook',
			'top'
		);
		add_rewrite_rule(
			'^' . $slug . '/destinations\.xml/?$',
			'index.php?' . self::QUERY_VAR . '=destinations',
			'top'
		);
		add_rewrite_rule(
			'^' . $slug . '/products\.xml/?$',
			'index.php?' . self::QUERY_VAR . '=products',
			'top'
		);
		add_rewrite_rule(
			'^' . $slug . '/google\.xml/?$',
			'index.php?' . self::QUERY_VAR . '=google',
			'top'
		);
		add_rewrite_rule(
			'^' . $slug . '/google-hotels\.csv/?$',
			'index.php?' . self::QUERY_VAR . '=google-hotels',
			'top'
		);

		// Flush once when the rule set (or the configured slug) changes,
		// so the pretty URLs resolve without forcing the admin to re-save
		// permalinks.
		$marker = self::REWRITE_VER . ':' . $slug;
		if ( get_option( self::REWRITE_FLAG ) !== $marker ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_FLAG, $marker );
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
	 * File extension each feed type is served under.
	 */
	private const TYPE_EXTENSIONS = [
		'facebook'      => 'xml',
		'destinations'  => 'xml',
		'products'      => 'xml',
		'google'        => 'xml',
		'google-hotels' => 'csv',
	];

	/**
	 * Public, copy-pasteable feed URL for a given catalog type: 'facebook'
	 * (Meta Hotels), 'destinations' (Meta Destinations), 'products' (Meta
	 * Products), 'google' (Merchant Center products) or 'google-hotels'
	 * (Google Ads Hotels and rentals). Uses the pretty permalink when
	 * available, otherwise the query arg.
	 */
	public static function feed_url( string $type = 'facebook' ): string {
		$type_slug = isset( self::TYPE_EXTENSIONS[ $type ] ) ? $type : 'facebook';
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . self::url_slug() . '/' . $type_slug . '.' . self::TYPE_EXTENSIONS[ $type_slug ] );
		}
		return add_query_arg( self::QUERY_VAR, $type_slug, home_url( '/' ) );
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
		if ( $type === 'google' ) {
			$this->render_google_products();
			exit;
		}
		if ( $type === 'google-hotels' ) {
			$this->render_google_hotels();
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
			foreach ( (array) ( $row['gallery'] ?? [] ) as $extra ) {
				printf( "<g:additional_image_link>%s</g:additional_image_link>\n", esc_url( $extra ) );
			}
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
	 * Google Merchant Center product feed (RSS 2.0 + the Google product
	 * namespace). Same shape as the Meta products feed, but with Google's own
	 * accepted values: availability is "in_stock" rather than "in stock",
	 * google_product_category is emitted when configured, title/description
	 * are capped at Google's limits, and no item_group_id is sent (Google
	 * treats a group id equal to the item id as a warning, where Meta needs
	 * it to stop automatic item grouping).
	 */
	private function render_google_products(): void {
		$opts     = Settings::all();
		$currency = $this->currency( $opts );
		$brand    = trim( (string) ( $opts['feed_brand'] ?? '' ) );
		if ( $brand === '' ) {
			$brand = (string) get_bloginfo( 'name' );
		}
		$product_type    = trim( (string) ( $opts['feed_product_type'] ?? '' ) );
		$google_category = trim( (string) ( $opts['feed_google_product_category'] ?? '' ) );

		$rows = Repository::listing_rows();
		$this->flush_and_headers();

		echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
		echo "<channel>\n";
		printf( "<title>%s</title>\n", $this->cdata( $this->feed_title() ) );
		printf( "<link>%s</link>\n", esc_url( home_url( '/' ) ) );
		printf( "<description>%s</description>\n", $this->cdata( __( 'Accommodation product feed', 'bushbreaks-maps' ) ) );

		foreach ( $rows as $row ) {
			// Google rejects an item without an image or a price.
			if ( $row['image'] === '' ) {
				continue;
			}
			$price = $row['price'];
			$sale  = $row['sale_price'];
			if ( $price === null && $sale === null ) {
				continue;
			}
			$sale_out = null;
			if ( $price === null ) {
				$price = $sale;
			} elseif ( $sale !== null && $sale < $price ) {
				$sale_out = $sale;
			}
			if ( (float) $price <= 0 ) {
				continue;
			}

			$description = $row['description'] !== '' ? $row['description'] : $row['name'];

			echo "<item>\n";
			printf( "<g:id>%d</g:id>\n", (int) $row['id'] );
			printf( "<g:title>%s</g:title>\n", $this->cdata( $this->clamp_text( $row['name'], 150 ) ) );
			printf( "<g:description>%s</g:description>\n", $this->cdata( $this->clamp_text( $description, 5000 ) ) );
			printf( "<g:link>%s</g:link>\n", esc_url( $row['url'] ) );
			printf( "<g:image_link>%s</g:image_link>\n", esc_url( $row['image'] ) );
			foreach ( (array) ( $row['gallery'] ?? [] ) as $extra ) {
				printf( "<g:additional_image_link>%s</g:additional_image_link>\n", esc_url( $extra ) );
			}
			echo "<g:availability>in_stock</g:availability>\n";
			echo "<g:condition>new</g:condition>\n";
			// Lodges carry no GTIN/MPN barcodes.
			echo "<g:identifier_exists>no</g:identifier_exists>\n";
			printf( "<g:price>%s</g:price>\n", esc_html( $this->money( (float) $price, $currency ) ) );
			if ( $sale_out !== null ) {
				printf( "<g:sale_price>%s</g:sale_price>\n", esc_html( $this->money( (float) $sale_out, $currency ) ) );
			}
			printf( "<g:brand>%s</g:brand>\n", $this->cdata( $brand ) );

			if ( $google_category !== '' ) {
				printf( "<g:google_product_category>%s</g:google_product_category>\n", $this->cdata( $google_category ) );
			}
			if ( $product_type !== '' ) {
				printf( "<g:product_type>%s</g:product_type>\n", $this->cdata( $product_type ) );
			}

			// Same custom labels as the Meta products feed, for campaign and
			// listing-group filters in Google Ads.
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
	 * Google Ads dynamic remarketing "Hotels and rentals" business data feed.
	 * Unlike every other feed here this one is CSV, because that is the only
	 * format Google Ads accepts for a business data feed. Only Property ID and
	 * Property name are required by the spec; the rest are what the ad
	 * actually renders, so rows without an image or a landing page are skipped
	 * the same way the Meta feeds skip them.
	 */
	private function render_google_hotels(): void {
		$opts     = Settings::all();
		$currency = $this->currency( $opts );
		$country  = trim( (string) ( $opts['feed_country'] ?? '' ) );
		// Category groups properties for the ad's listing rules; the break
		// type is the per-lodge grouping we have, with the fixed product type
		// as the fallback for lodges that carry no break type.
		$category = trim( (string) ( $opts['feed_product_type'] ?? '' ) );

		$rows = Repository::listing_rows();

		$this->discard_buffers();
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="google-hotels.csv"' );
		}

		$this->csv_row(
			[
				'Property ID',
				'Property name',
				'Final URL',
				'Image URL',
				'Destination name',
				'Description',
				'Price',
				'Sale price',
				'Star rating',
				'Category',
				'Contextual keywords',
				'Address',
			]
		);

		foreach ( $rows as $row ) {
			if ( $row['name'] === '' || $row['url'] === '' || $row['image'] === '' ) {
				continue;
			}

			// Price is optional in this feed, so a lodge without one still
			// gets an entry (with the columns left blank).
			$price    = $row['price'];
			$sale     = $row['sale_price'];
			$sale_out = null;
			if ( $price === null ) {
				$price = $sale;
			} elseif ( $sale !== null && $sale < $price ) {
				$sale_out = $sale;
			}

			$star = '';
			if ( $row['star_rating'] !== null ) {
				$rating = (float) $row['star_rating'];
				if ( $rating >= 1 && $rating <= 5 ) {
					$star = $this->format_star( $rating );
				}
			}

			$this->csv_row(
				[
					(string) (int) $row['id'],
					$row['name'],
					$row['url'],
					$row['image'],
					$this->destination_name( $row ),
					$this->clamp_text( $row['description'] !== '' ? $row['description'] : $row['name'], 200 ),
					$price !== null ? $this->money( (float) $price, $currency ) : '',
					$sale_out !== null ? $this->money( (float) $sale_out, $currency ) : '',
					$star,
					trim( (string) ( $row['break_type'] ?? '' ) ) !== '' ? (string) $row['break_type'] : $category,
					$this->contextual_keywords( $row ),
					$this->google_address( $row, $country ),
				]
			);
		}
	}

	/**
	 * Most specific place name Google should show as the destination:
	 * the reserve, else the province, else the city.
	 */
	private function destination_name( array $row ): string {
		foreach ( [ 'reserve', 'province', 'city' ] as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( $value !== '' ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Semicolon-separated keyword list (Google's format for the "Contextual
	 * keywords" column) built from the taxonomy terms and feature labels, with
	 * duplicates and empties removed.
	 */
	private function contextual_keywords( array $row ): string {
		$parts = [ $row['province'] ?? '', $row['reserve'] ?? '' ];
		$parts = array_merge( $parts, (array) ( $row['categories'] ?? [] ) );
		// features / break_type arrive already comma-joined.
		foreach ( [ 'features', 'break_type' ] as $key ) {
			$parts = array_merge( $parts, explode( ',', (string) ( $row[ $key ] ?? '' ) ) );
		}

		$seen = [];
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part === '' ) {
				continue;
			}
			$seen[ mb_strtolower( $part, 'UTF-8' ) ] = $part;
		}

		// Google caps the column at 10 keywords.
		return implode( '; ', array_slice( array_values( $seen ), 0, 10 ) );
	}

	/**
	 * Address in one of the forms Google accepts for this feed. "City, region,
	 * country" is used when the taxonomy gives us a place; otherwise the
	 * decimal lat/long pair, which Google also accepts. Street lines are left
	 * out on purpose: the full-address form wants a postal code we don't hold,
	 * and a partial street address parses worse than a plain city.
	 */
	private function google_address( array $row, string $country ): string {
		if ( ! empty( $row['country'] ) ) {
			$country = (string) $row['country'];
		}

		$parts = [];
		foreach ( [ (string) $row['city'], (string) $row['province'] ] as $part ) {
			$part = trim( $part );
			if ( $part !== '' ) {
				$parts[] = $part;
			}
		}

		if ( $parts ) {
			if ( trim( $country ) !== '' ) {
				$parts[] = trim( $country );
			}
			return implode( ', ', $parts );
		}

		// No place name: a bare country is too vague to pin a property to, so
		// fall back to the coordinates when we have them.
		if ( $row['lat'] !== null && $row['lng'] !== null ) {
			return $row['lat'] . ',' . $row['lng'];
		}

		return '';
	}

	/** Write one RFC 4180 CSV record. */
	private function csv_row( array $values ): void {
		$out = [];
		foreach ( $values as $value ) {
			$value = str_replace( [ "\r\n", "\r", "\n" ], ' ', (string) $value );
			$out[] = '"' . str_replace( '"', '""', $value ) . '"';
		}
		echo implode( ',', $out ) . "\r\n";
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
		$this->discard_buffers();

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		// Version stamp so the generating plugin version is visible in the feed
		// (helps confirm an update/cache purge actually took effect).
		echo '<!-- Bushbreaks Maps ' . esc_html( BUSHBREAKS_MAPS_VERSION ) . ' -->' . "\n";
	}

	/**
	 * Drop every output buffer WordPress or a theme may have opened, so the
	 * feed body starts at byte 0 of the response.
	 */
	private function discard_buffers(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
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
	 * Meta and Google both cap custom_label_0-4 at 100 characters; longer
	 * values trigger feed warnings.
	 */
	private function clamp_label( string $value ): string {
		return $this->clamp_text( $value, 100 );
	}

	/**
	 * Cut a value to a character limit, on a word boundary when one falls
	 * close enough to the end not to lose much.
	 */
	private function clamp_text( string $value, int $limit ): string {
		if ( mb_strlen( $value, 'UTF-8' ) <= $limit ) {
			return $value;
		}
		$cut = mb_substr( $value, 0, $limit, 'UTF-8' );
		$pos = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( $pos !== false && $pos > (int) ( $limit * 0.6 ) ) {
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
