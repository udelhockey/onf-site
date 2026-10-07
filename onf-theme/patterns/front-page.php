<?php
/**
 * Title: Home page
 * Slug: onf/front-page
 * Categories: onf-pages
 * Inserter: no
 */

?>
<!-- wp:group {"align":"full","className":"is-style-section-navy","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-section-navy" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
	<!-- wp:columns {"verticalAlignment":"center","align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|60","top":"var:preset|spacing|50"}}}} -->
	<div class="wp-block-columns alignwide are-vertically-aligned-center">
		<!-- wp:column {"verticalAlignment":"center","width":"60%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:60%">
			<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
			<p class="is-style-eyebrow"><?php esc_html_e( 'Open Net Foundation · since 2004', 'onf-theme' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:heading {"level":1} -->
			<h1 class="wp-block-heading"><?php esc_html_e( 'Hockey with a purpose', 'onf-theme' ); ?></h1>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"className":"is-style-lead"} -->
			<p class="is-style-lead"><?php esc_html_e( 'Hockey players and families turn games into funding for kids facing health problems, disabilities and illnesses. Every dollar raised goes to the causes we play for.', 'onf-theme' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( onf_theme_donate_url() ); ?>"><?php esc_html_e( 'Donate', 'onf-theme' ); ?></a></div>
				<!-- /wp:button -->

				<!-- wp:button {"className":"is-style-on-dark"} -->
				<div class="wp-block-button is-style-on-dark"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/events/' ) ); ?>"><?php esc_html_e( 'See events', 'onf-theme' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"verticalAlignment":"center","width":"40%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:40%">
			<!-- wp:image {"sizeSlug":"full","linkDestination":"none","align":"center","className":"onf-hero-badge"} -->
			<figure class="wp-block-image aligncenter size-full onf-hero-badge"><img src="<?php echo onf_theme_logo( 'badge.svg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>" alt="<?php esc_attr_e( 'Open Net Foundation badge: putting a check on childhood diseases', 'onf-theme' ); ?>"/></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
	<!-- wp:onf/foundation-total {"layout":"stats","align":"wide"} /-->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"is-style-section-cloud","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-section-cloud" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|60","top":"var:preset|spacing|50"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"62%"} -->
		<div class="wp-block-column" style="flex-basis:62%">
			<!-- wp:onf/cards {"source":"active","heading":"Happening now"} /-->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"38%"} -->
		<div class="wp-block-column" style="flex-basis:38%">
			<!-- wp:onf/leaderboard {"limit":5,"more":false} /-->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"is-style-section-ice","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-section-ice" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
	<!-- wp:paragraph {"className":"is-style-eyebrow"} -->
	<p class="is-style-eyebrow"><?php esc_html_e( 'Our mission', 'onf-theme' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph {"className":"is-style-lead"} -->
	<p class="is-style-lead"><?php esc_html_e( 'The Open Net Foundation leads events that generate funding for non-profit organizations and facilities that provide treatment, counseling, education, or assistance for childhood health problems, disabilities, or illnesses.', 'onf-theme' ); ?></p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons -->
	<div class="wp-block-buttons">
		<!-- wp:button {"className":"is-style-outline-navy"} -->
		<div class="wp-block-button is-style-outline-navy"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php esc_html_e( 'About us', 'onf-theme' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--70)">
	<!-- wp:onf/cards {"source":"past","limit":6,"heading":"Past events","align":"wide"} /-->

	<!-- wp:buttons {"align":"wide"} -->
	<div class="wp-block-buttons alignwide">
		<!-- wp:button {"className":"is-style-outline-navy"} -->
		<div class="wp-block-button is-style-outline-navy"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/events/#past' ) ); ?>"><?php esc_html_e( 'All past events', 'onf-theme' ); ?></a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
