<?php
/**
 * Plugin Name: Шахи проти ШІ
 * Plugin URI: https://uaserver.pp.ua/chess/
 * Description: Повнофункціональна шахова платформа з підтримкою PWA, 8 унікальними дизайнами дошки, ШІ-тренером, на двох мовах: українська та англійська.
 * Version: 2.1.0
 * Author: UAServer
 * Author URI: https://uaserver.pp.ua/
 * GitHub Plugin URI: https://github.com/portallcomua/chess
 * Primary Branch: main
 * Release Asset: true
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-chess-pwa
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_CHESS_VERSION', '2.1.0');
define('WP_CHESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_CHESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_CHESS_PRO_PROJECT_ID', 3); // ID проекту «Шахи PRO» у плагіні монетизації (UA Pay Box)

/**
 * 0. Допоміжні функції: гравці та статус PRO
 * Єдине джерело істини про PRO — колонка status у таблиці chess_players
 * (синхронізується з плагіном монетизації через wcc_check_user_premium).
 */
function wp_chess_table() {
    global $wpdb;
    return $wpdb->prefix . 'chess_players';
}

function wp_chess_get_player($email) {
    global $wpdb;
    $email = sanitize_email(strtolower(trim((string) $email)));
    if ($email === '') {
        return null;
    }
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wp_chess_table() . ' WHERE email = %s', $email));
}

// Створює запис гравця для існуючого користувача WordPress (якщо його ще немає)
function wp_chess_ensure_player($email) {
    global $wpdb;
    $row = wp_chess_get_player($email);
    if ($row) {
        return $row;
    }
    $wp_user = get_user_by('email', $email);
    if (!$wp_user) {
        return null;
    }
    $wpdb->insert(wp_chess_table(), array(
        'username'      => mb_substr($wp_user->display_name ? $wp_user->display_name : $wp_user->user_login, 0, 60),
        'email'         => strtolower($wp_user->user_email),
        'password_hash' => $wp_user->user_pass,
        'status'        => 'FREE',
        'registered_at' => current_time('mysql'),
    ));
    return wp_chess_get_player($email);
}

function wp_chess_is_pro_email($email) {
    global $wpdb;
    $row = wp_chess_get_player($email);
    $status = $row ? $row->status : 'FREE';
    if ($status !== 'PRO' && function_exists('wcc_check_user_premium')) {
        $wp_user = get_user_by('email', $email);
        if ($wp_user && wcc_check_user_premium($wp_user->ID, WP_CHESS_PRO_PROJECT_ID)) {
            $row = wp_chess_ensure_player($email);
            if ($row) {
                $wpdb->update(wp_chess_table(), array('status' => 'PRO'), array('id' => $row->id));
            }
            $status = 'PRO';
        }
    }
    return $status === 'PRO';
}

function wp_chess_grant_pro($email) {
    global $wpdb;
    $row = wp_chess_ensure_player($email);
    if (!$row) {
        return false;
    }
    $wpdb->update(wp_chess_table(), array('status' => 'PRO'), array('id' => $row->id));
    $wp_user = get_user_by('email', $row->email);
    if ($wp_user) {
        update_user_meta($wp_user->ID, '_wcc_user_premium_' . WP_CHESS_PRO_PROJECT_ID, '1');
        update_user_meta($wp_user->ID, 'wcc_chess_pro', '1');
        if (function_exists('wcc_set_user_premium')) {
            wcc_set_user_premium($wp_user->ID, WP_CHESS_PRO_PROJECT_ID, true);
        }
    }
    return true;
}

function wp_chess_revoke_pro($email) {
    global $wpdb;
    $row = wp_chess_get_player($email);
    if (!$row) {
        return false;
    }
    $wpdb->update(wp_chess_table(), array('status' => 'FREE'), array('id' => $row->id));
    $wp_user = get_user_by('email', $row->email);
    if ($wp_user) {
        delete_user_meta($wp_user->ID, '_wcc_user_premium_' . WP_CHESS_PRO_PROJECT_ID);
        delete_user_meta($wp_user->ID, 'wcc_chess_pro');
        if (function_exists('wcc_set_user_premium')) {
            wcc_set_user_premium($wp_user->ID, WP_CHESS_PRO_PROJECT_ID, false);
        }
    }
    return true;
}

/**
 * 1. Активація плагіна: таблиця шахістів, налаштування, роль «Шахіст»
 */
function wp_chess_create_table() {
    global $wpdb;
    $table_name = wp_chess_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        username varchar(60) NOT NULL,
        email varchar(100) NOT NULL,
        password_hash varchar(255) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'FREE',
        registered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        games_played int(11) NOT NULL DEFAULT 0,
        wins int(11) NOT NULL DEFAULT 0,
        hints_used_today int(11) NOT NULL DEFAULT 0,
        last_hint_date date DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email),
        KEY username (username)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    update_option('wp_chess_db_version', WP_CHESS_VERSION);
}

function wp_chess_activate_plugin() {
    wp_chess_create_table();

    if (get_option('wp_chess_price_text') === false) {
        update_option('wp_chess_price_text', '79 ₴');
    }
    if (get_option('wp_chess_daily_hints') === false) {
        update_option('wp_chess_daily_hints', 10);
    }
    if (get_option('wp_chess_card_url') === false) {
        update_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09');
    }
    if (get_option('wp_chess_online_time') === false) {
        update_option('wp_chess_online_time', 15);
    }
    if (get_option('wp_chess_disconnect_timeout') === false) {
        update_option('wp_chess_disconnect_timeout', 180);
    }
    if (get_option('wp_chess_guide_content') === false) {
        update_option('wp_chess_guide_content', wp_chess_get_default_guide_content());
    }
    delete_option('wp_chess_test_mode');
    delete_option('chess_pro');

    wp_chess_register_custom_role();
    wp_chess_ensure_local_app(true);
}
register_activation_hook(__FILE__, 'wp_chess_activate_plugin');

function wp_chess_register_custom_role() {
    if (!get_role('chess_player')) {
        add_role('chess_player', 'Шахіст', array('read' => true));
    }
}
add_action('init', 'wp_chess_register_custom_role');

// Оновлення структури БД та чистка старих налаштувань після апдейту плагіна
function wp_chess_maybe_upgrade() {
    if (get_option('wp_chess_db_version') !== WP_CHESS_VERSION) {
        wp_chess_create_table();
        delete_option('wp_chess_test_mode');
        delete_option('chess_pro');
        wp_chess_ensure_local_app(true);
    }
}
add_action('init', 'wp_chess_maybe_upgrade', 5);

