<?php
/**
 * AJAX-Handler: Perfex CRM Import
 *
 * Importiert Firmen, Kontakte und Notizen aus einer Perfex-CRM-Datenbank
 * direkt in Octoserv (Groundhogg). Rechnungsempfänger werden automatisch
 * per invoice_recipient-Meta markiert. Idempotent via perfex_company_id /
 * perfex_contact_id Meta-Keys.
 *
 * @package Octoserv
 */

defined( 'ABSPATH' ) || exit;

class Octo_AJAX_Perfex_Import {

	const BATCH_SIZE_COMPANIES = 20;
	const BATCH_SIZE_CONTACTS  = 50;
	const BATCH_SIZE_NOTES     = 50;

	public function __construct() {
		add_action( 'wp_ajax_octo_perfex_import_connect', array( $this, 'handle_connect' ) );
		add_action( 'wp_ajax_octo_perfex_import_preview', array( $this, 'handle_preview' ) );
		add_action( 'wp_ajax_octo_perfex_import_batch',   array( $this, 'handle_batch' ) );
		add_action( 'wp_ajax_octo_perfex_import_cancel',  array( $this, 'handle_cancel' ) );
	}

	// -------------------------------------------------------------------------
	// Sicherheitscheck
	// -------------------------------------------------------------------------

