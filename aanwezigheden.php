<?php
/**
* Plugin Name: Aanwezigheden zondag upload
* Plugin URI: https://le.pirlou.it/wp_plugins
* Description: files upload for zondag Aanwezigheden shorttag [lwt_uploader]
* Version: 0.1
* Author: Benoît de Biolley
* Author URI: https://le.pirlou.it/
**/

add_shortcode( 'lwt_uploader', 'lwt_uploader_callback' );

function lwt_uploader_callback() {

	ob_start();

	?>

	<form name="uploader" method="post" enctype="multipart/form-data">
		<p>
			<label>Select multiple files:</label><br/>
			<input type="file" name="myfile[]" multiple accept=".txt" required>
		</p>
		<button type="submit" name="lwt_upload" value="1">Aanwezigheden toevoegen</button>
	</form>

	<?php

	$feedback = get_transient('scanner_feedback');
	delete_transient('scanner_feedback');
	if (isset( $_POST['lwt_upload'] )){

		if (empty($feedback["unhandledRecords"])) {
			echo '<div class="success">✅ All files processed successfully.</div>';
		} else {
			echo '<div class="error">⚠️ Some lines could not be handled:</div>';
			echo '<pre>' . esc_html($feedback["unhandledRecords"]) . '</pre>';
		}
		if (!empty($feedback["globalStats"])) {
			echo '<div class="summary"><strong>📊 Summary:</strong><ul>';
			foreach ($feedback["globalStats"] as $team => $count) {
					echo '<li>' . esc_html($team) . ': ' . intval($count) . ' scans</li>';
			}
			echo '</ul></div>';
	  }
	}

	return ob_get_clean();

}

add_action( 'init', 'lwt_submit_form' );

function cfp_get_team_names() {
	return [
			1 => 'A-ploeg',
			3 => 'Tempo',
			4 => 'Sportivo',
			5 => 'Cyclo',
			6 => 'Toeristen',
			7 => 'D-ploeg',
			9 => 'Trappers',
			10 => 'Moderato',
			11 => 'Volgwagen'
	];
}

