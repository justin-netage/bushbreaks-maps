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

		$results = Repository::query(
			[
				'search' => $term,
				'limit'  => 50,
			]
		);

		wp_send_json_success( [ 'results' => $results ] );
	}
}
