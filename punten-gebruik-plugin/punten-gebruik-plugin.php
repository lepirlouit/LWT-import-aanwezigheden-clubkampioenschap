<?php
/**
 * Plugin Name: Punten Gebruik Plugin
 * Plugin URI: https://le.pirlou.it/wp_plugins
 * Description: Plugin to manage point usage for members.
 * Version: 1.0
 * Author: Benoît de Biolley
 * Author URI: https://le.pirlou.it/
 **/

add_shortcode('punten_gebruik_form', 'punten_gebruik_form_callback');

function punten_gebruik_form_callback() {
    global $wpdb;

    ob_start();

    // Get users
    $users = $wpdb->get_results("SELECT rijksregisternummer, vollnaam FROM ledenlijst ORDER BY vollnaam");

    ?>

    <form id="punten-gebruik-form" method="POST">
        <?php wp_nonce_field('punten_gebruik_submit_action', 'punten_gebruik_nonce'); ?>
        <p>
            <label for="punten-gebruik-name">Naam:</label><br/>
            <select id="punten-gebruik-name" name="name">
                <option value="">Select a name</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= esc_attr($user->rijksregisternummer) ?>" <?php if (isset($_GET['niss']) && $_GET['niss'] == $user->rijksregisternummer) echo 'selected'; ?>><?= esc_html($user->vollnaam) ?> (<?= esc_html($user->rijksregisternummer) ?>)</option>
                <?php endforeach; ?>
            </select>
            <div id="loading-spinner" class="spinner" style="display:none;"></div>
        </p>

        <div id="punten-gebruik-records" style="display:none;">
            <h3>Punten Geschiedenis</h3>
            <table id="records-table" border="1">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Wat</th>
                        <th>Punten</th>
                        <th>Bedrag</th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>

        <p id="punten-gebruik-points" style="display:none;">
            <label>Beschikbare Punten:</label><br/>
            <span id="available-points">0</span>
        </p>

        <div id="punten-gebruik-amount-description" style="display:none; gap: 20px;">
            <div>
                <label for="punten-gebruik-amount-input">Te gebruiken aantal:</label><br/>
                <input type="number" id="punten-gebruik-amount-input" name="amount" min="0" step="1">
                <button type="button" id="max-points-btn">Max</button>
                <span id="amount-euros">(0,00 €)</span>
            </div>
            <div>
                <label for="punten-gebruik-description-input">Omschrijving:</label><br/>
                <input type="text" id="punten-gebruik-description-input" name="description" size="40">
            </div>
            <button type="submit" name="punten_gebruik_submit" value="1" id="punten-gebruik-submit" style="display:none;">Gebruik Punten</button>
        </div>

    </form>
    <?php

    return ob_get_clean();
}

// Enqueue scripts
add_action('wp_enqueue_scripts', 'punten_gebruik_enqueue_scripts');

function punten_gebruik_enqueue_scripts() {
    wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
    wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
    wp_enqueue_script('punten-gebruik-js', plugin_dir_url(__FILE__) . 'punten-gebruik.js', ['jquery', 'select2-js'], null, true);
    wp_localize_script('punten-gebruik-js', 'punten_gebruik_ajax', ['ajax_url' => admin_url('admin-ajax.php')]);

    // Add spinner style
    wp_add_inline_style('select2-css', '.spinner { background: url(' . admin_url('images/spinner.gif') . ') no-repeat; background-size: 20px 20px; display: inline-block; width: 20px; height: 20px; }');
}

// AJAX handler for fetching points
add_action('wp_ajax_get_available_points', 'get_available_points');
add_action('wp_ajax_nopriv_get_available_points', 'get_available_points');

function get_available_points() {
    global $wpdb;

    $niss = sanitize_text_field($_POST['niss']);

    // Assume available points are stored in punten_gebruik table as sum of punten
    $available = $wpdb->get_var($wpdb->prepare("SELECT SUM(punten) FROM punten_gebruik WHERE rijksregisternummer = %s", $niss));

    if (!$available) $available = 0;

    // Get records
    $records = $wpdb->get_results($wpdb->prepare("SELECT datum, punten, titel, bedrag FROM punten_gebruik WHERE rijksregisternummer = %s ORDER BY datum DESC", $niss));

    wp_send_json(['available' => $available, 'records' => $records]);
}

// Form submission
add_action('init', 'punten_gebruik_handle_form');

function punten_gebruik_handle_form() {
    global $wpdb;

    if (isset($_POST['punten_gebruik_submit']) && isset($_POST['punten_gebruik_nonce']) && wp_verify_nonce($_POST['punten_gebruik_nonce'], 'punten_gebruik_submit_action')) {
        $niss = sanitize_text_field($_POST['name']);
        $amount = floatval($_POST['amount']);
        $description = sanitize_text_field($_POST['description'] ?? '');

        if ($amount > 0) {
            // Insert negative amount into punten_gebruik
            $wpdb->insert('punten_gebruik', [
                'rijksregisternummer' => $niss,
                'punten' => -$amount,
                'bedrag' => -($amount * 0.05),
                'titel' => $description,
                'datum' => current_time('mysql')
            ]);
        }

        // Redirect or show message
        wp_redirect(add_query_arg(['punten_used' => '1', 'niss' => $niss], $_SERVER['REQUEST_URI']));
        exit;
    }
}
