<?php
namespace Bushbreaks_Maps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax {

	public function register(): void {
		add_action( 'wp_ajax_bushbreaks_maps_search', [ $this, 'handle_search' ] );
		add_action( 'wp_ajax_nopriv_bushbreaks_maps_search', [ $this, 'handle_search' ] );
	}

	public function handle_search(): void {
		check_ajax_referer( 'bushbreaks_maps', 'nonce' );

		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';

		$cats = [];
		if ( isset( $_GET['cats'] ) && is_array( $_GET['cats'] ) ) {
			foreach ( wp_unslash( $_GET['cats'] ) as $c ) {
				if ( is_numeric( $c ) && (int) $c > 0 ) {
					$cats[] = (int) $c;
				}
			}
		}

		$dests = [];
		if ( isset( $_GET['dests'] ) && is_array( $_GET['dests'] ) ) {
			foreach ( wp_unslash( $_GET['dests'] ) as $d ) {
				if ( is_numeric( $d ) && (int) $d > 0 ) {
					$dests[] = (int) $d;
				}
			}
		}

		$results = Repository::query(
			[
				'search'          => $term,
				'category_ids'    => $cats,
				'destination_ids' => $dests,
				'limit'            => 50,
			]
		);

		wp_send_json_success( [ 'results' => $results ] );
	}
}