function retrieve_data_and_insert_activity($line, $niss, $date, $teamNumber, $comments, $wpdb, &$unhandledRecords) {
	$teamNames = cfp_get_team_names();
	$teamName = $teamNames[$teamNumber];
	$isoDate = DateTime::createFromFormat('d/m/Y', $date)->format('Y-m-d');
	$sundayNumber = get_sunday_number($date);

	try {
			$aantalKm = get_aantal_km($teamName, $isoDate, $wpdb);
			insert_activiteit($isoDate, $niss, $teamName, $aantalKm, $sundayNumber, $comments, $wpdb);
	} catch (Exception $e) {
		// echo '<pre>' . $e . '</pre>';
			$unhandledRecords[] = $line;
	}
}
function lwt_submit_form() {
  global $wpdb;
	$teamNames = cfp_get_team_names();


  $unhandledRecords = [];
	$stats = [];

	if ( isset( $_POST['lwt_upload'] ) ) {
		$files = $_FILES['myfile'];
		foreach ($files['name'] as $key => $value) {
			if ($files['name'][$key]) {
				$file = array(
					'name'     => $files['name'][$key],
					'type'     => $files['type'][$key],
					'tmp_name' => $files['tmp_name'][$key],
					'error'    => $files['error'][$key],
					'size'     => $files['size'][$key]
				);
				$override = array(
					'test_form' => false,
				);
				$uploaded_file = wp_handle_upload($file, $override);
			}
			if ( ! is_wp_error( $uploaded_file ) ) {
	
				$lines = file($uploaded_file['file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
				foreach ($lines as $line) {
					$parts = explode(',', $line);
					if (count($parts) !== 3) continue;

					[$date, $niss, $teamNumber] = $parts;
					$teamNumber = intval($teamNumber);

					if (!isset($teamNames[$teamNumber])) {
							$unhandledRecords[] = $line;
							continue;
					}

					retrieve_data_and_insert_activity($line, $niss, $date, $teamNumber, "", $wpdb, $unhandledRecords);

					$teamName=$teamNames[$teamNumber];
					if (!isset($stats[$teamName])) {
						$stats[$teamName] = 0;
					}
					$stats[$teamName]++;
				}
				
	
	
			}
		}
		set_transient( 'scanner_feedback' , ["unhandledRecords" => implode("\n", $unhandledRecords), "globalStats" => $stats] );
		// wp_redirect($_SERVER['REQUEST_URI']);
		// exit;
	}
}



function get_sunday_number($date) {
	$dateObj = DateTime::createFromFormat('d/m/Y', $date);
	$base = new DateTime('2025-01-01');
	$diff = $base->diff($dateObj)->days;
	$weeks = floor($diff / 7) - 7;
	return str_pad($weeks, 2, '0', STR_PAD_LEFT);
}

function get_aantal_km($teamName, $date, $wpdb) {
	if ($teamName === 'Volgwagen') return 0;

	$result = $wpdb->get_var(
			$wpdb->prepare(
					"SELECT afstand FROM zondagritten WHERE datum = %s AND ploeg = %s",
					$date, $teamName
			)
	);

	if ($result === null) throw new Exception('Afstand not found');
	return intval($result);
}

function insert_activiteit($isoDate, $niss, $teamName, $aantalKm, $sundayNumber, $comments, $wpdb) {
	$title = 'Z' . $sundayNumber;
	$now = current_time('mysql');
	$data = [
			'datum' => $isoDate,
			'rijksregisternummer' => $niss,
			'aard' => 'zondagrit',
			'ploeg' => $teamName,
			'kilometers' => $aantalKm,
			'punten' => 10,
			'title' => $title,
			'opmerkingen' => $comments,
			'created_at' => $now,
			'updated_at' => $now,
	];

	$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM activiteiten WHERE datum = %s AND rijksregisternummer = %s",
			$isoDate, $niss
	));

	if ($existing > 0) {
			unset($data['created_at']);
			$wpdb->update('activiteiten', $data, [
					'datum' => $isoDate,
					'rijksregisternummer' => $niss
			]);
	} else {
			unset($data['updated_at']);
			$wpdb->insert('activiteiten', $data);
	}

	if ($wpdb->last_error) {
			throw new Exception($wpdb->last_error);
	}
}

/****** Custom Form *****/

add_shortcode('custom_form', 'cfp_render_form');

function cfp_enqueue_scripts() {
	wp_enqueue_script('jquery-ui-datepicker');
	wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');
	wp_enqueue_script('cfp-custom-js', plugin_dir_url(__FILE__) . 'form.js', ['jquery'], null, true);
	// wp_localize_script('cfp-custom-js', 'cfp_ajax', ['ajax_url' => admin_url('admin-ajax.php')]);
}
add_action('wp_enqueue_scripts', 'cfp_enqueue_scripts');