// Шахісти не потрапляють в адмінку WordPress
function wp_chess_is_chess_player_only($user = null) {
    $user = $user ? $user : wp_get_current_user();
    return $user && $user->exists() && !empty($user->roles) && count($user->roles) === 1 && in_array('chess_player', $user->roles, true);
}
add_filter('show_admin_bar', function ($show) {
    return wp_chess_is_chess_player_only() ? false : $show;
});
add_action('admin_init', function () {
    if (!wp_doing_ajax() && wp_chess_is_chess_player_only()) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
});
add_filter('login_redirect', function ($redirect, $request, $user) {
    if ($user instanceof WP_User && wp_chess_is_chess_player_only($user)) {
        $page = (int) get_option('wp_chess_page_id', 0);
        return $page ? get_permalink($page) : home_url('/');
    }
    return $redirect;
}, 10, 3);

/**
 * 2. Автоматичне оновлення плагіна з GitHub (portallcomua/chess)
 */
class WP_Chess_GitHub_Updater {
    private $slug;
    private $github_api_result;

    public function __construct() {
        $this->slug = plugin_basename(__FILE__);
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
    }

    private function get_github_release() {
        if (!empty($this->github_api_result)) {
            return $this->github_api_result;
        }

        $url = 'https://api.github.com/repos/portallcomua/chess/releases/latest';
        $response = wp_remote_get($url, array(
            'headers' => array('User-Agent' => 'WordPress-WP-Chess-PWA-Plugin')
        ));

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $this->github_api_result = json_decode(wp_remote_retrieve_body($response));
        return $this->github_api_result;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();
        if ($release && !empty($release->tag_name)) {
            $new_version = ltrim($release->tag_name, 'v');
            if (version_compare(WP_CHESS_VERSION, $new_version, '<')) {
                $obj = new stdClass();
                $obj->slug = $this->slug;
                $obj->new_version = $new_version;
                $obj->url = 'https://uaserver.pp.ua/chess/';
                $obj->package = !empty($release->zipball_url) ? $release->zipball_url : '';
                if (!empty($release->assets)) {
                    foreach ($release->assets as $asset) {
                        if (substr($asset->name, -4) === '.zip') {
                            $obj->package = $asset->browser_download_url;
                            break;
                        }
                    }
                }
                $transient->response[$this->slug] = $obj;
            }
        }
        return $transient;
    }

    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!empty($args->slug) && $args->slug === $this->slug) {
            $release = $this->get_github_release();
            if ($release) {
                $res = new stdClass();
                $res->name = 'Шахи проти ШІ';
                $res->slug = $this->slug;
                $res->version = ltrim($release->tag_name, 'v');
                $res->author = '<a href="https://uaserver.pp.ua/">UAServer</a>';
                $res->homepage = 'https://uaserver.pp.ua/chess/';
                $res->sections = array(
                    'description' => 'Повнофункціональна шахова платформа з підтримкою PWA, 8 унікальними дизайнами дошки, ШІ-тренером.',
                    'changelog' => !empty($release->body) ? nl2br($release->body) : 'Оновлення та оптимізація гри.',
                );
                return $res;
            }
        }
        return $result;
    }
}
new WP_Chess_GitHub_Updater();

/**
 * 3. Реєстрація меню адміністратора
 */
function wp_chess_admin_menu() {
    add_menu_page(
        'Шахи проти ШІ',
        'Шахи проти ШІ',
        'manage_options',
        'wp-chess-ai',
        'wp_chess_render_admin_page',
        'dashicons-games',
        26
    );
}
add_action('admin_menu', 'wp_chess_admin_menu');

/**
 * 4. Відображення сторінки панелі керування (Admin Panel)
 */
