<?php
/**
 * Plugin Name: Perković Forms
 * Description: Custom kontakt forme s drag&drop builderom, multi-step/multi-column prikazom, Smart Logic uvjetima, predlošcima, UTM praćenjem, pipeline upravljanjem upitima i GTM/GA4 integracijom.
 * Version: 1.7.1

 * Text Domain: perkovic-forms
 * Update URI: https://updates.perkovic-forms.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PF_VERSION', '1.7.1' );
define( 'PF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PF_PLUGIN_FILE', __FILE__ );
define( 'PF_PLUGIN_SLUG', plugin_basename( __FILE__ ) );

/* =========================================================
 *  AUTO-UPDATE SUSTAV
 *  Provjerava GitHub releases za nova ažuriranja.
 *  Postavi GitHub repozitorij u PF_GITHUB_REPO ispod.
 *
 *  Kako podesiti:
 *  1. Kreiraj GitHub repozitorij (može biti privatan)
 *  2. Zamijeni 'tvoj-username/perkovic-forms' s pravim repom
 *  3. Za svako ažuriranje: kreiraj novi Release na GitHubu
 *     s tagom koji odgovara verziji (npr. 1.5.5) i priloži ZIP
 *  4. WordPress će automatski pokazati "Update available"
 * ========================================================= */
define( 'PF_GITHUB_REPO', 'tvoj-username/perkovic-forms' ); // ← PROMIJENI OVO

class PF_Auto_Updater {

	private $slug;
	private $version;
	private $repo;
	private $token;
	private $cache_key;
	private $cache_ttl = 12 * HOUR_IN_SECONDS;

	public function __construct() {
		$this->slug      = PF_PLUGIN_SLUG;
		$this->version   = PF_VERSION;
		$this->repo      = get_option( 'pf_github_repo', PF_GITHUB_REPO );
		$this->token     = get_option( 'pf_github_token', '' );
		$this->cache_key = 'pf_update_info';

		add_filter( 'plugins_api',                   array( $this, 'plugin_info' ),    20, 3 );
		add_filter( 'site_transient_update_plugins',  array( $this, 'check_update' ) );
		add_action( 'upgrader_process_complete',      array( $this, 'purge_cache' ),   10, 2 );
		add_action( 'admin_notices',                  array( $this, 'update_notice' ) );
		// Dodaj Authorization header za download privatnog repozitorija
		add_filter( 'http_request_args',              array( $this, 'inject_auth_header' ), 10, 2 );
	}

	/**
	 * Dodaj Authorization header za download ZIP-a s privatnog repozitorija
	 */
	public function inject_auth_header( $args, $url ) {
		if ( $this->token && strpos( $url, 'github.com' ) !== false ) {
			$args['headers']['Authorization'] = 'token ' . $this->token;
		}
		return $args;
	}

	/**
	 * Public wrapper za get_remote_info (za ručnu provjeru)
	 */
	public function get_remote_info_public() {
		return $this->get_remote_info();
	}

	/**
	 * Dohvaća informacije o zadnjem releaseu s GitHuba
	 */
	private function get_remote_info() {
		$cached = get_transient( $this->cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$url     = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$headers = array(
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
		);
		if ( $this->token ) {
			$headers['Authorization'] = 'token ' . $this->token;
		}

		$response = wp_remote_get( $url, array(
			'headers'   => $headers,
			'timeout'   => 10,
			'sslverify' => true,
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['tag_name'] ) ) {
			set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
			return null;
		}

		// Izvuci verziju iz taga (ukloni 'v' prefix ako postoji)
		$remote_version = ltrim( $body['tag_name'], 'v' );

		// Pronađi ZIP asset — SAMO naš priloženi ZIP, ne GitHub automatski zipball
		$zip_url = '';
		if ( ! empty( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['content_type'] ) && $asset['content_type'] === 'application/zip' ) {
					$zip_url = $asset['browser_download_url'];
					break;
				}
				// Prihvati i octet-stream ako je .zip datoteka
				if ( isset( $asset['name'] ) && str_ends_with( $asset['name'], '.zip' ) ) {
					$zip_url = $asset['browser_download_url'];
					break;
				}
			}
		}

		// NEMA fallback na zipball_url — GitHub zipball koristi krivi naziv foldera
		// što uzrokuje deaktivaciju plugina nakon ažuriranja.
		// Ako nema priloženog ZIP-a, update nije dostupan.
		if ( empty( $zip_url ) ) {
			set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
			return null;
		}

		$info = array(
			'version'      => $remote_version,
			'zip_url'      => $zip_url,
			'changelog'    => isset( $body['body'] ) ? wp_kses_post( $body['body'] ) : '',
			'release_date' => isset( $body['published_at'] ) ? date( 'Y-m-d', strtotime( $body['published_at'] ) ) : '',
			'release_url'  => $body['html_url'] ?? '',
		);

		set_transient( $this->cache_key, $info, $this->cache_ttl );
		return $info;
	}

	/**
	 * Filter: dodaje info o pluginu u WP "View Details" modal
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->slug ) ) {
			return $result;
		}

		$info = $this->get_remote_info();
		if ( ! $info ) {
			return $result;
		}

		$plugin_data = get_plugin_data( PF_PLUGIN_FILE );

		$obj              = new stdClass();
		$obj->name        = $plugin_data['Name'];
		$obj->slug        = dirname( $this->slug );
		$obj->version     = $info['version'];
		$obj->author      = $plugin_data['Author'];
		$obj->homepage    = $info['release_url'];
		$obj->download_link = $info['zip_url'];
		$obj->last_updated  = $info['release_date'];
		$obj->sections    = array(
			'changelog' => nl2br( esc_html( $info['changelog'] ) ) ?: 'Pogledaj GitHub za detalje.',
		);

		return $obj;
	}

	/**
	 * Filter: inject update info u WP update transient
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$info = $this->get_remote_info();
		if ( ! $info || empty( $info['version'] ) ) {
			return $transient;
		}

		if ( version_compare( $this->version, $info['version'], '<' ) ) {
			$obj                  = new stdClass();
			$obj->slug            = dirname( $this->slug );
			$obj->plugin          = $this->slug;
			$obj->new_version     = $info['version'];
			$obj->url             = $info['release_url'];
			$obj->package         = $info['zip_url'];
			$obj->tested          = get_bloginfo( 'version' );
			$obj->requires_php    = '7.4';

			$transient->response[ $this->slug ] = $obj;
		}

		return $transient;
	}

	/**
	 * Briše cache nakon ažuriranja
	 */
	public function purge_cache( $upgrader, $options ) {
		if ( $options['action'] === 'update' && $options['type'] === 'plugin' ) {
			delete_transient( $this->cache_key );
		}
	}

	/**
	 * Admin notice — prikazuje dostupno ažuriranje s direktnim linkom
	 */
	public function update_notice() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'plugins' ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$info = $this->get_remote_info();
		if ( ! $info || ! version_compare( $this->version, $info['version'], '<' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong>Perković Forms <?php echo esc_html( $info['version'] ); ?></strong> je dostupan.
				Trenutna verzija: <?php echo esc_html( $this->version ); ?>.
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( $this->slug ) ), 'upgrade-plugin_' . $this->slug ) ); ?>">
					Ažuriraj sada
				</a>
				<?php if ( $info['release_url'] ) : ?>
					&bull; <a href="<?php echo esc_url( $info['release_url'] ); ?>" target="_blank" rel="noopener">Što je novo?</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}

// Inicijalizacija auto-updatera (samo u adminu)
if ( is_admin() ) {
	new PF_Auto_Updater();
}


/* =========================================================
 *  DB MIGRACIJA NA AKTIVACIJI I UPGRADU
 *  Osigurava da sve tablice i kolone postoje bez gubitka podataka
 * ========================================================= */
function pf_run_migrations() {
	$installed = get_option( 'pf_db_version', '0' );

	// Pokreni sve potrebne migracije samo ako je verzija baze starija
	if ( version_compare( $installed, PF_VERSION, '<' ) ) {
		pf_maybe_create_analytics_table(); // Kreira/ažurira tablice
		update_option( 'pf_db_version', PF_VERSION );
	}
}
add_action( 'plugins_loaded', 'pf_run_migrations', 5 );

/* =========================================================
 *  AKTIVACIJA - kreiranje tablica
 * ========================================================= */
function pf_activate_plugin() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$forms_table       = $wpdb->prefix . 'pf_forms';
	$submissions_table = $wpdb->prefix . 'pf_submissions';
	$analytics_table   = $wpdb->prefix . 'pf_analytics';

	$sql_forms = "CREATE TABLE $forms_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		title VARCHAR(255) NOT NULL DEFAULT '',
		fields_json LONGTEXT NOT NULL,
		settings_json LONGTEXT NOT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id)
	) $charset_collate;";

	$sql_submissions = "CREATE TABLE $submissions_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		form_id BIGINT UNSIGNED NOT NULL,
		data_json LONGTEXT NOT NULL,
		ip_address VARCHAR(100) DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'new',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY form_id (form_id),
		KEY status (status),
		KEY created_at (created_at)
	) $charset_collate;";

	$sql_analytics = "CREATE TABLE $analytics_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		form_id BIGINT UNSIGNED NOT NULL,
		event_type VARCHAR(50) NOT NULL,
		step INT UNSIGNED DEFAULT NULL,
		total_steps INT UNSIGNED DEFAULT NULL,
		fill_percent TINYINT UNSIGNED DEFAULT NULL,
		session_id VARCHAR(64) DEFAULT '',
		ab_variant VARCHAR(1) DEFAULT NULL,
		utm_source VARCHAR(100) DEFAULT '',
		utm_medium VARCHAR(100) DEFAULT '',
		utm_campaign VARCHAR(100) DEFAULT '',
		utm_term VARCHAR(100) DEFAULT '',
		utm_content VARCHAR(100) DEFAULT '',
		landing_page VARCHAR(500) DEFAULT '',
		page_url VARCHAR(500) DEFAULT '',
		referrer VARCHAR(500) DEFAULT '',
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY form_id (form_id),
		KEY event_type (event_type),
		KEY created_at (created_at),
		KEY utm_source (utm_source)
	) $charset_collate;";

	$ab_table = $wpdb->prefix . 'pf_ab_tests';

	$sql_ab = "CREATE TABLE $ab_table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(255) NOT NULL DEFAULT '',
		form_a BIGINT UNSIGNED NOT NULL,
		form_b BIGINT UNSIGNED NOT NULL,
		traffic_split TINYINT UNSIGNED NOT NULL DEFAULT 50,
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		winner VARCHAR(1) DEFAULT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY status (status)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_forms );
	dbDelta( $sql_submissions );
	dbDelta( $sql_analytics );
	dbDelta( $sql_ab );
}
register_activation_hook( __FILE__, 'pf_activate_plugin' );

/* =========================================================
 *  DB UPGRADE (za postojeće instalacije bez analytics tablice)
 * ========================================================= */
function pf_maybe_create_analytics_table() {
	global $wpdb;
	$analytics_table = $wpdb->prefix . 'pf_analytics';

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$analytics_table'" ) !== $analytics_table ) {
		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $analytics_table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(50) NOT NULL,
			step INT UNSIGNED DEFAULT NULL,
			total_steps INT UNSIGNED DEFAULT NULL,
			fill_percent TINYINT UNSIGNED DEFAULT NULL,
			session_id VARCHAR(64) DEFAULT '',
			ab_variant VARCHAR(1) DEFAULT NULL,
			utm_source VARCHAR(100) DEFAULT '',
			utm_medium VARCHAR(100) DEFAULT '',
			utm_campaign VARCHAR(100) DEFAULT '',
			utm_term VARCHAR(100) DEFAULT '',
			utm_content VARCHAR(100) DEFAULT '',
			landing_page VARCHAR(500) DEFAULT '',
			page_url VARCHAR(500) DEFAULT '',
			referrer VARCHAR(500) DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY form_id (form_id),
			KEY event_type (event_type),
			KEY created_at (created_at),
			KEY utm_source (utm_source)
		) $charset_collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	} else {
		// Dodaj nove kolone ako tablica već postoji (upgrade s Faze 2)
		$existing_cols = $wpdb->get_col( "DESCRIBE $analytics_table", 0 );
		$to_add = array(
			'utm_term'    => "ALTER TABLE `$analytics_table` ADD COLUMN `utm_term` VARCHAR(100) DEFAULT '' AFTER `utm_campaign`",
			'utm_content' => "ALTER TABLE `$analytics_table` ADD COLUMN `utm_content` VARCHAR(100) DEFAULT '' AFTER `utm_term`",
			'landing_page'=> "ALTER TABLE `$analytics_table` ADD COLUMN `landing_page` VARCHAR(500) DEFAULT '' AFTER `utm_content`",
			'ab_variant'  => "ALTER TABLE `$analytics_table` ADD COLUMN `ab_variant` VARCHAR(1) DEFAULT NULL AFTER `session_id`",
		);
		foreach ( $to_add as $col => $sql ) {
			if ( ! in_array( $col, $existing_cols, true ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	// Kreiraj A/B test tablicu ako ne postoji
	$ab_table = $wpdb->prefix . 'pf_ab_tests';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$ab_table'" ) !== $ab_table ) {
		$charset_collate = $wpdb->get_charset_collate();
		$sql_ab = "CREATE TABLE $ab_table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			form_a BIGINT UNSIGNED NOT NULL,
			form_b BIGINT UNSIGNED NOT NULL,
			traffic_split TINYINT UNSIGNED NOT NULL DEFAULT 50,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			winner VARCHAR(1) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status)
		) $charset_collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_ab );
	}

	// Dodaj indekse na pf_submissions ako nedostaju (upgrade za starije instalacije)
	$sub_table = $wpdb->prefix . 'pf_submissions';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$sub_table'" ) === $sub_table ) {
		$existing_keys = $wpdb->get_col( "SHOW INDEX FROM `$sub_table`", 2 );
		if ( ! in_array( 'status', $existing_keys, true ) ) {
			$wpdb->query( "ALTER TABLE `$sub_table` ADD INDEX `status` (`status`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! in_array( 'created_at', $existing_keys, true ) ) {
			$wpdb->query( "ALTER TABLE `$sub_table` ADD INDEX `created_at` (`created_at`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
add_action( 'admin_init', 'pf_maybe_create_analytics_table' );


/* =========================================================
 *  ADMIN MENI
 * ========================================================= */
function pf_admin_menu() {
	add_menu_page(
		'Perković Forms',
		'Perković Forms',
		'manage_options',
		'pf-forms',
		'pf_render_forms_list_page',
		'dashicons-feedback',
		58
	);

	add_submenu_page( 'pf-forms', 'Sve forme',  'Sve forme',  'manage_options', 'pf-forms',       'pf_render_forms_list_page' );
	add_submenu_page( 'pf-forms', 'Nova forma',  'Dodaj formu','manage_options', 'pf-form-edit',   'pf_render_form_edit_page' );
	add_submenu_page( 'pf-forms', 'Upiti',       'Upiti',      'manage_options', 'pf-submissions', 'pf_render_submissions_page' );
	add_submenu_page( 'pf-forms', 'A/B Testovi', 'A/B Testovi','manage_options', 'pf-ab-tests',    'pf_render_ab_tests_page' );
	add_submenu_page( 'pf-forms', 'Analitika',   'Analitika',  'manage_options', 'pf-analytics',   'pf_render_analytics_page' );
	add_submenu_page( 'pf-forms', 'Postavke',    'Postavke',   'manage_options', 'pf-settings',    'pf_render_settings_page' );
}
add_action( 'admin_menu', 'pf_admin_menu' );


/* =========================================================
 *  ADMIN ASSETS
 * ========================================================= */
function pf_admin_assets( $hook ) {
	if ( strpos( $hook, 'pf-' ) === false ) {
		return;
	}

	wp_enqueue_style( 'pf-admin-css', PF_PLUGIN_URL . 'assets/css/admin.css', array(), PF_VERSION );

	if ( strpos( $hook, 'pf-form-edit' ) !== false ) {
		wp_enqueue_style( 'pf-frontend-css', PF_PLUGIN_URL . 'assets/css/frontend.css', array(), PF_VERSION );
		wp_enqueue_script( 'sortablejs', 'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js', array(), '1.15.2', true );
		wp_enqueue_script( 'pf-admin-js', PF_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'sortablejs' ), PF_VERSION, true );
		wp_localize_script( 'pf-admin-js', 'pfAdminCfg', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'aiNonce'  => wp_create_nonce( 'pf_ai_chat' ),
			'aiEnabled'=> (bool) get_option( 'pf_openai_api_key', '' ),
			'presets'  => pf_theme_presets(),
		) );
	}

	if ( strpos( $hook, 'pf-analytics' ) !== false ) {
		wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', array(), '4.4.1', true );
		wp_enqueue_script( 'pf-analytics-js', PF_PLUGIN_URL . 'assets/js/analytics.js', array( 'chartjs' ), PF_VERSION, true );
		wp_localize_script( 'pf-analytics-js', 'pfAnalytics', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pf_analytics' ),
		) );
	}

	if ( strpos( $hook, 'pf-ab-tests' ) !== false ) {
		wp_enqueue_script( 'pf-ab-tests-js', PF_PLUGIN_URL . 'assets/js/ab-tests.js', array(), PF_VERSION, true );
	}
}
add_action( 'admin_enqueue_scripts', 'pf_admin_assets' );


/* =========================================================
 *  HELPER: sigurno spremanje uploadane datoteke
 * ========================================================= */
function pf_handle_file_upload( $file ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$max_size = 10 * MB_IN_BYTES;
	if ( $file['size'] > $max_size ) {
		return new WP_Error( 'pf_file_too_large', 'Datoteka je preveć velika (maks. 10 MB).' );
	}

	$allowed_types = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
		'doc'          => 'application/msword',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	);

	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_types );
	if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
		return new WP_Error( 'pf_file_type', 'Nedozvoljen tip datoteke. Dozvoljeno: slike, PDF, Word.' );
	}

	$overrides = array(
		'test_form' => false,
		'mimes'     => $allowed_types,
	);

	$moved = wp_handle_upload( $file, $overrides );

	if ( isset( $moved['error'] ) ) {
		return new WP_Error( 'pf_upload_error', $moved['error'] );
	}

	return $moved['url'];
}


/* =========================================================
 *  HELPER: evaluacija conditional logike (server-side)
 * ========================================================= */
function pf_condition_met( $condition, $raw_values ) {
	if ( empty( $condition['field'] ) ) {
		return true;
	}

	$target = isset( $raw_values[ $condition['field'] ] ) ? $raw_values[ $condition['field'] ] : '';
	$op     = isset( $condition['operator'] ) ? $condition['operator'] : 'equals';
	$value  = isset( $condition['value'] ) ? $condition['value'] : '';

	if ( is_array( $target ) ) {
		switch ( $op ) {
			case 'not_equals':
				return ! in_array( $value, $target, true );
			case 'contains':
				foreach ( $target as $t ) {
					if ( stripos( $t, $value ) !== false ) {
						return true;
					}
				}
				return false;
			default: // equals
				return in_array( $value, $target, true );
		}
	}

	switch ( $op ) {
		case 'not_equals':
			return $target !== $value;
		case 'contains':
			return stripos( $target, $value ) !== false;
		default: // equals
			return $target === $value;
	}
}


/* =========================================================
 *  HELPER: struktura forme - steps > rows > cols > fields
 * ========================================================= */

/**
 * Default struktura za novu formu
 */
function pf_default_form_structure() {
	return array(
		'steps' => array(
			array(
				'rows' => array(
					array(
						'cols'  => 1,
						'cells' => array(
							array(
								array(
									'type'        => 'text',
									'label'       => 'Ime i prezime',
									'name'        => 'ime_prezime',
									'required'    => true,
									'options'     => array(),
									'placeholder' => 'Ivica Ivić',
									'condition'   => null,
								),
								array(
									'type'        => 'tel',
									'label'       => 'Kontakt broj',
									'name'        => 'kontakt_broj',
									'required'    => true,
									'options'     => array(),
									'placeholder' => '099 123 4567',
									'condition'   => null,
								),
							),
						),
					),
				),
			),
		),
	);
}

/**
 * Pretvara dekodirani fields_json u standardnu strukturu {steps:[...]}.
 * Podržava i stari "flat" format (niz polja s 'step') iz Faze 1-2.
 */
function pf_normalize_form_structure( $decoded ) {
	if ( is_array( $decoded ) && isset( $decoded['steps'] ) && is_array( $decoded['steps'] ) ) {
		return $decoded;
	}

	// Stari format: flat niz polja, svako ima 'step'
	$fields = is_array( $decoded ) ? $decoded : array();
	$by_step = array();

	foreach ( $fields as $f ) {
		$step = isset( $f['step'] ) ? max( 1, intval( $f['step'] ) ) : 1;
		unset( $f['step'] );
		$by_step[ $step ][] = $f;
	}

	if ( empty( $by_step ) ) {
		$by_step[1] = array();
	}

	ksort( $by_step );

	$steps = array();
	foreach ( $by_step as $step_fields ) {
		$steps[] = array(
			'rows' => array(
				array(
					'cols'  => 1,
					'cells' => array( $step_fields ),
				),
			),
		);
	}

	return array( 'steps' => $steps );
}

/**
 * Vraća flat niz svih polja u formi (bez step/row/col konteksta) -
 * koristi se za validaciju, email, CSV export.
 */
function pf_flatten_fields( $structure ) {
	$out = array();
	foreach ( (array) $structure['steps'] as $step ) {
		foreach ( (array) $step['rows'] as $row ) {
			foreach ( (array) $row['cells'] as $cell ) {
				foreach ( (array) $cell as $field ) {
					$out[] = $field;
				}
			}
		}
	}
	return $out;
}

/**
 * Sanitizacija jednog polja (zajednička za sve cells/cols)
 */
