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
	if (isset($_POST['lwt_upload'])) {
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

	// --- Scannings section ---
	global $wpdb;
	$convert_feedback = get_transient('scanning_convert_feedback');
	delete_transient('scanning_convert_feedback');
	if ($convert_feedback) {
		$total = array_sum($convert_feedback['per_team']);
		$team_parts = [];
		foreach ($convert_feedback['per_team'] as $team => $cnt) {
			$team_parts[] = esc_html($team) . ': ' . $cnt;
		}
		$detail = !empty($team_parts) ? ' (' . implode(', ', $team_parts) . ')' : '';
		echo '<div class="success">✅ ' . $total . ' scanning(s) omgezet' . $detail . '.</div>';
		if (!empty($convert_feedback['errors'])) {
			echo '<div class="error">⚠️ Mislukt voor:<br><pre>' . esc_html(implode("\n", $convert_feedback['errors'])) . '</pre></div>';
		}
	}

	$scan_rows = $wpdb->get_results(
		"SELECT DATE(moment) AS day, team, COUNT(*) AS cnt FROM scannings GROUP BY day, team ORDER BY day ASC, team ASC"
	);

	// Re-index by day
	$scan_days = [];
	foreach ($scan_rows as $row) {
		$scan_days[$row->day]['total'] = ($scan_days[$row->day]['total'] ?? 0) + intval($row->cnt);
		$scan_days[$row->day]['teams'][$row->team] = intval($row->cnt);
	}

	if (!empty($scan_days)) {
		echo '<h3>Scannings omzetten</h3>';
		echo '<table><thead><tr><th>Datum</th><th># Scans</th><th>Per ploeg</th><th></th></tr></thead><tbody>';
		foreach ($scan_days as $day => $data) {
			$display = esc_html(date('d/m/Y', strtotime($day)));
			$team_parts = [];
			foreach ($data['teams'] as $team => $cnt) {
				$team_parts[] = esc_html($team) . ': ' . $cnt;
			}
			echo '<tr>';
			echo '<td>' . $display . '</td>';
			echo '<td>' . $data['total'] . '</td>';
			echo '<td>' . implode(', ', $team_parts) . '</td>';
			echo '<td><form method="POST">';
			echo '<input type="hidden" name="lwt_convert_date" value="' . esc_attr($day) . '">';
			wp_nonce_field('lwt_convert_' . $day, 'lwt_convert_nonce');
			echo '<button type="submit">Converteren</button>';
			echo '</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	return ob_get_clean();
}

add_action( 'init', 'lwt_submit_form' );
add_action( 'init', 'lwt_convert_scanning_day' );

function lwt_convert_scanning_day() {
	global $wpdb;
	if (!isset($_POST['lwt_convert_date']) || !isset($_POST['lwt_convert_nonce'])) {
		return;
	}

	$date = sanitize_text_field($_POST['lwt_convert_date']);
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
		return;
	}

	if (!wp_verify_nonce($_POST['lwt_convert_nonce'], 'lwt_convert_' . $date)) {
		return;
	}

	$scannings = $wpdb->get_results(
		$wpdb->prepare("SELECT * FROM scannings WHERE DATE(moment) = %s", $date)
	);

	$per_team = [];
	$errors   = [];

	foreach ($scannings as $scanning) {
		$displayDate = date('d/m/Y', strtotime($scanning->moment));
		$teamName    = $scanning->team;
		$aantalKm    = get_aantal_km($teamName, $date);
		$sundayNr    = get_sunday_number($displayDate);
		try {
			insert_activiteit($date, $scanning->niss, $teamName, $aantalKm, $sundayNr, '');
			$wpdb->delete('scannings', ['id' => $scanning->id]);
			$per_team[$teamName] = ($per_team[$teamName] ?? 0) + 1;
		} catch (Exception $e) {
			$errors[] = $scanning->niss;
		}
	}

	set_transient('scanning_convert_feedback', ['per_team' => $per_team, 'errors' => $errors]);
	wp_redirect($_SERVER['REQUEST_URI']);
	exit;
}

function cfp_get_team_names() {
	return [
		1  => 'A-ploeg',
		3  => 'Tempo',
		4  => 'Sportivo',
		5  => 'Cyclo',
		6  => 'Toeristen',
		7  => 'D-ploeg',
		9  => 'Trappers',
		10 => 'Moderato',
		11 => 'Volgwagen',
	];
}

function retrieve_data_and_insert_activity($line, $niss, $date, $teamNumber, $comments) {
	$teamNames = cfp_get_team_names();
	$isoDate = DateTime::createFromFormat('d/m/Y', $date)->format('Y-m-d');
	if (!isset($teamNames[$teamNumber])) {
		$teamName = "onbekend";
		$aantalKm = 0;
	} else {
		$teamName = $teamNames[$teamNumber];
		$aantalKm = get_aantal_km($teamName, $isoDate);
	}
	$sundayNumber = get_sunday_number($date);
	insert_activiteit($isoDate, $niss, $teamName, $aantalKm, $sundayNumber, $comments);
}

function lwt_submit_form() {
	global $wpdb;
	$teamNames = cfp_get_team_names();
	$unhandledRecords = [];
	$stats = [];

	if (isset($_POST['lwt_upload'])) {
		$files = $_FILES['myfile'];
		foreach ($files['name'] as $key => $value) {
			if ($files['name'][$key]) {
				$file = array(
					'name'     => $files['name'][$key],
					'type'     => $files['type'][$key],
					'tmp_name' => $files['tmp_name'][$key],
					'error'    => $files['error'][$key],
					'size'     => $files['size'][$key],
				);
				$override = array(
					'test_form' => false,
				);
				$uploaded_file = wp_handle_upload($file, $override);
				if (!isset($uploaded_file['error'])) {
					$lines = file($uploaded_file['file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
					foreach ($lines as $line) {
						$parts = explode(',', $line);
						if (count($parts) !== 3) continue;

						[$date, $niss, $teamNumber] = $parts;
						$teamNumber = intval($teamNumber);
						$cleanNiss = substr($niss, 0, 11);
						try {
							retrieve_data_and_insert_activity($line, $cleanNiss, $date, $teamNumber, "");
						} catch (Exception $e) {
							$unhandledRecords[] = $line;
						}
						if (!isset($teamNames[$teamNumber])) {
							$teamName = 'onbekend';
						} else {
							$teamName = $teamNames[$teamNumber];
						}
						if (!isset($stats[$teamName])) {
							$stats[$teamName] = 0;
						}
						$stats[$teamName]++;
					}
				}
			}
		}
		set_transient('scanner_feedback', ["unhandledRecords" => implode("\n", $unhandledRecords), "globalStats" => $stats]);
		// wp_redirect($_SERVER['REQUEST_URI']);
		// exit;
	}
}

function get_sunday_number($date) {
	$ts = strtotime(str_replace('/', '-', $date));
	$year = date('Y', $ts);
	$firstSunday = strtotime("first sunday of march $year");
	$weeks = floor(($ts - $firstSunday) / (7 * 86400)) + 1;
	return str_pad($weeks, 2, '0', STR_PAD_LEFT);
}

function get_aantal_km($teamName, $date) {
	global $wpdb;
	if ($teamName === 'Volgwagen') return 0;

	$result = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT afstand FROM zondagritten WHERE datum = %s AND ploeg = %s",
			$date, $teamName
		)
	);

	if ($result === null) return 0;
	return intval($result);
}

function insert_activiteit($isoDate, $niss, $teamName, $aantalKm, $sundayNumber, $comments) {
	global $wpdb;
	$title = 'Z' . $sundayNumber;
	$now = current_time('mysql');
	$data = [
		'datum'               => $isoDate,
		'rijksregisternummer' => $niss,
		'aard'                => 'zondagrit',
		'ploeg'               => $teamName,
		'kilometers'          => $aantalKm,
		'punten'              => 10,
		'title'               => $title,
		'opmerkingen'         => $comments,
		'created_at'          => $now,
		'updated_at'          => $now,
	];

	$existing = $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM activiteiten WHERE datum = %s AND rijksregisternummer = %s",
		$isoDate, $niss
	));

	if ($existing > 0) {
		unset($data['created_at']);
		$wpdb->update('activiteiten', $data, [
			'datum'               => $isoDate,
			'rijksregisternummer' => $niss,
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
	wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
	wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
	wp_enqueue_script('jquery-ui-datepicker');
	wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');
	wp_enqueue_script('cfp-custom-js', plugin_dir_url(__FILE__) . 'form.js', ['jquery', 'select2-js'], null, true);
	// wp_localize_script('cfp-custom-js', 'cfp_ajax', ['ajax_url' => admin_url('admin-ajax.php')]);
	wp_add_inline_style('select2-css', '.spinner { background: url(' . admin_url('images/spinner.gif') . ') no-repeat; background-size: 20px 20px; display: inline-block; width: 20px; height: 20px; }');
}
add_action('wp_enqueue_scripts', 'cfp_enqueue_scripts');

function cfp_render_form() {
	global $wpdb;
	$teamNames = cfp_get_team_names();

	$users = $wpdb->get_results("SELECT rijksregisternummer, vollnaam FROM ledenlijst ORDER BY vollnaam");

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

		$name     = isset($_POST['name']) ? intval($_POST['name']) : 0;
		$niss     = sanitize_text_field($_POST['niss'] ?? '');
		$date     = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
		$groep    = isset($_POST['groep']) ? intval($_POST['groep']) : 0;
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
		$line = $date . ',' . $niss . ',' . $groep;
		try {
			retrieve_data_and_insert_activity($line, $niss, $date, $groep, $comments);
		} catch (Exception $e) {
			$unhandledRecords[] = $line;
		}
		wp_redirect(add_query_arg(array(
			'form'             => 'submitted',
			'unhandledRecords' => implode("\n", $unhandledRecords),
		), $_SERVER['REQUEST_URI']));
		exit;
	}
}

function is_valid_niss($niss) {
	// Alleen cijfers, 11 lang?
	if (!preg_match('/^\d{11}$/', $niss)) {
		return false;
	}

	$base     = substr($niss, 0, 9);
	$checksum = substr($niss, 9, 2);

	// Mogelijkheid 1: geboren voor 2000
	$expected1 = 97 - (intval($base) % 97);
	if ((int)$checksum === $expected1) {
		return true;
	}

	// Mogelijkheid 2: geboren vanaf 2000 → voeg '2' toe vooraan
	$base2000  = '2' . $base;
	$expected2 = 97 - (intval($base2000) % 97);
	return (int)$checksum === $expected2;
}
