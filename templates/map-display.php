<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @var string $height
 * @var array  $featured_items
 * @var array  $i18n
 */
?>
<div class="bbm-wrap" style="--bbm-height: <?php echo esc_attr( $height ); ?>;">
	<div class="bbm-sidebar">
		<div class="bbm-search">
			<input
				type="search"
				class="bbm-search-input"
				placeholder="<?php echo esc_attr( $i18n['searchPlaceholder'] ); ?>"
				aria-label="<?php echo esc_attr( $i18n['searchPlaceholder'] ); ?>"
			/>
		</div>

		<div class="bbm-results" hidden>
			<ul class="bbm-results-list"></ul>
		</div>

		<div class="bbm-featured">
			<h3 class="bbm-featured-heading"><?php echo esc_html( $i18n['featuredHeading'] ); ?></h3>
			<ul class="bbm-featured-list">
				<?php foreach ( $featured_items as $item ) : ?>
					<li class="bbm-card" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>"
						<?php if ( $item['lat'] !== null && $item['lng'] !== null ) : ?>
						data-lat="<?php echo esc_attr( (string) $item['lat'] ); ?>"
						data-lng="<?php echo esc_attr( (string) $item['lng'] ); ?>"
						<?php endif; ?>>
						<?php if ( $item['thumbnail'] ) : ?>
							<div class="bbm-card-thumb" style="background-image:url('<?php echo esc_url( $item['thumbnail'] ); ?>');"></div>
						<?php endif; ?>
						<div class="bbm-card-body">
							<a class="bbm-card-title" href="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
							<?php if ( $item['address'] ) : ?>
								<div class="bbm-card-address"><?php echo esc_html( $item['address'] ); ?></div>
							<?php endif; ?>
							<?php if ( $item['excerpt'] ) : ?>
								<p class="bbm-card-excerpt"><?php echo esc_html( $item['excerpt'] ); ?></p>
							<?php endif; ?>
							<a class="bbm-card-link" href="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo esc_html( $i18n['viewDetails'] ); ?> &rarr;</a>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>

	<div class="bbm-map" id="bbm-map" role="application" aria-label="Map of accommodations"></div>
</div>