function pf_sanitize_field( $f ) {
	$condition = null;
	if ( isset( $f['condition'] ) && is_array( $f['condition'] ) && ! empty( $f['condition']['field'] ) ) {
		$op = isset( $f['condition']['operator'] ) ? sanitize_key( $f['condition']['operator'] ) : 'equals';
		if ( ! in_array( $op, array( 'equals', 'not_equals', 'contains' ), true ) ) {
			$op = 'equals';
		}
		$condition = array(
			'field'    => sanitize_key( $f['condition']['field'] ),
			'operator' => $op,
			'value'    => isset( $f['condition']['value'] ) ? sanitize_text_field( $f['condition']['value'] ) : '',
		);
	}

	$utm_source = isset( $f['utm_source'] ) ? sanitize_key( $f['utm_source'] ) : '';
	// allowed: utm_source, utm_medium, utm_campaign, utm_term, utm_content, or empty
	$allowed_utm = array( '', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
	if ( ! in_array( $utm_source, $allowed_utm, true ) ) {
		$utm_source = '';
	}

	return array(
		'type'          => isset( $f['type'] ) ? sanitize_key( $f['type'] ) : 'text',
		'label'         => isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : '',
		'name'          => isset( $f['name'] ) ? sanitize_key( $f['name'] ) : '',
		'required'      => ! empty( $f['required'] ),
		'placeholder'   => isset( $f['placeholder'] ) ? sanitize_text_field( $f['placeholder'] ) : '',
		'default_value' => isset( $f['default_value'] ) ? sanitize_text_field( $f['default_value'] ) : '',
		'hidden'        => ! empty( $f['hidden'] ),
		'utm_source'    => $utm_source,
		'multiple'      => ! empty( $f['multiple'] ),
		'options'       => isset( $f['options'] ) && is_array( $f['options'] ) ? array_map( 'sanitize_text_field', $f['options'] ) : array(),
		'condition'     => $condition,
	);
}

/**
 * Sanitizacija cijele strukture {steps:[...]} prije spremanja u bazu
 */
function pf_sanitize_form_structure( $structure ) {
	$clean_steps = array();

	foreach ( (array) $structure['steps'] as $step ) {
		$clean_rows = array();

		foreach ( (array) $step['rows'] as $row ) {
			$cols = isset( $row['cols'] ) ? intval( $row['cols'] ) : 1;
			$cols = max( 1, min( 3, $cols ) );

			$clean_cells = array();
			$cells       = isset( $row['cells'] ) && is_array( $row['cells'] ) ? $row['cells'] : array();

			for ( $c = 0; $c < $cols; $c++ ) {
				$cell_fields = isset( $cells[ $c ] ) && is_array( $cells[ $c ] ) ? $cells[ $c ] : array();
				$clean_cell  = array();
				foreach ( $cell_fields as $f ) {
					$clean_cell[] = pf_sanitize_field( $f );
				}
				$clean_cells[] = $clean_cell;
			}

			$clean_rows[] = array(
				'cols'  => $cols,
				'cells' => $clean_cells,
			);
		}

		if ( empty( $clean_rows ) ) {
			$clean_rows[] = array( 'cols' => 1, 'cells' => array( array() ) );
		}

		$clean_steps[] = array( 'rows' => $clean_rows );
	}

	if ( empty( $clean_steps ) ) {
		return pf_default_form_structure();
	}

	return array( 'steps' => $clean_steps );
}


/* =========================================================
 *  STRANICA: Sve forme
 * ========================================================= */
function pf_render_forms_list_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu stranicu.' );
	}
	global $wpdb;
	$table = $wpdb->prefix . 'pf_forms';

	// Brisanje forme
	if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'delete' ) {
		$id = absint( $_GET['id'] );
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'pf_delete_form_' . $id ) ) {
			$wpdb->delete( $table, array( 'id' => $id ) );
			echo '<div class="updated notice"><p>Forma obrisana.</p></div>';
		}
	}

	// Dupliciranje forme (npr. za A/B varijante)
	if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'duplicate' ) {
		$id = absint( $_GET['id'] );
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'pf_duplicate_form_' . $id ) ) {
			$src = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
			if ( $src ) {
				$wpdb->insert(
					$table,
					array(
						'title'         => $src->title . ' (kopija)',
						'fields_json'   => $src->fields_json,
						'settings_json' => $src->settings_json,
						'created_at'    => current_time( 'mysql' ),
						'updated_at'    => current_time( 'mysql' ),
					)
				);
				echo '<div class="updated notice"><p>Forma dupliciran - uredi novu kopiju ispod.</p></div>';
			}
		}
	}

	$forms = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC" );

	// Dohvati aktivne A/B testove za badge prikaz
	$ab_table    = $wpdb->prefix . 'pf_ab_tests';
	$ab_tests    = $wpdb->get_results( "SELECT * FROM $ab_table WHERE status='active'" );
	$forms_in_ab = array(); // form_id => array('test_name', 'variant')
	foreach ( $ab_tests as $t ) {
		$forms_in_ab[ (int) $t->form_a ][] = array( 'name' => $t->name, 'variant' => 'A', 'id' => $t->id );
		$forms_in_ab[ (int) $t->form_b ][] = array( 'name' => $t->name, 'variant' => 'B', 'id' => $t->id );
	}
	?>
	<div class="wrap pf-wrap">
		<h1 class="wp-heading-inline">Perković Forms</h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=pf-form-edit' ) ); ?>" class="page-title-action">Dodaj novu formu</a>
		<hr class="wp-header-end">

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Naziv</th>
					<th>Shortcode</th>
					<th>Ažurirano</th>
					<th style="width:160px;">Akcije</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $forms ) ) : ?>
				<tr><td colspan="4">Nema još nijedne forme. Klikni "Dodaj novu formu" da kreneš.</td></tr>
			<?php else : ?>
				<?php foreach ( $forms as $form ) : ?>
					<tr>
						<td>
						<strong><?php echo esc_html( $form->title ); ?></strong>
						<?php if ( isset( $forms_in_ab[ (int) $form->id ] ) ) : ?>
							<?php foreach ( $forms_in_ab[ (int) $form->id ] as $ab ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=pf-ab-tests' ) ); ?>"
								   class="pf-ab-badge" title="<?php echo esc_attr( $ab['name'] ); ?>">
									A/B <?php echo esc_html( $ab['variant'] ); ?>
								</a>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
						<td><code>[pf_form id="<?php echo esc_attr( $form->id ); ?>"]</code></td>
						<td><?php echo esc_html( $form->updated_at ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=pf-form-edit&id=' . $form->id ) ); ?>">Uredi</a>
							|
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=pf-submissions&form_id=' . $form->id ) ); ?>">Upiti</a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pf-forms&action=duplicate&id=' . $form->id ), 'pf_duplicate_form_' . $form->id ) ); ?>"
							   title="Korisno za A/B varijante - dupliciraj pa promijeni naslov/polja">Dupliciraj</a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pf-forms&action=delete&id=' . $form->id ), 'pf_delete_form_' . $form->id ) ); ?>"
							   onclick="return confirm('Sigurno obrisati ovu formu? Ovo ne briše postojeće upite.');"
							   style="color:#b32d2e;">Obriši</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}


/* =========================================================
 *  HELPER FUNKCIJE ZA BUILDER (moraju biti prije edit page)
 * ========================================================= */

function pf_field_groups() {
	return array(
		'Osnovna polja' => array( 'text', 'email', 'tel', 'number', 'textarea' ),
		'Izbori'        => array( 'select', 'radio', 'checkbox', 'image_choice' ),
		'Ostalo'        => array( 'file', 'section_divider', 'hidden', 'html' ),
	);
}

function pf_sanitize_theme( $raw ) {
	if ( ! is_array( $raw ) ) return array();
	$allowed_fonts = array( 'inherit', "'Inter', sans-serif", "'Roboto', sans-serif", "'Lato', sans-serif", "'Playfair Display', serif", "'Montserrat', sans-serif", "'Open Sans', sans-serif" );
	$allowed_btn   = array( 'filled', 'outline', 'ghost' );
	$out = array();
	foreach ( array( 'primary_color', 'bg_color', 'text_color', 'label_color', 'border_color', 'button_text', 'input_bg' ) as $k ) {
		if ( isset( $raw[ $k ] ) && preg_match( '/^#[0-9a-fA-F]{3,6}$/', $raw[ $k ] ) ) {
			$out[ $k ] = sanitize_hex_color( $raw[ $k ] );
		}
	}
	if ( isset( $raw['border_radius'] ) ) {
		$out['border_radius'] = (string) min( 32, max( 0, absint( $raw['border_radius'] ) ) );
	}
	if ( isset( $raw['font_family'] ) && in_array( $raw['font_family'], $allowed_fonts, true ) ) {
		$out['font_family'] = $raw['font_family'];
	}
	if ( isset( $raw['button_style'] ) && in_array( $raw['button_style'], $allowed_btn, true ) ) {
		$out['button_style'] = $raw['button_style'];
	}
	if ( isset( $raw['label_style'] ) && in_array( $raw['label_style'], array( 'normal', 'uppercase', 'light' ), true ) ) {
		$out['label_style'] = $raw['label_style'];
	}
	if ( isset( $raw['font_size'] ) && in_array( $raw['font_size'], array( 'small', 'medium', 'large' ), true ) ) {
		$out['font_size'] = $raw['font_size'];
	}
	if ( isset( $raw['input_height'] ) && in_array( $raw['input_height'], array( 'compact', 'normal', 'spacious' ), true ) ) {
		$out['input_height'] = $raw['input_height'];
	}
	return $out;
}

function pf_theme_css( $form_id, $theme ) {
	$defaults = array(
		'primary_color' => '#B5654A', 'bg_color'    => '#FFFFFF',
		'text_color'    => '#2B2420', 'label_color'  => '#2B2420',
		'border_color'  => '#DDD4C8', 'border_radius'=> '8',
		'font_family'   => 'inherit', 'button_style' => 'filled',
		'button_text'   => '#FFFFFF', 'input_bg'     => '#FBF8F4',
	);
	$t = array_merge( $defaults, is_array( $theme ) ? $theme : array() );
	$radius     = $t['border_radius'] . 'px';
	$btn_bg     = $t['button_style'] === 'filled'  ? $t['primary_color'] : 'transparent';
	$btn_border = $t['button_style'] === 'ghost'   ? 'transparent'       : $t['primary_color'];
	$btn_text   = $t['button_style'] === 'filled'  ? $t['button_text']   : $t['primary_color'];

	$google_font = '';
	if ( $t['font_family'] !== 'inherit' ) {
		preg_match( "/'([^']+)'/", $t['font_family'], $m );
		if ( ! empty( $m[1] ) ) {
			$google_font = '@import url("https://fonts.googleapis.com/css2?family=' . rawurlencode( $m[1] ) . ':wght@400;600&display=swap");' . "\n";
		}
	}

	// Typography opcije
	$label_style  = isset( $t['label_style'] )  ? $t['label_style']  : 'normal';
	$font_size    = isset( $t['font_size'] )    ? $t['font_size']    : 'medium';
	$input_height = isset( $t['input_height'] ) ? $t['input_height'] : 'normal';

	// Label CSS prema stilu
	switch ( $label_style ) {
		case 'uppercase':
			$label_css = "font-weight:700 !important;font-size:10px !important;text-transform:uppercase !important;letter-spacing:0.08em !important;";
			break;
		case 'light':
			$label_css = "font-weight:400 !important;font-size:13px !important;text-transform:none !important;letter-spacing:0 !important;";
			break;
		default: // normal
			$label_css = "font-weight:600 !important;font-size:14px !important;text-transform:none !important;letter-spacing:0 !important;";
			break;
	}

	// Input font-size
	switch ( $font_size ) {
		case 'small':  $input_font_size = '13px'; break;
		case 'large':  $input_font_size = '17px'; break;
		default:       $input_font_size = '15px'; break;
	}

	// Input padding (visina)
	switch ( $input_height ) {
		case 'compact':  $input_padding = '9px 12px';  break;
		case 'spacious': $input_padding = '16px 16px'; break;
		default:         $input_padding = '12px 14px'; break;
	}
	$both_sel  = $wrap_sel . ', ' . $id_sel;

	// Scope na ID forme + wrapper (specifičan selector koji bije Elementor)
	return "<style id=\"pf-theme-{$form_id}\">\n"
		. "@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap');\n"
		. $google_font
		// Wrapper kartica
		. "{$wrap_sel} {\n"
		. "\tbackground: {$t['bg_color']};\n"
		. "\tborder-radius: {$radius};\n"
		. "\tpadding: 32px;\n"
		. "\tmax-width: 720px;\n"
		. "\tmargin: 0 auto;\n"
		. "\tbox-shadow: 0 4px 24px rgba(43,36,32,0.06);\n"
		. "}\n"
		// STRUKTURNI STILOVI (s !important jer Elementor agresivno resetira)
		. "{$id_sel} { box-sizing: border-box; }\n"
		. "{$id_sel} * { box-sizing: border-box; }\n"
		// Step indikator
		. "{$id_sel} .pf-steps-indicator {\n"
		. "\tdisplay: flex !important;\n"
		. "\talign-items: center !important;\n"
		. "\tmargin-bottom: 28px !important;\n"
		. "\tpadding: 0 !important;\n"
		. "\tbackground: none !important;\n"
		. "\tlist-style: none !important;\n"
		. "}\n"
		. "{$id_sel} .pf-step-dot {\n"
		. "\twidth: 36px !important;\n"
		. "\theight: 36px !important;\n"
		. "\tmin-width: 36px !important;\n"
		. "\tborder-radius: 50% !important;\n"
		. "\tbackground: #EFEAE2 !important;\n"
		. "\tcolor: #9C9182 !important;\n"
		. "\tdisplay: flex !important;\n"
		. "\talign-items: center !important;\n"
		. "\tjustify-content: center !important;\n"
		. "\tfont-weight: 700 !important;\n"
		. "\tfont-size: 14px !important;\n"
		. "\tflex: 0 0 auto !important;\n"
		. "\tline-height: 1 !important;\n"
		. "\tpadding: 0 !important;\n"
		. "\tmargin: 0 !important;\n"
		. "}\n"
		. "{$id_sel} .pf-step-dot.is-active,\n"
		. "{$id_sel} .pf-step-dot.is-complete {\n"
		. "\tbackground: {$t['primary_color']} !important;\n"
		. "\tcolor: #fff !important;\n"
		. "}\n"
		. "{$id_sel} .pf-step-line {\n"
		. "\tflex: 1 1 auto !important;\n"
		. "\theight: 2px !important;\n"
		. "\tbackground: #EFEAE2 !important;\n"
		. "\tmargin: 0 8px !important;\n"
		. "\tborder: none !important;\n"
		. "}\n"
		. "{$id_sel} .pf-step-line.is-complete { background: {$t['primary_color']} !important; }\n"
		// Step paneli - prikaz/skrivanje
		. "{$id_sel} .pf-step-panel { display: none !important; }\n"
		. "{$id_sel} .pf-step-panel.is-active { display: block !important; }\n"
		// Redovi grid
		. "{$id_sel} .pf-row {\n"
		. "\tdisplay: grid !important;\n"
		. "\tgap: 0 20px !important;\n"
		. "\tgrid-template-columns: 1fr !important;\n"
		. "}\n"
		. "{$id_sel} .pf-row.pf-cols-2 { grid-template-columns: repeat(2, 1fr) !important; }\n"
		. "{$id_sel} .pf-row.pf-cols-3 { grid-template-columns: repeat(3, 1fr) !important; }\n"
		. "@media (max-width: 640px) {\n"
		. "\t{$id_sel} .pf-row.pf-cols-2, {$id_sel} .pf-row.pf-cols-3 { grid-template-columns: 1fr !important; }\n"
		. "\t{$wrap_sel} { padding: 20px !important; }\n"
		. "}\n"
		. "{$id_sel} .pf-field { margin-bottom: 20px !important; padding: 0 !important; border: none !important; }\n"
		. "{$id_sel} .pf-step-actions {\n"
		. "\tdisplay: flex !important;\n"
		. "\tjustify-content: flex-end !important;\n"
		. "\tgap: 12px !important;\n"
		. "\tmargin-top: 28px !important;\n"
		. "}\n"
		// Inline opcije (checkbox/radio)
		. "{$id_sel} .pf-inline-option {\n"
		. "\tdisplay: flex !important;\n"
		. "\talign-items: center !important;\n"
		. "\tgap: 8px !important;\n"
		. "\tmargin-bottom: 10px !important;\n"
		. "\tfont-weight: 400 !important;\n"
		. "}\n"
		. "{$id_sel} .pf-inline-option input { width: 18px !important; height: 18px !important; margin: 0 !important; flex: 0 0 auto !important; }\n"
		. "{$id_sel} .pf-hp { display: none !important; }\n"
		// CSS varijable na formi
		. "{$id_sel} {\n"
		. "\t--pf-primary: {$t['primary_color']};\n"
		. "\t--pf-bg: {$t['bg_color']};\n"
		. "\t--pf-text: {$t['text_color']};\n"
		. "\t--pf-label: {$t['label_color']};\n"
		. "\t--pf-border: {$t['border_color']};\n"
		. "\t--pf-radius: {$radius};\n"
		. "\t--pf-font: {$t['font_family']};\n"
		. "\t--pf-input-bg: {$t['input_bg']};\n"
		. "\t--pf-btn-bg: {$btn_bg};\n"
		. "\t--pf-btn-border: {$btn_border};\n"
		. "\t--pf-btn-text: {$btn_text};\n"
		. "\tcolor: {$t['text_color']};\n"
		. "\tfont-family: {$t['font_family']};\n"
		. "}\n"
		// Direktni input overrides - CSS varijable ne rade uvijek u Elementoru
		. "{$id_sel} .pf-field input[type='text'],\n"
		. "{$id_sel} .pf-field input[type='email'],\n"
		. "{$id_sel} .pf-field input[type='tel'],\n"
		. "{$id_sel} .pf-field input[type='number'],\n"
		. "{$id_sel} .pf-field select,\n"
		. "{$id_sel} .pf-field textarea {\n"
		. "\tborder: 1.5px solid {$t['border_color']} !important;\n"
		. "\tborder-radius: {$radius} !important;\n"
		. "\tbackground: {$t['input_bg']} !important;\n"
		. "\tcolor: {$t['text_color']} !important;\n"
		. "\tpadding: {$input_padding} !important;\n"
		. "\tfont-size: {$input_font_size} !important;\n"
		. "\tfont-weight: 500 !important;\n"
		. "\twidth: 100% !important;\n"
		. "\tbox-sizing: border-box !important;\n"
		. "\t-webkit-appearance: none !important;\n"
		. "\tappearance: none !important;\n"
		. "}\n"
		. "{$id_sel} .pf-field select {\n"
		. "\tpadding-right: 38px !important;\n"
		. "\tbackground-image: url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%239C9182' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E\") !important;\n"
		. "\tbackground-repeat: no-repeat !important;\n"
		. "\tbackground-position: right 14px center !important;\n"
		. "}\n"
		. "{$id_sel} .pf-field input:focus,\n"
		. "{$id_sel} .pf-field select:focus,\n"
		. "{$id_sel} .pf-field textarea:focus {\n"
		. "\tborder-color: {$t['primary_color']} !important;\n"
		. "\tbox-shadow: 0 0 0 3px rgba(181, 101, 74, 0.12) !important;\n"
		. "\toutline: none !important;\n"
		. "}\n"
		. "{$id_sel} .pf-field label,\n"
		. "{$id_sel} .pf-field legend {\n"
		. "\tcolor: {$t['label_color']} !important;\n"
		. "\t{$label_css}\n"
		. "\tdisplay: block !important;\n"
		. "\tmargin-bottom: 6px !important;\n"
		. "}\n"
		. "{$id_sel} .pf-btn-primary {\n"
		. "\tbackground: {$btn_bg} !important;\n"
		. "\tcolor: {$btn_text} !important;\n"
		. "\tborder: 2px solid {$btn_border} !important;\n"
		. "\tborder-radius: {$radius} !important;\n"
		. "\tpadding: 11px 24px !important;\n"
		. "\tfont-family: 'Montserrat', sans-serif !important;\n"
		. "\tfont-size: 13px !important;\n"
		. "\tfont-weight: 700 !important;\n"
		. "\tletter-spacing: 0.04em !important;\n"
		. "\ttext-transform: uppercase !important;\n"
		. "\tcursor: pointer !important;\n"
		. "}\n"
		. "{$id_sel} .pf-btn-secondary {\n"
		. "\tborder-radius: {$radius} !important;\n"
		. "\tborder: 2px solid {$t['border_color']} !important;\n"
		. "\tbackground: transparent !important;\n"
		. "\tcolor: {$t['text_color']} !important;\n"
		. "\tpadding: 11px 24px !important;\n"
		. "\tfont-family: 'Montserrat', sans-serif !important;\n"
		. "\tfont-size: 13px !important;\n"
		. "\tfont-weight: 700 !important;\n"
		. "\tletter-spacing: 0.04em !important;\n"
		. "\ttext-transform: uppercase !important;\n"
		. "\tcursor: pointer !important;\n"
		. "}\n"
		. "{$id_sel} .pf-step-dot.is-active,\n"
		. "{$id_sel} .pf-step-dot.is-complete {\n"
		. "\tbackground: {$t['primary_color']} !important;\n"
		. "\tcolor: #fff !important;\n"
		. "}\n"
		. "{$id_sel} .pf-step-line.is-complete { background: {$t['primary_color']} !important; }\n"
		. "{$id_sel} .pf-required-mark { color: {$t['primary_color']} !important; }\n"
		. "{$id_sel} .pf-divider-line { border-top-color: {$t['border_color']} !important; }\n"
		. "{$id_sel} .pf-image-choice-card {\n"
		. "\tborder-color: {$t['border_color']} !important;\n"
		. "\tborder-radius: {$radius} !important;\n"
		. "\tbackground: {$t['input_bg']} !important;\n"
		. "}\n"
		. "{$id_sel} .pf-image-choice-item input:checked + .pf-image-choice-card {\n"
		. "\tborder-color: {$t['primary_color']} !important;\n"
		. "}\n"
		. "</style>\n";
}


