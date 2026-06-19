<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits Meta (Facebook) travel catalog feeds built from the accommodation
 * listings, in Meta's <listings>/<listing> XML format:
 *
 *  - Hotels feed       /bushbreaks-feed/facebook.xml      (?bbm_feed=facebook)
 *    hotel_id, name, address, latitude/longitude, base_price, image…
 *  - Destinations feed /bushbreaks-feed/destinations.xml  (?bbm_feed=destinations)
 *    destination_id, name, address, latitude/longitude, price, image…
 *
 * Each lodge is one entry in both feeds. Paste a URL into Commerce Manager →
 * the matching catalog type → Data sources → "Scheduled feed".
 */
class Feed {

	public const QUERY_VAR = 'bbm_feed';

	private const REWRITE_FLAG = 'bushbreaks_maps_feed_rewrites';
	private const REWRITE_VER  = '3';

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
	 * Public, copy-pasteable feed URL for a given catalog type ('facebook'
	 * for Hotels, 'destinations' for Destinations). Uses the pretty permalink
	 * when the site has a permalink structure, otherwise the query arg.
	 */
	public static function feed_url( string $type = 'facebook' ): string {
		$slug = $type === 'destinations' ? 'destinations' : 'facebook';
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
	}

	private function render_hotels(): void {
		$opts     = Settings::all();
		$currency = $this->currency( $opts );
		$brand    = trim( (string) ( $opts['feed_brand'] ?? '' ) );
		if ( $brand === '' ) {
			$brand = (string) get_bloginfo( 'name' );
		}
		$country = trim( (string) ( $opts['feed_country'] ?? '' ) );

		$rows = Repository::hotel_rows();
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
			if ( $row['neighborhood'] !== '' ) {
				printf( "<neighborhood>%s</neighborhood>\n", $this->cdata( $row['neighborhood'] ) );
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

		$rows = Repository::hotel_rows();
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
			if ( $row['neighborhood'] !== '' ) {
				printf( "<neighborhood>%s</neighborhood>\n", $this->cdata( $row['neighborhood'] ) );
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

	/**
	 * Shared preamble: discard any buffered output (stray whitespace would make
	 * XML parsers reject the feed with "XML declaration allowed only at the
	 * start of the document"), send headers and open the <listings> root.
	 */
	private function begin_xml(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo "<listings>\n";
		printf( "<title>%s</title>\n", $this->cdata( (string) get_bloginfo( 'name' ) ) );
	}

	private function echo_address( array $row, string $country ): void {
		echo "<address format=\"simple\">\n";
		if ( $row['addr1'] !== '' ) {
			printf( "<component name=\"addr1\">%s</component>\n", $this->cdata( $row['addr1'] ) );
		}
		if ( $row['city'] !== '' ) {
			printf( "<component name=\"city\">%s</component>\n", $this->cdata( $row['city'] ) );
		}
		if ( $row['region'] !== '' ) {
			printf( "<component name=\"region\">%s</component>\n", $this->cdata( $row['region'] ) );
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

	private function cdata( string $value ): string {
		// Defuse any literal CDATA terminator inside the value.
		$value = str_replace( ']]>', ']]]]><![CDATA[>', $value );
		return '<![CDATA[' . $value . ']]>';
	}
}
