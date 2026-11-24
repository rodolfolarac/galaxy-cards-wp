<?php
/**
 * Plugin Name:       Galaxy Cards
 * Plugin URI:        https://rolckgalaxy.com/projetos/galaxy-cards/
 * Description:       Baralho pessoal de memorização de vocabulário em inglês com repetição espaçada, áudio nativo e progresso guardado no banco do WordPress.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rolck Galaxy
 * Author URI:        https://rolckgalaxy.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       galaxy-cards
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GC_VERSION', '1.1.0');
define('GC_DB_VERSION', '1');
define('GC_FILE', __FILE__);
define('GC_DIR', plugin_dir_path(__FILE__));
define('GC_URL', plugin_dir_url(__FILE__));
define('GC_NS', 'galaxy-cards/v1');

require_once GC_DIR . 'includes/db.php';
require_once GC_DIR . 'includes/srs.php';
require_once GC_DIR . 'includes/auth.php';
require_once GC_DIR . 'includes/tts.php';
require_once GC_DIR . 'includes/api.php';
require_once GC_DIR . 'includes/importar.php';
require_once GC_DIR . 'includes/frontend.php';

if (is_admin()) {
    require_once GC_DIR . 'includes/admin.php';
}

register_activation_hook(__FILE__, 'gc_activate');

/**
 * Ativação: cria as tabelas, importa os cards que vêm junto com o plugin
 * (só se o banco estiver vazio) e liga os links permanentes, sem os quais o
 * endereço /galaxy-cards/ não existe.
 */
function gc_activate()
{
    gc_install_schema();
    add_option('gc_slug', 'galaxy-cards');
    add_option('gc_on_home', 0);
    gc_import_bundled_data();

    if (!get_option('permalink_structure')) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure('/%postname%/');
    }
    flush_rewrite_rules(true);
}

add_action('plugins_loaded', function () {
    if (get_option('gc_db_version') !== GC_DB_VERSION) {
        gc_install_schema();
    }
});
