<?php
/**
 * Title: Header
 * Slug: onf/header
 * Categories: header
 * Block Types: core/template-part/header
 * Inserter: no
 */

?>
<!-- wp:group {"align":"full","className":"onf-header is-style-section-navy","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull onf-header is-style-section-navy" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">
	<!-- wp:group {"align":"wide","layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:image {"sizeSlug":"full","linkDestination":"custom","className":"onf-logo"} -->
		<figure class="wp-block-image size-full onf-logo"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img src="<?php echo onf_theme_logo( 'lockup-compact-white.svg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>" alt="<?php esc_attr_e( 'Open Net Foundation home', 'onf-theme' ); ?>"/></a></figure>
		<!-- /wp:image -->

		<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|40"}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">
			<!-- wp:navigation {"overlayBackgroundColor":"navy","overlayTextColor":"white","overlayMenu":"mobile","layout":{"type":"flex","justifyContent":"right"},"style":{"spacing":{"blockGap":"var:preset|spacing|40"}}} -->
			<!-- wp:navigation-link {"label":"Events","url":"/events/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"20 Something Hockey","url":"/20s/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"Sponsors","url":"/sponsors/","kind":"custom","isTopLevelLink":true} /-->
			<!-- wp:navigation-link {"label":"Contact","url":"/contact-us/","kind":"custom","isTopLevelLink":true} /-->
			<!-- /wp:navigation -->

			<!-- wp:buttons {"className":"onf-header__donate"} -->
			<div class="wp-block-buttons onf-header__donate">
				<!-- wp:button -->
				<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( onf_theme_donate_url() ); ?>"><?php esc_html_e( 'Donate', 'onf-theme' ); ?></a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
