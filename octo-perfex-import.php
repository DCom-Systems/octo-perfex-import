<?php
/**
 * Plugin Name: Octo Perfex Import
 * Plugin URI:  https://github.com/DCom-Systems/octo-perfex-import
 * Description: Einmaliger Import von Firmen, Kontakten und Notizen aus Perfex CRM in Octoserv (Groundhogg). Rechnungsempfänger werden automatisch markiert.
 * Version:     1.0.0
 * Author:      DCom Systems KG
 * Author URI:  https://dcom-systems.de
 * Text Domain: octo-perfex-import
 * Requires Plugins: octoserv
 */

defined( 'ABSPATH' ) || exit;

define( 'OCTO_PERFEX_IMPORT_PATH', plugin_dir_path( __FILE__ ) );
define( 'OCTO_PERFEX_IMPORT_URL',  plugin_dir_url( __FILE__ ) );
define( 'OCTO_PERFEX_IMPORT_VERSION', '1.0.0' );

// AJAX-Handler laden
require_once OCTO_PERFEX_IMPORT_PATH . 'includes/class-ajax.php';
new Octo_AJAX_Perfex_Import();

// -------------------------------------------------------------------------
// Navigation: Eintrag im Funktionen-Untermenü (Core-Hook)
// -------------------------------------------------------------------------
add_action( 'octo_funktionen_submenu_items', function ( string $path ) {
	if ( ! function_exists( 'octo_user_is_admin' ) || ! octo_user_is_admin() ) {
		return;
	}
	$is_active = strpos( $path, 'funktionen/perfex-import' ) !== false;
	?>
	<li class="octo-nav-submenu-item<?php echo $is_active ? ' active' : ''; ?>">
		<a href="<?php echo esc_url( home_url( '/octo/funktionen/perfex-import' ) ); ?>" class="octo-nav-submenu-link">
			<span class="dashicons dashicons-migrate" style="margin-right:2px;"></span>
			<?php esc_html_e( 'Perfex-Import', 'octo-perfex-import' ); ?>
		</a>
	</li>
	<?php
} );

// -------------------------------------------------------------------------
// Routing: Seite ausliefern (Core-Hook)
// -------------------------------------------------------------------------
add_action( 'octo_funktionen_route', function ( string $uri ) {
	if ( strpos( $uri, '/octo/funktionen/perfex-import' ) === false ) {
		return;
	}

	// Zugriffsschutz: nur Admins
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/octo/login' ) );
		exit;
	}
	if ( ! ( function_exists( 'octo_user_is_admin' ) ? octo_user_is_admin() : current_user_can( 'manage_options' ) ) ) {
		wp_safe_redirect( home_url( '/octo/funktionen' ) );
		exit;
	}

	// Assets
	wp_enqueue_style( 'octo-frontend' );
	wp_enqueue_style(
		'octo-import',
		defined( 'OCTO_URL' ) ? OCTO_URL . 'assets/css/octo-import.css' : '',
		array(),
		defined( 'OCTO_VERSION' ) ? OCTO_VERSION : OCTO_PERFEX_IMPORT_VERSION
	);
	wp_enqueue_script(
		'octo-perfex-import',
		OCTO_PERFEX_IMPORT_URL . 'assets/js/perfex-import.js',
		array( 'jquery' ),
		OCTO_PERFEX_IMPORT_VERSION,
		true
	);

	// Template laden und Request beenden
	require OCTO_PERFEX_IMPORT_PATH . 'templates/wizard.php';
	exit;
} );
