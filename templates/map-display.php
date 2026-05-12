<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @var string $height
 * @var array  $list_items
 * @var array  $i18n
 */
?>
<div class="bbm-wrap" style="--bbm-height: <?php echo esc_attr( $height ); ?>;">
	<div class="bbm-filters">
		<div class="bbm-search">
			<input
				type="search"
				class="bbm-search-input"
				placeholder="<?php echo esc_attr( $i18n['searchPlaceholder'] ); ?>"
				aria-label="<?php echo esc_attr( $i18n['searchPlaceholder'] ); ?>"
			/>
		</div>

		<div class="bbm-destinations bbm-filter-group" hidden>
			<div class="bbm-category-dropdown">
				<button type="button" class="bbm-category-toggle bbm-destination-toggle" aria-expanded="false" aria-haspopup="listbox">
					<span class="bbm-category-toggle-label bbm-destination-toggle-label"><?php echo esc_html( $i18n['destinationPlaceholder'] ?? 'Filter by destination…' ); ?></span>
					<span class="bbm-category-toggle-arrow" aria-hidden="true">&#9662;</span>
				</button>
				<div class="bbm-category-panel bbm-destination-panel" role="listbox" aria-multiselectable="true" hidden></div>
			</div>
			<div class="bbm-category-chips bbm-destination-chips" role="list"></div>
		</div>

		<div class="bbm-categories bbm-filter-group" hidden>
			<div class="bbm-category-dropdown">
				<button type="button" class="bbm-category-toggle" aria-expanded="false" aria-haspopup="listbox">
					<span class="bbm-category-toggle-label"><?php echo esc_html( $i18n['categoryPlaceholder'] ?? 'Filter by category…' ); ?></span>
					<span class="bbm-category-toggle-arrow" aria-hidden="true">&#9662;</span>
				</button>
				<div class="bbm-category-panel" role="listbox" aria-multiselectable="true" hidden></div>
			</div>
			<div class="bbm-category-chips" role="list"></div>
		</div>

		<div class="bbm-result-count" aria-live="polite"></div>
	</div>

	<div class="bbm-map" id="bbm-map" role="application" aria-label="Map of accommodations"></div>

	<div class="bbm-list-region">
		<div class="bbm-loader" role="status" aria-live="polite" hidden>
			<span class="bbm-loader-spinner" aria-hidden="true"></span>
			<span class="bbm-loader-text"><?php echo esc_html( $i18n['searching'] ); ?></span>
		</div>

		<div class="bbm-results" hidden>
			<ul class="bbm-results-list"></ul>
		</div>

		<div class="bbm-list">
			<h3 class="bbm-list-heading"><?php echo esc_html( $i18n['listHeading'] ); ?></h3>
			<ul class="bbm-list-items">
				<?php foreach ( $list_items as $item ) : ?>
					<li class="bbm-card" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>"
						<?php if ( $item['lat'] !== null && $item['lng'] !== null ) : ?>
						data-lat="<?php echo esc_attr( (string) $item['lat'] ); ?>"
						data-lng="<?php echo esc_attr( (string) $item['lng'] ); ?>"
						<?php endif; ?>>
						<?php if ( $item['thumbnail'] ) : ?>
							<div class="bbm-card-thumb" style="background-image:url('<?php echo esc_url( $item['thumbnail'] ); ?>');"></div>
						<?php endif; ?>
						<div class="bbm-card-body">
							<span class="bbm-card-title"><?php echo esc_html( $item['title'] ); ?></span>
							<?php if ( $item['address'] ) : ?>
								<div class="bbm-card-address"><?php echo esc_html( $item['address'] ); ?></div>
							<?php endif; ?>
							<?php if ( $item['pricing']['special'] || $item['pricing']['normal'] ) : ?>
								<div class="bbm-card-pricing">
									<?php if ( $item['pricing']['special'] ) : ?>
										<span class="bbm-price-special"><?php echo esc_html( $item['pricing']['special'] ); ?></span>
										<?php if ( $item['pricing']['unit'] ) : ?>
											<span class="bbm-price-unit"><?php echo esc_html( $item['pricing']['unit'] ); ?></span>
										<?php endif; ?>
										<?php if ( $item['pricing']['normal'] ) : ?>
											<s class="bbm-price-was"><?php echo esc_html( $item['pricing']['normal'] ); ?></s>
										<?php endif; ?>
										<?php if ( $item['pricing']['discount'] !== null ) : ?>
											<span class="bbm-price-discount">&minus;<?php echo (int) $item['pricing']['discount']; ?>%</span>
										<?php endif; ?>
										<?php if ( $item['pricing']['valid_label'] ) : ?>
											<div class="bbm-price-valid"><?php echo esc_html( $item['pricing']['valid_label'] ); ?></div>
										<?php endif; ?>
									<?php else : ?>
										<span class="bbm-price-normal"><?php echo esc_html( $item['pricing']['normal'] ); ?></span>
										<?php if ( $item['pricing']['unit'] ) : ?>
											<span class="bbm-price-unit"><?php echo esc_html( $item['pricing']['unit'] ); ?></span>
										<?php endif; ?>
									<?php endif; ?>
								</div>
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
</div>