function cfp_render_form() {
	$teamNames = cfp_get_team_names();

	$users = $wpdb->get_results("SELECT rijksregisternummer, vollnaam FROM ledenlijst");

	ob_start(); ?>
	<form id="cfp-form" method="POST">
	<?php wp_nonce_field('cfp_submit_form_action', 'cfp_nonce'); ?>
		<p>
			<label for="cfp-name">Naam:</label><br/>
			<select id="cfp-name" name="name">
					<option value="">Select a name</option>
					<?php foreach ($users as $user): ?>
							<option value="<?= esc_attr($user->rijksregisternummer) ?>"><?= esc_html($user->vollnaam) ?> (<?= esc_html($user->rijksregisternummer) ?>)</option>
					<?php endforeach; ?>
			</select>
		</p>

		<p id="cfp-niss-container" style="margin-top: 10px; display:none;">
				<label for="cfp-niss">Rijksregisternummer:</label><br/>
				<input type="text" id="cfp-niss" name="niss" readonly>
				</p>
		<p>
			<label for="cfp-date">Datum:</label><br/>
			<input type="text" id="cfp-date" name="date" autocomplete="off">
		</p>
		<p>
			<label for="cfp-groep">Groep:</label><br/>
			<select id="cfp-groep" name="groep">
					<option value="">Select a Team</option> 
					<?php foreach ($teamNames as $id => $name): ?>
						<option value="<?= esc_attr($id) ?>"><?= esc_html($name) ?></option>
					<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="cfp-comments">Opmerkingen:</label><br/>
			<textarea id="cfp-comments" name="comments" rows="4" cols="40" maxlength="1000"></textarea>
			<small id="comment-counter">0 / 1000</small>
		</p>

		<button type="submit" name="lwt_custom_form" value="1">Aanwezigheid toevoegen</button>
	</form>
	<?php
	if (isset($_GET['form']) && $_GET['form'] === 'submitted') {
		if (empty($_GET['unhandledRecords'])) {
			echo '<div class="success">✅ Aanwezigheid Toegevoegd.</div>';
		} else {
			echo '<div class="error">⚠️ Probleem:</div>';
			echo '<pre>' . esc_html($_GET['unhandledRecords']) . '</pre>';
		}
	}
	 return ob_get_clean();
}

add_action('init', 'cfp_handle_form_submission');

function cfp_handle_form_submission() {
  global $wpdb;
	$teamNames = cfp_get_team_names();
	if (
		isset($_POST['lwt_custom_form']) &&
		isset($_POST['cfp_nonce']) &&
		wp_verify_nonce($_POST['cfp_nonce'], 'cfp_submit_form_action')
	) {
		$errors = [];

		$name = isset($_POST['name']) ? intval($_POST['name']) : 0;
		$niss = sanitize_text_field($_POST['niss'] ?? '');
		$date    = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
		$groep   = isset($_POST['groep']) ? intval($_POST['groep']) : 0;
		$comments = isset($_POST['comments']) ? sanitize_textarea_field($_POST['comments']) : '';

		if ($name <= 0) {
			$errors[] = 'Gelieve een naam te selecteren..';
		}

		if (!is_valid_niss($niss)) {
			$errors[] = 'Ongeldig NISS-nummer. Controleer of het 11 cijfers bevat en correct is.';
	}

		if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
				$errors[] = 'Please enter a valid date.';
		}

		if ($groep <= 0 || !array_key_exists($groep, $teamNames)) {
				$errors[] = 'Invalid group selected.';
		}

		if (!empty($errors)) {
			foreach ($errors as $error) {
					echo '<div class="cfp-error" style="color:red;">' . esc_html($error) . '</div>';
			}
			return;
		}
		$unhandledRecords = [];
		$line = $date.','.$niss.','.$groep;
		retrieve_data_and_insert_activity($line, $niss, $date, $groep, $comments, $wpdb, $unhandledRecords);
		wp_redirect(add_query_arg(array(
			'form' => 'submitted',
			'unhandledRecords' => implode("\n",$unhandledRecords),
		), $_SERVER['REQUEST_URI']));
		exit;
	}
}

function is_valid_niss($niss) {
	// Alleen cijfers, 11 lang?
	if (!preg_match('/^\d{11}$/', $niss)) {
			return false;
	}

	$base = substr($niss, 0, 9);
	$checksum = substr($niss, 9, 2);

	// Mogelijkheid 1: geboren voor 2000
	$expected1 = 97 - (intval($base) % 97);

	if ((int)$checksum === $expected1) {
			return true;
	}

	// Mogelijkheid 2: geboren vanaf 2000 → voeg '2' toe vooraan
	$base2000 = '2' . $base;
	$expected2 = 97 - (intval($base2000) % 97);

	return (int)$checksum === $expected2;
}