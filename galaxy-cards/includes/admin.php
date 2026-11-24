<?php
/**
 * Painel em Configurações → Galaxy Cards: endereço do app, código de
 * acesso, voz do Google, backup e restauração.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Menu próprio no painel do WordPress, com atalho para abrir o app. */
add_action('admin_menu', function () {
    add_menu_page('Galaxy Cards', 'Galaxy Cards', gc_capability(), 'galaxy-cards', 'gc_admin_page', 'dashicons-index-card', 3);
    add_submenu_page('galaxy-cards', 'Galaxy Cards', 'Configurações', 'manage_options', 'galaxy-cards', 'gc_admin_page');
    add_submenu_page('galaxy-cards', 'Abrir o app', 'Abrir o app ↗', gc_capability(), 'galaxy-cards-abrir', '__return_null');
});

/** "Abrir o app" leva direto para o Galaxy Cards. */
add_action('admin_init', function () {
    if (isset($_GET['page']) && $_GET['page'] === 'galaxy-cards-abrir') {
        wp_safe_redirect(gc_app_url());
        exit;
    }
});

add_filter('plugin_action_links_' . plugin_basename(GC_FILE), function ($links) {
    array_unshift(
        $links,
        '<a href="' . esc_url(gc_app_url()) . '" target="_blank">Abrir o app</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=galaxy-cards')) . '">Configurações</a>'
    );
    return $links;
});


function gc_admin_redirect($msg, $type = 'success')
{
    wp_safe_redirect(add_query_arg(['page' => 'galaxy-cards', 'gc_msg' => rawurlencode($msg), 'gc_type' => $type], admin_url('admin.php')));
    exit;
}

/* ---------- Salvar configurações ---------- */

add_action('admin_post_gc_save', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }
    check_admin_referer('gc_save');

    $slug = sanitize_title(wp_unslash(isset($_POST['gc_slug']) ? $_POST['gc_slug'] : ''));
    update_option('gc_slug', $slug !== '' ? $slug : 'galaxy-cards');
    update_option('gc_on_home', empty($_POST['gc_on_home']) ? 0 : 1);

    if (!empty($_POST['gc_tts_clear'])) {
        update_option('gc_tts_api_key', '', false);
    } elseif (!empty($_POST['gc_tts_api_key'])) {
        update_option('gc_tts_api_key', sanitize_text_field(wp_unslash($_POST['gc_tts_api_key'])), false);
    }
    $voice = sanitize_text_field(wp_unslash(isset($_POST['gc_tts_voice']) ? $_POST['gc_tts_voice'] : ''));
    update_option('gc_tts_voice', $voice !== '' ? $voice : 'en-US-Neural2-F', false);
    $rate = isset($_POST['gc_tts_rate']) ? str_replace(',', '.', (string) wp_unslash($_POST['gc_tts_rate'])) : '0.95';
    update_option('gc_tts_rate', is_numeric($rate) ? (string) max(0.25, min(4, (float) $rate)) : '0.95', false);

    gc_admin_redirect('Configurações salvas.');
});

/* ---------- Backup ---------- */

add_action('admin_post_gc_export', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }
    check_admin_referer('gc_export');
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="galaxy-cards-backup-' . gmdate('Y-m-d-His') . '.json"');
    echo wp_json_encode(gc_export_data());
    exit;
});

add_action('admin_post_gc_import', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }
    check_admin_referer('gc_import');
    if (empty($_POST['gc_confirm'])) {
        gc_admin_redirect('Marque a confirmação para restaurar o backup.', 'error');
    }
    if (empty($_FILES['gc_file']['tmp_name']) || !is_uploaded_file($_FILES['gc_file']['tmp_name'])) {
        gc_admin_redirect('Escolha o arquivo .json do backup.', 'error');
    }
    $raw = (string) file_get_contents($_FILES['gc_file']['tmp_name']);
    if (strpos($raw, '<?php') === 0) {
        $raw = substr($raw, strpos($raw, "\n") + 1);
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['cards']) || !is_array($data['cards'])) {
        gc_admin_redirect('Esse arquivo não é um backup do Galaxy Cards.', 'error');
    }
    $n = gc_import_data($data, true);
    if (!empty($data['settings']) && is_array($data['settings'])) {
        gc_apply_settings($data['settings'], false);
    }
    gc_admin_redirect(sprintf('Backup restaurado: %d cards, %d baralhos, %d revisões e %d áudios.', $n['cards'], $n['study_sessions'], $n['reviews'], $n['audio_cache']));
});

/* ---------- Tela ---------- */