/* =========================================================
 *  STRANICA: Dodaj / Uredi formu
 * ========================================================= */
function pf_render_form_edit_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu stranicu.' );
	}
	global $wpdb;
	$table = $wpdb->prefix . 'pf_forms';

	$form_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	$form    = null;

	if ( $form_id ) {
		$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $form_id ) );
	}

	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Forma je uspješno spremljena.</p></div>';
	}

	$title    = $form ? $form->title : '';
	$structure = $form ? pf_normalize_form_structure( json_decode( $form->fields_json, true ) ) : pf_default_form_structure();
	$settings  = $form ? json_decode( $form->settings_json, true ) : array();
	if ( ! is_array( $settings ) ) { $settings = array(); }

	$success_message = isset( $settings['success_message'] ) ? $settings['success_message'] : 'Hvala na upitu! Javljamo se u najkraćem mogućem roku.';
	$submit_label    = isset( $settings['submit_label'] )    ? $settings['submit_label']    : 'Pošalji upit';

	$theme_defaults = array(
		'primary_color' => '#B5654A', 'bg_color'      => '#FFFFFF',
		'text_color'    => '#2B2420', 'label_color'   => '#2B2420',
		'border_color'  => '#DDD4C8', 'border_radius' => '8',
		'font_family'   => "'Montserrat', sans-serif", 'button_style' => 'filled',
		'button_text'   => '#FFFFFF', 'input_bg'      => '#FBF8F4',
		'label_style'   => 'normal',  // normal | uppercase | light
		'font_size'     => 'medium',  // small | medium | large
		'input_height'  => 'normal',  // compact | normal | spacious
	);
	$theme = array_merge( $theme_defaults, isset( $settings['theme'] ) && is_array( $settings['theme'] ) ? $settings['theme'] : array() );
	$ai_configured = (bool) get_option( 'pf_openai_api_key', '' );

	$field_count = count( pf_flatten_fields( $structure ) );
	$step_count  = count( $structure['steps'] );
	?>

	<div class="pf-edit-page" id="pf-edit-page">

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pf-form-edit-form">
			<?php wp_nonce_field( 'pf_save_form', 'pf_nonce' ); ?>
			<input type="hidden" name="action"      value="pf_save_form">
			<input type="hidden" name="form_id"     value="<?php echo esc_attr( $form_id ); ?>">
			<input type="hidden" name="fields_json" id="pf-fields-json" value="">
			<input type="hidden" name="theme_json"  id="pf-theme-json"  value="<?php echo esc_attr( wp_json_encode( $theme ) ); ?>">
			<!-- Settings polja (šalju se pri submit, ali upravljaju iz Settings taba) -->
			<input type="hidden" name="success_message" id="pf-hidden-success" value="<?php echo esc_attr( $success_message ); ?>">
			<input type="hidden" name="submit_label"    id="pf-hidden-submit-label" value="<?php echo esc_attr( $submit_label ); ?>">
			<input type="hidden" name="autoresponder_enabled" id="pf-hidden-ar-enabled" value="<?php echo ! empty( $settings['autoresponder_enabled'] ) ? '1' : ''; ?>">
			<input type="hidden" name="autoresponder_subject" id="pf-hidden-ar-subject" value="<?php echo esc_attr( isset( $settings['autoresponder_subject'] ) ? $settings['autoresponder_subject'] : 'Primili smo Vaš upit - Namještaj Perković' ); ?>">
			<input type="hidden" name="autoresponder_message" id="pf-hidden-ar-message" value="<?php echo esc_attr( isset( $settings['autoresponder_message'] ) ? $settings['autoresponder_message'] : '' ); ?>">
			<input type="hidden" name="title" id="pf-hidden-title" value="<?php echo esc_attr( $title ); ?>">

			<!-- ============================================================
			     STICKY HEADER
			     ============================================================ -->
			<div class="pf-edit-header" id="pf-edit-header">
				<div class="pf-edit-header-left">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pf-forms' ) ); ?>" class="pf-back-btn" title="Natrag na sve forme">
						<span class="dashicons dashicons-arrow-left-alt2"></span>
					</a>
					<div class="pf-title-wrap">
						<span class="pf-title-display" id="pf-title-display" title="Klikni za uređivanje">
							<?php echo $title ? esc_html( $title ) : '<span class="pf-title-placeholder">Bez naziva forme</span>'; ?>
						</span>
						<input type="text" class="pf-title-input" id="pf-title-input"
						       value="<?php echo esc_attr( $title ); ?>"
						       placeholder="Upiši naziv forme..." autocomplete="off">
						<span class="pf-title-hint">Enter za potvrdu · Esc za odustajanje</span>
					</div>
				</div>

				<div class="pf-edit-header-right">
					<div class="pf-form-meta">
						<span class="pf-meta-pill">
							<span class="dashicons dashicons-forms"></span>
							<?php echo esc_html( $field_count ); ?> <?php echo $field_count === 1 ? 'polje' : ( $field_count < 5 ? 'polja' : 'polja' ); ?>
						</span>
						<span class="pf-meta-pill">
							<span class="dashicons dashicons-admin-page"></span>
							<?php echo esc_html( $step_count ); ?> <?php echo $step_count === 1 ? 'stranica' : 'stranice'; ?>
						</span>
						<?php if ( $form_id ) : ?>
							<span class="pf-meta-pill pf-meta-shortcode" title="Klikni za kopiranje">
								<span class="dashicons dashicons-shortcode"></span>
								[pf_form id="<?php echo esc_attr( $form_id ); ?>"]
							</span>
						<?php endif; ?>
					</div>
					<button type="button" class="pf-header-btn pf-header-btn-ghost" id="pf-preview-btn">
						<span class="dashicons dashicons-visibility"></span>
						<span>Pregled</span>
					</button>
					<button type="submit" class="pf-header-btn pf-header-btn-primary" id="pf-save-btn">
						<span class="dashicons dashicons-saved"></span>
						<span>Spremi</span>
					</button>
				</div>
			</div><!-- /header -->

			<!-- ============================================================
			     TABOVI
			     ============================================================ -->
			<div class="pf-edit-nav">
				<button type="button" class="pf-edit-tab is-active" data-tab="fields">
					<span class="dashicons dashicons-editor-table"></span> Polja forme
				</button>
				<button type="button" class="pf-edit-tab" data-tab="theme">
					<span class="dashicons dashicons-art"></span> Izgled
				</button>
				<button type="button" class="pf-edit-tab" data-tab="settings">
					<span class="dashicons dashicons-admin-settings"></span> Postavke
				</button>
				<button type="button" class="pf-edit-tab <?php echo ! $ai_configured ? 'pf-tab-disabled' : ''; ?>"
				        data-tab="ai" <?php echo ! $ai_configured ? 'title="Konfiguriraj OpenAI API ključ u Postavkama"' : ''; ?>>
					<span class="dashicons dashicons-superhero-alt"></span> AI Asistent
					<?php if ( ! $ai_configured ) : ?><span class="pf-tab-lock">🔒</span><?php endif; ?>
				</button>
			</div>

			<!-- ============================================================
			     TAB: POLJA FORME
			     ============================================================ -->
			<div class="pf-edit-tab-content is-active" data-tab="fields">
				<div class="pf-builder">

					<!-- Paleta -->
					<div class="pf-builder-palette">
						<div class="pf-palette-header">
							<span class="pf-palette-title">Tipovi polja</span>
						</div>
						<div class="pf-palette-list" id="pf-palette-list">
							<?php foreach ( pf_field_groups() as $group_label => $group_types ) : ?>
								<div class="pf-palette-group">
									<div class="pf-palette-group-label"><?php echo esc_html( $group_label ); ?></div>
									<div class="pf-palette-group-items">
										<?php foreach ( $group_types as $key ) : ?>
											<div class="pf-palette-item" data-new-type="<?php echo esc_attr( $key ); ?>">
												<span class="pf-palette-icon dashicons <?php echo esc_attr( pf_field_icon( $key ) ); ?>"></span>
												<?php echo esc_html( pf_field_types()[ $key ] ); ?>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="pf-palette-hint">Povuci tip polja u željenu stranicu</p>
					</div>

					<!-- Canvas -->
					<div class="pf-builder-canvas">
						<div class="pf-canvas-header">
							<div id="pf-steps-tabs" class="pf-steps-tabs"></div>
							<div class="pf-canvas-actions">
								<button type="button" class="pf-canvas-action-btn" id="pf-load-template-btn" title="Predlošci">
									<span class="dashicons dashicons-portfolio"></span>
								</button>
								<button type="button" class="pf-canvas-action-btn" id="pf-save-template-btn" title="Spremi kao predložak">
									<span class="dashicons dashicons-saved"></span>
								</button>
							</div>
						</div>
						<div id="pf-steps-container"></div>
					</div>

					<!-- Panel -->
					<div class="pf-builder-panel" id="pf-field-panel">
						<p class="pf-panel-placeholder">
							<span class="dashicons dashicons-arrow-left-alt"></span>
							Klikni na polje za uređivanje
						</p>
					</div>

				</div><!-- /pf-builder -->
			</div><!-- /TAB: Polja -->

			<!-- ============================================================
			     TAB: IZGLED
			     ============================================================ -->
			<div class="pf-edit-tab-content" data-tab="theme" style="display:none;">
				<div class="pf-theme-layout">

					<!-- Lijeva kolona: kontrole -->
					<div class="pf-theme-sidebar">

						<!-- Gotove teme -->
						<div class="pf-theme-block">
							<div class="pf-theme-block-title">Gotove teme</div>
							<div class="pf-preset-grid">
								<?php foreach ( pf_theme_presets() as $key => $preset ) : ?>
									<button type="button" class="pf-preset-card" data-preset="<?php echo esc_attr( $key ); ?>">
										<span class="pf-preset-swatch">
											<span style="background:<?php echo esc_attr( $preset['bg_color'] ); ?>;border-radius:3px 0 0 3px;"></span>
											<span style="background:<?php echo esc_attr( $preset['primary_color'] ); ?>;"></span>
											<span style="background:<?php echo esc_attr( $preset['text_color'] ); ?>;opacity:.3;border-radius:0 3px 3px 0;"></span>
										</span>
										<span class="pf-preset-label"><?php echo esc_html( $preset['label'] ); ?></span>
									</button>
								<?php endforeach; ?>
							</div>
						</div>

						<!-- Boje -->
						<div class="pf-theme-block">
							<div class="pf-theme-block-title">Boje</div>
							<?php
							$color_fields = array(
								'primary_color' => 'Primarna',
								'bg_color'      => 'Pozadina',
								'text_color'    => 'Tekst',
								'label_color'   => 'Labelice',
								'border_color'  => 'Obrub',
								'input_bg'      => 'Input pozadina',
							);
							foreach ( $color_fields as $prop => $lbl ) : ?>
								<div class="pf-color-row">
									<span class="pf-color-row-label"><?php echo esc_html( $lbl ); ?></span>
									<div class="pf-color-wrap">
										<input type="color" class="pf-color-input" data-prop="<?php echo esc_attr( $prop ); ?>"
										       value="<?php echo esc_attr( $theme[ $prop ] ?? '#ffffff' ); ?>">
										<input type="text" class="pf-color-text" data-prop="<?php echo esc_attr( $prop ); ?>"
										       value="<?php echo esc_attr( $theme[ $prop ] ?? '#ffffff' ); ?>" maxlength="7">
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<!-- Font -->
						<div class="pf-theme-block">
							<div class="pf-theme-block-title">Font</div>
							<select class="pf-select-input pf-select-full" data-prop="font_family">
								<?php foreach ( array(
									'inherit'                   => 'Tema stranice (default)',
									"'Inter', sans-serif"       => 'Inter',
									"'Montserrat', sans-serif"  => 'Montserrat',
									"'Lato', sans-serif"        => 'Lato',
									"'Nunito', sans-serif"      => 'Nunito',
									"'Raleway', sans-serif"     => 'Raleway',
									"'Roboto', sans-serif"      => 'Roboto',
									"'Open Sans', sans-serif"   => 'Open Sans',
									"'Playfair Display', serif" => 'Playfair Display',
									"'DM Sans', sans-serif"     => 'DM Sans',
								) as $val => $lbl ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $theme['font_family'] ?? 'inherit', $val ); ?>><?php echo esc_html( $lbl ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<!-- Oblik -->
						<div class="pf-theme-block">
							<div class="pf-theme-block-title">Oblik</div>
							<div class="pf-theme-option-row">
								<span>Zaobljenost</span>
								<div class="pf-range-wrap">
									<input type="range" class="pf-range-input" data-prop="border_radius"
									       min="0" max="24" step="2" value="<?php echo esc_attr( $theme['border_radius'] ?? '8' ); ?>">
									<span class="pf-range-val" id="pf-radius-label"><?php echo esc_html( $theme['border_radius'] ?? '8' ); ?>px</span>
								</div>
							</div>
						</div>

						<!-- Tipografija labela -->
						<div class="pf-theme-block">
							<div class="pf-theme-block-title">Labelice</div>
							<div class="pf-theme-option-row">
								<span>Stil</span>
								<div class="pf-toggle-group">
									<?php foreach ( array( 'normal' => 'Normal', 'uppercase' => 'CAPS', 'light' => 'Light' ) as $val => $lbl ) : ?>
										<button type="button" class="pf-toggle-opt pf-label-style-opt <?php echo ( $theme['label_style'] ?? 'normal' ) === $val ? 'is-active' : ''; ?>" data-val="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></button>
									<?php endforeach; ?>
								</div>
							</div>
							<div class="pf-theme-option-row">
								<span>Veličina</span>
								<div class="pf-toggle-group">
									<?php foreach ( array( 'small' => 'S', 'medium' => 'M', 'large' => 'L' ) as $val => $lbl ) : ?>
										<button type="button" class="pf-toggle-opt pf-size-opt <?php echo ( $theme['font_size'] ?? 'medium' ) === $val ? 'is-active' : ''; ?>" data-val="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></button>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<!-- Inputi -->
						<div class="pf-theme-block">
							<div class="pf-theme-block-title">Polja za unos</div>
							<div class="pf-theme-option-row">
								<span>Visina</span>
								<div class="pf-toggle-group">
									<?php foreach ( array( 'compact' => 'Zbijeno', 'normal' => 'Normalno', 'spacious' => 'Prostrano' ) as $val => $lbl ) : ?>
										<button type="button" class="pf-toggle-opt pf-height-opt <?php echo ( $theme['input_height'] ?? 'normal' ) === $val ? 'is-active' : ''; ?>" data-val="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></button>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<!-- Gumb -->
						<div class="pf-theme-block">
							<div class="pf-theme-block-title">Gumb za slanje</div>
							<div class="pf-theme-option-row">
								<span>Stil</span>
								<div class="pf-toggle-group">
									<?php foreach ( array( 'filled' => 'Pun', 'outline' => 'Obrub', 'ghost' => 'Ghost' ) as $val => $lbl ) : ?>
										<button type="button" class="pf-toggle-opt pf-btn-style-opt <?php echo $theme['button_style'] === $val ? 'is-active' : ''; ?>" data-val="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></button>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

					</div><!-- /sidebar -->

					<!-- Desna kolona: live preview -->
					<div class="pf-theme-preview-col">
						<div class="pf-theme-preview-label">
							<span>Pregled uživo</span>
							<span class="pf-theme-preview-note">Ažurira se odmah</span>
						</div>
						<div class="pf-theme-preview-frame" id="pf-theme-preview"></div>
					</div>

				</div>
			</div><!-- /TAB: Izgled -->

			<!-- ============================================================
			     TAB: POSTAVKE
			     ============================================================ -->
			<div class="pf-edit-tab-content" data-tab="settings" style="display:none;">
				<div class="pf-settings-grid">

					<div class="pf-settings-card">
						<div class="pf-settings-card-header">
							<span class="dashicons dashicons-feedback"></span>
							<strong>Poruke forme</strong>
						</div>
						<div class="pf-settings-card-body">
							<div class="pf-srow">
								<label for="pf-success">Poruka uspjeha</label>
								<input type="text" id="pf-success" class="pf-sinput"
								       value="<?php echo esc_attr( $success_message ); ?>"
								       placeholder="Hvala na upitu!">
								<p class="pf-sdesc">Prikazuje se korisniku nakon uspješnog slanja.</p>
							</div>
							<div class="pf-srow">
								<label for="pf-submit-label">Tekst gumba za slanje</label>
								<input type="text" id="pf-submit-label" class="pf-sinput"
								       value="<?php echo esc_attr( $submit_label ); ?>"
								       placeholder="Pošalji upit">
							</div>
						</div>
					</div>

					<div class="pf-settings-card">
						<div class="pf-settings-card-header">
							<span class="dashicons dashicons-email-alt"></span>
							<strong>Auto-odgovor korisniku</strong>
							<label class="pf-toggle-switch" style="margin-left:auto;">
								<input type="checkbox" id="pf-ar-enabled-check"
								       <?php checked( ! empty( $settings['autoresponder_enabled'] ) ); ?>>
								<span class="pf-toggle-slider"></span>
							</label>
						</div>
						<div class="pf-settings-card-body pf-ar-body">
							<p class="pf-sdesc">Email se šalje na adresu unesenu u prvo "Email" polje forme.</p>
							<div class="pf-srow">
								<label for="pf-ar-subject">Naslov emaila</label>
								<input type="text" id="pf-ar-subject" class="pf-sinput"
								       value="<?php echo esc_attr( isset( $settings['autoresponder_subject'] ) ? $settings['autoresponder_subject'] : 'Primili smo Vaš upit - Namještaj Perković' ); ?>">
							</div>
							<div class="pf-srow">
								<label for="pf-ar-message">Tekst emaila</label>
								<textarea id="pf-ar-message" class="pf-sinput" rows="6"
								          placeholder="Poštovani..."><?php echo esc_textarea( isset( $settings['autoresponder_message'] ) ? $settings['autoresponder_message'] : "Poštovani {Ime i prezime},\n\nHvala na upitu! Javljamo se u najkraćem mogućem roku.\n\nNamještaj Perković" ); ?></textarea>
								<p class="pf-sdesc">Koristite <code>{Label polja}</code> za automatsku zamjenu, npr. <code>{Ime i prezime}</code>.</p>
							</div>
						</div>
					</div>

					<?php if ( $form_id ) : ?>
					<div class="pf-settings-card pf-settings-card-full">
						<div class="pf-settings-card-header">
							<span class="dashicons dashicons-shortcode"></span>
							<strong>Embed</strong>
						</div>
						<div class="pf-settings-card-body">
							<div class="pf-srow">
								<label>Shortcode</label>
								<div class="pf-copy-row">
									<code class="pf-code-block">[pf_form id="<?php echo esc_html( $form_id ); ?>"]</code>
									<button type="button" class="pf-copy-btn" data-copy="[pf_form id=&quot;<?php echo esc_attr( $form_id ); ?>&quot;]">
										<span class="dashicons dashicons-clipboard"></span> Kopiraj
									</button>
								</div>
								<p class="pf-sdesc">Postavi u Elementor Shortcode widget na željenu stranicu.</p>
							</div>
							<?php if ( $form_id ) : ?>
							<div class="pf-srow">
								<label>A/B varijanta A</label>
								<div class="pf-copy-row">
									<code class="pf-code-block">[pf_form id="<?php echo esc_html( $form_id ); ?>" ab_variant="A"]</code>
									<button type="button" class="pf-copy-btn" data-copy="[pf_form id=&quot;<?php echo esc_attr( $form_id ); ?>&quot; ab_variant=&quot;A&quot;]">
										<span class="dashicons dashicons-clipboard"></span> Kopiraj
									</button>
								</div>
							</div>
							<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>

				</div>
			</div><!-- /TAB: Postavke -->

			<!-- ============================================================
			     TAB: AI ASISTENT
			     ============================================================ -->
			<div class="pf-edit-tab-content" data-tab="ai" style="display:none;">
				<?php if ( ! $ai_configured ) : ?>
					<div class="pf-ai-unconfigured">
						<span class="dashicons dashicons-admin-network" style="font-size:48px;color:#DDD4C8;display:block;margin-bottom:16px;"></span>
						<h3>OpenAI API ključ nije konfiguriran</h3>
						<p>Idi na <a href="<?php echo esc_url( admin_url( 'admin.php?page=pf-settings' ) ); ?>">Postavke → OpenAI</a> i unesi API ključ da aktiviraš AI Asistenta.</p>
					</div>
				<?php else : ?>
					<div class="pf-ai-layout">
						<div class="pf-ai-chat-panel">
							<div class="pf-ai-chat-header">
								<span class="pf-ai-avatar">🤖</span>
								<div>
									<strong>AI Asistent za forme</strong>
									<span class="pf-ai-model">GPT-4o</span>
								</div>
								<button type="button" class="pf-ai-clear-btn" id="pf-ai-clear" title="Novi razgovor">
									<span class="dashicons dashicons-image-rotate"></span>
								</button>
							</div>
							<div class="pf-ai-messages" id="pf-ai-messages">
								<div class="pf-ai-msg pf-ai-msg-agent">
									<div class="pf-ai-bubble">
										Zdravo! 👋 Ja sam tvoj AI asistent za kreiranje formi.<br><br>
										Reci mi što ti treba — na primjer:<br>
										<em>"Kreiraj formu za upit o kuhinji po mjeri"</em><br>
										<em>"Dodaj pitanje o budžetu na drugu stranicu"</em><br>
										<em>"Preformuliraj sva pitanja neformalnije"</em>
									</div>
								</div>
							</div>
							<div class="pf-ai-input-wrap">
								<textarea id="pf-ai-input" placeholder="Opiši što trebaš... (Enter = pošalji, Shift+Enter = novi red)" rows="2"></textarea>
								<button type="button" class="pf-ai-send-btn" id="pf-ai-send">
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</button>
							</div>
						</div>
						<div class="pf-ai-preview-panel">
							<div class="pf-ai-preview-header">
								<strong>Primijenjene promjene</strong>
							</div>
							<div class="pf-ai-suggestions" id="pf-ai-suggestions">
								<p class="pf-ai-empty-hint">Ovdje će se prikazati što je AI promijenio u formi.</p>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div><!-- /TAB: AI -->

		</form><!-- /pf-form-edit-form -->

		<!-- Preview modal -->
		<style>
			#pf-preview-modal, #pf-template-modal {
				position: fixed !important;
				top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
				width: 100% !important; height: 100% !important;
				background: rgba(43,36,32,0.5) !important;
				z-index: 999999 !important;
				align-items: center !important;
				justify-content: center !important;
				margin: 0 !important;
				padding: 20px !important;
				box-sizing: border-box !important;
			}
			#pf-preview-modal:not(.is-open),
			#pf-template-modal:not(.is-open) { display: none !important; }
			#pf-preview-modal.is-open,
			#pf-template-modal.is-open { display: flex !important; }
			#pf-preview-modal .pf-preview-modal-inner,
			#pf-template-modal .pf-preview-modal-inner {
				max-height: 90vh !important;
				overflow: auto !important;
			}
		</style>
		<div class="pf-preview-modal" id="pf-preview-modal" style="display:none;">
			<div class="pf-preview-modal-inner">
				<div class="pf-preview-modal-header">
					<div class="pf-preview-device-toggle">
						<button type="button" class="pf-preview-device-btn is-active" data-device="desktop">
							<span class="dashicons dashicons-desktop"></span> Računalo
						</button>
						<button type="button" class="pf-preview-device-btn" data-device="mobile">
							<span class="dashicons dashicons-smartphone"></span> Mobitel
						</button>
					</div>
					<button type="button" class="pf-preview-close" id="pf-preview-close">&times;</button>
				</div>
				<div class="pf-preview-modal-body">
					<div class="pf-preview-frame" id="pf-preview-frame"></div>
				</div>
			</div>
		</div>

		<!-- Template modal -->
		<div class="pf-preview-modal" id="pf-template-modal" style="display:none;">
			<div class="pf-preview-modal-inner" style="max-width:500px;">
				<div class="pf-preview-modal-header">
					<strong>Predlošci polja</strong>
					<button type="button" class="pf-preview-close" id="pf-template-modal-close">&times;</button>
				</div>
				<div class="pf-preview-modal-body" style="padding:20px;">
					<div class="pf-template-list"></div>
				</div>
			</div>
		</div>

		<script>
			window.pfInitialStructure = <?php echo wp_json_encode( $structure ); ?>;
			window.pfFieldTypes       = <?php echo wp_json_encode( pf_field_types() ); ?>;
			window.pfFieldIcons       = <?php echo wp_json_encode( pf_field_icons_map() ); ?>;
			window.pfFormId           = <?php echo wp_json_encode( $form ? (int) $form->id : 0 ); ?>;
			window.pfAjaxUrl          = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			window.pfTemplateNonce    = <?php echo wp_json_encode( wp_create_nonce( 'pf_templates' ) ); ?>;
		</script>

	</div><!-- /pf-edit-page -->
	<?php
}

