<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits a Meta (Facebook) Hotel catalog feed built from the accommodation
 * listings. Uses Meta's hotel feed XML schema: a <listings> root with one
 * <listing> per lodge, carrying hotel_id, name, a structured <address>,
 * latitude/longitude, base_price, url and image — the format Commerce
 * Manager expects for a Hotels catalog / hotel ads.
 *
 * Feed URL (pretty permalinks):  {site}/bushbreaks-feed/facebook.xml
 * Feed URL (fallback, always on): {site}/?bbm_feed=facebook
 *
 * Paste the URL into Commerce Manager → Catalog (Hotels) → Data sources →
 * "Scheduled feed" and Facebook will re-fetch it on its own cadence.
 */
class Feed {

	public const QUERY_VAR = 'bbm_feed';

	private const REWRITE_FLAG = 'bushbreaks_maps_feed_rewrites';
	private const REWRITE_VER  = '2';

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

		// Flush once when the rule set changes so the pretty URL resolves
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
	 * Public, copy-pasteable feed URL. Uses the pretty permalink when the
	 * site has a permalink structure, otherwise the always-on query arg.
	 */
	public static function feed_url(): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/bushbreaks-feed/facebook.xml' );
		}
		return add_query_arg( self::QUERY_VAR, 'facebook', home_url( '/' ) );
	}

	public function maybe_render(): void {
		$type = (string) get_query_var( self::QUERY_VAR );
		if ( $type === '' && isset( $_GET[ self::QUERY_VAR ] ) ) {
			$type = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		}
		if ( $type !== 'facebook' ) {
			return;
		}

		$this->render_facebook();
		exit;
	}

	private function render_facebook(): void {
		// Discard anything already buffered (stray whitespace/newlines emitted
		// by other plugins or the theme, gzip buffers, etc.) so the XML
		// declaration is the very first byte. Otherwise XML parsers reject the
		// feed with "XML declaration allowed only at the start of the document".
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$opts     = Settings::all();
		$currency = strtoupper( trim( (string) ( $opts['feed_currency'] ?? 'ZAR' ) ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = 'ZAR';
		}
		$brand = trim( (string) ( $opts['feed_brand'] ?? '' ) );
		if ( $brand === '' ) {
			$brand = (string) get_bloginfo( 'name' );
		}
		$country = trim( (string) ( $opts['feed_country'] ?? '' ) );

		$rows = Repository::hotel_rows();

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo "<listings>\n";
		printf( "<title>%s</title>\n", $this->cdata( (string) get_bloginfo( 'name' ) ) );

		foreach ( $rows as $row ) {
			// Meta requires every hotel to carry a location (latitude/longitude),
			// an image and a price. Skip rows that can't form a valid listing so
			// the whole feed isn't rejected.
			if ( $row['image'] === '' || $row['lat'] === null || $row['lng'] === null ) {
				continue;
			}

			$price = $row['price'];
			$sale  = $row['sale_price'];
			if ( $price === null && $sale === null ) {
				continue;
			}
			// base_price is the lowest available rate.
			if ( $price === null || ( $sale !== null && $sale < $price ) ) {
				$price = $sale;
			}

			$description = $row['description'] !== '' ? $row['description'] : $row['name'];

			echo "<listing>\n";
			printf( "<hotel_id>%d</hotel_id>\n", (int) $row['id'] );
			printf( "<name>%s</name>\n", $this->cdata( $row['name'] ) );
			printf( "<description>%s</description>\n", $this->cdata( $description ) );
			printf( "<brand>%s</brand>\n", $this->cdata( $brand ) );
			printf( "<latitude>%s</latitude>\n", esc_html( (string) $row['lat'] ) );
			printf( "<longitude>%s</longitude>\n", esc_html( (string) $row['lng'] ) );

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

			if ( $row['neighborhood'] !== '' ) {
				printf( "<neighborhood>%s</neighborhood>\n", $this->cdata( $row['neighborhood'] ) );
			}
			printf( "<base_price>%s</base_price>\n", esc_html( $this->money( (float) $price, $currency ) ) );
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
