<?php
/**
 * Perfex CRM Import Wizard
 *
 * 5-Schritte-Wizard für den Import von Firmen, Kontakten und Notizen
 * aus einer Perfex CRM-Datenbank.
 *
 * @package Octoserv
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$current_user = wp_get_current_user();
$logo_url     = octo_get_option( 'logo_url' );

// CRM-User für Owner-Zuweisung
$crm_users = array();
if ( function_exists( 'octo_get_crm_users' ) ) {
	$raw_users = octo_get_crm_users( false );
	foreach ( $raw_users as $u ) {
		$crm_users[] = array( 'id' => $u->ID, 'name' => $u->display_name );
	}
}

// Gespeicherte DB-Config laden (wird nach erfolgreichem Connect automatisch gespeichert)
$saved_db = get_option( 'octo_perfex_db_config', array() );

// JS-Daten bereitstellen (Script-Handle entspricht dem im Plugin registrierten)
wp_localize_script( 'octo-perfex-import', 'octoPerfexImportData', array(
	'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
	'nonce'    => wp_create_nonce( 'octo_perfex_import_nonce' ),
	'crmUsers' => $crm_users,
	'savedDb'  => array(
		'host'   => ! empty( $saved_db['host'] )   ? $saved_db['host']   : '',
		'name'   => ! empty( $saved_db['name'] )   ? $saved_db['name']   : '',
		'user'   => ! empty( $saved_db['user'] )   ? $saved_db['user']   : '',
		'prefix' => ! empty( $saved_db['prefix'] ) ? $saved_db['prefix'] : 'tbl',
	),
	'i18n'     => array(
		'step1'       => __( 'Verbindung',  'octoserv' ),
		'step2'       => __( 'Optionen',    'octoserv' ),
		'step3'       => __( 'Vorschau',    'octoserv' ),
		'step4'       => __( 'Import',      'octoserv' ),
		'step5'       => __( 'Ergebnis',    'octoserv' ),
		'connecting'  => __( 'Verbinde...', 'octoserv' ),
		'importing'   => __( 'Importiere Daten...', 'octoserv' ),
		'done'        => __( 'Fertig!',     'octoserv' ),
	),
) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body class="octo-app">
	<aside class="octo-sidebar">
		<div class="octo-sidebar-logo">
			<?php if ( $logo_url ) : ?>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" class="octo-logo">
			<?php else : ?>
				<h1 class="octo-logo-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
			<?php endif; ?>
		</div>
		<?php include OCTO_PATH . 'templates/includes/navigation-sidebar.php'; ?>
		<?php include OCTO_PATH . 'templates/includes/sidebar-footer.php'; ?>
	</aside>
	<main class="octo-main">
		<div class="octo-content">
			<h1><?php esc_html_e( 'Perfex-Import', 'octoserv' ); ?></h1>

			<!-- Wizard Stepper -->
			<div class="octo-import-stepper">
				<div class="octo-import-step active" data-step="1">
					<div class="octo-import-step-circle">1</div>
					<div class="octo-import-step-label"><?php esc_html_e( 'Verbindung', 'octoserv' ); ?></div>
				</div>
				<div class="octo-import-step-line"></div>
				<div class="octo-import-step" data-step="2">
					<div class="octo-import-step-circle">2</div>
					<div class="octo-import-step-label"><?php esc_html_e( 'Optionen', 'octoserv' ); ?></div>
				</div>
				<div class="octo-import-step-line"></div>
				<div class="octo-import-step" data-step="3">
					<div class="octo-import-step-circle">3</div>
					<div class="octo-import-step-label"><?php esc_html_e( 'Vorschau', 'octoserv' ); ?></div>
				</div>
				<div class="octo-import-step-line"></div>
				<div class="octo-import-step" data-step="4">
					<div class="octo-import-step-circle">4</div>
					<div class="octo-import-step-label"><?php esc_html_e( 'Import', 'octoserv' ); ?></div>
				</div>
				<div class="octo-import-step-line"></div>
				<div class="octo-import-step" data-step="5">
					<div class="octo-import-step-circle">5</div>
					<div class="octo-import-step-label"><?php esc_html_e( 'Ergebnis', 'octoserv' ); ?></div>
				</div>
			</div>

			<!-- Step 1: Datenbankverbindung -->
			<div class="octo-import-panel" id="octo-pimport-step-1">
				<h2><?php esc_html_e( 'Datenbankverbindung', 'octoserv' ); ?></h2>
				<p class="octo-import-hint"><?php esc_html_e( 'Geben Sie die Verbindungsdaten zur Perfex CRM-Datenbank ein.', 'octoserv' ); ?></p>

				<div class="octo-import-form-grid">
					<div class="octo-import-form-row">
						<label for="pimport-db-host"><?php esc_html_e( 'Host', 'octoserv' ); ?></label>
						<input type="text" id="pimport-db-host" value="localhost" class="octo-input">
					</div>
					<div class="octo-import-form-row">
						<label for="pimport-db-name"><?php esc_html_e( 'Datenbankname', 'octoserv' ); ?></label>
						<input type="text" id="pimport-db-name" value="" placeholder="z.B. perfex_db" class="octo-input">
					</div>
					<div class="octo-import-form-row">
						<label for="pimport-db-user"><?php esc_html_e( 'Benutzername', 'octoserv' ); ?></label>
						<input type="text" id="pimport-db-user" value="" class="octo-input">
					</div>
					<div class="octo-import-form-row">
						<label for="pimport-db-pass"><?php esc_html_e( 'Passwort', 'octoserv' ); ?></label>
						<input type="password" id="pimport-db-pass" value="" class="octo-input">
					</div>
					<div class="octo-import-form-row">
						<label for="pimport-db-prefix"><?php esc_html_e( 'Tabellenprefix', 'octoserv' ); ?></label>
						<input type="text" id="pimport-db-prefix" value="tbl" class="octo-input" style="width:120px;">
					</div>
				</div>

				<div id="pimport-connect-result" style="display:none; margin-top:16px;"></div>

				<div class="octo-import-actions" style="margin-top:20px;">
					<button type="button" id="pimport-btn-connect" class="octo-btn octo-btn-primary">
						<span class="dashicons dashicons-migrate" style="margin-right:4px;"></span>
						<?php esc_html_e( 'Verbindung testen', 'octoserv' ); ?>
					</button>
					<button type="button" id="pimport-btn-step1-next" class="octo-btn octo-btn-primary" style="display:none; margin-left:8px;">
						<?php esc_html_e( 'Weiter', 'octoserv' ); ?> &rarr;
					</button>
				</div>
			</div>

			<!-- Step 2: Optionen -->
			<div class="octo-import-panel" id="octo-pimport-step-2" style="display:none;">
				<h2><?php esc_html_e( 'Import-Optionen', 'octoserv' ); ?></h2>

				<div class="octo-import-form-grid">
					<div class="octo-import-form-row">
						<label><?php esc_html_e( 'Welche Clients importieren?', 'octoserv' ); ?></label>
						<div class="octo-radio-group">
							<label><input type="radio" name="pimport-scope" value="0" checked> <?php esc_html_e( 'Nur aktive Clients', 'octoserv' ); ?></label>
							<label style="margin-left:16px;"><input type="radio" name="pimport-scope" value="1"> <?php esc_html_e( 'Alle Clients (inkl. inaktive)', 'octoserv' ); ?></label>
						</div>
					</div>

					<div class="octo-import-form-row">
						<label for="pimport-owner"><?php esc_html_e( 'Verantwortlicher (Owner)', 'octoserv' ); ?></label>
						<select id="pimport-owner" class="octo-select">
							<?php foreach ( $crm_users as $u ) : ?>
								<option value="<?php echo esc_attr( $u['id'] ); ?>"<?php selected( $u['id'], $current_user->ID ); ?>>
									<?php echo esc_html( $u['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="octo-import-form-row">
						<label for="pimport-tag"><?php esc_html_e( 'Tag für importierte Kontakte', 'octoserv' ); ?> <span style="color:#999;">(<?php esc_html_e( 'optional', 'octoserv' ); ?>)</span></label>
						<input type="text" id="pimport-tag" placeholder="<?php esc_attr_e( 'z.B. Perfex-Import-2026', 'octoserv' ); ?>" class="octo-input" style="max-width:300px;">
					</div>

					<div class="octo-import-form-row">
						<label><?php esc_html_e( 'Notizen importieren?', 'octoserv' ); ?></label>
						<label class="octo-toggle">
							<input type="checkbox" id="pimport-notes" value="1" checked>
							<span class="octo-toggle-slider"></span>
						</label>
						<span style="margin-left:8px; color:#666; font-size:13px;"><?php esc_html_e( 'Kundennotizen als Journal-Einträge anlegen', 'octoserv' ); ?></span>
					</div>
				</div>

				<div class="octo-import-actions" style="margin-top:20px;">
					<button type="button" id="pimport-btn-step2-back" class="octo-btn octo-btn-secondary">&larr; <?php esc_html_e( 'Zurück', 'octoserv' ); ?></button>
					<button type="button" id="pimport-btn-step2-next" class="octo-btn octo-btn-primary" style="margin-left:8px;">
						<?php esc_html_e( 'Vorschau laden', 'octoserv' ); ?> &rarr;
					</button>
				</div>
			</div>

			<!-- Step 3: Vorschau -->
			<div class="octo-import-panel" id="octo-pimport-step-3" style="display:none;">
				<h2><?php esc_html_e( 'Vorschau', 'octoserv' ); ?></h2>
				<p class="octo-import-hint"><?php esc_html_e( 'Die ersten 5 Firmen mit ihren Kontakten. Rechnungsempfänger werden automatisch markiert.', 'octoserv' ); ?></p>

				<div id="pimport-preview-table"></div>

				<div class="octo-import-actions" style="margin-top:20px;">
					<button type="button" id="pimport-btn-step3-back" class="octo-btn octo-btn-secondary">&larr; <?php esc_html_e( 'Zurück', 'octoserv' ); ?></button>
					<button type="button" id="pimport-btn-start" class="octo-btn octo-btn-primary" style="margin-left:8px;">
						<span class="dashicons dashicons-migrate" style="margin-right:4px;"></span>
						<?php esc_html_e( 'Import starten', 'octoserv' ); ?>
					</button>
				</div>
			</div>

			<!-- Step 4: Import -->
			<div class="octo-import-panel" id="octo-pimport-step-4" style="display:none;">
				<h2><?php esc_html_e( 'Importiere Daten…', 'octoserv' ); ?></h2>

				<div class="octo-import-progress-wrap">
					<div class="octo-import-progress-label" id="pimport-phase-label"><?php esc_html_e( 'Firmen importieren…', 'octoserv' ); ?></div>
					<div class="octo-import-progress-bar">
						<div class="octo-import-progress-fill" id="pimport-progress-fill" style="width:0%"></div>
					</div>
					<div class="octo-import-progress-info" id="pimport-progress-info"></div>
				</div>

				<div class="octo-import-live-stats" style="margin-top:20px; display:flex; gap:16px; flex-wrap:wrap;">
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-stat-companies">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Firmen', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-stat-contacts">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Kontakte', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-stat-recipients">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Rechnungsempfänger', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-stat-notes">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Notizen', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-stat-errors" style="color:#e44;">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Fehler', 'octoserv' ); ?></div>
					</div>
				</div>

				<div class="octo-import-actions" style="margin-top:20px;">
					<button type="button" id="pimport-btn-cancel" class="octo-btn octo-btn-secondary">
						<?php esc_html_e( 'Abbrechen', 'octoserv' ); ?>
					</button>
				</div>
			</div>

			<!-- Step 5: Ergebnis -->
			<div class="octo-import-panel" id="octo-pimport-step-5" style="display:none;">
				<h2><?php esc_html_e( 'Import abgeschlossen', 'octoserv' ); ?></h2>

				<div class="octo-import-result-stats" style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px;">
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-result-companies-new">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Neue Firmen', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-result-companies-updated">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Firmen aktualisiert', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-result-contacts-new">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Neue Kontakte', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-result-contacts-updated">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Kontakte aktualisiert', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card" style="border-color:#E09000;">
						<div class="octo-import-stat-value" id="pimport-result-recipients" style="color:#E09000;">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Rechnungsempfänger markiert', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-result-notes">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Notizen', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card">
						<div class="octo-import-stat-value" id="pimport-result-skipped">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Übersprungen', 'octoserv' ); ?></div>
					</div>
					<div class="octo-import-stat-card" id="pimport-error-card" style="display:none; border-color:#e44;">
						<div class="octo-import-stat-value" id="pimport-result-errors" style="color:#e44;">0</div>
						<div class="octo-import-stat-label"><?php esc_html_e( 'Fehler', 'octoserv' ); ?></div>
					</div>
				</div>

				<div id="pimport-error-list" style="display:none; margin-bottom:20px;">
					<h3><?php esc_html_e( 'Fehlerprotokoll', 'octoserv' ); ?></h3>
					<ul id="pimport-error-ul" style="max-height:200px; overflow-y:auto; background:#fff3f3; border:1px solid #f5c6c6; padding:12px 12px 12px 28px; border-radius:4px;"></ul>
				</div>

				<div class="octo-import-actions">
					<a href="<?php echo esc_url( home_url( '/octo/companies' ) ); ?>" class="octo-btn octo-btn-primary">
						<?php esc_html_e( 'Zu den Firmen', 'octoserv' ); ?>
					</a>
					<a href="<?php echo esc_url( home_url( '/octo/contacts' ) ); ?>" class="octo-btn octo-btn-secondary" style="margin-left:8px;">
						<?php esc_html_e( 'Zu den Kontakten', 'octoserv' ); ?>
					</a>
					<button type="button" id="pimport-btn-restart" class="octo-btn octo-btn-secondary" style="margin-left:8px;">
						<?php esc_html_e( 'Neuer Import', 'octoserv' ); ?>
					</button>
				</div>
			</div>

		</div><!-- .octo-content -->
	</main>
	<?php wp_footer(); ?>
</body>
</html>