function pf_field_types() {
	return array(
		'text'           => 'Tekst',
		'email'          => 'Email',
		'tel'            => 'Telefon',
		'number'         => 'Broj',
		'textarea'       => 'Tekstualno polje',
		'select'         => 'Padajući izbornik',
		'radio'          => 'Radio gumbi',
		'checkbox'       => 'Checkbox',
		'image_choice'   => 'Izbor sa slikama',
		'file'           => 'Upload datoteke',
		'section_divider'=> 'Razdjelnik sekcije',
		'hidden'         => 'Skriveno polje (UTM)',
		'html'           => 'Info blok (HTML)',
	);
}

/**
 * Dashicon klasa po tipu polja (za prikaz u paleti/canvasu)
 */
function pf_field_icon( $type ) {
	$icons = pf_field_icons_map();
	return isset( $icons[ $type ] ) ? $icons[ $type ] : 'dashicons-editor-textcolor';
}

function pf_field_icons_map() {
	return array(
		'text'            => 'dashicons-editor-textcolor',
		'email'           => 'dashicons-email',
		'tel'             => 'dashicons-phone',
		'number'          => 'dashicons-calculator',
		'textarea'        => 'dashicons-text-page',
		'select'          => 'dashicons-menu-alt',
		'radio'           => 'dashicons-marker',
		'checkbox'        => 'dashicons-yes-alt',
		'image_choice'    => 'dashicons-format-image',
		'file'            => 'dashicons-upload',
		'section_divider' => 'dashicons-minus',
		'hidden'          => 'dashicons-hidden',
		'html'            => 'dashicons-editor-code',
	);
}


/* =========================================================
 *  SPREMANJE FORME
 * ========================================================= */
