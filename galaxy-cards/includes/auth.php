<?php
/**
 * Acesso pelo login do próprio WordPress.
 *
 * Quem está logado no WordPress com permissão de administrador usa o app.
 * As chamadas da API vão com o nonce "wp_rest" (cabeçalho X-WP-Nonce), que
 * é como o WordPress autentica a REST API pelos cookies de login dele.
 *
 * Para liberar outros perfis (ex.: editores), use o filtro:
 *   add_filter('gc_capability', fn () => 'edit_posts');
 */

if (!defined('ABSPATH')) {
    exit;
}

function gc_capability()
{
    return (string) apply_filters('gc_capability', 'manage_options');
}

function gc_is_authed()
{
    return is_user_logged_in() && current_user_can(gc_capability());
}

/** Endereços de entrar e sair do WordPress, sempre voltando para o app. */
function gc_login_url()
{
    return wp_login_url(gc_app_url());
}

function gc_logout_url()
{
    return wp_logout_url(gc_app_url());
}