	private function check_permission(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Nicht angemeldet.' ), 403 );
		}
		if ( ! function_exists( 'octo_user_can_access_funktionen_perfex_import' ) || ! octo_user_can_access_funktionen_perfex_import() ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung für Perfex-Import.' ), 403 );
		}
	}

	private function check_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'octo_perfex_import_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Sicherheitsüberprüfung fehlgeschlagen.' ), 403 );
		}
	}

	// -------------------------------------------------------------------------
	// DB-Verbindung
	// -------------------------------------------------------------------------

	private function get_db_config(): array {
		return array(
			'host'   => sanitize_text_field( wp_unslash( $_POST['db_host']   ?? 'localhost' ) ),
			'user'   => sanitize_text_field( wp_unslash( $_POST['db_user']   ?? '' ) ),
			'pass'   => sanitize_text_field( wp_unslash( $_POST['db_pass']   ?? '' ) ),
			'name'   => sanitize_text_field( wp_unslash( $_POST['db_name']   ?? '' ) ),
			'prefix' => sanitize_text_field( wp_unslash( $_POST['db_prefix'] ?? 'tbl' ) ),
		);
	}

	private function connect( array $db_config ): \mysqli|false {
		$mysqli = new \mysqli(
			$db_config['host'],
			$db_config['user'],
			$db_config['pass'],
			$db_config['name']
		);

		if ( $mysqli->connect_error ) {
			return false;
		}

		$mysqli->set_charset( 'utf8mb4' );
		return $mysqli;
	}

	// -------------------------------------------------------------------------
	// Schritt 1: Verbindung testen + Zähler
	// -------------------------------------------------------------------------

	public function handle_connect(): void {
		$this->check_permission();
		$this->check_nonce();

		$db_config = $this->get_db_config();
		$mysqli    = $this->connect( $db_config );

		if ( ! $mysqli ) {
			wp_send_json_error( array( 'message' => 'Datenbankverbindung fehlgeschlagen. Zugangsdaten prüfen.' ) );
		}

		$prefix = $db_config['prefix'];

		$counts = array();
		foreach ( array( 'companies' => 'clients', 'contacts' => 'contacts', 'notes' => 'customernotes' ) as $key => $table ) {
			$res = $mysqli->query( "SELECT COUNT(*) FROM `{$prefix}_{$table}`" );
			$counts[ $key ] = $res ? (int) $res->fetch_row()[0] : 0;
		}

		// Aktive Clients zählen
		$res_active = $mysqli->query( "SELECT COUNT(*) FROM `{$prefix}_clients` WHERE active = 1" );
		$counts['companies_active'] = $res_active ? (int) $res_active->fetch_row()[0] : 0;

		// Rechnungsempfänger zählen
		$res_inv = $mysqli->query( "SELECT COUNT(*) FROM `{$prefix}_contacts` WHERE invoice_emails = 1" );
		$counts['invoice_recipients'] = $res_inv ? (int) $res_inv->fetch_row()[0] : 0;

		$mysqli->close();

		// DB-Config (ohne Passwort) für nächsten Besuch speichern
		update_option( 'octo_perfex_db_config', array(
			'host'   => $db_config['host'],
			'name'   => $db_config['name'],
			'user'   => $db_config['user'],
			'prefix' => $db_config['prefix'],
		) );

		wp_send_json_success( $counts );
	}

	// -------------------------------------------------------------------------
	// Schritt 3: Vorschau (erste 5 Firmen mit Kontakten)
	// -------------------------------------------------------------------------

	public function handle_preview(): void {
		$this->check_permission();
		$this->check_nonce();

		$db_config       = $this->get_db_config();
		$include_inactive = ! empty( $_POST['include_inactive'] ) && $_POST['include_inactive'] === '1';
		$mysqli          = $this->connect( $db_config );

		if ( ! $mysqli ) {
			wp_send_json_error( array( 'message' => 'Datenbankverbindung fehlgeschlagen.' ) );
		}

		$prefix     = $db_config['prefix'];
		$where      = $include_inactive ? '' : 'WHERE active = 1 ';
		$clients    = $mysqli->query( "SELECT * FROM `{$prefix}_clients` {$where}ORDER BY userid ASC LIMIT 5" );
		$preview    = array();

		if ( $clients ) {
			while ( $client = $clients->fetch_object() ) {
				$item = array(
					'id'      => $client->userid,
					'company' => $client->company,
					'website' => $client->website ?? '',
					'city'    => $client->city ?? '',
					'active'  => (int) $client->active,
					'contacts' => array(),
				);

				$con_res = $mysqli->query( "SELECT * FROM `{$prefix}_contacts` WHERE userid = {$client->userid} ORDER BY is_primary DESC, id ASC" );
				if ( $con_res ) {
					while ( $con = $con_res->fetch_object() ) {
						$item['contacts'][] = array(
							'name'              => trim( ( $con->firstname ?? '' ) . ' ' . ( $con->lastname ?? '' ) ),
							'email'             => $con->email ?? '',
							'is_primary'        => (int) ( $con->is_primary ?? 0 ),
							'invoice_recipient' => (int) ( $con->invoice_emails ?? 0 ),
						);
					}
				}

				$preview[] = $item;
			}
		}

		$mysqli->close();

		wp_send_json_success( array( 'preview' => $preview ) );
	}

	// -------------------------------------------------------------------------
	// Schritt 4: Batch-Import
	// -------------------------------------------------------------------------

	public function handle_batch(): void {
		$this->check_permission();
		$this->check_nonce();

		global $wpdb;

		$db_config        = $this->get_db_config();
		$phase            = sanitize_text_field( wp_unslash( $_POST['phase']            ?? 'companies' ) );
		$offset           = absint( $_POST['offset'] ?? 0 );
		$include_inactive = ! empty( $_POST['include_inactive'] ) && $_POST['include_inactive'] === '1';
		$owner_id         = absint( $_POST['owner_id'] ?? get_current_user_id() );
		$tag_name         = sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) );
		$import_notes     = ! empty( $_POST['import_notes'] ) && $_POST['import_notes'] === '1';
		$import_id        = sanitize_text_field( wp_unslash( $_POST['import_id'] ?? '' ) );

		// Abbruch-Check
		if ( $import_id && get_transient( 'octo_pimport_cancelled_' . $import_id ) ) {
			wp_send_json_success( array( 'cancelled' => true ) );
		}

		$mysqli = $this->connect( $db_config );
		if ( ! $mysqli ) {
			wp_send_json_error( array( 'message' => 'Datenbankverbindung fehlgeschlagen.' ) );
		}

		$prefix = $db_config['prefix'];
		$stats  = array(
			'companies_new'     => 0,
			'companies_updated' => 0,
			'contacts_new'      => 0,
			'contacts_updated'  => 0,
			'invoice_recipients'=> 0,
			'notes'             => 0,
			'skipped'           => 0,
			'errors'            => array(),
		);

		if ( 'companies' === $phase ) {
			$this->batch_companies( $mysqli, $prefix, $include_inactive, $owner_id, $offset, $stats, $wpdb );
			$is_complete = $stats['_batch_count'] < self::BATCH_SIZE_COMPANIES;
		} elseif ( 'contacts' === $phase ) {
			$this->batch_contacts( $mysqli, $prefix, $include_inactive, $owner_id, $tag_name, $offset, $stats, $wpdb );
			$is_complete = $stats['_batch_count'] < self::BATCH_SIZE_CONTACTS;
		} elseif ( 'notes' === $phase ) {
			if ( $import_notes ) {
				$this->batch_notes( $mysqli, $prefix, $include_inactive, $offset, $stats, $wpdb );
				$is_complete = $stats['_batch_count'] < self::BATCH_SIZE_NOTES;
			} else {
				$is_complete = true;
			}
		} else {
			$is_complete = true;
		}

		$mysqli->close();

		unset( $stats['_batch_count'] );

		wp_send_json_success( array(
			'stats'       => $stats,
			'is_complete' => $is_complete,
			'next_offset' => $is_complete ? 0 : ( $offset + ( 'companies' === $phase ? self::BATCH_SIZE_COMPANIES : self::BATCH_SIZE_CONTACTS ) ),
		) );
	}

	// -------------------------------------------------------------------------
	// Batch: Firmen
	// -------------------------------------------------------------------------

	private function batch_companies( \mysqli $mysqli, string $prefix, bool $include_inactive, int $owner_id, int $offset, array &$stats, object $wpdb ): void {
		$where = $include_inactive ? '' : 'WHERE active = 1 ';
		$limit = self::BATCH_SIZE_COMPANIES;
		$res   = $mysqli->query( "SELECT * FROM `{$prefix}_clients` {$where}ORDER BY userid ASC LIMIT {$limit} OFFSET {$offset}" );

		$count = 0;
		if ( ! $res ) {
			$stats['errors'][] = 'Fehler beim Lesen der Firmenliste: ' . $mysqli->error;
			$stats['_batch_count'] = 0;
			return;
		}

		$companymeta_table = $wpdb->prefix . 'gh_companymeta';

		while ( $client = $res->fetch_object() ) {
			$count++;
			$perfex_id = (int) $client->userid;

			// Duplikat-Check via perfex_company_id meta
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT company_id FROM `{$companymeta_table}` WHERE meta_key = 'perfex_company_id' AND meta_value = %s LIMIT 1",
				(string) $perfex_id
			) );

			$address = trim( implode( ', ', array_filter( array(
				$client->address ?? '',
				( $client->zip ?? '' ) . ( isset( $client->zip ) && isset( $client->city ) ? ' ' : '' ) . ( $client->city ?? '' ),
			) ) ) );

			if ( $existing_id ) {
				// Update: Name, Domain, Adresse, Telefon
				$wpdb->update(
					$wpdb->prefix . 'gh_companies',
					array(
						'name'   => sanitize_text_field( $client->company ),
						'domain' => esc_url_raw( $client->website ?? '' ),
					),
					array( 'ID' => $existing_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				$this->upsert_companymeta( $wpdb, $existing_id, 'phone',   sanitize_text_field( $client->phonenumber ?? '' ) );
				$this->upsert_companymeta( $wpdb, $existing_id, 'address', sanitize_text_field( $address ) );
				$this->upsert_companymeta( $wpdb, $existing_id, 'vat_id',  sanitize_text_field( $client->vat ?? '' ) );
				$stats['companies_updated']++;
			} else {
				// Neu anlegen
				if ( ! class_exists( 'OCTO_Companies_Data' ) ) {
					$path = OCTO_PATH . 'includes/modules/companies/class-octo-companies-data.php';
					if ( file_exists( $path ) ) {
						require_once $path;
					}
				}

				$result = OCTO_Companies_Data::create_company( array(
					'name'     => sanitize_text_field( $client->company ),
					'domain'   => esc_url_raw( $client->website ?? '' ),
					'owner_id' => $owner_id,
					'phone'    => sanitize_text_field( $client->phonenumber ?? '' ),
					'address'  => sanitize_text_field( $address ),
				) );

				if ( is_wp_error( $result ) ) {
					$stats['errors'][] = 'Firma "' . esc_html( $client->company ) . '": ' . $result->get_error_message();
					continue;
				}

				$new_id = (int) $result;

				// vat_id speichern
				if ( ! empty( $client->vat ) ) {
					$this->upsert_companymeta( $wpdb, $new_id, 'vat_id', sanitize_text_field( $client->vat ) );
				}

				// Bridge: perfex_company_id speichern
				$wpdb->insert(
					$companymeta_table,
					array(
						'company_id' => $new_id,
						'meta_key'   => 'perfex_company_id',
						'meta_value' => (string) $perfex_id,
					),
					array( '%d', '%s', '%s' )
				);

				$stats['companies_new']++;
			}
		}

		$stats['_batch_count'] = $count;
	}

	// -------------------------------------------------------------------------
	// Batch: Kontakte
	// -------------------------------------------------------------------------

	private function batch_contacts( \mysqli $mysqli, string $prefix, bool $include_inactive, int $owner_id, string $tag_name, int $offset, array &$stats, object $wpdb ): void {
		$where = $include_inactive ? '' : 'WHERE cl.active = 1 ';
		$limit = self::BATCH_SIZE_CONTACTS;
		$sql   = "SELECT c.*, cl.company AS company_name
		          FROM `{$prefix}_contacts` c
		          LEFT JOIN `{$prefix}_clients` cl ON cl.userid = c.userid
		          {$where}ORDER BY c.id ASC LIMIT {$limit} OFFSET {$offset}";
		$res   = $mysqli->query( $sql );

		$count = 0;
		if ( ! $res ) {
			$stats['errors'][] = 'Fehler beim Lesen der Kontaktliste: ' . $mysqli->error;
			$stats['_batch_count'] = 0;
			return;
		}

		$rel_table         = $wpdb->prefix . 'gh_object_relationships';
		$companies_table   = $wpdb->prefix . 'gh_companies';
		$companymeta_table = $wpdb->prefix . 'gh_companymeta';
		$contactmeta_table = $wpdb->prefix . 'gh_contactmeta';

		// Tag vorbereiten
		$tag_id = 0;
		if ( $tag_name && function_exists( '\Groundhogg\parse_tag_list' ) ) {
			$tag_ids = \Groundhogg\parse_tag_list( $tag_name, 'ID', true );
			$tag_id  = ! empty( $tag_ids ) ? (int) $tag_ids[0] : 0;
		}

		while ( $contact = $res->fetch_object() ) {
			$count++;
			$perfex_id = (int) $contact->id;
			$email     = trim( $contact->email ?? '' );

			if ( ! is_email( $email ) ) {
				$stats['skipped']++;
				continue;
			}

			// E-Mail auf 50 Zeichen kürzen (UNIQUE-Constraint wp_gh_contacts)
			if ( strlen( $email ) > 50 ) {
				$stats['skipped']++;
				continue;
			}

			// Duplikat-Check: via perfex_contact_id meta
			$existing_contact_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT contact_id FROM `{$contactmeta_table}` WHERE meta_key = 'perfex_contact_id' AND meta_value = %s LIMIT 1",
				(string) $perfex_id
			) );

			if ( ! $existing_contact_id && function_exists( '\Groundhogg\get_contactdata' ) ) {
				$gh = \Groundhogg\get_contactdata( $email );
				if ( $gh && $gh->exists() ) {
					$existing_contact_id = $gh->get_id();
				}
			}

			if ( $existing_contact_id ) {
				if ( ! function_exists( '\Groundhogg\get_contactdata' ) ) {
					$stats['skipped']++;
					continue;
				}
				$gh = \Groundhogg\get_contactdata( $existing_contact_id );
				if ( ! $gh || ! $gh->exists() ) {
					$stats['skipped']++;
					continue;
				}
				$stats['contacts_updated']++;
			} else {
				if ( ! class_exists( '\Groundhogg\Contact' ) ) {
					$stats['skipped']++;
					continue;
				}
				$gh = new \Groundhogg\Contact( array(
					'email'      => $email,
					'first_name' => sanitize_text_field( $contact->firstname ?? '' ),
					'last_name'  => sanitize_text_field( $contact->lastname  ?? '' ),
					'owner_id'   => $owner_id,
				) );
				if ( ! $gh->exists() ) {
					$stats['errors'][] = 'Kontakt ' . esc_html( $email ) . ': konnte nicht angelegt werden.';
					continue;
				}
				$stats['contacts_new']++;
			}

			// Kern-Felder aktualisieren (bei vorhandenem Kontakt)
			$gh->update( array(
				'first_name' => sanitize_text_field( $contact->firstname ?? '' ),
				'last_name'  => sanitize_text_field( $contact->lastname  ?? '' ),
			) );

			// Meta-Felder
			if ( ! empty( $contact->phonenumber ) ) {
				$gh->update_meta( 'primary_phone', sanitize_text_field( $contact->phonenumber ) );
			}

			// Rechnungsempfänger automatisch markieren
			if ( (int) ( $contact->invoice_emails ?? 0 ) === 1 ) {
				$gh->update_meta( 'invoice_recipient', '1' );
				$stats['invoice_recipients']++;
			}

			// Bridge
			$gh->update_meta( 'perfex_contact_id', (string) $perfex_id );

			// Tag
			if ( $tag_id ) {
				$gh->apply_tag( $tag_id );
			}

			// Firma verknüpfen
			$perfex_company_id = (int) ( $contact->userid ?? 0 );
			if ( $perfex_company_id ) {
				$company_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT company_id FROM `{$companymeta_table}` WHERE meta_key = 'perfex_company_id' AND meta_value = %s LIMIT 1",
					(string) $perfex_company_id
				) );

				if ( $company_id ) {
					$gh_contact_id = $gh->get_id();

					// Object Relationship sicherstellen
					$rel_exists = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM `{$rel_table}`
						 WHERE primary_object_id = %d AND primary_object_type = 'company'
						   AND secondary_object_id = %d AND secondary_object_type = 'contact'",
						$company_id,
						$gh_contact_id
					) );

					if ( ! $rel_exists ) {
						$wpdb->insert(
							$rel_table,
							array(
								'primary_object_id'     => $company_id,
								'primary_object_type'   => 'company',
								'secondary_object_id'   => $gh_contact_id,
								'secondary_object_type' => 'contact',
							),
							array( '%d', '%s', '%d', '%s' )
						);
					}

					// Firmendaten im Kontakt setzen
					$company = $wpdb->get_row( $wpdb->prepare(
						"SELECT * FROM `{$companies_table}` WHERE ID = %d LIMIT 1",
						$company_id
					) );
					if ( $company ) {
						$gh->update_meta( 'company_name', sanitize_text_field( $company->name ) );
						if ( ! empty( $company->domain ) ) {
							$gh->update_meta( 'company_website', esc_url_raw( $company->domain ) );
						}
					}

					// Primärkontakt setzen
					if ( (int) ( $contact->is_primary ?? 0 ) === 1 ) {
						$wpdb->update(
							$companies_table,
							array( 'primary_contact_id' => $gh_contact_id ),
							array( 'ID' => $company_id ),
							array( '%d' ),
							array( '%d' )
						);
					}
				}
			}
		}

		$stats['_batch_count'] = $count;
	}

	// -------------------------------------------------------------------------
	// Batch: Notizen
	// -------------------------------------------------------------------------

	private function batch_notes( \mysqli $mysqli, string $prefix, bool $include_inactive, int $offset, array &$stats, object $wpdb ): void {
		$where = $include_inactive ? '' : 'WHERE cl.active = 1 ';
		$limit = self::BATCH_SIZE_NOTES;

		// Notizen mit primärer E-Mail des Kunden abrufen
		$sql = "SELECT cn.*, c.email
		        FROM `{$prefix}_customernotes` cn
		        LEFT JOIN `{$prefix}_contacts` c ON c.userid = cn.userid AND c.is_primary = 1
		        LEFT JOIN `{$prefix}_clients` cl ON cl.userid = cn.userid
		        {$where}ORDER BY cn.id ASC LIMIT {$limit} OFFSET {$offset}";
		$res = $mysqli->query( $sql );

		$count = 0;
		if ( ! $res ) {
			$stats['errors'][] = 'Fehler beim Lesen der Notizliste: ' . $mysqli->error;
			$stats['_batch_count'] = 0;
			return;
		}

		$notes_table = $wpdb->prefix . 'gh_notes';

		while ( $note = $res->fetch_object() ) {
			$count++;
			$email = trim( $note->email ?? '' );

			if ( ! is_email( $email ) || ! function_exists( '\Groundhogg\get_contactdata' ) ) {
				$stats['skipped']++;
				continue;
			}

			$gh = \Groundhogg\get_contactdata( $email );
			if ( ! $gh || ! $gh->exists() ) {
				$stats['skipped']++;
				continue;
			}

			$note_summary = 'Perfex #' . (int) $note->id;

			// Duplikat-Check
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM `{$notes_table}` WHERE object_id = %d AND context = 'perfex' AND summary = %s LIMIT 1",
				$gh->get_id(),
				$note_summary
			) );

			if ( $exists ) {
				$stats['skipped']++;
				continue;
			}

			$note_date  = ! empty( $note->date ) ? $note->date : current_time( 'mysql' );
			$timestamp  = strtotime( $note_date );

			$wpdb->insert(
				$notes_table,
				array(
					'object_id'    => $gh->get_id(),
					'object_type'  => 'contact',
					'user_id'      => get_current_user_id(),
					'summary'      => $note_summary,
					'content'      => sanitize_textarea_field( $note->note ?? '' ),
					'context'      => 'perfex',
					'type'         => 'note',
					'timestamp'    => $timestamp ?: current_time( 'timestamp' ),
					'date_created' => $note_date,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);

			$stats['notes']++;
		}

		$stats['_batch_count'] = $count;
	}

	// -------------------------------------------------------------------------
	// Schritt: Abbruch
	// -------------------------------------------------------------------------

	public function handle_cancel(): void {
		$this->check_permission();
		$this->check_nonce();

		$import_id = sanitize_text_field( wp_unslash( $_POST['import_id'] ?? '' ) );
		if ( $import_id ) {
			set_transient( 'octo_pimport_cancelled_' . $import_id, true, HOUR_IN_SECONDS );
		}

		wp_send_json_success( array( 'message' => 'Import abgebrochen.' ) );
	}

	// -------------------------------------------------------------------------
	// Hilfsmethoden
	// -------------------------------------------------------------------------

	private function upsert_companymeta( object $wpdb, int $company_id, string $key, string $value ): void {
		$table = $wpdb->prefix . 'gh_companymeta';

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM `{$table}` WHERE company_id = %d AND meta_key = %s LIMIT 1",
			$company_id,
			$key
		) );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array( 'meta_value' => $value ),
				array( 'meta_id' => $existing ),
				array( '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'company_id' => $company_id,
					'meta_key'   => $key,
					'meta_value' => $value,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}
}