function pf_handle_save_form() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu akciju.' );
	}
	check_admin_referer( 'pf_save_form', 'pf_nonce' );

	global $wpdb;
	$table = $wpdb->prefix . 'pf_forms';

	$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
	$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

	$raw_structure = isset( $_POST['fields_json'] ) ? wp_unslash( $_POST['fields_json'] ) : '';
	$decoded       = json_decode( $raw_structure, true );
	$structure     = pf_normalize_form_structure( is_array( $decoded ) ? $decoded : array() );
	$clean         = pf_sanitize_form_structure( $structure );

	$settings = array(
		'success_message'        => isset( $_POST['success_message'] ) ? sanitize_text_field( wp_unslash( $_POST['success_message'] ) ) : '',
		'submit_label'           => isset( $_POST['submit_label'] ) ? sanitize_text_field( wp_unslash( $_POST['submit_label'] ) ) : 'Pošalji',
		'autoresponder_enabled'  => ! empty( $_POST['autoresponder_enabled'] ),
		'autoresponder_subject'  => isset( $_POST['autoresponder_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['autoresponder_subject'] ) ) : '',
		'autoresponder_message'  => isset( $_POST['autoresponder_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['autoresponder_message'] ) ) : '',
		'theme'                  => pf_sanitize_theme( isset( $_POST['theme_json'] ) ? json_decode( wp_unslash( $_POST['theme_json'] ), true ) : array() ),
	);

	$data = array(
		'title'         => $title,
		'fields_json'   => wp_json_encode( $clean ),
		'settings_json' => wp_json_encode( $settings ),
		'updated_at'    => current_time( 'mysql' ),
	);

	if ( $form_id ) {
		$wpdb->update( $table, $data, array( 'id' => $form_id ) );
	} else {
		$data['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $data );
		$form_id = $wpdb->insert_id;
	}

	wp_safe_redirect( admin_url( 'admin.php?page=pf-form-edit&id=' . $form_id . '&saved=1' ) );
	exit;
}
add_action( 'admin_post_pf_save_form', 'pf_handle_save_form' );


/* =========================================================
 *  STRANICA: Upiti (submissions)
 * ========================================================= */
function pf_render_submissions_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu stranicu.' );
	}
	global $wpdb;
	$sub_table  = $wpdb->prefix . 'pf_submissions';
	$form_table = $wpdb->prefix . 'pf_forms';

	// Promjena statusa (AJAX-friendly inline)
	if ( isset( $_GET['action'], $_GET['id'], $_GET['status'], $_GET['_wpnonce'] ) && $_GET['action'] === 'set_status' ) {
		$id     = absint( $_GET['id'] );
		$status = sanitize_key( $_GET['status'] );
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'pf_status_' . $id ) ) {
			$wpdb->update( $sub_table, array( 'status' => $status ), array( 'id' => $id ) );
		}
	}

	// Brisanje
	if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'delete_submission' ) {
		$id = absint( $_GET['id'] );
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'pf_delete_sub_' . $id ) ) {
			$wpdb->delete( $sub_table, array( 'id' => $id ) );
			echo '<div class="updated notice"><p>Upit obrisan.</p></div>';
		}
	}

	$form_filter   = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
	$status_filter = isset( $_GET['status_filter'] ) ? sanitize_key( $_GET['status_filter'] ) : '';

	$where_parts = array();
	if ( $form_filter ) {
		$where_parts[] = $wpdb->prepare( 'form_id = %d', $form_filter );
	}
	if ( $status_filter ) {
		$where_parts[] = $wpdb->prepare( 'status = %s', $status_filter );
	}
	$where = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

	$submissions = $wpdb->get_results( "SELECT * FROM $sub_table $where ORDER BY id DESC LIMIT 200" );
	$forms       = $wpdb->get_results( "SELECT id, title FROM $form_table ORDER BY id DESC" );

	// Broji po statusu za ikonice
	$counts_raw = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM $sub_table GROUP BY status" );
	$counts = array( 'new' => 0, 'contacted' => 0, 'offer_sent' => 0, 'closed' => 0 );
	foreach ( $counts_raw as $c ) {
		if ( isset( $counts[ $c->status ] ) ) {
			$counts[ $c->status ] = (int) $c->cnt;
		}
	}

	$status_config = array(
		'new'        => array( 'label' => 'Novo',           'color' => '#2271b1', 'bg' => '#EAF3FB' ),
		'contacted'  => array( 'label' => 'Kontaktirano',   'color' => '#B58A00', 'bg' => '#FBF7E4' ),
		'offer_sent' => array( 'label' => 'Ponuda poslana', 'color' => '#7E8A6A', 'bg' => '#EEF1EA' ),
		'closed'     => array( 'label' => 'Zatvoreno',      'color' => '#338B45', 'bg' => '#EBF5EE' ),
	);
	?>
	<div class="wrap pf-wrap">
		<h1>Upiti</h1>

		<!-- Pipeline counters -->
		<div class="pf-pipeline">
			<?php foreach ( $status_config as $skey => $scfg ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pf-submissions', 'status_filter' => $skey ), admin_url( 'admin.php' ) ) ); ?>"
				   class="pf-pipeline-card <?php echo $status_filter === $skey ? 'is-active' : ''; ?>">
					<span class="pf-pipeline-count" style="color:<?php echo esc_attr( $scfg['color'] ); ?>">
						<?php echo esc_html( $counts[ $skey ] ?? 0 ); ?>
					</span>
					<span class="pf-pipeline-label"><?php echo esc_html( $scfg['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
			<?php if ( $status_filter ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pf-submissions', 'status_filter' => '' ), admin_url( 'admin.php' ) ) ); ?>"
				   class="pf-pipeline-reset">✕ Prikaži sve</a>
			<?php endif; ?>
		</div>

		<!-- Filteri -->
		<form method="get" class="pf-filter-bar">
			<input type="hidden" name="page" value="pf-submissions">
			<input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
			<label>Forma:
				<select name="form_id" onchange="this.form.submit()">
					<option value="0">Sve forme</option>
					<?php foreach ( $forms as $f ) : ?>
						<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $form_filter, $f->id ); ?>>
							<?php echo esc_html( $f->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pf_export_csv&form_id=' . $form_filter ), 'pf_export_csv' ) ); ?>">
				↓ Export CSV
			</a>
		</form>

		<!-- Tablica upita -->
		<table class="wp-list-table widefat fixed">
			<thead>
				<tr>
					<th style="width:55px;">ID</th>
					<th>Podaci</th>
					<th style="width:170px;">Datum</th>
					<th style="width:200px;">Status</th>
					<th style="width:70px;">Akcije</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $submissions ) ) : ?>
				<tr><td colspan="5" style="padding:20px;color:#9C9182;">Nema upita<?php echo $status_filter ? ' s ovim statusom' : ''; ?>.</td></tr>
			<?php else : ?>
				<?php foreach ( $submissions as $sub ) :
					$data = json_decode( $sub->data_json, true );
					$scfg = $status_config[ $sub->status ] ?? $status_config['new'];
				?>
					<tr>
						<td style="color:#9C9182;">#<?php echo esc_html( $sub->id ); ?></td>
						<td>
							<?php if ( is_array( $data ) ) : ?>
								<div class="pf-sub-data">
								<?php foreach ( $data as $label => $value ) : ?>
									<div class="pf-sub-row">
										<span class="pf-sub-label"><?php echo esc_html( $label ); ?></span>
										<span class="pf-sub-value">
										<?php
										if ( is_array( $value ) ) {
											echo esc_html( implode( ', ', $value ) );
										} elseif ( is_string( $value ) && preg_match( '#^https?://#', $value ) ) {
											echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">otvori datoteku ↗</a>';
										} else {
											echo esc_html( $value );
										}
										?>
										</span>
									</div>
								<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
						<td style="color:#9C9182;font-size:12px;">
							<?php echo esc_html( date_i18n( 'd.m.Y. H:i', strtotime( $sub->created_at ) ) ); ?>
						</td>
						<td>
							<div class="pf-status-wrap">
								<span class="pf-status-badge" style="color:<?php echo esc_attr( $scfg['color'] ); ?>;background:<?php echo esc_attr( $scfg['bg'] ); ?>">
									<?php echo esc_html( $scfg['label'] ); ?>
								</span>
								<select class="pf-status-select" data-id="<?php echo esc_attr( $sub->id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'pf_status_' . $sub->id ) ); ?>">
									<?php foreach ( $status_config as $skey => $sc ) : ?>
										<option value="<?php echo esc_attr( $skey ); ?>" <?php selected( $sub->status, $skey ); ?>>
											<?php echo esc_html( $sc['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pf-submissions&action=delete_submission&id=' . $sub->id ), 'pf_delete_sub_' . $sub->id ) ); ?>"
							   onclick="return confirm('Obrisati ovaj upit?');"
							   title="Obriši" style="color:#b32d2e;">
								<span class="dashicons dashicons-trash"></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	</div>

	<style>
	.pf-pipeline { display:flex; gap:12px; align-items:center; margin:16px 0 20px; flex-wrap:wrap; }
	.pf-pipeline-card { display:flex; flex-direction:column; align-items:center; padding:14px 20px; border:1px solid #DDD4C8; border-radius:8px; background:#fff; text-decoration:none; min-width:100px; transition:box-shadow .15s; }
	.pf-pipeline-card:hover,.pf-pipeline-card.is-active { box-shadow:0 0 0 2px #B5654A; border-color:#B5654A; }
	.pf-pipeline-count { font-size:28px; font-weight:700; line-height:1; }
	.pf-pipeline-label { font-size:12px; color:#9C9182; margin-top:4px; }
	.pf-pipeline-reset { font-size:12px; color:#9C9182; text-decoration:none; padding:4px 8px; border:1px dashed #DDD4C8; border-radius:6px; }
	.pf-pipeline-reset:hover { color:#C44545; border-color:#C44545; }
	.pf-filter-bar { display:flex; gap:12px; align-items:center; margin-bottom:16px; }
	.pf-sub-data { display:flex; flex-direction:column; gap:4px; padding:4px 0; }
	.pf-sub-row { display:flex; gap:8px; font-size:13px; }
	.pf-sub-label { font-weight:600; color:#2B2420; min-width:120px; flex-shrink:0; }
	.pf-sub-value { color:#6B6258; }
	.pf-status-wrap { display:flex; flex-direction:column; gap:6px; }
	.pf-status-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:10px; display:inline-block; }
	.pf-status-select { font-size:12px; width:100%; }
	</style>

	<script>
	document.querySelectorAll('.pf-status-select').forEach(function(sel) {
		sel.addEventListener('change', function() {
			var id    = this.dataset.id;
			var nonce = this.dataset.nonce;
			var status = this.value;
			var url = ajaxurl + '?action=pf_set_status_ajax&id=' + id + '&status=' + status + '&_wpnonce=' + nonce;
			fetch(url, { credentials: 'same-origin' }).then(function(r) { return r.json(); }).then(function(json) {
				if (json.success) { location.reload(); }
			});
		});
	});
	</script>
	<?php
}


/* =========================================================
 *  STRANICA: Postavke
 * ========================================================= */
function pf_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu stranicu.' );
	}

	if ( isset( $_POST['pf_settings_nonce'] ) && wp_verify_nonce( $_POST['pf_settings_nonce'], 'pf_save_settings' ) ) {
		update_option( 'pf_notify_email',       sanitize_email( wp_unslash( $_POST['pf_notify_email'] ?? '' ) ) );
		update_option( 'pf_ga4_measurement_id', sanitize_text_field( wp_unslash( $_POST['pf_ga4_measurement_id'] ?? '' ) ) );
		update_option( 'pf_ga4_api_secret',     sanitize_text_field( wp_unslash( $_POST['pf_ga4_api_secret'] ?? '' ) ) );
		update_option( 'pf_ga4_event_name',     sanitize_key( wp_unslash( $_POST['pf_ga4_event_name'] ?? 'generate_lead' ) ) );
		update_option( 'pf_meta_pixel_id',      sanitize_text_field( wp_unslash( $_POST['pf_meta_pixel_id'] ?? '' ) ) );
		update_option( 'pf_meta_pixel_event',   sanitize_text_field( wp_unslash( $_POST['pf_meta_pixel_event'] ?? 'Lead' ) ) );
		update_option( 'pf_openai_api_key',     sanitize_text_field( wp_unslash( $_POST['pf_openai_api_key'] ?? '' ) ) );
		// GitHub repo za auto-update (sprema se kao WP opcija, override konstante)
		if ( isset( $_POST['pf_github_repo'] ) ) {
			update_option( 'pf_github_repo', sanitize_text_field( wp_unslash( $_POST['pf_github_repo'] ) ) );
			delete_transient( 'pf_update_info' );
		}
		if ( isset( $_POST['pf_github_token'] ) ) {
			update_option( 'pf_github_token', sanitize_text_field( wp_unslash( $_POST['pf_github_token'] ) ) );
			delete_transient( 'pf_update_info' );
		}
		echo '<div class="updated notice"><p>Postavke spremljene.</p></div>';
	}

	$notify_email       = get_option( 'pf_notify_email',       get_option( 'admin_email' ) );
	$ga4_measurement_id = get_option( 'pf_ga4_measurement_id', '' );
	$ga4_api_secret     = get_option( 'pf_ga4_api_secret',     '' );
	$ga4_event_name     = get_option( 'pf_ga4_event_name',     'generate_lead' );
	$meta_pixel_id      = get_option( 'pf_meta_pixel_id',      '' );
	$meta_pixel_event   = get_option( 'pf_meta_pixel_event',   'Lead' );
	$openai_api_key     = get_option( 'pf_openai_api_key',     '' );
	$github_repo        = get_option( 'pf_github_repo',        PF_GITHUB_REPO );
	$github_token       = get_option( 'pf_github_token',       '' );

	$ga4_configured    = $ga4_measurement_id && $ga4_api_secret;
	$pixel_configured  = (bool) $meta_pixel_id;
	$openai_configured = (bool) $openai_api_key;
	$github_configured = $github_repo && $github_repo !== 'tvoj-username/perkovic-forms';
	?>
	<div class="wrap pf-wrap pf-settings-wrap">
		<h1>Postavke — Perković Forms</h1>

		<form method="post">
			<?php wp_nonce_field( 'pf_save_settings', 'pf_settings_nonce' ); ?>

			<!-- Auto-Update (GitHub) -->
			<div class="pf-settings-section">
				<div class="pf-settings-section-header">
					<h2>Automatska ažuriranja (GitHub)</h2>
					<span class="pf-settings-status <?php echo $github_configured ? 'is-active' : ''; ?>">
						<?php echo $github_configured ? '✓ Konfigurirano' : '○ Nije konfigurirano'; ?>
					</span>
				</div>
				<p class="description">
					Plugin automatski provjerava GitHub releases za nova ažuriranja i prikazuje "Update available" u WP admin baš kao za kupljene plugine.
				</p>
				<table class="form-table">
					<tr>
						<th><label for="pf-github-repo">GitHub repozitorij</label></th>
						<td>
							<input type="text" id="pf-github-repo" name="pf_github_repo"
							       class="regular-text" value="<?php echo esc_attr( $github_repo ); ?>"
							       placeholder="korisnik/perkovic-forms">
							<p class="description">
								Format: <code>tvoj-github-username/perkovic-forms</code>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="pf-github-token">Personal Access Token</label></th>
						<td>
							<input type="password" id="pf-github-token" name="pf_github_token"
							       class="regular-text" value="<?php echo esc_attr( $github_token ); ?>"
							       placeholder="ghp_xxxxxxxxxxxxxxxxxxxx">
							<p class="description">
								Potreban za <strong>privatni</strong> repozitorij. Kreiraj na
								<a href="https://github.com/settings/tokens/new?scopes=repo&description=Perkovic+Forms+Updater" target="_blank" rel="noopener">
									github.com/settings/tokens
								</a> →
								"Generate new token (classic)" → scope: <code>repo</code> → Generate.<br>
								Za <strong>javni</strong> repozitorij token nije potreban.
							</p>
						</td>
					</tr>
					<?php if ( $github_configured ) : ?>
					<tr>
						<th>Status</th>
						<td>
							<button type="button" class="button" id="pf-check-update-btn">
								<span class="dashicons dashicons-update"></span> Provjeri ažuriranja
							</button>
							<span id="pf-update-result" style="margin-left:10px;font-size:13px;"></span>
						</td>
					</tr>
					<?php endif; ?>
				</table>
			</div>

			<!-- Opće -->
			<div class="pf-settings-section">
				<h2>Opće</h2>
				<table class="form-table">
					<tr>
						<th><label for="pf-notify-email">Email za primanje upita</label></th>
						<td>
							<input type="email" id="pf-notify-email" name="pf_notify_email" class="regular-text" value="<?php echo esc_attr( $notify_email ); ?>">
							<p class="description">Na ovu adresu šalje se email pri svakom novom upitu.</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- OpenAI -->
			<div class="pf-settings-section">
				<div class="pf-settings-section-header">
					<h2>OpenAI (AI Asistent)</h2>
					<span class="pf-settings-status <?php echo $openai_configured ? 'is-active' : ''; ?>">
						<?php echo $openai_configured ? '✓ Konfigurirano' : '○ Nije konfigurirano'; ?>
					</span>
				</div>
				<p class="description">
					API ključ za AI Asistenta koji pomaže u kreiranju formi i za AI chat na kraju forme.
					Koristi GPT-4o model.
				</p>
				<table class="form-table">
					<tr>
						<th><label for="pf-openai-key">API ključ</label></th>
						<td>
							<input type="password" id="pf-openai-key" name="pf_openai_api_key" class="regular-text"
							       value="<?php echo esc_attr( $openai_api_key ); ?>" placeholder="sk-...">
							<p class="description">
								Nađi na <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>.
								Ključ se sprema enkriptiran u WP opcijama.
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- GA4 -->
			<div class="pf-settings-section">
				<div class="pf-settings-section-header">
					<h2>Google Analytics 4</h2>
					<span class="pf-settings-status <?php echo $ga4_configured ? 'is-active' : ''; ?>">
						<?php echo $ga4_configured ? '✓ Konfigurirano' : '○ Nije konfigurirano'; ?>
					</span>
				</div>
				<p class="description">
					Server-side slanje eventi direktno u GA4 putem Measurement Protocol — pouzdanije od GTM-a, radi i s ad blockerima.
					Svako slanje forme šalje event odmah s backenda, bez JavaScripta.
				</p>
				<table class="form-table">
					<tr>
						<th><label for="pf-ga4-id">Measurement ID</label></th>
						<td>
							<input type="text" id="pf-ga4-id" name="pf_ga4_measurement_id" class="regular-text" value="<?php echo esc_attr( $ga4_measurement_id ); ?>" placeholder="G-XXXXXXXXXX">
							<p class="description">Nađi u GA4 → Admin → Data Streams → tvoj stream → Measurement ID.</p>
						</td>
					</tr>
					<tr>
						<th><label for="pf-ga4-secret">API Secret</label></th>
						<td>
							<input type="text" id="pf-ga4-secret" name="pf_ga4_api_secret" class="regular-text" value="<?php echo esc_attr( $ga4_api_secret ); ?>" placeholder="abc123...">
							<p class="description">GA4 → Admin → Data Streams → tvoj stream → Measurement Protocol API secrets → "Create".</p>
						</td>
					</tr>
					<tr>
						<th><label for="pf-ga4-event">Naziv GA4 eventa</label></th>
						<td>
							<input type="text" id="pf-ga4-event" name="pf_ga4_event_name" class="regular-text" value="<?php echo esc_attr( $ga4_event_name ); ?>" placeholder="generate_lead">
							<p class="description">Preporučeno: <code>generate_lead</code>. Šalje se pri svakom uspješnom slanju forme.</p>
						</td>
					</tr>
					<?php if ( $ga4_configured ) : ?>
					<tr>
						<th>Test eventi</th>
						<td>
							<button type="button" class="button" id="pf-ga4-test-btn">
								<span class="dashicons dashicons-controls-play"></span> Pošalji test event
							</button>
							<span id="pf-ga4-test-result" style="margin-left:10px;font-size:13px;"></span>
							<p class="description">Šalje testni <code><?php echo esc_html( $ga4_event_name ); ?></code> event i vraća validaciju iz GA4.</p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
			</div>

			<!-- Meta Pixel -->
			<div class="pf-settings-section">
				<div class="pf-settings-section-header">
					<h2>Meta Pixel (Facebook)</h2>
					<span class="pf-settings-status <?php echo $pixel_configured ? 'is-active' : ''; ?>">
						<?php echo $pixel_configured ? '✓ Konfigurirano' : '○ Nije konfigurirano'; ?>
					</span>
				</div>
				<p class="description">
					Plugin injektira Meta Pixel kod u <code>&lt;head&gt;</code> stranica s formom i šalje standard event pri slanju.
					Nema potrebe za GTM-om za osnovno praćenje.
				</p>
				<table class="form-table">
					<tr>
						<th><label for="pf-pixel-id">Pixel ID</label></th>
						<td>
							<input type="text" id="pf-pixel-id" name="pf_meta_pixel_id" class="regular-text" value="<?php echo esc_attr( $meta_pixel_id ); ?>" placeholder="1234567890123456">
							<p class="description">Nađi u Meta Business Suite → Events Manager → tvoj Pixel → Settings → Pixel ID.</p>
						</td>
					</tr>
					<tr>
						<th><label for="pf-pixel-event">Standard event</label></th>
						<td>
							<select id="pf-pixel-event" name="pf_meta_pixel_event">
								<?php foreach ( array( 'Lead', 'CompleteRegistration', 'Contact', 'SubmitApplication', 'Schedule' ) as $ev ) : ?>
									<option value="<?php echo esc_attr( $ev ); ?>" <?php selected( $meta_pixel_event, $ev ); ?>><?php echo esc_html( $ev ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Preporučeno: <code>Lead</code>. Šalje se pri svakom uspješnom slanju forme.</p>
						</td>
					</tr>
				</table>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary">Spremi postavke</button>
			</p>
		</form>

		<script>
		(function() {
			// GA4 test event
			var gaBtn = document.getElementById('pf-ga4-test-btn');
			var gaRes = document.getElementById('pf-ga4-test-result');
			if (gaBtn && gaRes) {
				gaBtn.addEventListener('click', function() {
					gaBtn.disabled = true;
					gaRes.textContent = 'Šaljem...';
					gaRes.style.color = '#9C9182';
					fetch(ajaxurl + '?action=pf_test_ga4_event&_wpnonce=<?php echo esc_js( wp_create_nonce( 'pf_test_ga4' ) ); ?>', { credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(json) {
						gaBtn.disabled = false;
						if (json.success) { gaRes.textContent = '✓ ' + json.data.message; gaRes.style.color = '#338B45'; }
						else { gaRes.textContent = '✗ ' + (json.data || 'Greška'); gaRes.style.color = '#C44545'; }
					}).catch(function() { gaBtn.disabled = false; gaRes.textContent = '✗ Greška u komunikaciji'; gaRes.style.color = '#C44545'; });
				});
			}

			// Check for updates
			var upBtn = document.getElementById('pf-check-update-btn');
			var upRes = document.getElementById('pf-update-result');
			if (upBtn && upRes) {
				upBtn.addEventListener('click', function() {
					upBtn.disabled = true;
					upRes.textContent = 'Provjera...';
					upRes.style.color = '#9C9182';
					fetch(ajaxurl + '?action=pf_check_update_manual&_wpnonce=<?php echo esc_js( wp_create_nonce( 'pf_check_update' ) ); ?>', { credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(function(json) {
						upBtn.disabled = false;
						if (json.success) { upRes.textContent = json.data.message; upRes.style.color = json.data.has_update ? '#B58A00' : '#338B45'; }
						else { upRes.textContent = '✗ ' + (json.data || 'Greška'); upRes.style.color = '#C44545'; }
					}).catch(function() { upBtn.disabled = false; upRes.textContent = '✗ Greška'; upRes.style.color = '#C44545'; });
				});
			}
		})();
		</script>
	</div>
	<?php
}


/* =========================================================
 *  FRONTEND: Shortcode + render
 * ========================================================= */
function pf_shortcode_form( $atts ) {
	$atts = shortcode_atts( array(
		'id'         => 0,
		'ab_variant' => '', // 'A' ili 'B' - za ručno postavljanje varijante
	), $atts );

	$form_id    = absint( $atts['id'] );
	$ab_variant = strtoupper( sanitize_key( $atts['ab_variant'] ) );
	$ab_variant = in_array( $ab_variant, array( 'A', 'B' ), true ) ? $ab_variant : '';

	if ( ! $form_id ) {
		return '';
	}

	// Provjeri je li forma dio aktivnog A/B testa (bez ab_variant parametra)
	// Ako jest, automatski dodijeli varijantu 50/50
	if ( ! $ab_variant ) {
		global $wpdb;
		$ab_table = $wpdb->prefix . 'pf_ab_tests';
		$active_test = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $ab_table
			 WHERE status = 'active' AND (form_a = %d OR form_b = %d)
			 LIMIT 1",
			$form_id, $form_id
		) );

		if ( $active_test ) {
			// Provjeri sessionStorage via JS - ovdje koristimo cookie fallback
			$cookie_key = 'pf_ab_' . $active_test->id;
			if ( isset( $_COOKIE[ $cookie_key ] ) && in_array( $_COOKIE[ $cookie_key ], array( 'A', 'B' ), true ) ) {
				$ab_variant = $_COOKIE[ $cookie_key ];
			} else {
				// Random split prema traffic_split postotku
				$ab_variant = ( mt_rand( 1, 100 ) <= (int) $active_test->traffic_split ) ? 'A' : 'B';
			}
			// Zamijeni form_id odgovarajućom varijantom
			if ( $ab_variant === 'A' && (int) $active_test->form_a !== $form_id ) {
				$form_id = (int) $active_test->form_a;
			} elseif ( $ab_variant === 'B' && (int) $active_test->form_b !== $form_id ) {
				$form_id = (int) $active_test->form_b;
			}
		}
	}

	global $wpdb;
	$table = $wpdb->prefix . 'pf_forms';
	$form  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $form_id ) );

	if ( ! $form ) {
		return '<p>Forma nije pronađena.</p>';
	}

	$structure = pf_normalize_form_structure( json_decode( $form->fields_json, true ) );
	$settings  = json_decode( $form->settings_json, true );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$theme        = isset( $settings['theme'] ) && is_array( $settings['theme'] ) ? $settings['theme'] : array();
	$success_message = ! empty( $settings['success_message'] ) ? $settings['success_message'] : 'Hvala na upitu!';
	$submit_label    = ! empty( $settings['submit_label'] ) ? $settings['submit_label'] : 'Pošalji';

	// Enqueue frontend assets
	wp_enqueue_style( 'pf-frontend-css', PF_PLUGIN_URL . 'assets/css/frontend.css', array(), PF_VERSION );
	wp_enqueue_script( 'pf-frontend-js', PF_PLUGIN_URL . 'assets/js/frontend.js', array(), PF_VERSION, true );

	// Theme CSS — ispisuje se i inline (garantirano u outputu) i u wp_head (za prioritet)
	$theme_css_output = pf_theme_css( $form_id, $theme );
	add_action( 'wp_head', function() use ( $theme_css_output ) {
		echo $theme_css_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}, 999 );

	$steps       = $structure['steps'];
	$total_steps = count( $steps );

	$wrap_id = 'pf-wrap-' . $form_id;

	ob_start();

	// Inline theme CSS — garantiran bez obzira na caching ili Elementor redoslijed
	echo $theme_css_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped


	// Postavi cookie za konzistentnu dodjelu varijante kroz sesiju
	if ( $ab_variant && ! headers_sent() ) {
		$cookie_key = 'pf_ab_';
		// Pronađi test_id za ovaj form_id
		global $wpdb;
		$ab_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}pf_ab_tests WHERE status='active' AND (form_a=%d OR form_b=%d) LIMIT 1",
			$form_id, $form_id
		) );
		if ( $ab_row ) {
			setcookie( 'pf_ab_' . $ab_row->id, $ab_variant, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
	}
	?>
	<div id="<?php echo esc_attr( $wrap_id ); ?>" class="pf-form-wrap">
	<form class="pf-form"
	      id="pf-form-<?php echo esc_attr( $form_id ); ?>"
	      data-form-id="<?php echo esc_attr( $form_id ); ?>"
	      data-form-title="<?php echo esc_attr( $form->title ); ?>"
	      data-success="<?php echo esc_attr( $success_message ); ?>"
	      <?php if ( $ab_variant ) : ?>data-ab-variant="<?php echo esc_attr( $ab_variant ); ?>"<?php endif; ?>
	      enctype="multipart/form-data" novalidate>

		<?php if ( $total_steps > 1 ) : ?>
			<div class="pf-steps-indicator">
				<?php for ( $i = 0; $i < $total_steps; $i++ ) : ?>
					<div class="pf-step-dot <?php echo $i === 0 ? 'is-active' : ''; ?>" data-step="<?php echo esc_attr( $i + 1 ); ?>">
						<?php echo esc_html( $i + 1 ); ?>
					</div>
					<?php if ( $i < $total_steps - 1 ) : ?><div class="pf-step-line"></div><?php endif; ?>
				<?php endfor; ?>
			</div>
		<?php endif; ?>

		<?php foreach ( $steps as $i => $step ) : ?>
			<div class="pf-step-panel <?php echo $i === 0 ? 'is-active' : ''; ?>" data-step="<?php echo esc_attr( $i + 1 ); ?>">
				<?php foreach ( (array) $step['rows'] as $row ) : ?>
					<?php $cols = isset( $row['cols'] ) ? max( 1, min( 3, intval( $row['cols'] ) ) ) : 1; ?>
					<div class="pf-row pf-cols-<?php echo esc_attr( $cols ); ?>">
						<?php foreach ( (array) $row['cells'] as $cell ) : ?>
							<div class="pf-col">
								<?php foreach ( (array) $cell as $field ) : ?>
									<?php pf_render_frontend_field( $field ); ?>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<div class="pf-step-actions">
					<?php if ( $i > 0 ) : ?>
						<button type="button" class="pf-btn pf-btn-secondary pf-prev">Natrag</button>
					<?php endif; ?>

					<?php if ( $i < $total_steps - 1 ) : ?>
						<button type="button" class="pf-btn pf-btn-primary pf-next">Sljedeći korak</button>
					<?php else : ?>
						<button type="submit" class="pf-btn pf-btn-primary pf-submit"><?php echo esc_html( $submit_label ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>

		<!-- Honeypot -->
		<div class="pf-hp" aria-hidden="true">
			<input type="text" name="pf_hp" tabindex="-1" autocomplete="off">
		</div>

		<input type="hidden" name="pf_nonce" value="<?php echo esc_attr( wp_create_nonce( 'pf_submit_' . $form_id ) ); ?>">

		<div class="pf-message" role="status"></div>
	</form>
	</div><!-- /pf-form-wrap -->
	<?php
	return ob_get_clean();
}
add_shortcode( 'pf_form', 'pf_shortcode_form' );


/**
 * Render jednog polja na frontendu
 */
function pf_render_frontend_field( $field ) {
	$type          = isset( $field['type'] ) ? $field['type'] : 'text';
	$label         = isset( $field['label'] ) ? $field['label'] : '';
	$name          = isset( $field['name'] ) ? $field['name'] : '';
	$required      = ! empty( $field['required'] );
	$placeholder   = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
	$options       = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
	$default_value = isset( $field['default_value'] ) ? $field['default_value'] : '';
	$hidden        = ! empty( $field['hidden'] );
	$utm_source    = isset( $field['utm_source'] ) ? $field['utm_source'] : '';

	// Skriveno/UTM polje
	if ( $hidden || $type === 'hidden' ) {
		$data_utm = $utm_source ? ' data-utm="' . esc_attr( $utm_source ) . '"' : '';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $default_value ) . '"' . $data_utm . ' class="pf-hidden-field">';
		return;
	}

	// Build cond_attrs early - używane przez wszystkie typy
	$cond_attrs = '';
	if ( isset( $field['condition'] ) && is_array( $field['condition'] ) && ! empty( $field['condition']['field'] ) ) {
		$cond_attrs = sprintf(
			' data-cond-field="%s" data-cond-op="%s" data-cond-value="%s"',
			esc_attr( $field['condition']['field'] ),
			esc_attr( $field['condition']['operator'] ),
			esc_attr( $field['condition']['value'] )
		);
	}

	// Section divider
	if ( $type === 'section_divider' ) {
		$title       = $label;
		$description = $placeholder;
		?>
		<div class="pf-field pf-field-section-divider"<?php echo $cond_attrs; ?>>
			<?php if ( $title ) : ?>
				<div class="pf-divider-title"><?php echo esc_html( $title ); ?></div>
			<?php endif; ?>
			<div class="pf-divider-line"></div>
			<?php if ( $description ) : ?>
				<div class="pf-divider-desc"><?php echo esc_html( $description ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		return;
	}

	// Image choice (checkbox/radio with images)
	if ( $type === 'image_choice' ) {
		$multiple   = ! empty( $field['multiple'] );
		$input_type = $multiple ? 'checkbox' : 'radio';
		$req_mark   = $required ? '<span class="pf-required-mark">*</span>' : '';
		?>
		<div class="pf-field pf-field-image-choice"<?php echo $cond_attrs; ?>>
			<fieldset>
				<legend><?php echo esc_html( $label ); ?> <?php echo $req_mark; ?></legend>
				<div class="pf-image-choice-grid">
					<?php foreach ( $options as $opt ) :
						// format: "Label|https://url.jpg" ili samo "Label"
						$parts     = explode( '|', $opt, 2 );
						$opt_label = trim( $parts[0] );
						$opt_img   = isset( $parts[1] ) ? esc_url( trim( $parts[1] ) ) : '';
					?>
						<label class="pf-image-choice-item">
							<input type="<?php echo esc_attr( $input_type ); ?>"
							       name="<?php echo esc_attr( $name ); ?><?php echo $multiple ? '[]' : ''; ?>"
							       value="<?php echo esc_attr( $opt_label ); ?>"
							       <?php echo $required && ! $multiple ? 'required' : ''; ?>>
							<span class="pf-image-choice-card">
								<?php if ( $opt_img ) : ?>
									<span class="pf-image-choice-img" style="background-image:url('<?php echo $opt_img; ?>')"></span>
								<?php else : ?>
									<span class="pf-image-choice-icon">
										<span class="dashicons dashicons-format-image"></span>
									</span>
								<?php endif; ?>
								<span class="pf-image-choice-label"><?php echo esc_html( $opt_label ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		</div>
		<?php
		return;
	}

	if ( $type === 'html' ) {
		echo '<div class="pf-field pf-field-html"' . $cond_attrs . '>' . wp_kses_post( $placeholder ) . '</div>';
		return;
	}

	$req_attr  = $required ? 'required' : '';
	$req_mark  = $required ? '<span class="pf-required-mark">*</span>' : '';
	$field_id  = 'pf-field-' . esc_attr( $name );
	?>
	<div class="pf-field pf-field-<?php echo esc_attr( $type ); ?>"<?php echo $cond_attrs; ?>>
		<?php if ( $type !== 'checkbox' && $type !== 'radio' ) : ?>
			<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?> <?php echo $req_mark; ?></label>
		<?php endif; ?>

		<?php switch ( $type ) :
			case 'textarea' : ?>
				<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $req_attr; ?>><?php echo esc_textarea( $default_value ); ?></textarea>
				<?php break;

			case 'select' : ?>
				<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $req_attr; ?>>
					<option value="">Odaberite...</option>
					<?php foreach ( $options as $opt ) : ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $default_value, $opt ); ?>><?php echo esc_html( $opt ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php break;

			case 'radio' : ?>
				<fieldset>
					<legend><?php echo esc_html( $label ); ?> <?php echo $req_mark; ?></legend>
					<?php foreach ( $options as $i => $opt ) : ?>
						<label class="pf-inline-option">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $opt ); ?>" <?php echo $i === 0 ? $req_attr : ''; ?> <?php checked( $default_value, $opt ); ?>>
							<?php echo esc_html( $opt ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php break;

			case 'checkbox' : ?>
				<fieldset>
					<legend><?php echo esc_html( $label ); ?> <?php echo $req_mark; ?></legend>
					<?php
					$default_arr = $default_value ? array_map( 'trim', explode( ',', $default_value ) ) : array();
					foreach ( $options as $opt ) : ?>
						<label class="pf-inline-option">
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $opt ); ?>" <?php checked( in_array( $opt, $default_arr, true ) ); ?>>
							<?php echo esc_html( $opt ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php break;

			case 'file' : ?>
				<input type="file" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $req_attr; ?> <?php echo ! empty( $placeholder ) ? 'accept="' . esc_attr( $placeholder ) . '"' : ''; ?>>
				<?php if ( ! empty( $placeholder ) ) : ?>
					<p class="pf-field-hint">Dozvoljeno: <?php echo esc_html( $placeholder ); ?> (maks. 10 MB)</p>
				<?php endif; ?>
				<?php break;

			default : // text, email, tel, number
				$input_type = in_array( $type, array( 'email', 'tel', 'number' ), true ) ? $type : 'text';
				?>
				<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" value="<?php echo esc_attr( $default_value ); ?>" <?php echo $req_attr; ?>>
				<?php break;
		endswitch; ?>
	</div>
	<?php
}


/* =========================================================
 *  AJAX: SLANJE FORME (frontend)
 * ========================================================= */
function pf_handle_submit_form() {
	global $wpdb;

	$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
	$nonce   = isset( $_POST['pf_nonce'] ) ? $_POST['pf_nonce'] : '';

	if ( ! $form_id || ! wp_verify_nonce( $nonce, 'pf_submit_' . $form_id ) ) {
		wp_send_json_error( array( 'message' => 'Sigurnosna provjera nije uspjela. Osvježite stranicu i pokušajte ponovno.' ), 403 );
	}

	// Honeypot
	if ( ! empty( $_POST['pf_hp'] ) ) {
		wp_send_json_error( array( 'message' => 'Greška.' ), 400 );
	}

	// Rate limiting po IP-u
	$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
	$rl_key   = 'pf_rl_' . md5( $ip );
	$attempts = (int) get_transient( $rl_key );

	if ( $attempts >= 8 ) {
		wp_send_json_error( array( 'message' => 'Previše pokušaja. Pokušajte kasnije.' ), 429 );
	}
	set_transient( $rl_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );

	$forms_table = $wpdb->prefix . 'pf_forms';
	$form        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $forms_table WHERE id = %d", $form_id ) );

	if ( ! $form ) {
		wp_send_json_error( array( 'message' => 'Forma ne postoji.' ), 404 );
	}

	$structure = pf_normalize_form_structure( json_decode( $form->fields_json, true ) );
	$fields    = pf_flatten_fields( $structure );

	// Sirove vrijednosti (po "name") za evaluaciju uvjeta
	$raw_values = array();
	foreach ( $fields as $field ) {
		if ( $field['type'] === 'html' || $field['type'] === 'section_divider' || empty( $field['name'] ) ) {
			continue;
		}
		if ( $field['type'] === 'checkbox' || $field['type'] === 'image_choice' ) {
			$raw_values[ $field['name'] ] = isset( $_POST[ $field['name'] ] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST[ $field['name'] ] ) ) : array();
		} else {
			$raw_values[ $field['name'] ] = isset( $_POST[ $field['name'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field['name'] ] ) ) : '';
		}
	}

	$clean_data = array();
	$missing    = array();

	foreach ( $fields as $field ) {
		if ( $field['type'] === 'html' || $field['type'] === 'section_divider' ) {
			continue;
		}

		$name     = $field['name'];
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$label    = $field['label'];

		// Ako polje ima uvjet koji nije ispunjen, ne traži ga kao obavezno
		if ( $required && ! empty( $field['condition'] ) && ! pf_condition_met( $field['condition'], $raw_values ) ) {
			$required = false;
		}

		if ( $type === 'checkbox' || $type === 'image_choice' ) {
			$raw   = isset( $_POST[ $name ] ) ? (array) $_POST[ $name ] : array();
			$value = array_map( 'sanitize_text_field', wp_unslash( $raw ) );
			if ( $required && empty( $value ) ) {
				$missing[] = $label;
			}
			$clean_data[ $label ] = $value;
			continue;
		}

		if ( $type === 'file' ) {
			$has_file = isset( $_FILES[ $name ] ) && $_FILES[ $name ]['error'] !== UPLOAD_ERR_NO_FILE;

			if ( ! $has_file ) {
				if ( $required ) {
					$missing[] = $label;
				}
				$clean_data[ $label ] = '';
				continue;
			}

			if ( $_FILES[ $name ]['error'] !== UPLOAD_ERR_OK ) {
				wp_send_json_error( array( 'message' => 'Greška kod uploada datoteke za polje "' . $label . '".' ), 400 );
			}

			$uploaded = pf_handle_file_upload( $_FILES[ $name ] );
			if ( is_wp_error( $uploaded ) ) {
				wp_send_json_error( array( 'message' => $uploaded->get_error_message() ), 400 );
			}

			$clean_data[ $label ] = $uploaded;
			continue;
		}

		$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';

		switch ( $type ) {
			case 'email':
				$value = sanitize_email( $raw );
				break;
			case 'textarea':
				$value = sanitize_textarea_field( $raw );
				break;
			default:
				$value = sanitize_text_field( $raw );
				break;
		}

		if ( $required && $value === '' ) {
			$missing[] = $label;
		}

		$clean_data[ $label ] = $value;
	}

	if ( ! empty( $missing ) ) {
		wp_send_json_error( array(
			'message' => 'Molimo popunite sva obavezna polja: ' . implode( ', ', $missing ),
		), 400 );
	}

	// Spremi u bazu
	$sub_table = $wpdb->prefix . 'pf_submissions';
	$wpdb->insert( $sub_table, array(
		'form_id'    => $form_id,
		'data_json'  => wp_json_encode( $clean_data, JSON_UNESCAPED_UNICODE ),
		'ip_address' => $ip,
		'status'     => 'new',
		'created_at' => current_time( 'mysql' ),
	) );

	// Email notifikacija (admin)
	$notify_email = get_option( 'pf_notify_email', get_option( 'admin_email' ) );
	$subject      = 'Novi upit - ' . $form->title;
	$body         = "Primljen je novi upit putem forme \"{$form->title}\":\n\n";
	foreach ( $clean_data as $label => $value ) {
		$body .= $label . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : $value ) . "\n";
	}
	wp_mail( $notify_email, $subject, $body );

	// Auto-odgovor korisniku
	$settings = json_decode( $form->settings_json, true );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	if ( ! empty( $settings['autoresponder_enabled'] ) ) {
		$recipient = '';
		foreach ( $fields as $f ) {
			if ( $f['type'] === 'email' && ! empty( $clean_data[ $f['label'] ] ) ) {
				$recipient = $clean_data[ $f['label'] ];
				break;
			}
		}

		if ( $recipient && is_email( $recipient ) ) {
			$ar_subject = ! empty( $settings['autoresponder_subject'] ) ? $settings['autoresponder_subject'] : 'Primili smo Vaš upit';
			$ar_message = ! empty( $settings['autoresponder_message'] ) ? $settings['autoresponder_message'] : 'Hvala na upitu, javljamo se uskoro.';

			foreach ( $clean_data as $label => $value ) {
				$replacement = is_array( $value ) ? implode( ', ', $value ) : $value;
				$ar_subject  = str_replace( '{' . $label . '}', $replacement, $ar_subject );
				$ar_message  = str_replace( '{' . $label . '}', $replacement, $ar_message );
			}

			wp_mail( $recipient, $ar_subject, $ar_message );
		}
	}

	// GA4 Measurement Protocol - server-side event
	pf_send_ga4_event( $form_id, $form->title, $clean_data );

	wp_send_json_success( array(
		'message'    => 'ok',
		'form_id'    => $form_id,
		'form_title' => $form->title,
	) );
}
add_action( 'wp_ajax_pf_submit_form', 'pf_handle_submit_form' );
add_action( 'wp_ajax_nopriv_pf_submit_form', 'pf_handle_submit_form' );


/* =========================================================
 *  GA4 Measurement Protocol - server-side slanje
 * ========================================================= */
function pf_send_ga4_event( $form_id, $form_title, $clean_data = array(), $is_test = false ) {
	$measurement_id = get_option( 'pf_ga4_measurement_id', '' );
	$api_secret     = get_option( 'pf_ga4_api_secret', '' );
	$event_name     = get_option( 'pf_ga4_event_name', 'generate_lead' );

	if ( ! $measurement_id || ! $api_secret ) {
		return array( 'skipped' => true, 'reason' => 'GA4 nije konfiguriran.' );
	}

	// GA4 event parametri
	$event_params = array(
		'form_id'    => (string) $form_id,
		'form_title' => (string) $form_title,
		'currency'   => 'EUR',
	);

	// Dodaj vrijednost ako postoji numeričko polje s "cijena/vrijednost/budget" u labelu
	foreach ( $clean_data as $label => $value ) {
		if ( is_numeric( $value ) && preg_match( '/cijena|vrijednost|budget|price|value/i', $label ) ) {
			$event_params['value'] = (float) $value;
			break;
		}
	}

	// client_id - anonimni ID za GA4 sesiju (dummy ako nema cookie)
	$client_id = '000000000.0000000000';
	if ( ! $is_test && isset( $_COOKIE['_ga'] ) ) {
		preg_match( '/GA\d+\.\d+\.(\d+\.\d+)/', sanitize_text_field( $_COOKIE['_ga'] ), $matches );
		if ( ! empty( $matches[1] ) ) {
			$client_id = $matches[1];
		}
	}

	$endpoint = $is_test
		? 'https://www.google-analytics.com/debug/mp/collect'
		: 'https://www.google-analytics.com/mp/collect';

	$payload = array(
		'client_id' => $client_id,
		'events'    => array(
			array(
				'name'   => sanitize_key( $event_name ),
				'params' => $event_params,
			),
		),
	);

	$response = wp_remote_post(
		$endpoint . '?measurement_id=' . rawurlencode( $measurement_id ) . '&api_secret=' . rawurlencode( $api_secret ),
		array(
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
			'timeout'     => 5,
			'blocking'    => $is_test,
			'sslverify'   => true,
		)
	);

	if ( $is_test ) {
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$validation = isset( $body['validationMessages'] ) ? $body['validationMessages'] : array();

		if ( empty( $validation ) ) {
			return array( 'success' => true, 'message' => 'Event prošao GA4 validaciju bez grešaka.' );
		} else {
			$msgs = array_map( function( $m ) { return $m['description'] ?? ''; }, $validation );
			return array( 'success' => false, 'error' => implode( '; ', $msgs ) );
		}
	}

	return array( 'sent' => true );
}


/* =========================================================
 *  AJAX: Test GA4 event (validacija)
 * ========================================================= */
function pf_test_ga4_event() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_test_ga4' );

	$result = pf_send_ga4_event( 0, 'Test forma', array(), true );

	if ( ! empty( $result['skipped'] ) ) {
		wp_send_json_error( $result['reason'] );
	} elseif ( ! empty( $result['success'] ) ) {
		wp_send_json_success( array( 'message' => $result['message'] ) );
	} else {
		wp_send_json_error( $result['error'] ?? 'Nepoznata greška.' );
	}
}
add_action( 'wp_ajax_pf_test_ga4_event', 'pf_test_ga4_event' );


/* =========================================================
 *  AJAX: Ručna provjera ažuriranja
 * ========================================================= */
function pf_check_update_manual() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_check_update' );

	// Resetiraj cache pa provjeri
	delete_transient( 'pf_update_info' );
	$updater = new PF_Auto_Updater();
	$info    = $updater->get_remote_info_public();

	if ( ! $info ) {
		wp_send_json_error( 'Nije moguće dohvatiti informacije. Provjeri GitHub repozitorij u Postavkama.' );
	}

	$current = PF_VERSION;
	$remote  = $info['version'];

	if ( version_compare( $current, $remote, '<' ) ) {
		wp_send_json_success( array(
			'has_update' => true,
			'message'    => '⬆ Dostupna je nova verzija ' . $remote . '! (Trenutna: ' . $current . ') — idi na Dodaci → dostupna ažuriranja.',
		) );
	} else {
		wp_send_json_success( array(
			'has_update' => false,
			'message'    => '✓ Koristiš najnoviju verziju (' . $current . ').',
		) );
	}
}
add_action( 'wp_ajax_pf_check_update_manual', 'pf_check_update_manual' );


/* =========================================================
 *  Meta Pixel + GA4 gtag - client-side inject u wp_head
 *  Učitava se samo na stranicama koje imaju shortcode forme
 * ========================================================= */
function pf_maybe_inject_pixels() {
	global $post;

	// Provjeri ima li shortcode na ovoj stranici
	if ( ! is_singular() || ! $post ) {
		return;
	}
	if ( ! has_shortcode( $post->post_content, 'pf_form' ) ) {
		return;
	}

	$meta_pixel_id    = get_option( 'pf_meta_pixel_id',    '' );
	$meta_pixel_event = get_option( 'pf_meta_pixel_event', 'Lead' );
	$ga4_id           = get_option( 'pf_ga4_measurement_id', '' );
	$ga4_event        = get_option( 'pf_ga4_event_name', 'generate_lead' );

	if ( ! $meta_pixel_id && ! $ga4_id ) {
		return;
	}
	?>
	<!-- Perković Forms - Marketing Pixels -->
	<?php if ( $meta_pixel_id ) : ?>
	<script>
	!function(f,b,e,v,n,t,s)
	{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
	n.callMethod.apply(n,arguments):n.queue.push(arguments)};
	if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
	n.queue=[];t=b.createElement(e);t.async=!0;
	t.src=v;s=b.getElementsByTagName(e)[0];
	s.parentNode.insertBefore(t,s)}(window, document,'script',
	'https://connect.facebook.net/en_US/fbevents.js');
	fbq('init', '<?php echo esc_js( $meta_pixel_id ); ?>');
	fbq('track', 'PageView');
	window.pfMetaPixelEvent = '<?php echo esc_js( $meta_pixel_event ); ?>';
	</script>
	<noscript><img height="1" width="1" style="display:none"
	  src="https://www.facebook.com/tr?id=<?php echo esc_attr( $meta_pixel_id ); ?>&ev=PageView&noscript=1"
	/></noscript>
	<?php endif; ?>

	<?php if ( $ga4_id ) : ?>
	<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $ga4_id ); ?>"></script>
	<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());
	gtag('config', '<?php echo esc_js( $ga4_id ); ?>');
	window.pfGa4EventName = '<?php echo esc_js( $ga4_event ); ?>';
	window.pfGa4Id = '<?php echo esc_js( $ga4_id ); ?>';
	</script>
	<?php endif; ?>
	<!-- / Perković Forms - Marketing Pixels -->
	<?php
}
add_action( 'wp_head', 'pf_maybe_inject_pixels' );


