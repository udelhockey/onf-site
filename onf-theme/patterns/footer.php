<?php
/**
 * Title: Footer
 * Slug: onf/footer
 * Categories: footer
 * Block Types: core/template-part/footer
 * Inserter: no
 */

?>
<!-- wp:group {"align":"full","className":"is-style-section-navy","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull is-style-section-navy" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--40)">
	<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|60"}}}} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"width":"44%"} -->
		<div class="wp-block-column" style="flex-basis:44%">
			<!-- wp:image {"width":"240px","sizeSlug":"full","linkDestination":"custom"} -->
			<figure class="wp-block-image size-full is-resized"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo onf_theme_logo( 'lockup-compact-white.svg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>" alt="<?php esc_attr_e( 'Open Net Foundation home', 'onf-theme' ); ?>" style="width:240px"/></a></figure>
			<!-- /wp:image -->

			<!-- wp:paragraph {"textColor":"on-navy","fontSize":"small"} -->
			<p class="has-on-navy-color has-text-color has-small-font-size"><?php esc_html_e( 'Hockey events that fund treatment, counseling, education and support for kids facing health problems, disabilities and illnesses.', 'onf-theme' ); ?></p>
			<!-- /wp:paragraph -->

			<!-- wp:onf/foundation-total {"layout":"line"} /-->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:heading {"level":6} -->
			<h6 class="wp-block-heading"><?php esc_html_e( 'Explore', 'onf-theme' ); ?></h6>
			<!-- /wp:heading -->

			<!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex","orientation":"vertical"},"style":{"spacing":{"blockGap":"var:preset|spacing|10"}}} -->
			<!-- wp:navigation-link {"label":"Events","url":"/events/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"Donate","url":"/donation/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"Sponsors","url":"/sponsors/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"Contact","url":"/contact-us/","kind":"custom","isTopLevelLink":true} /-->
			<!-- /wp:navigation -->
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:heading {"level":6} -->
			<h6 class="wp-block-heading"><?php esc_html_e( 'Contact', 'onf-theme' ); ?></h6>
			<!-- /wp:heading -->

			<!-- wp:paragraph {"fontSize":"small"} -->
			<p class="has-small-font-size">Open Net Foundation<br>PO Box 5355<br>Wilmington, DE 19808</p>
			<!-- /wp:paragraph -->

			<!-- wp:paragraph {"textColor":"on-navy","fontSize":"small"} -->
			<p class="has-on-navy-color has-text-color has-small-font-size"><?php esc_html_e( '501(c)(3) nonprofit · EIN 20-1472003', 'onf-theme' ); ?></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->

	<!-- wp:separator {"align":"wide","className":"is-style-wide"} -->
	<hr class="wp-block-separator alignwide has-alpha-channel-opacity is-style-wide"/>
	<!-- /wp:separator -->

	<!-- wp:paragraph {"align":"wide","textColor":"on-navy","fontSize":"x-small"} -->
	<p class="alignwide has-on-navy-color has-text-color has-x-small-font-size">© <?php echo esc_html( gmdate( 'Y' ) ); ?> Open Net Foundation · <?php esc_html_e( 'Site by', 'onf-theme' ); ?> Madkel</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
