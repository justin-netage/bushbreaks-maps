<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves coordinates for an accommodation:
 * - Parses lat/lng out of a Google Maps embed iframe (preferred), or
 * - Geocodes a free-text location via OpenStreetMap Nominatim.
 */
class Geocoder {

	public static function parse_iframe( string $html ): ?array {
		if ( $html === '' ) {
			return null;
		}
		$decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 );
		if ( preg_match( '/!2d(-?\d+(?:\.\d+)?)!3d(-?\d+(?:\.\d+)?)/', $decoded, $m ) ) {
			$lng = (float) $m[1];
			$lat = (float) $m[2];
			if ( self::valid( $lat, $lng ) ) {
				return [ 'lat' => $lat, 'lng' => $lng ];
			}
		}
		return null;
	}

	public static function geocode( string $query ): ?array {
		$query = trim( $query );
		if ( $query === '' ) {
			return null;
		}

		$url = add_query_arg(
			[
				'q'              => $query,
				'format'         => 'jsonv2',
				'limit'          => 1,
				'addressdetails' => 0,
			],
			'https://nominatim.openstreetmap.org/search'
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 6,
				'headers' => [
					'User-Agent'      => self::user_agent(),
					'Accept-Language' => 'en',
					'Referer'         => home_url(),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data[0] ) ) {
			return null;
		}

		$first = $data[0];
		if ( ! isset( $first['lat'], $first['lon'] ) ) {
			return null;
		}

		$lat = (float) $first['lat'];
		$lng = (float) $first['lon'];
		if ( ! self::valid( $lat, $lng ) ) {
			return null;
		}

		return [ 'lat' => $lat, 'lng' => $lng ];
	}

	private static function valid( float $lat, float $lng ): bool {
		return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
	}

	private static function user_agent(): string {
		$email = (string) get_bloginfo( 'admin_email' );
		return sprintf(
			'Bushbreaks-Maps-Plugin/%s (+%s; %s)',
			BUSHBREAKS_MAPS_VERSION,
			home_url(),
			$email
		);
	}
}