/* =========================================================
 *  AJAX: Osvježi nonce (rješava problem keširanih stranica)
 *  WP Fastest Cache servira staru stranicu sa staforim nonceom;
 *  ovaj endpoint vraća svjež nonce pri učitavanju forme.
 * ========================================================= */
function pf_refresh_nonce() {
	$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
	if ( ! $form_id ) {
		wp_send_json_error();
	}
	wp_send_json_success( array(
		'nonce' => wp_create_nonce( 'pf_submit_' . $form_id ),
	) );
}
add_action( 'wp_ajax_pf_refresh_nonce', 'pf_refresh_nonce' );
add_action( 'wp_ajax_nopriv_pf_refresh_nonce', 'pf_refresh_nonce' );


/* =========================================================
 *  EXPORT: CSV
 * ========================================================= */
function pf_handle_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu akciju.' );
	}
	check_admin_referer( 'pf_export_csv' );

	global $wpdb;
	$sub_table  = $wpdb->prefix . 'pf_submissions';
	$form_table = $wpdb->prefix . 'pf_forms';

	$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

	$where = '';
	if ( $form_id ) {
		$where = $wpdb->prepare( 'WHERE form_id = %d', $form_id );
	}

	$submissions = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		"SELECT * FROM $sub_table $where ORDER BY id DESC LIMIT 5000"
	);

	$filename = 'upiti-' . ( $form_id ? 'forma-' . $form_id : 'sve' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$output = fopen( 'php://output', 'w' );
	// BOM za pravilan prikaz hrvatskih znakova u Excelu
	fputs( $output, "\xEF\xBB\xBF" );

	if ( $form_id ) {
		// Fiksni redoslijed stupaca prema poljima forme
		$form   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $form_table WHERE id = %d", $form_id ) );
		$fields = $form ? pf_flatten_fields( pf_normalize_form_structure( json_decode( $form->fields_json, true ) ) ) : array();
		$labels = array();
		foreach ( (array) $fields as $f ) {
			if ( $f['type'] !== 'html' && $f['type'] !== 'section_divider' && ! empty( $f['name'] ) ) {
				$labels[] = $f['label'];
			}
		}

		fputcsv( $output, array_merge( array( 'ID', 'Datum', 'Status' ), $labels ) );

		foreach ( $submissions as $sub ) {
			$data = json_decode( $sub->data_json, true );
			$row  = array( $sub->id, $sub->created_at, $sub->status );
			foreach ( $labels as $label ) {
				$val   = isset( $data[ $label ] ) ? $data[ $label ] : '';
				$row[] = is_array( $val ) ? implode( ', ', $val ) : $val;
			}
			fputcsv( $output, $row );
		}
	} else {
		fputcsv( $output, array( 'ID', 'Forma', 'Datum', 'Status', 'Podaci' ) );

		$form_titles = array();
		foreach ( $submissions as $sub ) {
			if ( ! isset( $form_titles[ $sub->form_id ] ) ) {
				$form_titles[ $sub->form_id ] = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM $form_table WHERE id = %d", $sub->form_id ) );
			}
			$data    = json_decode( $sub->data_json, true );
			$pairs   = array();
			foreach ( (array) $data as $label => $val ) {
				$pairs[] = $label . ': ' . ( is_array( $val ) ? implode( ', ', $val ) : $val );
			}
			fputcsv( $output, array( $sub->id, $form_titles[ $sub->form_id ], $sub->created_at, $sub->status, implode( ' | ', $pairs ) ) );
		}
	}

	fclose( $output );
	exit;
}
add_action( 'admin_post_pf_export_csv', 'pf_handle_export_csv' );


/* =========================================================
 *  AJAX: Promjena statusa upita (inline, bez page reload)
 * ========================================================= */
function pf_set_status_ajax() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Nemaš dozvolu.' ), 403 );
	}

	global $wpdb;
	$sub_table = $wpdb->prefix . 'pf_submissions';

	$id     = absint( $_GET['id'] ?? 0 );
	$status = sanitize_key( $_GET['status'] ?? '' );
	$nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

	$allowed = array( 'new', 'contacted', 'offer_sent', 'closed' );

	if ( ! $id || ! in_array( $status, $allowed, true ) || ! wp_verify_nonce( $nonce, 'pf_status_' . $id ) ) {
		wp_send_json_error( array( 'message' => 'Neispravan zahtjev.' ) );
	}

	$wpdb->update( $sub_table, array( 'status' => $status ), array( 'id' => $id ) );
	wp_send_json_success();
}
add_action( 'wp_ajax_pf_set_status_ajax', 'pf_set_status_ajax' );


/* =========================================================
 *  AJAX: Predlošci polja (Templates)
 * ========================================================= */
function pf_get_templates() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_templates', 'nonce' );
	$templates = get_option( 'pf_field_templates', array() );
	wp_send_json_success( $templates );
}
add_action( 'wp_ajax_pf_get_templates', 'pf_get_templates' );

function pf_save_template() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_templates', 'nonce' );

	$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$fields = isset( $_POST['fields'] ) ? json_decode( wp_unslash( $_POST['fields'] ), true ) : array();

	if ( ! $name || ! is_array( $fields ) ) {
		wp_send_json_error( 'Neispravan zahtjev.' );
	}

	$clean_fields = array_map( 'pf_sanitize_field', $fields );

	$templates = get_option( 'pf_field_templates', array() );
	$id = 'tpl_' . time();
	$templates[ $id ] = array(
		'id'     => $id,
		'name'   => $name,
		'fields' => $clean_fields,
	);
	update_option( 'pf_field_templates', $templates );
	wp_send_json_success( $templates[ $id ] );
}
add_action( 'wp_ajax_pf_save_template', 'pf_save_template' );

function pf_delete_template() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_templates', 'nonce' );
	$id = sanitize_key( $_POST['template_id'] ?? '' );

	$templates = get_option( 'pf_field_templates', array() );
	unset( $templates[ $id ] );
	update_option( 'pf_field_templates', $templates );
	wp_send_json_success();
}
add_action( 'wp_ajax_pf_delete_template', 'pf_delete_template' );


/* =========================================================
 *  AJAX: Prima funnel eventi s frontenda i sprema u pf_analytics
 * ========================================================= */
function pf_track_event() {
	global $wpdb;
	$table = $wpdb->prefix . 'pf_analytics';

	// Prihvaćamo POST (JSON body ili form fields)
	$raw = file_get_contents( 'php://input' );
	if ( $raw ) {
		$data = json_decode( $raw, true );
	} else {
		$data = $_POST;
	}

	$event_type  = isset( $data['event'] ) ? sanitize_key( $data['event'] ) : '';
	$form_id     = isset( $data['form_id'] ) ? absint( $data['form_id'] ) : 0;

	// Dozvoljeni eventi
	$allowed = array( 'pf_form_view', 'pf_form_start', 'pf_step_complete', 'pf_form_abandon', 'pf_form_submit' );
	if ( ! $form_id || ! in_array( $event_type, $allowed, true ) ) {
		wp_send_json_error( 'Neispravan zahtjev.' );
	}

	// Provjeri da forma stvarno postoji (sprječava onečišćenje podataka botovima)
	$forms_table = $wpdb->prefix . 'pf_forms';
	$form_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $forms_table WHERE id = %d", $form_id ) );
	if ( ! $form_exists ) {
		http_response_code( 204 );
		exit;
	}

	// Lagani rate limiting po IP-u (maks. 120 eventi / 10 min)
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( $ip ) {
		$rl_key = 'pf_track_rl_' . md5( $ip );
		$count  = (int) get_transient( $rl_key );
		if ( $count >= 120 ) {
			http_response_code( 429 );
			exit;
		}
		set_transient( $rl_key, $count + 1, 10 * MINUTE_IN_SECONDS );
	}

	$session_id = isset( $data['session_id'] ) ? sanitize_text_field( $data['session_id'] ) : '';
	$ab_variant = isset( $data['ab_variant'] ) ? strtoupper( sanitize_key( $data['ab_variant'] ) ) : null;
	if ( ! in_array( $ab_variant, array( 'A', 'B' ), true ) ) {
		$ab_variant = null;
	}

	$wpdb->insert( $table, array(
		'form_id'      => $form_id,
		'event_type'   => $event_type,
		'step'         => isset( $data['step_from'] ) ? absint( $data['step_from'] ) : ( isset( $data['last_step'] ) ? absint( $data['last_step'] ) : null ),
		'total_steps'  => isset( $data['total_steps'] ) ? absint( $data['total_steps'] ) : null,
		'fill_percent' => isset( $data['fill_percent'] ) ? min( 100, absint( $data['fill_percent'] ) ) : null,
		'session_id'   => substr( $session_id, 0, 64 ),
		'ab_variant'   => $ab_variant,
		'utm_source'   => isset( $data['utm_source'] )   ? sanitize_text_field( $data['utm_source'] )   : '',
		'utm_medium'   => isset( $data['utm_medium'] )   ? sanitize_text_field( $data['utm_medium'] )   : '',
		'utm_campaign' => isset( $data['utm_campaign'] ) ? sanitize_text_field( $data['utm_campaign'] ) : '',
		'utm_term'     => isset( $data['utm_term'] )     ? sanitize_text_field( $data['utm_term'] )     : '',
		'utm_content'  => isset( $data['utm_content'] )  ? sanitize_text_field( $data['utm_content'] )  : '',
		'landing_page' => isset( $data['landing_page'] ) ? esc_url_raw( substr( $data['landing_page'], 0, 500 ) ) : '',
		'page_url'     => isset( $data['page_url'] )     ? esc_url_raw( substr( $data['page_url'], 0, 500 ) )     : '',
		'referrer'     => isset( $data['referrer'] )     ? esc_url_raw( substr( $data['referrer'], 0, 500 ) )     : '',
		'created_at'   => current_time( 'mysql' ),
	) );

	// Uspješan odgovor (za sendBeacon - ne treba JSON, samo 200)
	http_response_code( 200 );
	exit;
}
add_action( 'wp_ajax_pf_track_event',        'pf_track_event' );
add_action( 'wp_ajax_nopriv_pf_track_event', 'pf_track_event' );

