<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits a Facebook / Meta product catalog feed built from the
 * accommodation listings. The output is RSS 2.0 with the Google product
 * namespace (`g:`) — the same schema Meta Commerce Manager, Google
 * Merchant Center and Pinterest catalogues all consume, so one feed URL
 * serves all three.
 *
 * Feed URL (pretty permalinks):  {site}/bushbreaks-feed/facebook.xml
 * Feed URL (fallback, always on): {site}/?bbm_feed=facebook
 *
 * Paste the URL into Commerce Manager → Catalog → Data sources →
 * "Scheduled feed" and Facebook will re-fetch it on its own cadence.
 */
class Feed {

	public const QUERY_VAR = 'bbm_feed';

	private const REWRITE_FLAG = 'bushbreaks_maps_feed_rewrites';
	private const REWRITE_VER  = '1';

	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^bushbreaks-feed/facebook\.xml$',
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
		$opts     = Settings::all();
		$currency = strtoupper( trim( (string) ( $opts['feed_currency'] ?? 'ZAR' ) ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$currency = 'ZAR';
		}
		$brand = trim( (string) ( $opts['feed_brand'] ?? '' ) );
		if ( $brand === '' ) {
			$brand = (string) get_bloginfo( 'name' );
		}

		$rows = Repository::feed_rows();

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=utf-8' );
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
		echo "<channel>\n";
		printf( "<title>%s</title>\n", $this->cdata( (string) get_bloginfo( 'name' ) ) );
		printf( "<link>%s</link>\n", esc_url( home_url( '/' ) ) );
		printf(
			"<description>%s</description>\n",
			$this->cdata( __( 'Accommodation listings product feed', 'bushbreaks-maps' ) )
		);

		foreach ( $rows as $row ) {
			// Facebook rejects a product without an image_link or a price, so
			// skip rows that can't form a valid item rather than poisoning the
			// whole feed.
			if ( $row['image'] === '' ) {
				continue;
			}

			$price = $row['price'];
			$sale  = $row['sale_price'];
			if ( $price === null && $sale === null ) {
				continue;
			}
			// No standard price but a special exists -> the special is the price.
			if ( $price === null && $sale !== null ) {
				$price = $sale;
				$sale  = null;
			}
			// A "special" that isn't actually lower isn't a sale price.
			if ( $sale !== null && $price !== null && $sale >= $price ) {
				$sale = null;
			}

			$description = $row['description'] !== '' ? $row['description'] : $row['title'];

			echo "<item>\n";
			printf( "<g:id>%d</g:id>\n", (int) $row['id'] );
			printf( "<g:title>%s</g:title>\n", $this->cdata( $row['title'] ) );
			printf( "<g:description>%s</g:description>\n", $this->cdata( $description ) );
			printf( "<g:link>%s</g:link>\n", esc_url( $row['link'] ) );
			printf( "<g:image_link>%s</g:image_link>\n", esc_url( $row['image'] ) );
			echo "<g:availability>in stock</g:availability>\n";
			echo "<g:condition>new</g:condition>\n";
			printf( "<g:price>%s</g:price>\n", esc_html( $this->money( (float) $price, $currency ) ) );
			if ( $sale !== null ) {
				printf( "<g:sale_price>%s</g:sale_price>\n", esc_html( $this->money( (float) $sale, $currency ) ) );
			}
			printf( "<g:brand>%s</g:brand>\n", $this->cdata( $brand ) );
			echo "</item>\n";
		}

		echo "</channel>\n";
		echo "</rss>\n";
	}

	private function money( float $amount, string $currency ): string {
		return number_format( $amount, 2, '.', '' ) . ' ' . $currency;
	}

	private function cdata( string $value ): string {
		// Defuse any literal CDATA terminator inside the value.
		$value = str_replace( ']]>', ']]]]><![CDATA[>', $value );
		return '<![CDATA[' . $value . ']]>';
	}
}
