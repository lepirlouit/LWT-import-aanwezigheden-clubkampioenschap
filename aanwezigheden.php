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
			<label>Select multiple files:</label><br>
			<input type="file" name="myfile[]" multiple accept=".txt" required>
		</p>
		<input type="hidden" name="lwt_upload" value="1" />
		<input type="submit" name="upload_file" />
	</form>

	<?php

	$feedback = get_transient('scanner_feedback');
	delete_transient('scanner_feedback');
	if (isset( $_POST['lwt_upload'] )){

		if (empty($feedback)) {
			echo '<div class="success">✅ All files processed successfully.</div>';
		} else {
			echo '<div class="error">⚠️ Some lines could not be handled:</div>';
			echo '<pre>' . esc_html($feedback) . '</pre>';
		}
	}

	return ob_get_clean();

}

add_action( 'init', 'lwt_submit_form' );

function lwt_submit_form() {
  global $wpdb;

  $teamNames = [
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

  $unhandledRecords = [];

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
	
						$teamName = $teamNames[$teamNumber];
						$isoDate = DateTime::createFromFormat('d/m/Y', $date)->format('Y-m-d');
						$sundayNumber = get_sunday_number($date);
	
						try {
								$aantalKm = get_aantal_km($teamName, $isoDate, $wpdb);
								insert_activiteit($isoDate, $niss, $teamName, $aantalKm, $sundayNumber, $wpdb);
						} catch (Exception $e) {
							// echo '<pre>' . $e . '</pre>';
								$unhandledRecords[] = $line;
						}
				}
				
	
	
			}
		}
		set_transient( 'scanner_feedback' , implode("\n", $unhandledRecords) );
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

function insert_activiteit($isoDate, $niss, $teamName, $aantalKm, $sundayNumber, $wpdb) {
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