// Beacon endpoint (prima raw JSON payload)
add_action( 'wp_ajax_pf_track_abandon',        'pf_track_event' );
add_action( 'wp_ajax_nopriv_pf_track_abandon', 'pf_track_event' );


/* =========================================================
 *  AJAX: Dohvati analytics podatke za dashboard
 * ========================================================= */
function pf_get_analytics_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_analytics', 'nonce' );

	global $wpdb;
	$a_table = $wpdb->prefix . 'pf_analytics';
	$s_table = $wpdb->prefix . 'pf_submissions';
	$f_table = $wpdb->prefix . 'pf_forms';

	$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
	$days    = isset( $_GET['days'] ) ? min( 365, absint( $_GET['days'] ) ) : 30;

	$form_where = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : '';
	$date_from  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

	// 1. Funnel totali
	$funnel_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT event_type, COUNT(*) as cnt
		 FROM $a_table
		 WHERE created_at >= %s $form_where
		 GROUP BY event_type",
		$date_from
	), ARRAY_A );

	$funnel = array(
		'pf_form_view'     => 0,
		'pf_form_start'    => 0,
		'pf_step_complete' => 0,
		'pf_form_submit'   => 0,
		'pf_form_abandon'  => 0,
	);
	foreach ( $funnel_raw as $row ) {
		if ( isset( $funnel[ $row['event_type'] ] ) ) {
			$funnel[ $row['event_type'] ] = (int) $row['cnt'];
		}
	}

	// Conversion rate (view → submit)
	$funnel['conversion_rate'] = $funnel['pf_form_view'] > 0
		? round( ( $funnel['pf_form_submit'] / $funnel['pf_form_view'] ) * 100, 1 )
		: 0;

	// 2. Konverzije po danima (line chart)
	$daily_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(created_at) as day, event_type, COUNT(*) as cnt
		 FROM $a_table
		 WHERE created_at >= %s
		   AND event_type IN ('pf_form_view','pf_form_start','pf_form_submit','pf_form_abandon')
		   $form_where
		 GROUP BY day, event_type
		 ORDER BY day ASC",
		$date_from
	), ARRAY_A );

	$daily = array();
	foreach ( $daily_raw as $row ) {
		$day = $row['day'];
		if ( ! isset( $daily[ $day ] ) ) {
			$daily[ $day ] = array( 'pf_form_view' => 0, 'pf_form_start' => 0, 'pf_form_submit' => 0, 'pf_form_abandon' => 0 );
		}
		$daily[ $day ][ $row['event_type'] ] = (int) $row['cnt'];
	}

	// 3. Step drop-off (koji korak korisnici napuštaju)
	$steps_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT step, COUNT(*) as cnt
		 FROM $a_table
		 WHERE event_type = 'pf_form_abandon'
		   AND step IS NOT NULL
		   AND created_at >= %s
		   $form_where
		 GROUP BY step
		 ORDER BY step ASC",
		$date_from
	), ARRAY_A );

	// 4. Prosječni fill_percent pri napuštanju
	$avg_fill = $wpdb->get_var( $wpdb->prepare(
		"SELECT AVG(fill_percent)
		 FROM $a_table
		 WHERE event_type = 'pf_form_abandon'
		   AND fill_percent IS NOT NULL
		   AND created_at >= %s
		   $form_where",
		$date_from
	) );

	// 5. Top odabiri u select/radio/checkbox (iz pf_submissions)
	$choice_stats = array();
	if ( $form_id ) {
		$form = $wpdb->get_row( $wpdb->prepare( "SELECT fields_json FROM $f_table WHERE id = %d", $form_id ) );
		if ( $form ) {
			$structure    = pf_normalize_form_structure( json_decode( $form->fields_json, true ) );
			$choice_fields = array_filter( pf_flatten_fields( $structure ), function( $f ) {
				return in_array( $f['type'], array( 'select', 'radio', 'checkbox', 'image_choice' ), true ) && ! empty( $f['label'] );
			} );

			$subs = $wpdb->get_results( $wpdb->prepare(
				"SELECT data_json FROM $s_table WHERE form_id = %d AND created_at >= %s",
				$form_id, $date_from
			) );

			foreach ( $choice_fields as $field ) {
				$label  = $field['label'];
				$counts = array();
				foreach ( $subs as $sub ) {
					$data = json_decode( $sub->data_json, true );
					if ( ! isset( $data[ $label ] ) ) continue;
					$vals = (array) $data[ $label ];
					foreach ( $vals as $v ) {
						$v = trim( $v );
						if ( $v === '' ) continue;
						$counts[ $v ] = ( $counts[ $v ] ?? 0 ) + 1;
					}
				}
				arsort( $counts );
				if ( $counts ) {
					$choice_stats[] = array(
						'label'  => $label,
						'counts' => $counts,
					);
				}
			}
		}
	}

	// 6. Prosječno vrijeme ispunjavanja (start → submit, isti session_id)
	$avg_time = $wpdb->get_var( $wpdb->prepare(
		"SELECT AVG(TIMESTAMPDIFF(SECOND, s.created_at, e.created_at))
		 FROM $a_table s
		 JOIN $a_table e ON s.session_id = e.session_id AND s.form_id = e.form_id
		 WHERE s.event_type = 'pf_form_start'
		   AND e.event_type = 'pf_form_submit'
		   AND s.session_id != ''
		   AND s.created_at >= %s
		   $form_where",
		$date_from
	) );

	wp_send_json_success( array(
		'funnel'       => $funnel,
		'daily'        => $daily,
		'steps_raw'    => $steps_raw,
		'avg_fill'     => $avg_fill ? round( (float) $avg_fill ) : null,
		'avg_time_sec' => $avg_time ? round( (float) $avg_time ) : null,
		'choice_stats' => $choice_stats,
		'date_from'    => $date_from,
		'days'         => $days,
	) );
}
add_action( 'wp_ajax_pf_get_analytics_data', 'pf_get_analytics_data' );


/* =========================================================
 *  AJAX: Attribution podatke za Tab 2
 * ========================================================= */
function pf_get_attribution_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_analytics', 'nonce' );

	global $wpdb;
	$a_table = $wpdb->prefix . 'pf_analytics';

	$form_id    = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
	$days       = isset( $_GET['days'] ) ? min( 365, absint( $_GET['days'] ) ) : 30;
	$date_from  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
	$form_where = $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : '';

	// 1. Performanse po UTM source × medium kombinaciji
	$by_source = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			COALESCE(NULLIF(utm_source,''), '(direct)') as source,
			COALESCE(NULLIF(utm_medium,''), '(none)')   as medium,
			COUNT(CASE WHEN event_type = 'pf_form_view'    THEN 1 END) as views,
			COUNT(CASE WHEN event_type = 'pf_form_start'   THEN 1 END) as starts,
			COUNT(CASE WHEN event_type = 'pf_form_submit'  THEN 1 END) as submits,
			COUNT(CASE WHEN event_type = 'pf_form_abandon' THEN 1 END) as abandons
		 FROM $a_table
		 WHERE created_at >= %s $form_where
		 GROUP BY source, medium
		 ORDER BY submits DESC, views DESC
		 LIMIT 50",
		$date_from
	), ARRAY_A );

	// Dodaj conversion_rate za svaki red
	foreach ( $by_source as &$row ) {
		$row['views']    = (int) $row['views'];
		$row['starts']   = (int) $row['starts'];
		$row['submits']  = (int) $row['submits'];
		$row['abandons'] = (int) $row['abandons'];
		$row['conversion_rate'] = $row['views'] > 0
			? round( ( $row['submits'] / $row['views'] ) * 100, 1 )
			: 0;
	}
	unset( $row );

	// 2. Performanse po kampanji
	$by_campaign = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			COALESCE(NULLIF(utm_campaign,''), '(bez kampanje)') as campaign,
			COALESCE(NULLIF(utm_source,''), '(direct)')         as source,
			COUNT(CASE WHEN event_type = 'pf_form_view'   THEN 1 END) as views,
			COUNT(CASE WHEN event_type = 'pf_form_submit' THEN 1 END) as submits
		 FROM $a_table
		 WHERE created_at >= %s $form_where
		 GROUP BY campaign, source
		 ORDER BY submits DESC, views DESC
		 LIMIT 30",
		$date_from
	), ARRAY_A );

	foreach ( $by_campaign as &$row ) {
		$row['views']   = (int) $row['views'];
		$row['submits'] = (int) $row['submits'];
		$row['conversion_rate'] = $row['views'] > 0
			? round( ( $row['submits'] / $row['views'] ) * 100, 1 )
			: 0;
	}
	unset( $row );

	// 3. Top 5 izvora po konverzijama (za pie chart)
	$top5 = array_slice( $by_source, 0, 5 );

	// 4. Top landing pagovi (stranice koje vode do konverzije)
	$by_landing = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			COALESCE(NULLIF(landing_page,''), page_url, '(nepoznato)') as page,
			COUNT(CASE WHEN event_type = 'pf_form_view'   THEN 1 END) as views,
			COUNT(CASE WHEN event_type = 'pf_form_submit' THEN 1 END) as submits
		 FROM $a_table
		 WHERE created_at >= %s $form_where
		 GROUP BY page
		 ORDER BY submits DESC, views DESC
		 LIMIT 20",
		$date_from
	), ARRAY_A );

	foreach ( $by_landing as &$row ) {
		$row['views']   = (int) $row['views'];
		$row['submits'] = (int) $row['submits'];
		$row['conversion_rate'] = $row['views'] > 0
			? round( ( $row['submits'] / $row['views'] ) * 100, 1 )
			: 0;
		// Skrati URL za prikaz
		$row['page_short'] = preg_replace( '#^https?://[^/]+#', '', $row['page'] ) ?: '/';
	}
	unset( $row );

	// 5. Referrers (domene koje šalju promet)
	$by_referrer = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			COALESCE(NULLIF(referrer,''), '(direct)') as referrer,
			COUNT(CASE WHEN event_type = 'pf_form_view'   THEN 1 END) as views,
			COUNT(CASE WHEN event_type = 'pf_form_submit' THEN 1 END) as submits
		 FROM $a_table
		 WHERE created_at >= %s $form_where
		 GROUP BY referrer
		 ORDER BY submits DESC, views DESC
		 LIMIT 20",
		$date_from
	), ARRAY_A );

	foreach ( $by_referrer as &$row ) {
		$row['views']   = (int) $row['views'];
		$row['submits'] = (int) $row['submits'];
		// Izvuci samo domenu iz referrera
		$parsed = wp_parse_url( $row['referrer'] );
		$row['referrer_domain'] = isset( $parsed['host'] ) ? $parsed['host'] : $row['referrer'];
	}
	unset( $row );

	wp_send_json_success( array(
		'by_source'   => $by_source,
		'by_campaign' => $by_campaign,
		'top5'        => $top5,
		'by_landing'  => $by_landing,
		'by_referrer' => $by_referrer,
		'date_from'   => $date_from,
		'days'        => $days,
	) );
}
add_action( 'wp_ajax_pf_get_attribution_data', 'pf_get_attribution_data' );


/* =========================================================
 *  Export attribution kao CSV
 * ========================================================= */
function pf_export_attribution_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu.' );
	}
	check_admin_referer( 'pf_export_attribution' );

	global $wpdb;
	$a_table   = $wpdb->prefix . 'pf_analytics';
	$form_id   = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
	$days      = isset( $_GET['days'] ) ? min( 365, absint( $_GET['days'] ) ) : 30;
	$date_from = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );
	$form_where= $form_id ? $wpdb->prepare( 'AND form_id = %d', $form_id ) : '';

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT
			utm_source, utm_medium, utm_campaign, utm_term, utm_content,
			landing_page, page_url, referrer, event_type,
			session_id, created_at
		 FROM $a_table
		 WHERE created_at >= %s
		   AND event_type IN ('pf_form_view','pf_form_start','pf_form_submit','pf_form_abandon')
		   $form_where
		 ORDER BY created_at DESC
		 LIMIT 5000",
		$date_from
	), ARRAY_A );

	$filename = 'attribution-forma' . ( $form_id ?: '-sve' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$out = fopen( 'php://output', 'w' );
	fputs( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array( 'Datum', 'Event', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content', 'Landing Page', 'Page URL', 'Referrer', 'Session ID' ) );

	foreach ( $rows as $row ) {
		fputcsv( $out, array(
			$row['created_at'],
			$row['event_type'],
			$row['utm_source'],
			$row['utm_medium'],
			$row['utm_campaign'],
			$row['utm_term'],
			$row['utm_content'],
			$row['landing_page'],
			$row['page_url'],
			$row['referrer'],
			$row['session_id'],
		) );
	}
	fclose( $out );
	exit;
}
add_action( 'admin_post_pf_export_attribution', 'pf_export_attribution_csv' );


/* =========================================================
 *  AJAX: A/B test podatke za usporedbu
 * ========================================================= */
function pf_get_ab_data() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_analytics', 'nonce' );

	global $wpdb;
	$a_table  = $wpdb->prefix . 'pf_analytics';
	$ab_table = $wpdb->prefix . 'pf_ab_tests';

	$test_id   = isset( $_GET['test_id'] ) ? absint( $_GET['test_id'] ) : 0;
	$days      = isset( $_GET['days'] ) ? min( 365, absint( $_GET['days'] ) ) : 30;
	$date_from = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

	$test = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ab_table WHERE id = %d", $test_id ) );
	if ( ! $test ) {
		wp_send_json_error( 'Test nije pronađen.' );
	}

	$variants = array( 'A' => (int) $test->form_a, 'B' => (int) $test->form_b );
	$result   = array();

	foreach ( $variants as $variant => $fid ) {
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(CASE WHEN event_type='pf_form_view'    THEN 1 END) as views,
				COUNT(CASE WHEN event_type='pf_form_start'   THEN 1 END) as starts,
				COUNT(CASE WHEN event_type='pf_form_submit'  THEN 1 END) as submits,
				COUNT(CASE WHEN event_type='pf_form_abandon' THEN 1 END) as abandons
			 FROM $a_table
			 WHERE form_id = %d AND created_at >= %s",
			$fid, $date_from
		), ARRAY_A );

		$views   = (int) ( $row['views']   ?? 0 );
		$submits = (int) ( $row['submits'] ?? 0 );
		$cr      = $views > 0 ? round( ( $submits / $views ) * 100, 2 ) : 0;

		// Daily trend
		$daily_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) as day, event_type, COUNT(*) as cnt
			 FROM $a_table
			 WHERE form_id = %d AND created_at >= %s
			   AND event_type IN ('pf_form_view','pf_form_submit')
			 GROUP BY day, event_type ORDER BY day ASC",
			$fid, $date_from
		), ARRAY_A );

		$daily = array();
		foreach ( $daily_raw as $dr ) {
			if ( ! isset( $daily[ $dr['day'] ] ) ) {
				$daily[ $dr['day'] ] = array( 'pf_form_view' => 0, 'pf_form_submit' => 0 );
			}
			$daily[ $dr['day'] ][ $dr['event_type'] ] = (int) $dr['cnt'];
		}

		$result[ $variant ] = array(
			'form_id'         => $fid,
			'views'           => $views,
			'starts'          => (int) ( $row['starts']  ?? 0 ),
			'submits'         => $submits,
			'abandons'        => (int) ( $row['abandons'] ?? 0 ),
			'conversion_rate' => $cr,
			'daily'           => $daily,
		);
	}

	// Chi-square test za statističku signifikantnost
	$va = $result['A'];
	$vb = $result['B'];
	$chi2   = null;
	$pvalue = null;
	$significant = false;

	if ( $va['views'] > 0 && $vb['views'] > 0 ) {
		// 2x2 contingency table: [submit, no-submit] x [A, B]
		$a1 = $va['submits'];
		$a2 = $va['views'] - $va['submits'];
		$b1 = $vb['submits'];
		$b2 = $vb['views'] - $vb['submits'];
		$n  = $a1 + $a2 + $b1 + $b2;

		if ( $n > 0 ) {
			$e_a1 = ( $a1 + $b1 ) * ( $a1 + $a2 ) / $n;
			$e_a2 = ( $a2 + $b2 ) * ( $a1 + $a2 ) / $n;
			$e_b1 = ( $a1 + $b1 ) * ( $b1 + $b2 ) / $n;
			$e_b2 = ( $a2 + $b2 ) * ( $b1 + $b2 ) / $n;

			if ( $e_a1 > 0 && $e_a2 > 0 && $e_b1 > 0 && $e_b2 > 0 ) {
				$chi2 = round(
					pow( $a1 - $e_a1, 2 ) / $e_a1 +
					pow( $a2 - $e_a2, 2 ) / $e_a2 +
					pow( $b1 - $e_b1, 2 ) / $e_b1 +
					pow( $b2 - $e_b2, 2 ) / $e_b2,
					3
				);
				// p < 0.05 ako chi2 > 3.841 (df=1)
				$significant = $chi2 >= 3.841;
				$pvalue       = $chi2 >= 10.828 ? '< 0.001' : ( $chi2 >= 6.635 ? '< 0.01' : ( $chi2 >= 3.841 ? '< 0.05' : '> 0.05' ) );
			}
		}
	}

	// Preporuka
	$recommendation = '';
	if ( $significant ) {
		$winner         = $va['conversion_rate'] >= $vb['conversion_rate'] ? 'A' : 'B';
		$diff           = abs( round( $va['conversion_rate'] - $vb['conversion_rate'], 1 ) );
		$recommendation = "Varijanta $winner je statistički značajno bolja za $diff postotnih bodova (p $pvalue). Preporučujemo koristiti Varijantu $winner.";
	} elseif ( $va['views'] + $vb['views'] < 100 ) {
		$recommendation = 'Prikupite više podataka - preporuka je moguća nakon min. 100 pregleda po varijanti.';
	} else {
		$recommendation = "Razlika nije statistički značajna (p $pvalue). Nastavite test ili prihvatite trenutnu formu.";
	}

	wp_send_json_success( array(
		'test'           => $test,
		'variants'       => $result,
		'chi2'           => $chi2,
		'pvalue'         => $pvalue,
		'significant'    => $significant,
		'recommendation' => $recommendation,
		'days'           => $days,
	) );
}
add_action( 'wp_ajax_pf_get_ab_data', 'pf_get_ab_data' );


/* =========================================================
 *  AJAX: Spremi/završi/obriši A/B test
 * ========================================================= */
function pf_save_ab_test() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_analytics', 'nonce' );

	global $wpdb;
	$ab_table = $wpdb->prefix . 'pf_ab_tests';

	$action  = sanitize_key( $_POST['ab_action'] ?? 'create' );
	$test_id = absint( $_POST['test_id'] ?? 0 );

	if ( $action === 'create' ) {
		$name   = sanitize_text_field( $_POST['name']   ?? '' );
		$form_a = absint( $_POST['form_a'] ?? 0 );
		$form_b = absint( $_POST['form_b'] ?? 0 );
		$split  = min( 90, max( 10, absint( $_POST['traffic_split'] ?? 50 ) ) );

		if ( ! $name || ! $form_a || ! $form_b || $form_a === $form_b ) {
			wp_send_json_error( 'Neispravan zahtjev - provjeri nazive i forme.' );
		}

		$wpdb->insert( $ab_table, array(
			'name'          => $name,
			'form_a'        => $form_a,
			'form_b'        => $form_b,
			'traffic_split' => $split,
			'status'        => 'active',
			'created_at'    => current_time( 'mysql' ),
		) );

		wp_send_json_success( array( 'test_id' => $wpdb->insert_id ) );

	} elseif ( $action === 'end' ) {
		$winner = strtoupper( sanitize_key( $_POST['winner'] ?? '' ) );
		if ( ! in_array( $winner, array( 'A', 'B' ), true ) ) {
			$winner = null;
		}
		$wpdb->update( $ab_table, array( 'status' => 'ended', 'winner' => $winner ), array( 'id' => $test_id ) );
		wp_send_json_success();

	} elseif ( $action === 'delete' ) {
		$wpdb->delete( $ab_table, array( 'id' => $test_id ) );
		wp_send_json_success();
	}

	wp_send_json_error( 'Nepoznata akcija.' );
}
add_action( 'wp_ajax_pf_save_ab_test', 'pf_save_ab_test' );


/* =========================================================
 *  STRANICA: A/B Testovi
 * ========================================================= */
