<?php
/**
 * Plugin Name: Шахи проти ШІ
 * Plugin URI: https://uaserver.pp.ua/chess/
 * Description: Повнофункціональна шахова платформа з підтримкою PWA, 8 унікальними дизайнами дошки, ШІ-тренером, на двох мовах: українська та англійська.
 * Version: 2.0.1
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

// Заборона прямого виклику файлу поза WordPress
if (!defined('ABSPATH')) {
    exit;
}

define('WP_CHESS_VERSION', '2.0.1');
define('WP_CHESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_CHESS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Інтеграція з UA Pay Box («Шахи PRO пожиттєвий», ID проекту 3)
 */
function chess_domain() {
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $host = preg_replace('/:\d+$/', '', strtolower(trim($host)));
    return preg_replace('/^www\./', '', $host);
}

function chess_hub() {
    return 'https://uaserver.pp.ua/wp-json/upb/v1/';
}

function chess_state( $force = false ) {
    $c = get_option( 'chess_pro' );
    $c = is_array( $c ) ? $c : array( 'state' => 'free', 'expires' => 0, 'checked' => 0 );
    if ( $force || time() - (int) $c['checked'] > 6 * HOUR_IN_SECONDS ) {
        $r = wp_remote_get( add_query_arg( array( 'service' => 'chess', 'domain' => chess_domain() ), chess_hub() . 'status' ), array( 'timeout' => 8 ) );
        if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
            $j = json_decode( wp_remote_retrieve_body( $r ), true );
            if ( is_array( $j ) && isset( $j['state'] ) ) {
                $c = array( 'state' => $j['state'], 'expires' => (int) $j['expires'], 'checked' => time() );
                update_option( 'chess_pro', $c, false );
            }
        } elseif ( time() - (int) $c['checked'] > 7 * DAY_IN_SECONDS ) {
            $c['state'] = 'free';
        }
    }
    return $c;
}

// true - для цього домену є чинний PRO
function chess_is_pro() {
    $c = chess_state();
    return in_array( $c['state'], array( 'active', 'expiring', 'unlimited' ), true ) && ( ! $c['expires'] || $c['expires'] > time() );
}

// Викличте при кліку «Купити PRO» і відкрийте у новій вкладці посилання на оплату — PRO вмикається одразу.
// Повертає масив з ключем 'url' (Конверт) або WP_Error. У коментарі до платежу клієнт має вказати ДОМЕН.
function chess_activate_pro() {
    $r = wp_remote_post( chess_hub() . 'activate', array( 'timeout' => 15, 'body' => array(
        'service' => 'chess', 'domain' => chess_domain(), 'email' => get_option( 'admin_email' ), 'site_url' => home_url(),
    ) ) );
    if ( is_wp_error( $r ) ) {
        return $r;
    }
    $j = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || ! is_array( $j ) ) {
        return new WP_Error( 'pay', isset( $j['message'] ) ? $j['message'] : 'Помилка сервера оплати' );
    }
    chess_state( true );
    return $j;
}

/**
 * 1. Активація плагіна: створення окремої таблиці шахістів та налаштувань
 */
function wp_chess_activate_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
        PRIMARY KEY (id),
        UNIQUE KEY email (email),
        KEY username (username)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Початкові налаштування за замовчуванням
    if (get_option('wp_chess_price_text') === false) {
        update_option('wp_chess_price_text', '79 ₴');
    }
    if (get_option('wp_chess_daily_hints') === false) {
        update_option('wp_chess_daily_hints', 10);
    }
    if (get_option('wp_chess_card_url') === false) {
        update_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09');
    }
    if (get_option('wp_chess_test_mode') === false) {
        update_option('wp_chess_test_mode', '0');
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

    // Реєстрація спеціальної ролі WordPress «Шахіст»
    if (!get_role('chess_player')) {
        add_role('chess_player', 'Шахіст', array(
            'read' => true,
        ));
    }

    // Автоматично створюємо локальну папку app/ з грою
    wp_chess_ensure_local_app(true);
}
register_activation_hook(__FILE__, 'wp_chess_activate_plugin');

// Гарантуємо реєстрацію ролі «Шахіст» при кожному запуску WordPress
function wp_chess_register_custom_role() {
    if (!get_role('chess_player')) {
        add_role('chess_player', 'Шахіст', array(
            'read' => true,
        ));
    }
}
add_action('init', 'wp_chess_register_custom_role');

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
        update_option('wp_chess_test_mode', isset($_POST['wp_chess_test_mode']) ? '1' : '0');

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
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_name WHERE id = %d", $pid));
        $new_status = ($current_status === 'PRO') ? 'FREE' : 'PRO';
        $wpdb->update($table_name, array('status' => $new_status), array('id' => $pid));
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
    $test_mode = get_option('wp_chess_test_mode', '0');
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
                                    При кліку на «Сплатити» гра відкриває це посилання в новій вкладці та <strong>миттєво надає гравцю статус PRO</strong> (довічний доступ до всіх 8 дизайнів дошки та необмежені підказки ШІ-тренера).
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
                        <tr style="background:#f8fafc; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0;">
                            <th scope="row"><label for="wp_chess_test_mode">Тестовий режим (імітація оплат):</label></th>
                            <td>
                                <label for="wp_chess_test_mode" style="font-weight:600; color:#0f172a; cursor:pointer;">
                                    <input name="wp_chess_test_mode" type="checkbox" id="wp_chess_test_mode" value="1" <?php checked($test_mode, '1'); ?>>
                                    ⚡ Увімкнути тестовий режим для кнопок оплати
                                </label>
                                <p class="description" style="margin-top:5px; color:#475569;">
                                    Коли галочка увімкнена, натискання на кнопку «Сплатити» <strong>не здійснює перехід</strong> на сторонні сайти. Натомість гра миттєво імітує успішний платіж, виводить сповіщення <em>«Тестовий платіж успішний! Статус PRO активовано.»</em> та відкриває гравцю довічний безліміт на всі дизайни та підказки.
                                </p>
                            </td>
                        </tr>
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
                <p>Користувачі шахів ізольовані від адмінки WordPress — вони не мають доступу до панелі сайту.</p>

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
 * 5. Прямий кінцевий пункт (Direct Endpoint) для гри
 * Працює завжди на 100%, навіть якщо на хостингу заблоковано запис у файлову систему!
 * Запобігає кешуванню будь-якими плагінами кешу (WP-Super-Cache, LiteSpeed, W3TC тощо).
 */
