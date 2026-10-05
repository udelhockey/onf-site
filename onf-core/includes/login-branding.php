<?php
/**
 * Login page branding: ONF logo on wp-login.php, linked to the site home.
 * Replaces the "My WP Login Logo" plugin.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'login_enqueue_scripts',
	static function () {
		$logo = esc_url( ONF_CORE_URL . 'assets/onf-login-logo.svg?v=' . ONF_CORE_VERSION );
		?>
		<style>
			#login h1 a,
			.login h1 a {
				background-image: url('<?php echo $logo; ?>');
				background-size: contain;
				background-position: center;
				background-repeat: no-repeat;
				width: 320px;
				height: 50px;
				max-width: 100%;
				margin-bottom: 24px;
			}
		</style>
		<?php
	}
);

add_filter( 'login_headerurl', static fn() => home_url( '/' ) );
add_filter( 'login_headertext', static fn() => get_bloginfo( 'name' ) );
