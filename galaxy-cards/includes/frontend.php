<?php
/**
 * Serve o app (o mesmo build React do original) numa página própria, sem o
 * tema do WordPress: /galaxy-cards/ por padrão, ou a página inicial do site.
 */

if (!defined('ABSPATH')) {
    exit;
}

function gc_slug()
{
    $s = trim((string) get_option('gc_slug', 'galaxy-cards'), '/');
    return $s === '' ? 'galaxy-cards' : $s;
}

/** Endereço do app. */
function gc_app_url()
{
    if (get_option('gc_on_home')) {
        return home_url('/');
    }
    if (get_option('permalink_structure')) {
        return home_url('/' . gc_slug() . '/');
    }
    return add_query_arg('galaxy-cards', '', home_url('/'));
}

/** Caminho pedido, relativo à raiz do WordPress. */
function gc_request_path()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
    $base = (string) parse_url(home_url('/'), PHP_URL_PATH);
    $base = $base === '' ? '/' : $base;
    if (strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }
    $path = preg_replace('#^index\.php/?#', '', $path);
    return trim($path, '/');
}

function gc_is_app_request()
{
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }
    if (isset($_GET['rest_route'])) {
        return false;
    }
    if (isset($_GET['galaxy-cards'])) {
        return true;
    }
    $path = gc_request_path();
    $slug = gc_slug();
    if ($path === $slug || strpos($path, $slug . '/') === 0) {
        return true;
    }
    if (get_option('gc_on_home') && $path === '') {
        // Na página inicial, deixa passar o que é do próprio WordPress (busca, prévia, feed…).
        foreach (['s', 'p', 'page_id', 'preview', 'feed', 'customize_changeset_uuid'] as $q) {
            if (isset($_GET[$q])) {
                return false;
            }
        }
        return true;
    }
    return false;
}

add_action('parse_request', function () {
    if (!gc_is_app_request()) {
        return;
    }
    gc_render_app();
    exit;
}, 0);

function gc_render_app()
{
    $file = GC_DIR . 'app/index.html';
    $html = is_readable($file) ? (string) file_get_contents($file) : '<!doctype html><title>Galaxy Cards</title><p>Build do app ausente.</p>';

    // O build referencia /assets/…; aqui os arquivos ficam dentro do plugin.
    $assets = GC_URL . 'app/assets/';
    $html = str_replace(['"/assets/', "'/assets/"], ['"' . $assets, "'" . $assets], $html);

    // Logado no WordPress, mas sem permissão (ex.: assinante): explica em vez de mandar para o login em loop.
    if (is_user_logged_in() && !gc_is_authed()) {
        wp_die(
            'Sua conta do WordPress não tem permissão para usar o Galaxy Cards. '
            . '<a href="' . esc_url(gc_logout_url()) . '">Sair e entrar com outra conta</a>.',
            'Galaxy Cards',
            ['response' => 403]
        );
    }

    // Configuração para o app: API (endereço relativo, para não misturar http
    // e https), nonce da REST API e os links de login/logout do WordPress.
    $config = [
        'api' => wp_make_link_relative(rest_url(GC_NS)),
        'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        'loginUrl' => gc_login_url(),
        'logoutUrl' => gc_logout_url(),
    ];
    $script = '<script>window.__GC__ = ' . wp_json_encode($config) . ';</script>';
    $html = preg_replace('/<script type="module"/', $script . "\n    <script type=\"module\"", $html, 1);

    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML do build do app.
}