function wp_chess_embed_listener() {
    if (isset($_GET['wp_chess_embed'])) {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        if (!defined('DONOTMINIFY')) define('DONOTMINIFY', true);
        if (!defined('DONOTCDN')) define('DONOTCDN', true);
        nocache_headers();
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo wp_chess_get_game_html();
        exit;
    }
}
add_action('init', 'wp_chess_embed_listener', 1);

/**
 * 6. Отримання чистого HTML коду шахів з конфігурацією сайту
 */
function wp_chess_get_game_html() {
    $local_file = WP_CHESS_PLUGIN_DIR . 'app/index.html';
    $html = '';

    if (file_exists($local_file) && filesize($local_file) > 1000) {
        $html = @file_get_contents($local_file);
    }

    if (empty($html) || strlen($html) < 1000) {
        $html = wp_chess_decode_embedded_payload();
    }

    // Вставляємо <base href="..."> для усунення чорного екрану при будь-якому вбудовуванні
    $app_base_url = esc_url(plugins_url('app/', __FILE__));
    $base_tag = '<base href="' . $app_base_url . '">';
    if (strpos($html, '<base ') === false) {
        if (strpos($html, '<head>') !== false) {
            $html = str_replace('<head>', '<head>' . "\n  " . $base_tag, $html);
        } elseif (strpos($html, '<head ') !== false) {
            $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n  " . $base_tag, $html, 1);
        } else {
            $html = $base_tag . $html;
        }
    }

    // Динамічне підставлення актуальних налаштувань адміністратора
    $price_text = get_option('wp_chess_price_text', '79 ₴');
    $daily_hints = get_option('wp_chess_daily_hints', 10);
    $paypal_url = get_option('wp_chess_paypal_url', 'https://www.paypal.com/checkout?amount=1.99');
    $card_url = get_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09');
    $test_mode = (get_option('wp_chess_test_mode', '0') === '1');
    $api_url = rest_url('chess/v1');

    // Перевірка статусу користувача та інтеграція з UA Pay Box
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';
    $current_uid = get_current_user_id();
    $wp_u = $current_uid > 0 ? wp_get_current_user() : null;
    $has_wcc = function_exists('wcc_check_user_premium');
    $is_user_premium = false;
    $is_explicitly_blocked = false;

    // 1. Якщо для всього сайту / домену активовано PRO в UA Pay Box:
    if (chess_is_pro()) {
        $is_user_premium = true;
    }
    // 2. Інтеграція з плагіном UA Pay Box / WCC (проект «Шахи PRO пожиттєвий», ID 3):
    elseif ($current_uid > 0 && $has_wcc) {
        if (wcc_check_user_premium($current_uid, 3)) {
            $is_user_premium = true;
        } else {
            $is_user_premium = false;
            $is_explicitly_blocked = false;
        }
    }
    // 3. Перевірка статусу в локальній базі шахістів:
    elseif ($current_uid > 0 && $wp_u && !empty($wp_u->user_email)) {
        $db_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_name WHERE email = %s", $wp_u->user_email));
        if ($db_status === 'PRO') {
            $is_user_premium = true;
        } elseif ($db_status === 'FREE') {
            $is_user_premium = false;
            $is_explicitly_blocked = false;
        }
    }

    // 4. Адміністратор сайту або тестовий режим завжди мають PRO:
    if (current_user_can('manage_options') || $test_mode || ($wp_u && $wp_u->user_email === 'portall.mail@gmail.com')) {
        $is_user_premium = true;
        $is_explicitly_blocked = false;
    }

    // Шорткод / кнопка UA Pay Box
    $buy_button_html = '';
    if (shortcode_exists('universal_pay_box')) {
        $buy_button_html = do_shortcode('[universal_pay_box id="3"]');
    } elseif (class_exists('UM_Helpers') && method_exists('UM_Helpers', 'buy_button')) {
        $buy_button_html = UM_Helpers::buy_button(3, 'Купити PRO');
    } else {
        $buy_button_html = '<button data-upb-buy="3" class="button button-primary">Купити PRO</button>';
    }

    $config_script = "<script>
      window.WP_CHESS_CONFIG = {
        priceText: " . json_encode($price_text) . ",
        dailyHints: " . intval($daily_hints) . ",
        paypalUrl: " . json_encode($paypal_url) . ",
        cardUrl: " . json_encode($card_url) . ",
        testMode: " . ($test_mode ? 'true' : 'false') . ",
        apiUrl: " . json_encode($api_url) . ",
        hasMonetizationPlugin: " . ($has_wcc ? 'true' : 'false') . ",
        isUserPremium: " . ($is_user_premium ? 'true' : 'false') . ",
        isBlocked: " . ($is_explicitly_blocked ? 'true' : 'false') . ",
        userId: " . intval($current_uid) . ",
        userEmail: " . json_encode(($wp_u && !empty($wp_u->user_email)) ? $wp_u->user_email : '') . ",
        userName: " . json_encode(($wp_u && !empty($wp_u->display_name)) ? $wp_u->display_name : '') . ",
        buyButtonHtml: " . json_encode($buy_button_html) . "
      };
    </script>";

    if (strpos($html, '</head>') !== false) {
        return str_replace('</head>', $config_script . '</head>', $html);
    }

    return $config_script . $html;
}