function wp_chess_render_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';

    // Збереження основних налаштувань
    if (isset($_POST['wp_chess_save_settings']) && check_admin_referer('wp_chess_settings_nonce')) {
        update_option('wp_chess_price_text', sanitize_text_field($_POST['wp_chess_price_text']));
        update_option('wp_chess_daily_hints', absint($_POST['wp_chess_daily_hints']));
        if (isset($_POST['wp_chess_paypal_url'])) {
            update_option('wp_chess_paypal_url', esc_url_raw($_POST['wp_chess_paypal_url']));
        }
        update_option('wp_chess_card_url', esc_url_raw($_POST['wp_chess_card_url']));

        // Оновлюємо гру на диску з новими параметрами
        wp_chess_ensure_local_app(true);

        echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Налаштування успішно збережено!</strong></p></div>';
    }

    // Примусове перестворення папки app/
    if (isset($_GET['action']) && $_GET['action'] === 'recreate_app' && check_admin_referer('wp_chess_recreate_app_nonce')) {
        wp_chess_ensure_local_app(true);
        echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Папку app/ та гру index.html успішно згенеровано на сервері!</strong></p></div>';
    }

    // Зміна статусу гравця (FREE <-> PRO)
    if (isset($_GET['action'], $_GET['player_id']) && $_GET['action'] === 'toggle_status') {
        $pid = absint($_GET['player_id']);
        check_admin_referer('toggle_player_' . $pid);
        $prow = $wpdb->get_row($wpdb->prepare("SELECT email, status FROM $table_name WHERE id = %d", $pid));
        $new_status = ($prow && $prow->status === 'PRO') ? 'FREE' : 'PRO';
        if ($prow) {
            if ($new_status === 'PRO') {
                wp_chess_grant_pro($prow->email);
            } else {
                wp_chess_revoke_pro($prow->email);
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>Статус гравця #' . $pid . ' змінено на <strong>' . $new_status . '</strong></p></div>';
    }

    // Відправка email розсилки
    if (isset($_POST['wp_chess_send_broadcast']) && check_admin_referer('wp_chess_broadcast_nonce')) {
        $subj = sanitize_text_field($_POST['broadcast_subject']);
        $body = wp_kses_post($_POST['broadcast_message']);
        $players = $wpdb->get_results("SELECT username, email FROM $table_name");
        $sent_count = 0;

        foreach ($players as $pl) {
            $personalized = str_replace('{username}', $pl->username, $body);
            $headers = array('Content-Type: text/html; charset=UTF-8');
            if (wp_mail($pl->email, $subj, nl2br($personalized), $headers)) {
                $sent_count++;
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>📧 Лист успішно надіслано <strong>' . $sent_count . '</strong> гравцям!</p></div>';
    }

    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
    $price_text = get_option('wp_chess_price_text', '79 ₴');
    $daily_hints = get_option('wp_chess_daily_hints', 10);
    $paypal_url = get_option('wp_chess_paypal_url', 'https://www.paypal.com/checkout?amount=1.99');
    $card_url = get_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09');
    $local_index = WP_CHESS_PLUGIN_DIR . 'app/index.html';
    $has_local_file = file_exists($local_index) && filesize($local_index) > 1000;
    ?>
    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:10px;">
            <span style="font-size:28px;">♟️</span>
            Шахи проти ШІ — Панель керування <span style="font-size:13px; background:#0f172a; color:#f8fafc; padding:3px 8px; border-radius:12px; margin-left:8px;">v<?php echo WP_CHESS_VERSION; ?> UAServer</span>
        </h1>

        <h2 class="nav-tab-wrapper" style="margin-top:20px;">
            <a href="?page=wp-chess-ai&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Налаштування & Монетизація</a>
            <a href="?page=wp-chess-ai&tab=players" class="nav-tab <?php echo $active_tab === 'players' ? 'nav-tab-active' : ''; ?>">👥 База шахістів (FREE / PRO)</a>
            <a href="?page=wp-chess-ai&tab=broadcast" class="nav-tab <?php echo $active_tab === 'broadcast' ? 'nav-tab-active' : ''; ?>">📧 Розсилка гравцям</a>
            <a href="?page=wp-chess-ai&tab=shortcodes" class="nav-tab <?php echo $active_tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">🔗 Шорткод для сайту</a>
        </h2>

        <?php if ($active_tab === 'settings') : ?>
            <!-- Вкладка 1: Налаштування -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:15px; max-width:850px;">
                <h2 style="margin-top:0;">Параметри оплати та ШІ-тренера</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('wp_chess_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="wp_chess_price_text">Текст вартості Premium (PRO):</label></th>
                            <td>
                                <input name="wp_chess_price_text" type="text" id="wp_chess_price_text" value="<?php echo esc_attr($price_text); ?>" class="regular-text" style="width:250px;">
                                <p class="description">Наприклад: <code>79 ₴</code> або <code>$1.99</code> (відображається на кнопці оплати).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wp_chess_daily_hints">Безкоштовних підказок ШІ на добу:</label></th>
                            <td>
                                <input name="wp_chess_daily_hints" type="number" min="1" max="100" id="wp_chess_daily_hints" value="<?php echo esc_attr($daily_hints); ?>" class="small-text">
                                <p class="description">За замовчуванням: 10 підказок на добу для FREE користувачів. Для PRO — довічний безліміт.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wp_chess_card_url">Посилання на Приват-Конверт (Оплата карткою):</label></th>
                            <td>
                                <input name="wp_chess_card_url" type="url" id="wp_chess_card_url" value="<?php echo esc_attr($card_url); ?>" placeholder="https://www.privat24.ua/send/k4m09" class="large-text">
                                <p class="description" style="color:#0f172a; font-weight:500; margin-top:5px;">
                                    Введіть посилання на ваш Приват-Конверт у Приват24 (наприклад, <code>https://www.privat24.ua/send/k4m09</code>).<br>
                                    При кліку на «Сплатити» гра відкриває це посилання в новій вкладці та <strong>миттєво надає гравцю статус PRO</strong> (потім ви підтверджуєте оплату у Приват24 і за потреби блокуєте PRO на вкладці «База шахістів») (довічний доступ до всіх 8 дизайнів дошки та необмежені підказки ШІ-тренера).
                                </p>
                            </td>
                        </tr>
                        <!-- PayPal закоментовано за запитом адміністратора:
                        <tr>
                            <th scope="row"><label for="wp_chess_paypal_url">Посилання на оплату через PayPal:</label></th>
                            <td>
                                <input name="wp_chess_paypal_url" type="url" id="wp_chess_paypal_url" value="<?php echo esc_attr($paypal_url); ?>" class="large-text">
                                <p class="description">Посилання на кнопку або checkout PayPal.</p>
                            </td>
                        </tr>
                        -->
                        <tr>
                            <th scope="row">Автономний рушій гри:</th>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                    <?php if ($has_local_file) : ?>
                                        <span style="color:#155724; background:#d4edda; border:1px solid #c3e6cb; padding:6px 14px; border-radius:6px; font-weight:600; font-size:13px;">
                                            ✅ Локальний файл app/index.html створено (<?php echo round(filesize($local_index) / 1024, 1); ?> KB)
                                        </span>
                                    <?php else : ?>
                                        <span style="color:#0c5460; background:#d1ecf1; border:1px solid #bee5eb; padding:6px 14px; border-radius:6px; font-weight:600; font-size:13px;">
                                            ⚡ Автономний вбудований рушій v<?php echo WP_CHESS_VERSION; ?> активний (гра працює напряму)
                                        </span>
                                    <?php endif; ?>
                                    <a href="<?php echo wp_nonce_url('?page=wp-chess-ai&tab=settings&action=recreate_app', 'wp_chess_recreate_app_nonce'); ?>" class="button button-secondary">
                                        🔄 Записати / Оновити файл app/index.html
                                    </a>
                                </div>
                                <p class="description" style="margin-top:6px; color:#64748b;">
                                    Гра працює автономно та відкривається миттєво завдяки прямій інтеграції в shortcode.
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="wp_chess_save_settings" id="submit" class="button button-primary" value="Зберегти налаштування">
                    </p>
                </form>
            </div>

        <?php elseif ($active_tab === 'players') : ?>
            <!-- Вкладка 2: База користувачів -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:15px;">
                <h2>👥 Зареєстровані шахісти (Автономна база)</h2>
                <p>Кожен шахіст також є користувачем WordPress з роллю «Шахіст» (без доступу до панелі сайту). Статус PRO тут — єдине джерело істини для гри.</p>

                <?php
                $players = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 100");
                if (empty($players)) :
                ?>
                    <p style="color:#666;">Поки немає зареєстрованих гравців. Вони з’являться тут після реєстрації або активації PRO у грі.</p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:50px;">ID</th>
                                <th>Ім’я / Логін</th>
                                <th>Email</th>
                                <th>Дата реєстрації</th>
                                <th>Ігор / Перемог</th>
                                <th>Статус</th>
                                <th>Дія</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $p) : ?>
                                <tr>
                                    <td>#<?php echo esc_html($p->id); ?></td>
                                    <td><strong><?php echo esc_html($p->username); ?></strong></td>
                                    <td><a href="mailto:<?php echo esc_attr($p->email); ?>"><?php echo esc_html($p->email); ?></a></td>
                                    <td><?php echo esc_html($p->registered_at); ?></td>
                                    <td><?php echo intval($p->games_played); ?> / <?php echo intval($p->wins); ?></td>
                                    <td>
                                        <?php if ($p->status === 'PRO') : ?>
                                            <span style="color:#155724; background:#d4edda; padding:3px 8px; border-radius:4px; font-weight:bold;">⭐ PRO</span>
                                        <?php else : ?>
                                            <span style="color:#383d41; background:#e2e3e5; padding:3px 8px; border-radius:4px;">FREE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo wp_nonce_url('?page=wp-chess-ai&tab=players&action=toggle_status&player_id=' . $p->id, 'toggle_player_' . $p->id); ?>" class="button button-small <?php echo ($p->status === 'PRO') ? '' : 'button-primary'; ?>">
                                            <?php echo ($p->status === 'PRO') ? '🚫 Заблокувати ПРО' : '⭐ Активувати PRO'; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php elseif ($active_tab === 'broadcast') : ?>
            <!-- Вкладка 3: Розсилка email -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:15px; max-width:800px;">
                <h2>📧 Розсилка повідомлень усім шахістам</h2>
                <p>Надішліть важливі новини, турнірні анонси або пропозиції всім зареєстрованим шахістам.</p>

                <form method="post" action="">
                    <?php wp_nonce_field('wp_chess_broadcast_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="broadcast_subject">Тема листа:</label></th>
                            <td>
                                <input name="broadcast_subject" type="text" id="broadcast_subject" class="large-text" placeholder="Новий турнір або оновлення на UAServer Chess" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="broadcast_message">Текст повідомлення:</label></th>
                            <td>
                                <textarea name="broadcast_message" id="broadcast_message" rows="8" class="large-text" placeholder="Привіт, {username}! Запрошуємо взяти участь у новому шаховому турнірі..." required></textarea>
                                <p class="description">Підказка: використовуйте <code>{username}</code> для автоматичної підстановки імені гравця.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="wp_chess_send_broadcast" class="button button-primary" value="🚀 Розіслати всім шахістам">
                    </p>
                </form>
            </div>

        <?php elseif ($active_tab === 'shortcodes') : ?>
            <!-- Вкладка 4: Шорткод для сайту -->
            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:15px; max-width:850px;">
                <h2>🔗 Шорткод для сайту</h2>
                <p>Для розміщення гри на сторінці <code>https://uaserver.pp.ua/chess/</code>:</p>

                <div style="background:#f0f6fc; border-left:4px solid #0073aa; padding:18px 22px; margin:20px 0; border-radius:6px;">
                    <h3 style="margin-top:0; color:#0f172a;">Вкладка «Грати» (Шахова гра):</h3>
                    <p style="font-size:14px; color:#334155;">Вставте наступний шорткод на вкладку «Грати». Гра завантажується <strong>миттєво (0 мс)</strong> без очікувань, без зависань і без сторонніх екранів:</p>
                    <p><code style="font-size:18px; padding:10px 18px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; display:inline-block; font-weight:bold; color:#0f172a;">[wp_chess_pwa]</code></p>
                </div>

                <div style="background:#f8fafc; border-left:4px solid #64748b; padding:18px 22px; margin:20px 0; border-radius:6px;">
                    <h3 style="margin-top:0; color:#0f172a;">Вкладка «Як грати» (Інструкція):</h3>
                    <p style="font-size:14px; color:#334155;">Текст інструкції для вкладки «Як грати» ви заповнюєте самостійно прямо на сторінці WordPress (в Elementor або стандартному редакторі WordPress), тому окреме керування правилами в адмінці плагіна вимкнено.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}


/**
 * 5. Конфігурація гри для сторінки (передається в iframe через window.WP_CHESS_CONFIG)
 */
function wp_chess_build_config() {
    global $wpdb;
    $table_name = wp_chess_table();

    $current_uid = get_current_user_id();
    $wp_u = $current_uid > 0 ? wp_get_current_user() : null;
    $email = ($wp_u && !empty($wp_u->user_email)) ? $wp_u->user_email : '';

    $page_id = (int) get_option('wp_chess_page_id', 0);
    $page_url = $page_id ? get_permalink($page_id) : '';
    if (!$page_url) {
        $page_url = home_url('/');
    }

    return array(
        'pageUrl'               => $page_url,
        'iconUrl'               => plugins_url('app/pwa-192x192.png', __FILE__),
        'priceText'             => get_option('wp_chess_price_text', '79 ₴'),
        'dailyHints'            => intval(get_option('wp_chess_daily_hints', 10)),
        'cardUrl'               => get_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09'),
        'apiUrl'                => rest_url('chess/v1'),
        'lostPasswordUrl'       => wp_lostpassword_url($page_url),
        'hasMonetizationPlugin' => function_exists('wcc_check_user_premium'),
        'isUserPremium'         => ($email !== '' && wp_chess_is_pro_email($email)),
        'isBlocked'             => false,
        'userId'                => intval($current_uid),
        'userEmail'             => $email,
        'userName'              => ($wp_u && !empty($wp_u->display_name)) ? $wp_u->display_name : '',
        'totalPlayers'          => max(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name")),
        'totalGames'            => max((int) $wpdb->get_var("SELECT COALESCE(SUM(games_played), 0) FROM $table_name"), (int) get_option('wp_chess_total_games_count', 0)),
        'onlinePlayers'         => max(1, wp_chess_record_session_ping()),
    );
}

/**
 * 6. Прямий кінцевий пункт (Direct Endpoint) для гри
 */
function wp_chess_embed_listener() {
    if (isset($_GET['wp_chess_embed'])) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        if (!defined('DONOTMINIFY')) define('DONOTMINIFY', true);
        if (!defined('DONOTCDN')) define('DONOTCDN', true);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo wp_chess_get_game_html();
        exit;
    }
}
add_action('init', 'wp_chess_embed_listener', 1);

function wp_chess_get_game_html() {
    $local_file = WP_CHESS_PLUGIN_DIR . 'app/index.html';
    $html = '';

    if (file_exists($local_file) && filesize($local_file) > 1000) {
        $html = @file_get_contents($local_file);
    }
    if (empty($html) || strlen($html) < 1000) {
        $html = wp_chess_decode_embedded_payload();
    }

    $base_tag = '<base href="' . esc_url(plugins_url('app/', __FILE__)) . '">';
    if (strpos($html, '<base ') === false) {
        if (strpos($html, '<head>') !== false) {
            $html = str_replace('<head>', '<head>' . "\n  " . $base_tag, $html);
        } else {
            $html = $base_tag . $html;
        }
    }

    $config_script = '<script>window.WP_CHESS_CONFIG = ' . wp_json_encode(wp_chess_build_config()) . ';</script>';
    if (strpos($html, '</head>') !== false) {
        return str_replace('</head>', $config_script . '</head>', $html);
    }
    return $config_script . $html;
}

/**
 * 7. Шорткод [wp_chess_pwa]
 */
function wp_chess_game_shortcode($atts) {
    wp_chess_ensure_local_app();

    // Запам'ятовуємо сторінку з грою (для маніфесту PWA)
    $pid = get_the_ID();
    if ($pid && is_singular() && (int) get_option('wp_chess_page_id', 0) !== (int) $pid) {
        update_option('wp_chess_page_id', (int) $pid);
    }

    $local_file = WP_CHESS_PLUGIN_DIR . 'app/index.html';
    if (file_exists($local_file) && filesize($local_file) > 1000) {
        $default_url = plugins_url('app/index.html?v=' . WP_CHESS_VERSION, __FILE__);
    } else {
        $default_url = add_query_arg(array('wp_chess_embed' => '1', 'v' => WP_CHESS_VERSION), home_url('/'));
    }

    $attributes = shortcode_atts(array(
        'url' => $default_url,
        'width' => '100%',
        'height' => '880px',
        'border_radius' => '16px',
    ), $atts, 'wp_chess_pwa');

    $app_url = esc_url($attributes['url']);
    $height = esc_attr($attributes['height']);
    $radius = esc_attr($attributes['border_radius']);

    $config = wp_chess_build_config();
    $config['appUrl'] = $app_url;

    $html = '<!-- UAServer WP Chess Platform PWA v' . WP_CHESS_VERSION . ' -->';
    $html .= '<script>
      window.WP_CHESS_CONFIG = ' . wp_json_encode($config) . ';
      var wpChessDeferredPrompt = null;
      function wpChessPost(t) { var f = document.getElementById("wp-chess-frame"); if (f && f.contentWindow) { f.contentWindow.postMessage({ type: t }, "*"); } }
      window.addEventListener("beforeinstallprompt", function(e) { e.preventDefault(); wpChessDeferredPrompt = e; wpChessPost("WP_CHESS_CAN_INSTALL"); });
      window.addEventListener("appinstalled", function() { wpChessDeferredPrompt = null; wpChessPost("WP_CHESS_INSTALLED"); });
      window.addEventListener("message", function(e) {
        if (!e.data) return;
        var f = document.getElementById("wp-chess-frame");
        if (e.data.type === "WP_CHESS_GET_CONFIG") {
          if (f && f.contentWindow) { f.contentWindow.postMessage({ type: "WP_CHESS_CONFIG_RESPONSE", config: window.WP_CHESS_CONFIG }, "*"); }
        } else if (e.data.type === "WP_CHESS_CHECK_INSTALL") {
          if (wpChessDeferredPrompt) { wpChessPost("WP_CHESS_CAN_INSTALL"); }
        } else if (e.data.type === "WP_CHESS_TRIGGER_INSTALL") {
          if (wpChessDeferredPrompt) {
            wpChessDeferredPrompt.prompt().then(function(choice) {
              if (choice && choice.outcome === "accepted") { wpChessDeferredPrompt = null; wpChessPost("WP_CHESS_INSTALLED"); }
            });
          }
        }
      });
    </script>';
    $html .= '<div class="wp-chess-game-container" style="width:100%; max-width:1200px; margin:0 auto; clear:both; position:relative;">';
    $html .= '<iframe id="wp-chess-frame" src="' . $app_url . '" title="Шахи проти ШІ" width="100%" height="' . $height . '" ';
    $html .= 'style="width:100%; height:' . $height . '; min-height:680px; border:none; border-radius:' . $radius . '; background:#020617; display:block; box-shadow:0 15px 35px rgba(0,0,0,0.3);" ';
    $html .= 'allow="fullscreen; autoplay; clipboard-write" loading="eager" ';
    $html .= 'sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals"></iframe>';
    $html .= '</div>';

    return $html;
}
add_shortcode('wp_chess_pwa', 'wp_chess_game_shortcode');

function wp_chess_guide_shortcode($atts) {
    $guide = get_option('wp_chess_guide_content', wp_chess_get_default_guide_content());
    $output = '<div class="wp-chess-guide-box" style="background:#0f172a; color:#e2e8f0; border:1px solid #1e293b; border-radius:16px; padding:28px; margin:20px 0; font-size:15px; line-height:1.7; box-shadow:0 10px 25px rgba(0,0,0,0.2);">';
    $output .= wp_kses_post($guide);
    $output .= '</div>';
    return $output;
}
add_shortcode('wp_chess_pwa_guide', 'wp_chess_guide_shortcode');

/**
 * 8. PWA: власний маніфест і service worker ТІЛЬКИ на сторінці з грою.
 * Раніше маніфест із шляхами «/pwa-192x192.png» підключався на всіх сторінках
 * і конфліктував з іконками інших плагінів.
 */
function wp_chess_is_chess_page() {
    if (!is_singular()) {
        return false;
    }
    $post = get_queried_object();
    if (!($post instanceof WP_Post)) {
        return false;
    }
    if ((int) get_option('wp_chess_page_id', 0) === (int) $post->ID) {
        return true;
    }
    if (has_shortcode($post->post_content, 'wp_chess_pwa')) {
        return true;
    }
    $el = get_post_meta($post->ID, '_elementor_data', true);
    return is_string($el) && strpos($el, 'wp_chess_pwa') !== false;
}

function wp_chess_page_scope($start) {
    $u = wp_parse_url($start);
    $path = isset($u['path']) ? $u['path'] : '/';
    if (substr($path, -1) !== '/') {
        $path = trailingslashit(dirname($path));
    }
    $port = isset($u['port']) ? ':' . $u['port'] : '';
    return $u['scheme'] . '://' . $u['host'] . $port . $path;
}

function wp_chess_inject_pwa_head() {
    if (!wp_chess_is_chess_page()) {
        return;
    }
    $post_id = (int) get_queried_object_id();
    $manifest = add_query_arg(array('wp_chess_manifest' => '1', 'p' => $post_id), home_url('/'));
    $sw = add_query_arg('wp_chess_sw', '1', home_url('/'));
    $scope = wp_chess_page_scope(get_permalink($post_id));
    $icon = plugins_url('app/pwa-192x192.png', __FILE__);
    echo "\n<!-- WP Chess PWA -->\n";
    echo '<link rel="manifest" id="wp-chess-manifest" href="' . esc_url($manifest) . '">' . "\n";
    echo '<meta name="theme-color" content="#020617">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="Шахи проти ШІ">' . "\n";
    echo '<link rel="apple-touch-icon" sizes="192x192" href="' . esc_url($icon) . '">' . "\n";
    echo '<script>
document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(\'link[rel="manifest"]\').forEach(function (l) { if (l.id !== "wp-chess-manifest") { l.parentNode.removeChild(l); } });
  document.querySelectorAll(\'link[rel="apple-touch-icon"], link[rel="apple-touch-icon-precomposed"]\').forEach(function (l) { if (l.href !== ' . wp_json_encode($icon) . ') { l.parentNode.removeChild(l); } });
});
if ("serviceWorker" in navigator) { window.addEventListener("load", function () { navigator.serviceWorker.register(' . wp_json_encode($sw) . ', { scope: ' . wp_json_encode(wp_parse_url($scope, PHP_URL_PATH)) . ' }).catch(function () {}); }); }
</script>' . "\n";
}
add_action('wp_head', 'wp_chess_inject_pwa_head', 1);

function wp_chess_serve_pwa_files() {
    if (isset($_GET['wp_chess_manifest'])) {
        $pid = isset($_GET['p']) ? absint($_GET['p']) : (int) get_option('wp_chess_page_id', 0);
        $start = $pid ? get_permalink($pid) : '';
        if (!$start) {
            $start = home_url('/');
        }
        $icons = plugins_url('app/', __FILE__);
        $manifest = array(
            'id'               => $start,
            'name'             => 'Шахи проти ШІ',
            'short_name'       => 'Шахи ШІ',
            'description'      => 'Шахова платформа з 8 дизайнами дошки та ШІ-тренером.',
            'start_url'        => $start,
            'scope'            => wp_chess_page_scope($start),
            'display'          => 'standalone',
            'background_color' => '#0f172a',
            'theme_color'      => '#020617',
            'lang'             => 'uk',
            'icons'            => array(
                array('src' => $icons . 'pwa-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => $icons . 'pwa-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'),
                array('src' => $icons . 'pwa-maskable-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'),
            ),
        );
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        echo wp_json_encode($manifest);
        exit;
    }
    if (isset($_GET['wp_chess_sw'])) {
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache');
        echo "self.addEventListener('install',function(){self.skipWaiting();});self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim());});self.addEventListener('fetch',function(){});";
        exit;
    }
}
add_action('init', 'wp_chess_serve_pwa_files', 1);

/**
 * 9. REST API
 */
function wp_chess_register_rest_routes() {
    $routes = array(
        'register'     => array('POST', 'wp_chess_api_register'),
        'login'        => array('POST', 'wp_chess_api_login'),
        'logout'       => array('POST', 'wp_chess_api_logout'),
        'status'       => array(array('GET', 'POST'), 'wp_chess_api_status'),
        'instant-pro'  => array('POST', 'wp_chess_api_activate_pro'),
        'activate-pro' => array('POST', 'wp_chess_api_activate_pro'),
        'stats'        => array(array('GET', 'POST'), 'wp_chess_api_stats'),
        'heartbeat'    => array('POST', 'wp_chess_api_heartbeat'),
        'record-game'  => array('POST', 'wp_chess_api_record_game'),
    );
    foreach ($routes as $path => $r) {
        register_rest_route('chess/v1', '/' . $path, array(
            'methods' => $r[0],
            'callback' => $r[1],
            'permission_callback' => '__return_true',
        ));
    }
}
add_action('rest_api_init', 'wp_chess_register_rest_routes');

function wp_chess_json_params($request) {
    $p = $request->get_json_params();
    if (!is_array($p)) {
        $p = $request->get_params();
    }
    return is_array($p) ? $p : array();
}

function wp_chess_error($message, $code = 400) {
    return new WP_REST_Response(array('success' => false, 'message' => $message), $code);
}

function wp_chess_login_wp_user($uid) {
    if ($uid > 0 && !headers_sent()) {
        wp_set_current_user($uid);
        wp_set_auth_cookie($uid, true);
    }
}

// Унікальний логін WordPress із пошти (нік гравця може бути кирилицею — він іде в display_name)
function wp_chess_unique_login($email) {
    $base = sanitize_user(current(explode('@', $email)), true);
    $base = substr($base, 0, 50);
    if (strlen($base) < 3) {
        $base = 'chess' . wp_rand(1000, 9999);
    }
    $login = $base;
    $i = 1;
    while (username_exists($login)) {
        $login = $base . ($i <= 50 ? $i : wp_rand(1000, 99999));
        $i++;
    }
    return $login;
}

function wp_chess_create_wp_user($email, $password, $name) {
    $uid = wp_insert_user(array(
        'user_login'           => wp_chess_unique_login($email),
        'user_pass'            => $password,
        'user_email'           => $email,
        'display_name'         => $name,
        'nickname'             => $name,
        'role'                 => 'chess_player',
        'show_admin_bar_front' => 'false',
    ));
    return $uid;
}

function wp_chess_user_payload($row, $is_pro) {
    return array(
        'id'        => (string) $row->id,
        'name'      => $row->username,
        'email'     => $row->email,
        'isPremium' => (bool) $is_pro,
        'stats'     => array(
            'gamesPlayed' => (int) $row->games_played,
            'wins'        => (int) $row->wins,
            'losses'      => max(0, (int) $row->games_played - (int) $row->wins),
            'draws'       => 0,
        ),
    );
}

function wp_chess_api_register($request) {
    global $wpdb;
    $table_name = wp_chess_table();
    $p = wp_chess_json_params($request);

    $name = trim(wp_strip_all_tags((string) ($p['username'] ?? '')));
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 60) : substr($name, 0, 60);
    $email = sanitize_email(strtolower(trim((string) ($p['email'] ?? ''))));
    $password = (string) ($p['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        return wp_chess_error('Всі поля обов’язкові для заповнення');
    }
    if (!is_email($email)) {
        return wp_chess_error('Некоректний формат email адреси');
    }
    if (strlen($password) < 6) {
        return wp_chess_error('Пароль має містити щонайменше 6 символів');
    }

    $wp_user = get_user_by('email', $email);
    $row = wp_chess_get_player($email);
    $exists_msg = 'Користувач з такою поштою вже зареєстрований. Увійдіть у вкладці «Вхід» або скористайтеся «Забули пароль?»';

    if ($wp_user) {
        if (!wp_check_password($password, $wp_user->user_pass, $wp_user->ID)) {
            return wp_chess_error($exists_msg);
        }
        $uid = $wp_user->ID;
        if (empty($wp_user->roles)) {
            $wp_user->set_role('chess_player');
        }
    } else {
        // Старий запис без акаунта WP: дозволяємо лише за правильним паролем або якщо це «заглушка» оплати
        if ($row && !wp_check_password($password, $row->password_hash) && !wp_check_password('chess_player', $row->password_hash)) {
            return wp_chess_error($exists_msg);
        }
        $uid = wp_chess_create_wp_user($email, $password, $name);
        if (is_wp_error($uid)) {
            return wp_chess_error($uid->get_error_message());
        }
    }

    if ($row) {
        $wpdb->update($table_name, array('username' => $name, 'password_hash' => wp_hash_password($password)), array('id' => $row->id));
    } else {
        $ok = $wpdb->insert($table_name, array(
            'username'      => $name,
            'email'         => $email,
            'password_hash' => wp_hash_password($password),
            'status'        => 'FREE',
            'registered_at' => current_time('mysql'),
        ));
        if (!$ok) {
            return wp_chess_error('Не вдалося зберегти гравця в базі. Спробуйте ще раз.', 500);
        }
    }
    $row = wp_chess_get_player($email);
    wp_chess_login_wp_user($uid);

    return new WP_REST_Response(array(
        'success' => true,
        'user'    => wp_chess_user_payload($row, wp_chess_is_pro_email($email)),
        'message' => 'Гравця зареєстровано (роль «Шахіст»).',
    ), 200);
}

function wp_chess_api_login($request) {
    global $wpdb;
    $table_name = wp_chess_table();
    $p = wp_chess_json_params($request);

    $ident = trim((string) ($p['email'] ?? $p['username'] ?? ''));
    $password = (string) ($p['password'] ?? '');
    if ($ident === '' || $password === '') {
        return wp_chess_error('Введіть логін/email та пароль');
    }

    $wp_user = is_email($ident) ? get_user_by('email', $ident) : get_user_by('login', $ident);
    $row = null;
    if (!$wp_user) {
        $row = is_email($ident)
            ? wp_chess_get_player($ident)
            : $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE username = %s ORDER BY id ASC LIMIT 1", $ident));
        if ($row) {
            $wp_user = get_user_by('email', $row->email);
        }
    }

    $fail = wp_chess_error('Невірний логін/email або пароль', 403);

    if ($wp_user) {
        if (!wp_check_password($password, $wp_user->user_pass, $wp_user->ID)) {
            return $fail;
        }
        $email = strtolower($wp_user->user_email);
        $row = wp_chess_ensure_player($email);
        $uid = $wp_user->ID;
    } else {
        // Гравець зі старої бази без акаунта WordPress — створюємо акаунт при першому вході
        if (!$row || wp_check_password('chess_player', $row->password_hash) || !wp_check_password($password, $row->password_hash)) {
            return $fail;
        }
        $uid = wp_chess_create_wp_user(strtolower($row->email), $password, $row->username);
        if (is_wp_error($uid)) {
            return wp_chess_error($uid->get_error_message());
        }
        $email = strtolower($row->email);
    }
    if (!$row) {
        return $fail;
    }
    wp_chess_login_wp_user($uid);

    return new WP_REST_Response(array(
        'success' => true,
        'user'    => wp_chess_user_payload($row, wp_chess_is_pro_email($email)),
    ), 200);
}

function wp_chess_api_logout($request) {
    wp_clear_auth_cookie();
    wp_set_current_user(0);
    return new WP_REST_Response(array('success' => true), 200);
}

function wp_chess_api_status($request) {
    $email = sanitize_email(strtolower(trim((string) $request->get_param('email'))));
    if ($email === '') {
        return new WP_REST_Response(array('success' => true, 'isPremium' => false, 'status' => 'FREE', 'isBlocked' => false), 200);
    }
    $is_pro = wp_chess_is_pro_email($email);
    return new WP_REST_Response(array(
        'success'   => true,
        'isPremium' => $is_pro,
        'status'    => $is_pro ? 'PRO' : 'FREE',
        'isBlocked' => false,
    ), 200);
}

/**
 * 10. Реальний облік онлайн гравців та статистики партій
 */
function wp_chess_get_online_players_count() {
    $active = get_transient('wp_chess_active_sessions');
    if (!is_array($active)) {
        $active = array();
    }
    $cutoff = time() - 300;
    $changed = false;
    foreach ($active as $sid => $last_seen) {
        if ($last_seen < $cutoff) {
            unset($active[$sid]);
            $changed = true;
        }
    }
    if ($changed) {
        set_transient('wp_chess_active_sessions', $active, 600);
    }
    return max(1, count($active));
}

function wp_chess_record_session_ping($session_id = '') {
    $active = get_transient('wp_chess_active_sessions');
    if (!is_array($active)) {
        $active = array();
    }
    $now = time();
    $cutoff = $now - 300;
    foreach ($active as $sid => $last_seen) {
        if ($last_seen < $cutoff) {
            unset($active[$sid]);
        }
    }
    if (empty($session_id)) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $session_id = md5($ip . $ua);
    }
    $active[$session_id] = $now;
    set_transient('wp_chess_active_sessions', $active, 600);
    return max(1, count($active));
}

function wp_chess_api_stats($request) {
    global $wpdb;
    $table_name = wp_chess_table();
    $p = wp_chess_json_params($request);
    $online_count = wp_chess_record_session_ping(sanitize_text_field($p['sessionId'] ?? ''));
    $total_games = max((int) $wpdb->get_var("SELECT COALESCE(SUM(games_played), 0) FROM $table_name"), (int) get_option('wp_chess_total_games_count', 0));
    return new WP_REST_Response(array(
        'success'       => true,
        'totalPlayers'  => max(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name")),
        'onlinePlayers' => $online_count,
        'totalGames'    => $total_games,
    ), 200);
}

function wp_chess_api_heartbeat($request) {
    $p = wp_chess_json_params($request);
    return new WP_REST_Response(array(
        'success'       => true,
        'onlinePlayers' => wp_chess_record_session_ping(sanitize_text_field($p['sessionId'] ?? '')),
    ), 200);
}

function wp_chess_api_record_game($request) {
    global $wpdb;
    $table_name = wp_chess_table();
    $p = wp_chess_json_params($request);
    $email = sanitize_email(strtolower(trim((string) ($p['email'] ?? ''))));
    $outcome = sanitize_text_field($p['outcome'] ?? '');

    if ($email !== '' && wp_chess_ensure_player($email)) {
        if ($outcome === 'win') {
            $wpdb->query($wpdb->prepare("UPDATE $table_name SET games_played = games_played + 1, wins = wins + 1 WHERE email = %s", $email));
        } else {
            $wpdb->query($wpdb->prepare("UPDATE $table_name SET games_played = games_played + 1 WHERE email = %s", $email));
        }
    }

    $cnt = (int) get_option('wp_chess_total_games_count', 0) + 1;
    update_option('wp_chess_total_games_count', $cnt);

    return new WP_REST_Response(array(
        'success'       => true,
        'totalGames'    => $cnt,
        'totalPlayers'  => max(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name")),
        'onlinePlayers' => wp_chess_get_online_players_count(),
    ), 200);
}

/**
 * Миттєва активація PRO при переході до оплати (Приват-Конверт).
 * Гравець має бути зареєстрований; адміністратор потім підтверджує оплату або блокує PRO.
 */
function wp_chess_api_activate_pro($request) {
    $p = wp_chess_json_params($request);
    $email = sanitize_email(strtolower(trim((string) ($p['userEmail'] ?? $p['email'] ?? ''))));
    if ($email === '' && is_user_logged_in()) {
        $email = strtolower(wp_get_current_user()->user_email);
    }
    if ($email === '') {
        return wp_chess_error('Спочатку увійдіть або зареєструйтесь');
    }
    if (!wp_chess_grant_pro($email)) {
        return wp_chess_error('Гравця з такою поштою не знайдено. Зареєструйтесь у грі.');
    }
    return new WP_REST_Response(array(
        'success'   => true,
        'isPremium' => true,
        'status'    => 'PRO',
        'url'       => get_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09'),
    ), 200);
}

/**
 * 10. Стандартний текст інструкції
 */
function wp_chess_get_default_guide_content() {
    return '<h3>Як грати у «Шахи проти ШІ»:</h3>
<ol>
<li><strong>Здійснення ходів:</strong> Натисніть на фігуру вашого кольору — дозволені правилами поля підсвітяться зеленими мітками. Натисніть на мітку, щоб завершити хід.</li>
<li><strong>Режими гри:</strong> За замовчуванням увімкнено режим «Шахи проти ШІ» з автоматичним ходом бота. Також можна обрати «2 гравці» для гри удвох на одному пристрої.</li>
<li><strong>Вибір дизайну:</strong> У шапці гри натисніть «Вибір дизайну» та оберіть один із 8 стилів (Дерево, Турнірний, Мармур, Неон, Скло, Фентезі тощо). Наведіть на мініатюру на комп’ютері або торкніться на телефоні для збільшеного перегляду!</li>
<li><strong>ШІ-підказки:</strong> Натисніть кнопку «💡 Підказка ШІ» під дошкою — тренер підкаже найкращий хід та дасть тактичне пояснення. Безкоштовно доступно 10 підказок на добу.</li>
<li><strong>Встановлення іконки (PWA):</strong> Натисніть кнопку «📱 Створити іконку», щоб додати гру на робочий стіл комп’ютера чи головний екран смартфона.</li>
</ol>';
}

/**
 * 11. Автоматичне створення папки app/ та файлу index.html
 */
function wp_chess_ensure_local_app($force = false) {
    $app_dir = WP_CHESS_PLUGIN_DIR . 'app';
    $index_file = $app_dir . '/index.html';

    if (!$force && file_exists($index_file) && filesize($index_file) > 1000) {
        return true;
    }

    if (!is_dir($app_dir)) {
        @wp_mkdir_p($app_dir);
        @chmod($app_dir, 0755);
    }

    $html = wp_chess_decode_embedded_payload();
    if (!empty($html) && strlen($html) > 1000) {
        @file_put_contents($index_file, $html);
        if (!file_exists($index_file) || filesize($index_file) < 1000) {
            if ($fp = @fopen($index_file, 'wb')) {
                @fwrite($fp, $html);
                @fclose($fp);
            }
        }
        if (!file_exists($index_file) || filesize($index_file) < 1000) {
            if (!function_exists('WP_Filesystem')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }
            WP_Filesystem();
            global $wp_filesystem;
            if ($wp_filesystem) {
                $wp_filesystem->put_contents($index_file, $html, FS_CHMOD_FILE);
            }
        }
        if (file_exists($index_file) && filesize($index_file) > 1000) {
            @chmod($index_file, 0644);
            return true;
        }
    }

    return false;
}

/**
 * 12. Розпакування вбудованого автономного двигуна гри
 */
function wp_chess_decode_embedded_payload() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $payload = 'PCFkb2N0eXBlIGh0bWw+CjxodG1sIGxhbmc9InVrIj4KICA8aGVhZD4KICAgIDxtZXRhIGNoYXJzZXQ9IlVURi04IiAvPgogICAgPG1ldGEgbmFtZT0idmlld3BvcnQiIGNvbnRlbnQ9IndpZHRoPWRldmljZS13aWR0aCwgaW5pdGlhbC1zY2FsZT0xLjAiIC8+CiAgICA8dGl0bGU+0KjQsNGF0Lgg0L/RgNC+0YLQuCDQqNCGPC90aXRsZT4KICAgIDxtZXRhIG5hbWU9ImRlc2NyaXB0aW9uIiBjb250ZW50PSLQn9C+0LLQvdC+0YTRg9C90LrRhtGW0L7QvdCw0LvRjNC90LAg0YjQsNGF0L7QstCwINCz0YDQsCDQtyDQsNC90LDQu9GW0LfQvtC8INC/0LDRgNGC0ZbQuSwg0YLQsNC50LzQtdGA0LDQvNC4INGC0LAg0LPQvdGD0YfQutC40LzQuCDQvdCw0LvQsNGI0YLRg9Cy0LDQvdC90Y/QvNC4LiIgLz4KICAgIDxtZXRhIHByb3BlcnR5PSJvZzp0aXRsZSIgY29udGVudD0i0KjQsNGF0Lgg0L/RgNC+0YLQuCDQqNCGIiAvPgogICAgPG1ldGEgcHJvcGVydHk9Im9nOmRlc2NyaXB0aW9uIiBjb250ZW50PSLQn9C+0LLQvdC+0YTRg9C90LrRhtGW0L7QvdCw0LvRjNC90LAg0YjQsNGF0L7QstCwINCz0YDQsCDQtyDQsNC90LDQu9GW0LfQvtC8INC/0LDRgNGC0ZbQuSwg0YLQsNC50LzQtdGA0LDQvNC4INGC0LAg0LPQvdGD0YfQutC40LzQuCDQvdCw0LvQsNGI0YLRg9Cy0LDQvdC90Y/QvNC4LiIgLz4KICAgIDxtZXRhIHByb3BlcnR5PSJvZzp0eXBlIiBjb250ZW50PSJ3ZWJzaXRlIiAvPgogICAgPG1ldGEgbmFtZT0idHdpdHRlcjpjYXJkIiBjb250ZW50PSJzdW1tYXJ5X2xhcmdlX2ltYWdlIiAvPgogICAgPG1ldGEgbmFtZT0idGhlbWUtY29sb3IiIGNvbnRlbnQ9IiMwZjE3MmEiIC8+CiAgICA8bWV0YSBuYW1lPSJtb2JpbGUtd2ViLWFwcC1jYXBhYmxlIiBjb250ZW50PSJ5ZXMiIC8+CiAgICA8bWV0YSBuYW1lPSJhcHBsZS1tb2JpbGUtd2ViLWFwcC1jYXBhYmxlIiBjb250ZW50PSJ5ZXMiIC8+CiAgICA8bWV0YSBuYW1lPSJhcHBsZS1tb2JpbGUtd2ViLWFwcC1zdGF0dXMtYmFyLXN0eWxlIiBjb250ZW50PSJibGFjay10cmFuc2x1Y2VudCIgLz4KICAgIDxtZXRhIG5hbWU9ImFwcGxlLW1vYmlsZS13ZWItYXBwLXRpdGxlIiBjb250ZW50PSLQqNCw0YXQuCDQv9GA0L7RgtC4INCo0IYiIC8+CiAgICA8bGluayByZWw9Imljb24iIHR5cGU9ImltYWdlL3N2Zyt4bWwiIGhyZWY9Ii4vaWNvbi5zdmciIC8+CiAgICA8bGluayByZWw9Imljb24iIHR5cGU9ImltYWdlL3gtaWNvbiIgaHJlZj0iLi9mYXZpY29uLmljbyIgLz4KICAgIDxsaW5rIHJlbD0iYXBwbGUtdG91Y2gtaWNvbiIgaHJlZj0iLi9hcHBsZS10b3VjaC1pY29uLnBuZyIgLz4KICAgIDxzY3JpcHQgdHlwZT0ibW9kdWxlIiBjcm9zc29yaWdpbiBzcmM9Ii4vYXNzZXRzL2luZGV4LTkyZTRlYjcyLmpzIj48L3NjcmlwdD4KICAgIDxsaW5rIHJlbD0ic3R5bGVzaGVldCIgY3Jvc3NvcmlnaW4gaHJlZj0iLi9hc3NldHMvaW5kZXgtMGU4OWZkYTEuY3NzIj4KICA8bGluayByZWw9Im1hbmlmZXN0IiBocmVmPSIuL21hbmlmZXN0LndlYm1hbmlmZXN0Ij48c2NyaXB0IGlkPSJ2aXRlLXBsdWdpbi1wd2E6cmVnaXN0ZXItc3ciIHNyYz0iLi9yZWdpc3RlclNXLmpzIj48L3NjcmlwdD48L2hlYWQ+CiAgPGJvZHk+CiAgICA8ZGl2IGlkPSJyb290Ij48L2Rpdj4KICA8L2JvZHk+CjwvaHRtbD4KCg==';
    $cached = base64_decode($payload);
    return $cached;
}