function gc_admin_page()
{
    global $wpdb;
    if (!current_user_can('manage_options')) {
        return;
    }
    $count = function ($t) use ($wpdb) {
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gc_table($t));
    };
    $msg = isset($_GET['gc_msg']) ? sanitize_text_field(wp_unslash($_GET['gc_msg'])) : '';
    $type = (isset($_GET['gc_type']) && $_GET['gc_type'] === 'error') ? 'error' : 'success';
    $hasKey = gc_has_google_tts();
    $post = esc_url(admin_url('admin-post.php'));
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Galaxy Cards</h1>
        <a href="<?php echo esc_url(gc_app_url()); ?>" class="page-title-action" target="_blank">Abrir o app</a>
        <hr class="wp-header-end">
        <?php if ($msg) : ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
        <?php endif; ?>

        <p style="font-size:14px">
            O app está em <a href="<?php echo esc_url(gc_app_url()); ?>" target="_blank"><strong><?php echo esc_html(gc_app_url()); ?></strong></a>
            &nbsp;·&nbsp; <?php echo esc_html($count('cards')); ?> cards, <?php echo esc_html($count('study_sessions')); ?> baralhos,
            <?php echo esc_html($count('reviews')); ?> revisões e <?php echo esc_html($count('audio_cache')); ?> áudios salvos.
        </p>
        <?php if (!get_option('permalink_structure') && !get_option('gc_on_home')) : ?>
            <div class="notice notice-info inline"><p>Os links permanentes do site estão em “Simples”. O app funciona no endereço acima,
            mas para usar <code>/<?php echo esc_html(gc_slug()); ?>/</code> ative em <a href="<?php echo esc_url(admin_url('options-permalink.php')); ?>">Configurações → Links permanentes</a> a opção “Nome do post”.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo $post; ?>">
            <input type="hidden" name="action" value="gc_save">
            <?php wp_nonce_field('gc_save'); ?>
            <h2 class="title">Endereço</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gc_slug">Endereço do app</label></th>
                    <td><code><?php echo esc_html(home_url('/')); ?></code><input name="gc_slug" id="gc_slug" type="text" class="regular-text" style="width:14em" value="<?php echo esc_attr(gc_slug()); ?>"><code>/</code></td>
                </tr>
                <tr>
                    <th scope="row">Página inicial</th>
                    <td><label><input name="gc_on_home" type="checkbox" value="1" <?php checked((bool) get_option('gc_on_home')); ?>> Abrir o Galaxy Cards na página inicial do site (<?php echo esc_html(home_url('/')); ?>)</label>
                    <p class="description">O painel do WordPress continua em <code>/wp-admin</code>.</p></td>
                </tr>
            </table>

            <h2 class="title">Acesso</h2>
            <p>O app usa o login do próprio WordPress: entra quem estiver logado como <strong>administrador</strong> do site.
            Quem não estiver logado é levado para a tela de login do WordPress e volta direto para o app.
            Senha e usuários são os do WordPress (<a href="<?php echo esc_url(admin_url('profile.php')); ?>">Perfil</a>).</p>

            <h2 class="title">Áudio (Google Cloud Text-to-Speech)</h2>
            <p>Sem chave, o app usa a voz do próprio navegador. Os áudios já gerados ficam salvos no banco e não custam nova chamada.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="gc_tts_api_key">Chave da API</label></th>
                    <td><input name="gc_tts_api_key" id="gc_tts_api_key" type="password" class="regular-text" autocomplete="off" placeholder="<?php echo $hasKey ? 'Chave configurada (deixe em branco para manter)' : 'Cole a chave aqui'; ?>">
                    <?php if ($hasKey) : ?><br><label><input type="checkbox" name="gc_tts_clear" value="1"> Remover a chave</label><?php endif; ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc_tts_voice">Voz</label></th>
                    <td><input name="gc_tts_voice" id="gc_tts_voice" type="text" class="regular-text" value="<?php echo esc_attr(gc_tts_voice()); ?>">
                    <p class="description">Ex.: en-US-Neural2-F, en-US-Neural2-J, en-GB-Neural2-A.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gc_tts_rate">Velocidade</label></th>
                    <td><input name="gc_tts_rate" id="gc_tts_rate" type="text" class="small-text" value="<?php echo esc_attr(gc_js_number(gc_tts_rate())); ?>">
                    <p class="description">1 = normal. Trocar voz ou velocidade gera áudios novos.</p></td>
                </tr>
            </table>
            <?php submit_button('Salvar configurações'); ?>
        </form>

        <hr>
        <h2 class="title">Backup</h2>
        <form method="post" action="<?php echo $post; ?>" style="display:inline-block;margin-right:12px">
            <input type="hidden" name="action" value="gc_export">
            <?php wp_nonce_field('gc_export'); ?>
            <?php submit_button('Baixar backup (.json)', 'secondary', 'submit', false); ?>
        </form>

        <h3>Restaurar backup</h3>
        <form method="post" action="<?php echo $post; ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="gc_import">
            <?php wp_nonce_field('gc_import'); ?>
            <p><input type="file" name="gc_file" accept=".json,application/json"></p>
            <p><label><input type="checkbox" name="gc_confirm" value="1"> Entendo que os cards, baralhos e revisões atuais serão substituídos pelos do arquivo.</label></p>
            <?php submit_button('Restaurar', 'delete', 'submit', false); ?>
        </form>
    </div>
    <?php
}

/** Atalho na barra superior do WordPress. */
add_action('admin_bar_menu', function ($bar) {
    if (!gc_is_authed()) {
        return;
    }
    $bar->add_node(['id' => 'galaxy-cards', 'title' => '✦ Galaxy Cards', 'href' => gc_app_url()]);
}, 80);