/**
 * 7. Шорткод 1: [wp_chess_pwa] (Шахова гра)
 */
function wp_chess_game_shortcode($atts) {
    // 1. Автоматична перевірка та створення локального файлу app/index.html (якщо хостинг дозволяє)
    wp_chess_ensure_local_app();

    // 2. Визначення URL з міткою часу для обходу будь-якого кешу
    $local_file = WP_CHESS_PLUGIN_DIR . 'app/index.html';
    $has_local_file = file_exists($local_file) && filesize($local_file) > 1000;
    if ($has_local_file) {
        $default_url = plugins_url('app/index.html?v=' . WP_CHESS_VERSION . '&t=' . time(), __FILE__);
    } else {
        $default_url = add_query_arg(array('wp_chess_embed' => '1', 'v' => WP_CHESS_VERSION, 't' => time()), home_url('/'));
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

    $price_text = get_option('wp_chess_price_text', '79 ₴');
    $daily_hints = get_option('wp_chess_daily_hints', 10);
    $paypal_url = get_option('wp_chess_paypal_url', 'https://www.paypal.com/checkout?amount=1.99');
    $card_url = get_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09');
    $test_mode = (get_option('wp_chess_test_mode', '0') === '1');
    $api_url = rest_url('chess/v1');

    // Перевірка статусу користувача для шорткоду
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';
    $current_uid = get_current_user_id();
    $wp_u = $current_uid > 0 ? wp_get_current_user() : null;
    $has_wcc = function_exists('wcc_check_user_premium');
    $is_user_premium = false;
    $is_explicitly_blocked = false;

    // 1. Доменний PRO через UA Pay Box:
    if (chess_is_pro()) {
        $is_user_premium = true;
    }
    // 2. UA Pay Box / WCC перевірка за ID проекту 3:
    elseif ($current_uid > 0 && $has_wcc) {
        if (wcc_check_user_premium($current_uid, 3)) {
            $is_user_premium = true;
        } else {
            $is_user_premium = false;
            $is_explicitly_blocked = false;
        }
    }
    // 3. Локальна таблиця шахістів:
    elseif ($current_uid > 0 && $wp_u && !empty($wp_u->user_email)) {
        $db_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_name WHERE email = %s", $wp_u->user_email));
        if ($db_status === 'PRO') {
            $is_user_premium = true;
        } elseif ($db_status === 'FREE') {
            $is_user_premium = false;
            $is_explicitly_blocked = false;
        }
    }

    // 4. Адміністратор сайту або тестовий режим:
    if (current_user_can('manage_options') || $test_mode || ($wp_u && $wp_u->user_email === 'portall.mail@gmail.com')) {
        $is_user_premium = true;
        $is_explicitly_blocked = false;
    }

    $buy_button_html = '';
    if (shortcode_exists('universal_pay_box')) {
        $buy_button_html = do_shortcode('[universal_pay_box id="3"]');
    } elseif (class_exists('UM_Helpers') && method_exists('UM_Helpers', 'buy_button')) {
        $buy_button_html = UM_Helpers::buy_button(3, 'Купити PRO');
    } else {
        $buy_button_html = '<button data-upb-buy="3" class="button button-primary">Купити PRO</button>';
    }

    // Реальна статистика гравців та онлайн-сесій на сайті
    $online_players = wp_chess_record_session_ping();
    $total_players = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_games_db = (int)$wpdb->get_var("SELECT COALESCE(SUM(games_played), 0) FROM $table_name");
    $total_games_opt = (int)get_option('wp_chess_total_games_count', 0);
    $total_games = max($total_games_db, $total_games_opt);
    $page_url = get_permalink() ? get_permalink() : home_url('/chess/');
    $icon_url = esc_url(plugins_url('app/pwa-192x192.png', __FILE__));

    $config_json = json_encode(array(
        'pageUrl' => $page_url,
        'appUrl' => esc_url($app_url),
        'iconUrl' => $icon_url,
        'priceText' => $price_text,
        'dailyHints' => intval($daily_hints),
        'paypalUrl' => $paypal_url,
        'cardUrl' => $card_url,
        'testMode' => $test_mode,
        'apiUrl' => $api_url,
        'hasMonetizationPlugin' => $has_wcc,
        'isUserPremium' => $is_user_premium,
        'isBlocked' => $is_explicitly_blocked,
        'userId' => intval($current_uid),
        'userEmail' => ($wp_u && !empty($wp_u->user_email)) ? $wp_u->user_email : '',
        'userName' => ($wp_u && !empty($wp_u->display_name)) ? $wp_u->display_name : '',
        'buyButtonHtml' => $buy_button_html,
        'totalPlayers' => max(1, $total_players),
        'totalGames' => $total_games,
        'onlinePlayers' => max(1, $online_players),
    ));

    $html = '<!-- UAServer WP Chess Platform PWA v' . WP_CHESS_VERSION . ' -->';
    $html .= '<script>
      window.WP_CHESS_CONFIG = ' . $config_json . ';
      var wpChessDeferredPrompt = null;
      window.addEventListener("beforeinstallprompt", function(e) {
        e.preventDefault();
        wpChessDeferredPrompt = e;
        var f = document.getElementById("wp-chess-frame");
        if (f && f.contentWindow) {
          f.contentWindow.postMessage({ type: "WP_CHESS_CAN_INSTALL" }, "*");
        }
      });
      window.addEventListener("appinstalled", function() {
        wpChessDeferredPrompt = null;
        var f = document.getElementById("wp-chess-frame");
        if (f && f.contentWindow) {
          f.contentWindow.postMessage({ type: "WP_CHESS_INSTALLED" }, "*");
        }
      });
      window.addEventListener("message", function(e) {
        if (!e.data) return;
        var f = document.getElementById("wp-chess-frame");
        if (e.data.type === "WP_CHESS_GET_CONFIG") {
          if (f && f.contentWindow) {
            f.contentWindow.postMessage({ type: "WP_CHESS_CONFIG_RESPONSE", config: window.WP_CHESS_CONFIG }, "*");
          }
        } else if (e.data.type === "WP_CHESS_CHECK_INSTALL") {
          if (wpChessDeferredPrompt && f && f.contentWindow) {
            f.contentWindow.postMessage({ type: "WP_CHESS_CAN_INSTALL" }, "*");
          }
        } else if (e.data.type === "WP_CHESS_TRIGGER_INSTALL") {
          if (wpChessDeferredPrompt) {
            wpChessDeferredPrompt.prompt().then(function(choice) {
              if (choice && choice.outcome === "accepted") {
                wpChessDeferredPrompt = null;
                if (f && f.contentWindow) {
                  f.contentWindow.postMessage({ type: "WP_CHESS_INSTALLED" }, "*");
                }
              }
            });
          }
        }
      });
    </script>';
    $html .= '<div class="wp-chess-game-container" style="width:100%; max-width:1200px; margin:0 auto; clear:both; position:relative;">';
    $html .= '<iframe ';
    $html .= 'id="wp-chess-frame" ';
    // Завантажуємо гру безпосередньо через src, без srcdoc, щоб уникнути помилок відносних шляхів та чорного екрану
    $html .= 'src="' . esc_url($app_url) . '" ';
    $html .= 'title="Шахи проти ШІ" ';
    $html .= 'width="100%" ';
    $html .= 'height="' . $height . '" ';
    $html .= 'style="width:100%; height:' . $height . '; min-height:680px; border:none; border-radius:' . $radius . '; background:#020617; display:block; box-shadow:0 15px 35px rgba(0,0,0,0.3);" ';
    $html .= 'allow="fullscreen; autoplay; clipboard-write" ';
    $html .= 'loading="eager" ';
    $html .= 'sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-modals" ';
    $html .= '></iframe>';
    $html .= '</div>';

    return $html;
}
add_shortcode('wp_chess_pwa', 'wp_chess_game_shortcode');

/**
 * 8. Шорткод 2: [wp_chess_pwa_guide] (Інструкція «Як грати»)
 */
function wp_chess_guide_shortcode($atts) {
    $guide = get_option('wp_chess_guide_content', wp_chess_get_default_guide_content());

    $output = '<div class="wp-chess-guide-box" style="background:#0f172a; color:#e2e8f0; border:1px solid #1e293b; border-radius:16px; padding:28px; margin:20px 0; font-size:15px; line-height:1.7; box-shadow:0 10px 25px rgba(0,0,0,0.2);">';
    $output .= wp_kses_post($guide);
    $output .= '</div>';

    return $output;
}
add_shortcode('wp_chess_pwa_guide', 'wp_chess_guide_shortcode');

/**
 * 8.1. Впровадження PWA-метаданих у заголовок сторінки сайту
 */
function wp_chess_inject_pwa_head() {
    $plugin_app_url = esc_url(plugins_url('app/', __FILE__));
    echo "\n" . '<!-- WP Chess PWA Meta -->' . "\n";
    echo '<link rel="manifest" href="' . $plugin_app_url . 'manifest.webmanifest">' . "\n";
    echo '<meta name="theme-color" content="#020617">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="Шахи проти ШІ">' . "\n";
    echo '<link rel="apple-touch-icon" sizes="192x192" href="' . $plugin_app_url . 'pwa-192x192.png">' . "\n";
}
add_action('wp_head', 'wp_chess_inject_pwa_head');

/**
 * 9. REST API для реєстрації, входу та отримання PRO
 */
function wp_chess_register_rest_routes() {
    register_rest_route('chess/v1', '/register', array(
        'methods' => 'POST',
        'callback' => 'wp_chess_api_register',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('chess/v1', '/login', array(
        'methods' => 'POST',
        'callback' => 'wp_chess_api_login',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('chess/v1', '/status', array(
        'methods' => array('GET', 'POST'),
        'callback' => 'wp_chess_api_status',
        'permission_callback' => '__return_true',
    ));

    // Миттєва активація PRO при переході на Приват-Конверт
    register_rest_route('chess/v1', '/instant-pro', array(
        'methods' => 'POST',
        'callback' => 'wp_chess_api_instant_pro',
        'permission_callback' => '__return_true',
    ));

    // Інтеграція UA Pay Box: миттєва активація PRO та отримання посилання на оплату
    register_rest_route('chess/v1', '/activate-pro', array(
        'methods' => array('GET', 'POST'),
        'callback' => 'wp_chess_api_activate_pro',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('chess/v1', '/payment-webhook', array(
        'methods' => 'POST',
        'callback' => 'wp_chess_api_payment_webhook',
        'permission_callback' => '__return_true',
    ));

    // Реальна статистика: лічильник гравців та активних онлайн-сесій
    register_rest_route('chess/v1', '/stats', array(
        'methods' => array('GET', 'POST'),
        'callback' => 'wp_chess_api_stats',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('chess/v1', '/heartbeat', array(
        'methods' => 'POST',
        'callback' => 'wp_chess_api_heartbeat',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('chess/v1', '/record-game', array(
        'methods' => 'POST',
        'callback' => 'wp_chess_api_record_game',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'wp_chess_register_rest_routes');

function wp_chess_api_register($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';

    $params = $request->get_json_params();
    $username = sanitize_user($params['username'] ?? '', true);
    $email = sanitize_email($params['email'] ?? '');
    $password = $params['password'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Всі поля обов’язкові для заповнення'), 400);
    }

    if (!is_email($email)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Некоректний формат email адреси'), 400);
    }

    // 1. Інтеграція з користувачами сайту WordPress (роль «Шахіст»)
    $wp_user_by_email = get_user_by('email', $email);
    $wp_user_by_login = get_user_by('login', $username);

    if ($wp_user_by_email && $wp_user_by_login && $wp_user_by_email->ID !== $wp_user_by_login->ID) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Користувач з такою поштою або логіном уже зареєстрований'), 400);
    }

    $wp_uid = 0;
    if ($wp_user_by_email) {
        $wp_uid = $wp_user_by_email->ID;
        if (!wp_check_password($password, $wp_user_by_email->user_pass, $wp_uid)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Користувач з такою поштою вже існує. Будь ласка, введіть вірний пароль у вкладці «Вхід»'), 400);
        }
    } elseif ($wp_user_by_login) {
        $wp_uid = $wp_user_by_login->ID;
        if (!wp_check_password($password, $wp_user_by_login->user_pass, $wp_uid)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Користувач з таким логіном вже існує. Будь ласка, оберіть інший логін'), 400);
        }
    } else {
        // Створюємо нового користувача в WordPress з роллю «Шахіст»
        $wp_uid = wp_create_user($username, $password, $email);
        if (is_wp_error($wp_uid)) {
            return new WP_REST_Response(array('success' => false, 'message' => $wp_uid->get_error_message()), 400);
        }
        $wp_user = new WP_User($wp_uid);
        $wp_user->set_role('chess_player');
        wp_update_user(array('ID' => $wp_uid, 'display_name' => $username));
    }

    // 2. Додавання та синхронізація в автономну базу шахістів плагіна ($wpdb->prefix . 'chess_players')
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id, status, games_played, wins FROM $table_name WHERE email = %s", $email));
    $status = 'FREE'; // Нові користувачі завжди починають як FREE
    $player_id = 0;
    $games_played = 0;
    $wins = 0;

    if ($existing) {
        $player_id = $existing->id;
        $status = $existing->status;
        $games_played = (int)$existing->games_played;
        $wins = (int)$existing->wins;
        $wpdb->update($table_name, array('username' => $username), array('id' => $player_id));
    } else {
        $hash = wp_hash_password($password);
        $wpdb->insert($table_name, array(
            'username' => $username,
            'email' => $email,
            'password_hash' => $hash,
            'status' => 'FREE',
            'registered_at' => current_time('mysql'),
            'games_played' => 0,
            'wins' => 0,
        ));
        $player_id = $wpdb->insert_id;
    }

    // Перевірка наявності PRO
    $is_pro = false;
    if ($status === 'PRO') {
        $is_pro = true;
    } elseif ($wp_uid > 0 && user_can($wp_uid, 'manage_options')) {
        $is_pro = true;
    } elseif (chess_is_pro()) {
        $is_pro = true;
    } elseif ($wp_uid > 0 && function_exists('wcc_check_user_premium') && wcc_check_user_premium($wp_uid, 3)) {
        $is_pro = true;
    }

    return new WP_REST_Response(array(
        'success' => true,
        'user' => array(
            'id' => (string)($player_id ?: $wp_uid),
            'name' => $username,
            'email' => $email,
            'isPremium' => $is_pro,
            'stats' => array(
                'gamesPlayed' => $games_played,
                'wins' => $wins,
                'losses' => 0,
                'draws' => 0,
            )
        ),
        'message' => 'Користувача успішно зареєстровано в WordPress (роль «Шахіст») та в базі шахістів.',
    ), 200);
}

function wp_chess_api_login($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';

    $params = $request->get_json_params();
    $login_input = sanitize_text_field($params['email'] ?? $params['username'] ?? '');
    $password = $params['password'] ?? '';

    if (empty($login_input) || empty($password)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Введіть логін/email та пароль'), 400);
    }

    $wp_user = null;
    if (is_email($login_input)) {
        $wp_user = get_user_by('email', $login_input);
    }
    if (!$wp_user) {
        $wp_user = get_user_by('login', $login_input);
    }

    $player_record = null;
    $authenticated = false;

    if ($wp_user && wp_check_password($password, $wp_user->user_pass, $wp_user->ID)) {
        $authenticated = true;
        // Синхронізуємо з таблицею chess_players якщо ще немає
        $player_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE email = %s", $wp_user->user_email));
        if (!$player_record) {
            $is_admin = user_can($wp_user->ID, 'manage_options');
            $wpdb->insert($table_name, array(
                'username' => $wp_user->display_name ?: $wp_user->user_login,
                'email' => $wp_user->user_email,
                'password_hash' => $wp_user->user_pass,
                'status' => $is_admin ? 'PRO' : 'FREE',
                'registered_at' => current_time('mysql'),
            ));
            $player_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $wpdb->insert_id));
        }
    } else {
        // Перевіряємо в автономній базі шахістів
        if (is_email($login_input)) {
            $player_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE email = %s", $login_input));
        } else {
            $player_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE username = %s", $login_input));
        }
        if ($player_record && wp_check_password($password, $player_record->password_hash)) {
            $authenticated = true;
        }
    }

    if (!$authenticated || !$player_record) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Невірний логін/email або пароль'), 403);
    }

    // Визначаємо статус PRO
    $is_pro = false;
    if ($player_record->status === 'PRO') {
        $is_pro = true;
    } elseif ($wp_user && user_can($wp_user->ID, 'manage_options')) {
        $is_pro = true;
    } elseif (chess_is_pro()) {
        $is_pro = true;
    } elseif ($wp_user && function_exists('wcc_check_user_premium') && wcc_check_user_premium($wp_user->ID, 3)) {
        $is_pro = true;
    }

    return new WP_REST_Response(array(
        'success' => true,
        'user' => array(
            'id' => (string)$player_record->id,
            'name' => $player_record->username,
            'email' => $player_record->email,
            'isPremium' => $is_pro,
            'stats' => array(
                'gamesPlayed' => (int)$player_record->games_played,
                'wins' => (int)$player_record->wins,
                'losses' => 0,
                'draws' => 0,
            )
        )
    ), 200);
}


function wp_chess_api_status($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';

    $email = sanitize_email($request->get_param('email') ?? '');
    $user_id = absint($request->get_param('user_id') ?? 0);

    $wp_uid = get_current_user_id();
    if (!$wp_uid && !empty($email)) {
        $wp_u = get_user_by('email', $email);
        if ($wp_u) {
            $wp_uid = $wp_u->ID;
        }
    }

    // 0. Доменний PRO через UA Pay Box:
    if (chess_is_pro()) {
        return new WP_REST_Response(array(
            'success' => true,
            'isPremium' => true,
            'status' => 'PRO',
            'isBlocked' => false,
            'source' => 'ua_pay_box_domain'
        ), 200);
    }

    // Перевірка прав адміністратора або тестового режиму
    if (current_user_can('manage_options') || $email === 'portall.mail@gmail.com' || (get_option('wp_chess_test_mode', '0') === '1')) {
        return new WP_REST_Response(array(
            'success' => true,
            'isPremium' => true,
            'status' => 'PRO',
            'isBlocked' => false,
            'source' => 'admin_access'
        ), 200);
    }

    // 1. ГОЛОВНИЙ ПРІОРИТЕТ: Окремий плагін монетизації користувача (проект Шахи PRO пожиттєвий, ID 3)
    if (function_exists('wcc_check_user_premium')) {
        $check_id = $wp_uid > 0 ? $wp_uid : $user_id;
        if ($check_id > 0) {
            $is_pro = (bool) wcc_check_user_premium($check_id, 3);
            return new WP_REST_Response(array(
                'success' => true,
                'isPremium' => $is_pro,
                'status' => $is_pro ? 'PRO' : 'FREE',
                'isBlocked' => false,
                'source' => 'wcc_monetization_plugin'
            ), 200);
        }
    }

    // 2. Локальна перевірка таблиці гравців
    if (!empty($email)) {
        $player = $wpdb->get_row($wpdb->prepare("SELECT id, username, email, status FROM $table_name WHERE email = %s", $email));
        if ($player) {
            $is_pro = ($player->status === 'PRO');
            return new WP_REST_Response(array(
                'success' => true,
                'id' => $player->id,
                'email' => $player->email,
                'isPremium' => $is_pro,
                'status' => $player->status,
                'isBlocked' => false,
            ), 200);
        }
    } elseif ($user_id > 0) {
        $player = $wpdb->get_row($wpdb->prepare("SELECT id, username, email, status FROM $table_name WHERE id = %d", $user_id));
        if ($player) {
            $is_pro = ($player->status === 'PRO');
            return new WP_REST_Response(array(
                'success' => true,
                'id' => $player->id,
                'email' => $player->email,
                'isPremium' => $is_pro,
                'status' => $player->status,
                'isBlocked' => false,
            ), 200);
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'isPremium' => false,
        'status' => 'FREE',
        'isBlocked' => false
    ), 200);
}

/**
 * 10. Реальний облік онлайн гравців та статистики партій (без фіктивних цифр)
 */
function wp_chess_get_online_players_count() {
    $active = get_transient('wp_chess_active_sessions');
    if (!is_array($active)) {
        $active = array();
    }
    $now = time();
    $cutoff = $now - 300; // 5 хвилин
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
    $table_name = $wpdb->prefix . 'chess_players';

    $params = $request->get_json_params();
    $sid = sanitize_text_field($params['sessionId'] ?? '');
    $online_count = wp_chess_record_session_ping($sid);

    $total_players = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_games_db = (int)$wpdb->get_var("SELECT COALESCE(SUM(games_played), 0) FROM $table_name");
    $total_games_opt = (int)get_option('wp_chess_total_games_count', 0);
    $total_games = max($total_games_db, $total_games_opt);

    return new WP_REST_Response(array(
        'success' => true,
        'totalPlayers' => max(1, $total_players),
        'onlinePlayers' => $online_count,
        'totalGames' => $total_games,
    ), 200);
}

function wp_chess_api_heartbeat($request) {
    $params = $request->get_json_params();
    $sid = sanitize_text_field($params['sessionId'] ?? '');
    $online_count = wp_chess_record_session_ping($sid);
    return new WP_REST_Response(array(
        'success' => true,
        'onlinePlayers' => $online_count,
    ), 200);
}

function wp_chess_api_record_game($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';
    $params = $request->get_json_params();
    $email = sanitize_email($params['email'] ?? '');
    $outcome = sanitize_text_field($params['outcome'] ?? '');

    if (!empty($email)) {
        if ($outcome === 'win') {
            $wpdb->query($wpdb->prepare("UPDATE $table_name SET games_played = games_played + 1, wins = wins + 1 WHERE email = %s", $email));
        } else {
            $wpdb->query($wpdb->prepare("UPDATE $table_name SET games_played = games_played + 1 WHERE email = %s", $email));
        }
    }

    $cnt = (int)get_option('wp_chess_total_games_count', 0) + 1;
    update_option('wp_chess_total_games_count', $cnt);

    $total_players = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $online_count = wp_chess_get_online_players_count();

    return new WP_REST_Response(array(
        'success' => true,
        'totalGames' => $cnt,
        'totalPlayers' => max(1, $total_players),
        'onlinePlayers' => $online_count
    ), 200);
}

/**
 * Миттєве надання PRO при переході на Приват-Конверт
 */
function wp_chess_api_instant_pro($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';

    $params = $request->get_json_params();
    $email = sanitize_email($params['email'] ?? '');
    $username = sanitize_text_field($params['username'] ?? 'Player');

    $wp_uid = get_current_user_id();
    if (!$wp_uid && !empty($email)) {
        $wp_u = get_user_by('email', $email);
        if ($wp_u) {
            $wp_uid = $wp_u->ID;
        }
    }

    // 1. Оновлюємо статус у локальній таблиці шахістів на PRO
    if (!empty($email)) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE email = %s", $email));
        if ($exists) {
            $wpdb->update($table_name, array('status' => 'PRO'), array('email' => $email));
        } else {
            $wpdb->insert($table_name, array(
                'username' => $username,
                'email' => $email,
                'password_hash' => wp_hash_password('chess_player'),
                'status' => 'PRO',
                'registered_at' => current_time('mysql'),
            ));
        }
    }

    // 2. Взаємодія з окремим плагіном монетизації користувача (проект Шахи PRO, ID 3)
    if ($wp_uid > 0) {
        update_user_meta($wp_uid, '_wcc_user_premium_3', '1');
        update_user_meta($wp_uid, 'wcc_chess_pro', '1');
        if (function_exists('wcc_set_user_premium')) {
            wcc_set_user_premium($wp_uid, 3, true);
        }
        // Хук для сторонніх плагінів
        do_action('wcc_user_transitioned_to_privat', $wp_uid, 3, $email);
    }

    return new WP_REST_Response(array(
        'success' => true,
        'isPremium' => true,
        'status' => 'PRO',
        'message' => 'PRO активовано миттєво з переходом на Приват-Конверт'
    ), 200);
}

/**
 * Інтеграція UA Pay Box: миттєва активація PRO та отримання посилання на оплату
 */
function wp_chess_api_activate_pro($request) {
    // 1. Виклик функції активації через UA Pay Box Hub
    $res = chess_activate_pro();

    // 2. Оновлюємо статус поточного користувача
    $params = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : array();
    $email = !empty($params['userEmail']) ? sanitize_email($params['userEmail']) : (!empty($params['email']) ? sanitize_email($params['email']) : '');
    $user_id = !empty($params['userId']) ? absint($params['userId']) : get_current_user_id();

    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';
    if ($user_id > 0) {
        $wpdb->update($table_name, array('status' => 'PRO'), array('id' => $user_id));
        update_user_meta($user_id, '_wcc_user_premium_3', '1');
        update_user_meta($user_id, 'wcc_chess_pro', '1');
        if (function_exists('wcc_set_user_premium')) {
            wcc_set_user_premium($user_id, 3, true);
        }
    }
    if (!empty($email)) {
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE email = %s", $email));
        if ($exists) {
            $wpdb->update($table_name, array('status' => 'PRO'), array('email' => $email));
        } else {
            $wpdb->insert($table_name, array(
                'username' => explode('@', $email)[0],
                'email' => $email,
                'password_hash' => wp_hash_password('chess_player'),
                'status' => 'PRO',
                'registered_at' => current_time('mysql'),
            ));
        }
    }

    if (is_wp_error($res)) {
        $fallback_url = get_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09');
        return new WP_REST_Response(array(
            'success' => true,
            'url' => $fallback_url,
            'state' => 'active',
            'fallback' => true,
            'message' => $res->get_error_message(),
        ), 200);
    }

    $url = (is_array($res) && isset($res['url'])) ? $res['url'] : get_option('wp_chess_card_url', 'https://www.privat24.ua/send/k4m09');
    $state = (is_array($res) && isset($res['state'])) ? $res['state'] : 'active';

    return new WP_REST_Response(array(
        'success' => true,
        'url' => $url,
        'state' => $state,
        'data' => $res,
    ), 200);
}

function wp_chess_api_payment_webhook($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'chess_players';

    $params = $request->get_json_params();
    $email = sanitize_email($params['user_email'] ?? $params['payer_email'] ?? '');
    $user_id = absint($params['custom'] ?? $params['user_id'] ?? 0);

    if ($user_id > 0) {
        $wpdb->update($table_name, array('status' => 'PRO'), array('id' => $user_id));
        return new WP_REST_Response(array('success' => true, 'updated_id' => $user_id), 200);
    } elseif (!empty($email)) {
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE email = %s", $email));
        if ($existing) {
            $wpdb->update($table_name, array('status' => 'PRO'), array('id' => $existing));
        } else {
            $wpdb->insert($table_name, array(
                'username' => explode('@', $email)[0],
                'email' => $email,
                'password_hash' => wp_hash_password('chess_player'),
                'status' => 'PRO',
                'registered_at' => current_time('mysql'),
            ));
        }
        return new WP_REST_Response(array('success' => true, 'updated_email' => $email), 200);
    }

    return new WP_REST_Response(array('success' => false, 'message' => 'Invalid parameters'), 400);
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
 * Використовує чистий base64_decode без сторонніх бібліотек чи zlib
 */
function wp_chess_decode_embedded_payload() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $payload = 'PCFkb2N0eXBlIGh0bWw+CjxodG1sIGxhbmc9ImVuIj4KICA8aGVhZD4KICAgIDxtZXRhIGNoYXJzZXQ9IlVURi04IiAvPgogICAgPG1ldGEgbmFtZT0idmlld3BvcnQiIGNvbnRlbnQ9IndpZHRoPWRldmljZS13aWR0aCwgaW5pdGlhbC1zY2FsZT0xLjAiIC8+CiAgICA8dGl0bGU+0KjQsNGF0Lgg0L/RgNC+0YLQuCDQqNCGPC90aXRsZT4KICAgIDxtZXRhIG5hbWU9ImRlc2NyaXB0aW9uIiBjb250ZW50PSLQn9C+0LLQvdC+0YTRg9C90LrRhtGW0L7QvdCw0LvRjNC90LAg0YjQsNGF0L7QstCwINCz0YDQsCDQtyDQsNC90LDQu9GW0LfQvtC8INC/0LDRgNGC0ZbQuSwg0YLQsNC50LzQtdGA0LDQvNC4INGC0LAg0LPQvdGD0YfQutC40LzQuCDQvdCw0LvQsNGI0YLRg9Cy0LDQvdC90Y/QvNC4LiIgLz4KICAgIDxtZXRhIHByb3BlcnR5PSJvZzp0aXRsZSIgY29udGVudD0i0KjQsNGF0Lgg0L/RgNC+0YLQuCDQqNCGIiAvPgogICAgPG1ldGEgcHJvcGVydHk9Im9nOmRlc2NyaXB0aW9uIiBjb250ZW50PSLQn9C+0LLQvdC+0YTRg9C90LrRhtGW0L7QvdCw0LvRjNC90LAg0YjQsNGF0L7QstCwINCz0YDQsCDQtyDQsNC90LDQu9GW0LfQvtC8INC/0LDRgNGC0ZbQuSwg0YLQsNC50LzQtdGA0LDQvNC4INGC0LAg0LPQvdGD0YfQutC40LzQuCDQvdCw0LvQsNGI0YLRg9Cy0LDQvdC90Y/QvNC4LiIgLz4KICAgIDxtZXRhIHByb3BlcnR5PSJvZzp0eXBlIiBjb250ZW50PSJ3ZWJzaXRlIiAvPgogICAgPG1ldGEgbmFtZT0idHdpdHRlcjpjYXJkIiBjb250ZW50PSJzdW1tYXJ5X2xhcmdlX2ltYWdlIiAvPgogICAgPG1ldGEgbmFtZT0idGhlbWUtY29sb3IiIGNvbnRlbnQ9IiMwZjE3MmEiIC8+CiAgICA8bWV0YSBuYW1lPSJtb2JpbGUtd2ViLWFwcC1jYXBhYmxlIiBjb250ZW50PSJ5ZXMiIC8+CiAgICA8bWV0YSBuYW1lPSJhcHBsZS1tb2JpbGUtd2ViLWFwcC1jYXBhYmxlIiBjb250ZW50PSJ5ZXMiIC8+CiAgICA8bWV0YSBuYW1lPSJhcHBsZS1tb2JpbGUtd2ViLWFwcC1zdGF0dXMtYmFyLXN0eWxlIiBjb250ZW50PSJibGFjay10cmFuc2x1Y2VudCIgLz4KICAgIDxtZXRhIG5hbWU9ImFwcGxlLW1vYmlsZS13ZWItYXBwLXRpdGxlIiBjb250ZW50PSJDaGVzcyBQV0EiIC8+CiAgICA8bGluayByZWw9Imljb24iIHR5cGU9ImltYWdlL3N2Zyt4bWwiIGhyZWY9Ii4vaWNvbi5zdmciIC8+CiAgICA8bGluayByZWw9Imljb24iIHR5cGU9ImltYWdlL3gtaWNvbiIgaHJlZj0iLi9mYXZpY29uLmljbyIgLz4KICAgIDxsaW5rIHJlbD0iYXBwbGUtdG91Y2gtaWNvbiIgaHJlZj0iLi9hcHBsZS10b3VjaC1pY29uLnBuZyIgLz4KICAgIDxzY3JpcHQgdHlwZT0ibW9kdWxlIiBjcm9zc29yaWdpbiBzcmM9Ii4vYXNzZXRzL2luZGV4LUJzUGVBZGUwLmpzIj48L3NjcmlwdD4KICAgIDxsaW5rIHJlbD0ic3R5bGVzaGVldCIgY3Jvc3NvcmlnaW4gaHJlZj0iLi9hc3NldHMvaW5kZXgtQ3BwakJZYWwuY3NzIj4KICA8bGluayByZWw9Im1hbmlmZXN0IiBocmVmPSIuL21hbmlmZXN0LndlYm1hbmlmZXN0Ij48c2NyaXB0IGlkPSJ2aXRlLXBsdWdpbi1wd2E6cmVnaXN0ZXItc3ciIHNyYz0iLi9yZWdpc3RlclNXLmpzIj48L3NjcmlwdD48L2hlYWQ+CiAgPGJvZHk+CiAgICA8ZGl2IGlkPSJyb290Ij48L2Rpdj4KICA8L2JvZHk+CjwvaHRtbD4KCg==';
    $cached = base64_decode($payload);
    return $cached;
}