function pf_render_ab_tests_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu stranicu.' );
	}

	global $wpdb;
	$ab_table = $wpdb->prefix . 'pf_ab_tests';
	$f_table  = $wpdb->prefix . 'pf_forms';
	$tests    = $wpdb->get_results( "SELECT * FROM $ab_table ORDER BY id DESC" );
	$forms    = $wpdb->get_results( "SELECT id, title FROM $f_table ORDER BY id DESC" );

	// Mapa id→title
	$form_titles = array();
	foreach ( $forms as $f ) {
		$form_titles[ $f->id ] = $f->title;
	}
	?>
	<div class="wrap pf-wrap pf-ab-wrap">
		<h1>A/B Testovi</h1>
		<p class="description">Uspoređuj dvije varijante forme. Forma A i Forma B prikazuju se random posjetiteljima, a plugin bilježi tko konvertira bolje.</p>

		<!-- Kreiraj novi test -->
		<div class="pf-ab-create-card">
			<h2>Kreiraj novi test</h2>
			<div class="pf-ab-create-form">
				<div class="pf-ab-create-row">
					<label>Naziv testa</label>
					<input type="text" id="pf-ab-name" placeholder="npr. Test gumba - Pošalji vs Zatraži ponudu" class="regular-text">
				</div>
				<div class="pf-ab-create-grid">
					<div>
						<label>Varijanta A</label>
						<select id="pf-ab-form-a">
							<option value="">— odaberi formu —</option>
							<?php foreach ( $forms as $f ) : ?>
								<option value="<?php echo esc_attr( $f->id ); ?>"><?php echo esc_html( $f->title ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Shortcode: <code>[pf_form id="X" ab_variant="A"]</code></p>
					</div>
					<div class="pf-ab-vs">VS</div>
					<div>
						<label>Varijanta B</label>
						<select id="pf-ab-form-b">
							<option value="">— odaberi formu —</option>
							<?php foreach ( $forms as $f ) : ?>
								<option value="<?php echo esc_attr( $f->id ); ?>"><?php echo esc_html( $f->title ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Shortcode: <code>[pf_form id="Y" ab_variant="B"]</code></p>
					</div>
				</div>
				<div class="pf-ab-create-row">
					<label>Traffic split: <strong id="pf-ab-split-label">50% A / 50% B</strong></label>
					<input type="range" id="pf-ab-split" min="10" max="90" value="50" step="5">
				</div>
				<button type="button" class="button button-primary" id="pf-ab-create-btn">Pokreni test</button>
			</div>
		</div>

		<!-- Lista testova -->
		<?php if ( empty( $tests ) ) : ?>
			<div class="pf-ab-empty">
				<span class="dashicons dashicons-chart-bar" style="font-size:40px;color:#DDD4C8;"></span>
				<p>Nema aktivnih A/B testova. Kreiraj prvi test gore.</p>
			</div>
		<?php else : ?>
			<h2 style="margin-top:32px;">Aktivni i završeni testovi</h2>
			<div class="pf-ab-tests-list">
				<?php foreach ( $tests as $test ) :
					$status_label = $test->status === 'active' ? 'Aktivan' : 'Završen';
					$status_color = $test->status === 'active' ? '#338B45' : '#9C9182';
					$title_a = $form_titles[ $test->form_a ] ?? 'Forma #' . $test->form_a;
					$title_b = $form_titles[ $test->form_b ] ?? 'Forma #' . $test->form_b;
				?>
				<div class="pf-ab-test-card" data-test-id="<?php echo esc_attr( $test->id ); ?>">
					<div class="pf-ab-test-header">
						<div class="pf-ab-test-meta">
							<h3><?php echo esc_html( $test->name ); ?></h3>
							<span class="pf-ab-status-badge" style="background:<?php echo esc_attr( $status_color ); ?>20;color:<?php echo esc_attr( $status_color ); ?>">
								<?php echo esc_html( $status_label ); ?>
							</span>
							<?php if ( $test->winner ) : ?>
								<span class="pf-ab-winner-badge">🏆 Pobjednik: Varijanta <?php echo esc_html( $test->winner ); ?></span>
							<?php endif; ?>
						</div>
						<div class="pf-ab-test-actions">
							<?php if ( $test->status === 'active' ) : ?>
								<button class="button pf-ab-end-btn" data-test-id="<?php echo esc_attr( $test->id ); ?>">Završi test</button>
							<?php endif; ?>
							<button class="button pf-ab-delete-btn" data-test-id="<?php echo esc_attr( $test->id ); ?>" style="color:#b32d2e;">Obriši</button>
						</div>
					</div>

					<div class="pf-ab-variants-preview">
						<div class="pf-ab-variant-pill pf-variant-a">A: <?php echo esc_html( $title_a ); ?></div>
						<div class="pf-ab-split-info"><?php echo esc_html( $test->traffic_split ); ?>% / <?php echo esc_html( 100 - $test->traffic_split ); ?>%</div>
						<div class="pf-ab-variant-pill pf-variant-b">B: <?php echo esc_html( $title_b ); ?></div>
					</div>

					<!-- Rezultati (učitavaju se AJAX-om) -->
					<div class="pf-ab-results" data-test-id="<?php echo esc_attr( $test->id ); ?>">
						<div class="pf-ab-loading">
							<span class="spinner is-active" style="float:none;margin:0;"></span> Učitavanje rezultata...
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<script>
			window.pfAbInit = {
				nonce:   <?php echo wp_json_encode( wp_create_nonce( 'pf_analytics' ) ); ?>,
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				days:    30
			};
		</script>
	</div>
	<?php
}


/* =========================================================
 *  STRANICA: Analitika
 * ========================================================= */
function pf_render_analytics_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nemaš dozvolu za ovu stranicu.' );
	}

	global $wpdb;
	$forms   = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}pf_forms ORDER BY id DESC" );
	$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : ( ! empty( $forms ) ? $forms[0]->id : 0 );
	$days    = isset( $_GET['days'] ) ? min( 365, absint( $_GET['days'] ) ) : 30;
	?>
	<div class="wrap pf-wrap pf-analytics-wrap">
		<h1>Analitika</h1>

		<div class="pf-analytics-filters">
			<select id="pf-analytics-form">
				<?php foreach ( $forms as $f ) : ?>
					<option value="<?php echo esc_attr( $f->id ); ?>" <?php selected( $form_id, $f->id ); ?>>
						<?php echo esc_html( $f->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<div class="pf-days-toggle">
				<?php foreach ( array( 7 => '7 dana', 30 => '30 dana', 90 => '90 dana' ) as $d => $label ) : ?>
					<button class="pf-day-btn <?php echo $days === $d ? 'is-active' : ''; ?>" data-days="<?php echo esc_attr( $d ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>
			</div>
			<span class="pf-analytics-loading" id="pf-analytics-loading" style="display:none;">
				<span class="spinner is-active" style="float:none;margin:0;"></span> Učitavanje...
			</span>
		</div>

		<!-- Tabovi -->
		<div class="pf-analytics-tabs">
			<button class="pf-analytics-tab is-active" data-tab="funnel">Funnel analitika</button>
			<button class="pf-analytics-tab" data-tab="attribution">Attribution</button>
		</div>

		<!-- ===== TAB 1: FUNNEL ===== -->
		<div class="pf-analytics-tab-content is-active" data-tab="funnel">

			<div class="pf-stat-grid" id="pf-stat-grid">
				<?php
				$stat_defs = array(
					array( 'key' => 'pf_form_view',    'label' => 'Pregledi forme',     'icon' => 'dashicons-visibility', 'color' => '#2271b1' ),
					array( 'key' => 'pf_form_start',   'label' => 'Počeli ispunjavati', 'icon' => 'dashicons-edit',       'color' => '#B58A00' ),
					array( 'key' => 'pf_form_submit',  'label' => 'Poslali upit',       'icon' => 'dashicons-yes-alt',    'color' => '#338B45' ),
					array( 'key' => 'pf_form_abandon', 'label' => 'Napustili formu',    'icon' => 'dashicons-no-alt',     'color' => '#C44545' ),
					array( 'key' => 'conversion_rate', 'label' => 'Stopa konverzije',   'icon' => 'dashicons-chart-line', 'color' => '#B5654A' ),
				);
				foreach ( $stat_defs as $s ) : ?>
					<div class="pf-stat-card">
						<div class="pf-stat-icon" style="background:<?php echo esc_attr( $s['color'] ); ?>20;color:<?php echo esc_attr( $s['color'] ); ?>">
							<span class="dashicons <?php echo esc_attr( $s['icon'] ); ?>"></span>
						</div>
						<div class="pf-stat-body">
							<div class="pf-stat-value" data-key="<?php echo esc_attr( $s['key'] ); ?>">—</div>
							<div class="pf-stat-label"><?php echo esc_html( $s['label'] ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
				<div class="pf-stat-card">
					<div class="pf-stat-icon" style="background:#7E8A6A20;color:#7E8A6A">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="pf-stat-body">
						<div class="pf-stat-value" data-key="avg_time">—</div>
						<div class="pf-stat-label">Prosj. vrijeme ispunjavanja</div>
					</div>
				</div>
			</div>

			<div class="pf-charts-grid">
				<div class="pf-chart-card pf-chart-wide">
					<div class="pf-chart-header">
						<h3>Trend po danima</h3>
						<div class="pf-chart-legend">
							<span class="pf-legend-dot" style="background:#2271b1"></span>Pregledi
							<span class="pf-legend-dot" style="background:#B58A00"></span>Počeli
							<span class="pf-legend-dot" style="background:#338B45"></span>Poslali
							<span class="pf-legend-dot" style="background:#C44545"></span>Napustili
						</div>
					</div>
					<div class="pf-chart-body"><canvas id="pf-chart-daily"></canvas></div>
				</div>
				<div class="pf-chart-card">
					<div class="pf-chart-header"><h3>Napuštanje po koraku</h3></div>
					<div class="pf-chart-body"><canvas id="pf-chart-steps"></canvas></div>
					<p class="pf-chart-note" id="pf-avg-fill-note"></p>
				</div>
				<div class="pf-chart-card">
					<div class="pf-chart-header"><h3>Popularnost odabira</h3></div>
					<div class="pf-chart-body" id="pf-choice-charts"></div>
				</div>
			</div>

		</div><!-- /TAB 1 -->

		<!-- ===== TAB 2: ATTRIBUTION ===== -->
		<div class="pf-analytics-tab-content" data-tab="attribution" style="display:none;">

			<div class="pf-attr-header">
				<p class="description">Koji kanali i kampanje donose posjetitelje koji ispunjavaju formu.</p>
				<a id="pf-attr-export-btn" href="#" class="button">
					<span class="dashicons dashicons-download"></span> Export CSV
				</a>
			</div>

			<div class="pf-charts-grid" style="margin-bottom:24px;">
				<div class="pf-chart-card">
					<div class="pf-chart-header"><h3>Top 5 izvora po konverzijama</h3></div>
					<div class="pf-chart-body" style="height:240px;"><canvas id="pf-chart-sources-pie"></canvas></div>
				</div>
				<div class="pf-chart-card">
					<div class="pf-chart-header"><h3>Source / Medium</h3></div>
					<div id="pf-attr-source-table"></div>
				</div>
			</div>

			<div class="pf-chart-card" style="margin-bottom:20px;">
				<div class="pf-chart-header"><h3>Kampanje</h3></div>
				<div id="pf-attr-campaign-table"></div>
			</div>

			<div class="pf-charts-grid">
				<div class="pf-chart-card">
					<div class="pf-chart-header"><h3>Top landing stranice</h3></div>
					<div id="pf-attr-landing-table"></div>
				</div>
				<div class="pf-chart-card">
					<div class="pf-chart-header"><h3>Referreri</h3></div>
					<div id="pf-attr-referrer-table"></div>
				</div>
			</div>

		</div><!-- /TAB 2 -->

		<script>
			window.pfAnalyticsInit = {
				formId: <?php echo (int) $form_id; ?>,
				days:   <?php echo (int) $days; ?>,
				exportAttrUrl: <?php echo wp_json_encode( wp_nonce_url( admin_url( 'admin-post.php?action=pf_export_attribution' ), 'pf_export_attribution' ) ); ?>
			};
		</script>
	</div>
	<?php
}


/* =========================================================
 *  AJAX: OpenAI AI Asistent za kreiranje forme
 * ========================================================= */

function pf_theme_presets() {
	return array(
		'warm'       => array( 'label' => 'Warm',       'primary_color' => '#B5654A', 'bg_color' => '#FBF8F4', 'text_color' => '#2B2420', 'label_color' => '#2B2420', 'border_color' => '#DDD4C8', 'input_bg' => '#FFFFFF', 'border_radius' => '8',  'button_style' => 'filled', 'button_text' => '#FFFFFF' ),
		'minimalist' => array( 'label' => 'Minimalist', 'primary_color' => '#111111', 'bg_color' => '#FFFFFF', 'text_color' => '#111111', 'label_color' => '#111111', 'border_color' => '#E2E2E2', 'input_bg' => '#FAFAFA',  'border_radius' => '0',  'button_style' => 'filled', 'button_text' => '#FFFFFF' ),
		'corporate'  => array( 'label' => 'Corporate',  'primary_color' => '#2271b1', 'bg_color' => '#FFFFFF', 'text_color' => '#1D2327', 'label_color' => '#1D2327', 'border_color' => '#C3C4C7', 'input_bg' => '#F6F7F7',  'border_radius' => '4',  'button_style' => 'filled', 'button_text' => '#FFFFFF' ),
		'dark'       => array( 'label' => 'Dark',       'primary_color' => '#A78BFA', 'bg_color' => '#1E1E2E', 'text_color' => '#E2E8F0', 'label_color' => '#CBD5E1', 'border_color' => '#334155', 'input_bg' => '#2D2D3F',  'border_radius' => '8',  'button_style' => 'filled', 'button_text' => '#1E1E2E' ),
		'soft'       => array( 'label' => 'Soft',       'primary_color' => '#EC8FAC', 'bg_color' => '#FFF5F7', 'text_color' => '#4A2030', 'label_color' => '#4A2030', 'border_color' => '#F9C6D5', 'input_bg' => '#FFFFFF',  'border_radius' => '16', 'button_style' => 'filled', 'button_text' => '#FFFFFF' ),
		'forest'     => array( 'label' => 'Forest',     'primary_color' => '#2D6A4F', 'bg_color' => '#F0F7F4', 'text_color' => '#1B3A2D', 'label_color' => '#1B3A2D', 'border_color' => '#B7D5C8', 'input_bg' => '#FFFFFF',  'border_radius' => '6',  'button_style' => 'filled', 'button_text' => '#FFFFFF' ),
	);
}

function pf_ai_chat() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Nemaš dozvolu.', 403 );
	}
	check_ajax_referer( 'pf_ai_chat', 'nonce' );

	$api_key = get_option( 'pf_openai_api_key', '' );
	if ( ! $api_key ) {
		wp_send_json_error( 'OpenAI API ključ nije konfiguriran. Idi na Postavke.' );
	}

	$message  = isset( $_POST['message'] )      ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$history  = isset( $_POST['history'] )       ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();
	$form_ctx = isset( $_POST['form_context'] )  ? sanitize_textarea_field( wp_unslash( $_POST['form_context'] ) ) : '{}';

	if ( ! $message ) {
		wp_send_json_error( 'Poruka je prazna.' );
	}

	if ( ! is_array( $history ) ) $history = array();

	$clean_history = array();
	foreach ( $history as $h ) {
		if ( isset( $h['role'], $h['content'] ) && in_array( $h['role'], array( 'user', 'assistant' ), true ) ) {
			$clean_history[] = array(
				'role'    => $h['role'],
				'content' => sanitize_textarea_field( $h['content'] ),
			);
		}
	}
	$clean_history = array_slice( $clean_history, -20 );

	$field_types_desc = implode( ', ', array_map(
		function( $k, $v ) { return "$k ($v)"; },
		array_keys( pf_field_types() ),
		pf_field_types()
	) );

	$system_prompt = <<<'PROMPT'
Ti si iskusan UX stručnjak i ekspert za dizajn web formi. Pomažeš korisnicima kreirati visoko-konverzijske kontaktne forme za prikupljanje upita.

Tvoja osobnost: profesionalan, konkretan, proaktivan. Kad korisnik kaže "napravi formu za kuhinju", ti ne pitaš 10 pitanja — odmah napraviš razumnu formu i kažeš "Evo prijedloga, možemo prilagoditi".

KONTEKST APLIKACIJE:
Ovo je Perković Forms — WordPress plugin za kreiranje multi-step formi. Forme se koriste za prikupljanje upita od potencijalnih kupaca (lead generation). Korisnici su uglavnom vlasnici malih/srednjih tvrtki koji nemaju tehničko znanje.

DOSTUPNI TIPOVI POLJA:
PROMPT;

	$system_prompt .= "\n$field_types_desc\n\n";

	$system_prompt .= <<<'PROMPT'
STRUKTURA PODATAKA:
Forma je organizirana: steps (stranice) > rows (redovi) > cells (stupci) > fields (polja).
- Svaka "stranica" (step) je zaseban korak u multi-step formi
- Svaki red može imati 1, 2 ili 3 stupca (cols: 1/2/3)
- Svaka ćelija (cell) je array polja
- Polje "name" mora biti slug (mala slova, podcrtaji, bez razmaka): ime_prezime, email, budzet

TRENUTNA FORMA:
PROMPT;

	$system_prompt .= $form_ctx . "\n\n";

	$system_prompt .= <<<'PROMPT'
PRAVILA DIZAJNA (uvijek primjenjuj):
1. Kontakt podaci (ime, email, telefon) IDA UVIJEK na ZADNJU stranicu — korisnici češće ostave projekt detalje nego osobne podatke ako ih pitaš prve
2. Forma od 5-8 polja po stranici je idealna. Više od 10 polja na jednoj stranici smanjuje konverziju
3. Obavezna polja označi required:true, ali neka ih bude što manje — samo ono što stvarno trebaš
4. Koristiti select/radio umjesto textarea gdje god ima smisla — lakše za korisnika
5. Placeholder treba biti primjer, ne opis polja: "npr. Moderna kuhinja" umjesto "Unesite opis"
6. Section divider koristiti za grupiranje logički srodnih polja (npr. "Detalji projekta" / "Kontakt")
7. Za image_choice — koristiti kad je odabir vizualan (stil namještaja, tip prostora)
8. Conditional logic koristiti za "Nešto drugo" opcije — prikaži tekstualni input samo kad je označeno

UX BEST PRACTICES:
- Kratka, jasna pitanja. "Što trebate?" umjesto "Opišite nam prirodu vašeg projekta"
- Radio/checkbox opcije: 3-7 opcija je optimum. Više → dropdown
- Multi-step: stranica 1 = tip projekta/zahtjevi, stranica 2 = detalji, zadnja = kontakt
- Gumb "Sljedeći korak" motivira napredak — korisnik ne vidi koliko polja slijedi

DOSTUPNE AKCIJE (koristi SAMO ove):
add_field — dodaj novo polje:
{"type":"add_field","step":1,"field":{"type":"text","label":"Ime i prezime","name":"ime_prezime","required":true,"placeholder":"npr. Ivica Ivić","options":[],"condition":null}}

update_field — promijeni postojeće polje po "name":
{"type":"update_field","name":"ime_prezime","changes":{"label":"Novi label","placeholder":"novi placeholder","required":false}}

delete_field — obriši polje po "name":
{"type":"delete_field","name":"ime_prezime"}

add_step — dodaj novu praznu stranicu:
{"type":"add_step"}

reorder — premjesti polje unutar iste stranice:
{"type":"reorder_fields","step":1,"order":["name1","name2","name3"]}

replace_all — zamijeni cijelu formu (koristi za kompleksne prerade ili kreiranje od nule):
{"type":"replace_all","steps":[{"rows":[{"cols":1,"cells":[[{"type":"text","label":"Ime i prezime","name":"ime_prezime","required":true,"placeholder":"npr. Ivica Ivić","options":[],"condition":null}]]}]}]}

IMAGE_CHOICE opcije format: "Naziv opcije|https://url-slike.jpg" (URL je opcionalan)
CONDITIONAL format: {"field":"naziv_polja","operator":"equals","value":"Vrijednost"} (operator: equals/not_equals/contains)

VAŽNO ZA KVALITETNE AKCIJE:
- Za "add_field": uvijek dodaj razumne placeholder vrijednosti i opcije za select/radio/checkbox
- Za "replace_all": uvijek uključi sve stranice u logičnom redoslijedu
- Nikad ne koristiti prazan "name" — uvijek generiraj smisleni slug
- Opcije za checkbox/radio/select: jedna opcija po elementu u arrayu, bez | separatora
- Image_choice opcije: koristiti | separator samo ako imaš URL slike

FORMAT ODGOVORA (OBAVEZNO — isključivo ovaj JSON, ništa drugo):
{
  "message": "Tekst poruke korisniku na hrvatskom jeziku — prijatan, konkretan, objasniti što si napravio",
  "actions": [
    ... array akcija koje treba primijeniti ...
  ]
}

Ako nema promjena: {"message":"Tvoja poruka","actions":[]}

PRIMJERI DOBRE KOMUNIKACIJE:
Korisnik: "napravi mi formu za ponudu kuhinja"
Ti: "Napravio sam 2-koračnu formu za kuhinje. Na prvoj stranici su pitanja o projektu (dimenzije, stil, budžet, rok), na drugoj kontakt podaci. Prilagodi prema potrebi!"

Korisnik: "promijeni treće pitanje da bude dropdown"
Ti: "Promijeni sam [naziv polja] u padajući izbornik. Opcije su preuzete kakve su bile — provjeri odgovaraju li."

Korisnik: "dodaj uvjet na pitanje o budžetu"
Ti: "Dodao sam uvjet da se pitanje o budžetu prikazuje samo kad je odabran projekt veći od single-room renovacije. Ako želiš drugačiji trigger, reci mi."
PROMPT;

	$messages = array_merge(
		array( array( 'role' => 'system', 'content' => $system_prompt ) ),
		$clean_history,
		array( array( 'role' => 'user', 'content' => $message ) )
	);

	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		array(
			'headers'  => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'     => wp_json_encode( array(
				'model'           => 'gpt-4o',
				'messages'        => $messages,
				'temperature'     => 0.4,
				'max_tokens'      => 4000,
				'response_format' => array( 'type' => 'json_object' ),
			) ),
			'timeout'  => 30,
			'blocking' => true,
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( 'Greška: ' . $response->get_error_message() );
	}

	$status = wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status !== 200 ) {
		$err = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $status;
		wp_send_json_error( 'OpenAI greška: ' . $err );
	}

	$content = isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '';
	$parsed  = json_decode( $content, true );

	if ( ! $parsed || ! isset( $parsed['message'] ) ) {
		wp_send_json_error( 'Neispravan odgovor od AI-ja. Pokušaj ponovo.' );
	}

	wp_send_json_success( array(
		'message' => sanitize_textarea_field( $parsed['message'] ),
		'actions' => isset( $parsed['actions'] ) && is_array( $parsed['actions'] ) ? $parsed['actions'] : array(),
	) );
}
add_action( 'wp_ajax_pf_ai_chat', 'pf_ai_chat' );


function pf_print_ajax_url() {
	?>
	<script>
		window.pfAjaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	</script>
	<?php
}
add_action( 'wp_footer', 'pf_print_ajax_url' );
