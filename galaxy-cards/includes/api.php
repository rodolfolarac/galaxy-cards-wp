<?php
/**
 * API do app na REST API do WordPress (/wp-json/galaxy-cards/v1/…).
 * Mesmas rotas, mesmas regras e mesmas respostas do servidor Express
 * original (server/routes/*.ts), para o front funcionar sem mudança.
 */

if (!defined('ABSPATH')) {
    exit;
}

function gc_json($data, $status = 200)
{
    $r = new WP_REST_Response($data, $status);
    $r->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    if (is_user_logged_in()) {
        // Nonce sempre renovado: uma aba aberta por dias continua funcionando.
        $r->header('X-GC-Nonce', wp_create_nonce('wp_rest'));
    }
    return $r;
}

function gc_err($message, $status)
{
    return gc_json(['error' => $message], $status);
}

function gc_body(WP_REST_Request $req)
{
    $b = $req->get_json_params();
    return is_array($b) ? $b : [];
}

/** Registra uma rota. Tudo, menos /auth, exige estar logado no WordPress. */
function gc_route($methods, $route, $cb, $auth = true)
{
    register_rest_route(GC_NS, $route, [
        'methods' => $methods,
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $req) use ($cb, $auth) {
            try {
                if ($auth && !gc_is_authed()) {
                    return gc_err('unauthorized', 401);
                }
                return call_user_func($cb, $req);
            } catch (Throwable $e) {
                error_log('[galaxy-cards] ' . $e->getMessage());
                return gc_err('Erro interno. Veja os logs do servidor.', 500);
            }
        },
    ]);
}

function gc_is_int_value($v)
{
    return is_int($v) || (is_float($v) && floor($v) == $v && is_finite($v));
}

add_action('rest_api_init', function () {
    gc_route('GET', '/health', function () {
        return gc_json(['ok' => true]);
    }, false);

    /* ---------------- Acesso ---------------- */

    gc_route('GET', '/auth/me', function () {
        return gc_json(['authenticated' => gc_is_authed(), 'loginUrl' => gc_login_url()]);
    }, false);

    // O login acontece na tela do WordPress; esta rota só orienta quem chamar.
    gc_route('POST', '/auth/login', function () {
        return gc_err('Entre pelo login do WordPress: ' . gc_login_url(), 400);
    }, false);

    gc_route('POST', '/auth/logout', function () {
        wp_logout();
        return gc_json(['authenticated' => false]);
    }, false);

    /* ---------------- Cards ---------------- */

    gc_route('GET', '/cards', 'gc_api_cards_list');
    gc_route('GET', '/cards/summary', 'gc_api_cards_summary');
    gc_route('POST', '/cards', 'gc_api_cards_create');
    gc_route('POST', '/cards/bulk', 'gc_api_cards_bulk');
    gc_route('PATCH', '/cards/(?P<id>\d+)', 'gc_api_cards_update');
    gc_route('DELETE', '/cards/(?P<id>\d+)', 'gc_api_cards_delete');
    gc_route('POST', '/cards/(?P<id>\d+)/archive', 'gc_api_cards_archive');
    gc_route('POST', '/cards/(?P<id>\d+)/reset', 'gc_api_cards_reset');

    /* ---------------- Estudo ---------------- */

    gc_route('GET', '/study/available', 'gc_api_study_available');
    gc_route('GET', '/study/active', 'gc_api_study_active');
    gc_route('POST', '/study/open', 'gc_api_study_open');
    gc_route('POST', '/study/sessions/(?P<id>\d+)/review', 'gc_api_study_review');
    gc_route('PATCH', '/study/sessions/(?P<id>\d+)', 'gc_api_study_tick');
    gc_route('POST', '/study/sessions/(?P<id>\d+)/finish', 'gc_api_study_finish');
    gc_route('GET', '/study/sessions', 'gc_api_study_sessions');

    /* ---------------- Estatísticas ---------------- */

    gc_route('GET', '/stats/overview', 'gc_api_stats_overview');
    gc_route('GET', '/stats/daily', 'gc_api_stats_daily');
    gc_route('GET', '/stats/words', 'gc_api_stats_words');

    /* ---------------- Áudio ---------------- */

    gc_route('GET', '/tts/status', function () {
        return gc_json(['google' => gc_has_google_tts(), 'voice' => gc_tts_voice()]);
    });
    gc_route('POST', '/tts', 'gc_api_tts');
});

/* =====================================================================
 * Cards
 * ===================================================================== */

/**
 * Validação igual à do zod no original:
 * palavra 1–200, tradução 1–400, frase/tradução da frase até 600, nota até 1000.
 */
function gc_parse_card_input(array $b, $partial)
{
    $spec = [
        'word' => [200, 'A palavra é obrigatória.', true],
        'translation' => [400, 'A tradução é obrigatória.', true],
        'phrase' => [600, null, false],
        'phraseTranslation' => [600, null, false],
        'notes' => [1000, null, false],
    ];
    $out = [];
    foreach ($spec as $k => $s) {
        list($max, $msg, $required) = $s;
        if (!array_key_exists($k, $b)) {
            if ($required && !$partial) {
                return [null, 'Required'];
            }
            continue;
        }
        $v = $b[$k];
        if ($v === null) {
            if ($required) {
                return [null, 'Expected string, received null'];
            }
            $out[$k] = null;
            continue;
        }
        if (!is_string($v)) {
            return [null, 'Dados inválidos.'];
        }
        $v = gc_trim($v);
        if ($required && $v === '') {
            return [null, $msg];
        }
        if (mb_strlen($v) > $max) {
            return [null, "String must contain at most {$max} character(s)"];
        }
        $out[$k] = $v;
    }
    return [$out, null];
}

function gc_blank($v)
{
    return ($v !== null && $v !== '') ? $v : null;
}

function gc_api_cards_list(WP_REST_Request $req)
{
    global $wpdb;
    $t = gc_table('cards');
    $q = gc_trim((string) $req->get_param('q'));
    $filter = (string) ($req->get_param('filter') ?? 'all');
    $filter = $filter === '' ? 'all' : $filter;
    $limit = (int) $req->get_param('limit');
    $limit = min($limit ?: 100, 500);
    $limit = max($limit, 1);
    $offset = max((int) $req->get_param('offset'), 0);

    $conds = [];
    $args = [];
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $conds[] = '(word LIKE %s OR translation LIKE %s OR phrase LIKE %s)';
        array_push($args, $like, $like, $like);
    }
    if ($filter === 'mastered') {
        $conds[] = '(mastered = 1 AND retired = 0)';
    }
    if ($filter === 'permanent') {
        $conds[] = 'retired = 1';
    }
    if ($filter === 'learning') {
        $conds[] = '(mastered = 0 AND archived = 0 AND retired = 0)';
    }
    if ($filter === 'due') {
        $conds[] = '(archived = 0 AND retired = 0 AND due_at <= %s)';
        $args[] = gc_now();
    }
    if ($filter === 'archived') {
        $conds[] = 'archived = 1';
    } elseif ($filter !== 'all') {
        $conds[] = 'archived = 0';
    }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    $prep = function ($sql) use ($wpdb, $args) {
        return $args ? $wpdb->prepare($sql, $args) : $sql;
    };

    $rows = $wpdb->get_results($prep("SELECT * FROM $t $where ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset"), ARRAY_A);
    gc_check_db();
    $total = (int) $wpdb->get_var($prep("SELECT COUNT(*) FROM $t $where"));

    return gc_json(['cards' => array_map('gc_card_out', $rows), 'total' => $total]);
}

function gc_api_cards_summary()
{
    global $wpdb;
    $t = gc_table('cards');
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS registered,
                COALESCE(SUM(mastered = 1 AND archived = 0), 0) AS mastered,
                COALESCE(SUM(retired = 1 AND archived = 0), 0) AS permanent,
                COALESCE(SUM(archived = 1), 0) AS archived,
                COALESCE(SUM(archived = 0 AND retired = 0 AND due_at <= %s), 0) AS due_now,
                COALESCE(SUM(archived = 0 AND retired = 0 AND mastered = 0), 0) AS learning
         FROM $t",
        gc_now()
    ), ARRAY_A);
    gc_check_db();
    return gc_json([
        'registered' => (int) $r['registered'],
        'mastered' => (int) $r['mastered'],
        'permanent' => (int) $r['permanent'],
        'archived' => (int) $r['archived'],
        'dueNow' => (int) $r['due_now'],
        'learning' => (int) $r['learning'],
    ]);
}

function gc_insert_card(array $d)
{
    global $wpdb;
    $now = gc_now();
    $wpdb->insert(gc_table('cards'), [
        'word' => $d['word'],
        'translation' => $d['translation'],
        'phrase' => gc_blank(isset($d['phrase']) ? $d['phrase'] : null),
        'phrase_translation' => gc_blank(isset($d['phraseTranslation']) ? $d['phraseTranslation'] : null),
        'notes' => gc_blank(isset($d['notes']) ? $d['notes'] : null),
        'interval_days' => 0,
        'due_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    gc_check_db();
    return (int) $wpdb->insert_id;
}

function gc_api_cards_create(WP_REST_Request $req)
{
    list($d, $error) = gc_parse_card_input(gc_body($req), false);
    if ($error) {
        return gc_err($error, 400);
    }
    $dup = gc_find_duplicate($d['word'], gc_existing_words());
    if ($dup) {
        return gc_err(gc_duplicate_message($dup), 409);
    }
    $id = gc_insert_card($d);
    return gc_json(gc_card_out(gc_get_card($id)), 201);
}

/** Cadastro em massa: uma linha por card, separada por ; ou tab. */
function gc_api_cards_bulk(WP_REST_Request $req)
{
    $b = gc_body($req);
    $text = isset($b['text']) && is_scalar($b['text']) ? (string) $b['text'] : '';
    $lines = array_values(array_filter(array_map('gc_trim', explode("\n", $text)), 'strlen'));
    if (!$lines) {
        return gc_err('Cole ao menos uma linha.', 400);
    }

    $existing = gc_existing_words();
    $seen = [];
    $values = [];
    $errors = [];
    foreach ($lines as $i => $line) {
        $n = $i + 1;
        $parts = array_map('gc_trim', preg_split('/\t|;/', $line));
        $word = isset($parts[0]) ? $parts[0] : '';
        $translation = isset($parts[1]) ? $parts[1] : '';
        if ($word === '' || $translation === '') {
            $errors[] = "Linha {$n}: precisa de \"palavra ; tradução\".";
            continue;
        }
        $dup = gc_find_duplicate($word, $existing);
        if ($dup) {
            $errors[] = "Linha {$n}: " . gc_duplicate_message($dup);
            continue;
        }
        $key = gc_normalize_word($word);
        if (isset($seen[$key])) {
            $errors[] = "Linha {$n}: “{$word}” repete a linha {$seen[$key]}.";
            continue;
        }
        $seen[$key] = $n;
        $values[] = [
            'word' => $word,
            'translation' => $translation,
            'phrase' => isset($parts[2]) ? $parts[2] : null,
            'phraseTranslation' => isset($parts[3]) ? $parts[3] : null,
        ];
    }

    if (!$values) {
        return gc_err($errors ? $errors[0] : 'Nada para importar.', 400);
    }
    foreach ($values as $v) {
        gc_insert_card($v);
    }
    return gc_json(['created' => count($values), 'errors' => $errors], 201);
}

function gc_api_cards_update(WP_REST_Request $req)
{
    global $wpdb;
    $id = (int) $req['id'];
    list($d, $error) = gc_parse_card_input(gc_body($req), true);
    if ($error) {
        return gc_err($error, 400);
    }
    if (array_key_exists('word', $d)) {
        $dup = gc_find_duplicate($d['word'], gc_existing_words(), $id);
        if ($dup) {
            return gc_err(gc_duplicate_message($dup), 409);
        }
    }
    if (!gc_get_card($id)) {
        return gc_err('Card não encontrado.', 404);
    }
    $map = ['word' => 'word', 'translation' => 'translation', 'phrase' => 'phrase', 'phraseTranslation' => 'phrase_translation', 'notes' => 'notes'];
    $set = ['updated_at' => gc_now()];
    foreach ($map as $k => $col) {
        if (array_key_exists($k, $d)) {
            $set[$col] = in_array($k, ['word', 'translation'], true) ? $d[$k] : gc_blank($d[$k]);
        }
    }
    $wpdb->update(gc_table('cards'), $set, ['id' => $id]);
    gc_check_db();
    return gc_json(gc_card_out(gc_get_card($id)));
}

function gc_api_cards_archive(WP_REST_Request $req)
{
    global $wpdb;
    $id = (int) $req['id'];
    $b = gc_body($req);
    $archived = array_key_exists('archived', $b) && $b['archived'] !== null ? (bool) $b['archived'] : true;
    if (!gc_get_card($id)) {
        return gc_err('Card não encontrado.', 404);
    }
    $wpdb->update(gc_table('cards'), ['archived' => $archived ? 1 : 0, 'updated_at' => gc_now()], ['id' => $id]);
    gc_check_db();
    return gc_json(gc_card_out(gc_get_card($id)));
}

/** Volta a carta para o baralho de hoje, zerando o agendamento. */
function gc_api_cards_reset(WP_REST_Request $req)
{
    global $wpdb;
    $id = (int) $req['id'];
    if (!gc_get_card($id)) {
        return gc_err('Card não encontrado.', 404);
    }
    $now = gc_now();
    $wpdb->update(gc_table('cards'), [
        'interval_days' => 0,
        'streak' => 0,
        'mastered' => 0,
        'retired' => 0,
        'due_at' => $now,
        'updated_at' => $now,
    ], ['id' => $id]);
    gc_check_db();
    return gc_json(gc_card_out(gc_get_card($id)));
}

function gc_api_cards_delete(WP_REST_Request $req)
{
    global $wpdb;
    $id = (int) $req['id'];
    if (!gc_get_card($id)) {
        return gc_err('Card não encontrado.', 404);
    }
    // Igual ao ON DELETE CASCADE do original.
    $wpdb->delete(gc_table('reviews'), ['card_id' => $id]);
    $wpdb->delete(gc_table('cards'), ['id' => $id]);
    gc_check_db();
    return gc_json(['deleted' => true]);
}

/* =====================================================================
 * Estudo
 * ===================================================================== */

function gc_api_study_available()
{
    global $wpdb;
    $t = gc_table('cards');
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT COALESCE(SUM(due_at <= %s), 0) AS due, COUNT(*) AS total FROM $t WHERE archived = 0 AND retired = 0",
        gc_now()
    ), ARRAY_A);
    gc_check_db();
    return gc_json(['due' => (int) $r['due'], 'total' => (int) $r['total']]);
}

/** Sessão em andamento, se houver: permite retomar depois de fechar o app. */
function gc_api_study_active()
{
    global $wpdb;
    $s = $wpdb->get_row("SELECT * FROM " . gc_table('study_sessions') . " WHERE status = 'active' ORDER BY started_at DESC, id DESC LIMIT 1", ARRAY_A);
    gc_check_db();
    if (!$s) {
        return gc_json(['session' => null, 'cards' => []]);
    }
    $ids = $wpdb->get_col($wpdb->prepare('SELECT card_id FROM ' . gc_table('reviews') . ' WHERE session_id = %d ORDER BY id', $s['id']));
    return gc_json(['session' => gc_session_out($s), 'reviewedCardIds' => array_map('intval', $ids)]);
}

function gc_create_session($requested, $queued)
{
    global $wpdb;
    $wpdb->insert(gc_table('study_sessions'), [
        'requested_count' => $requested,
        'queued_count' => $queued,
        'status' => 'active',
        'started_at' => gc_now(),
    ]);
    gc_check_db();
    return gc_get_session((int) $wpdb->insert_id);
}

/** Abre um baralho novo: sorteia as cartas e cria a sessão. */
function gc_api_study_open(WP_REST_Request $req)
{
    global $wpdb;
    $b = gc_body($req);
    $t = gc_table('cards');

    $requested = null;
    if (array_key_exists('count', $b) && $b['count'] !== null) {
        if (!gc_is_int_value($b['count']) || $b['count'] <= 0 || $b['count'] > 1000) {
            return gc_err('Parâmetros inválidos.', 400);
        }
        $requested = (int) $b['count'];
    }
    $includeFuture = false;
    if (array_key_exists('includeFuture', $b) && $b['includeFuture'] !== null) {
        if (!is_bool($b['includeFuture'])) {
            return gc_err('Parâmetros inválidos.', 400);
        }
        $includeFuture = $b['includeFuture'];
    }
    $cardIds = [];
    if (array_key_exists('cardIds', $b) && $b['cardIds'] !== null) {
        if (!is_array($b['cardIds']) || count($b['cardIds']) > 1000) {
            return gc_err('Parâmetros inválidos.', 400);
        }
        foreach ($b['cardIds'] as $cid) {
            if (!gc_is_int_value($cid) || $cid <= 0) {
                return gc_err('Parâmetros inválidos.', 400);
            }
            $cardIds[] = (int) $cid;
        }
    }

    // Encerra qualquer sessão pendurada antes de abrir outra.
    $wpdb->query($wpdb->prepare(
        'UPDATE ' . gc_table('study_sessions') . " SET status = 'aborted', ended_at = %s WHERE status = 'active'",
        gc_now()
    ));

    // Rodada dirigida ("revisar as difíceis"): exatamente as cartas pedidas.
    if ($cardIds) {
        $in = implode(',', array_map('intval', $cardIds));
        $picked = $wpdb->get_results("SELECT * FROM $t WHERE id IN ($in)", ARRAY_A);
        gc_check_db();
        if (!$picked) {
            return gc_err('Essas cartas não existem mais.', 409);
        }
        $deck = gc_shuffle($picked);
        $session = gc_create_session(count($deck), count($deck));
        return gc_json(['session' => gc_session_out($session), 'cards' => array_map('gc_card_out', $deck)], 201);
    }

    $now = gc_now();
    $due = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE archived = 0 AND retired = 0 AND due_at <= %s", $now), ARRAY_A);
    gc_check_db();
    $deck = gc_shuffle($due);

    if ($requested !== null && count($deck) < $requested && $includeFuture) {
        $have = [];
        foreach ($deck as $c) {
            $have[(int) $c['id']] = true;
        }
        $extra = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE archived = 0 AND retired = 0 ORDER BY due_at ASC LIMIT %d",
            $requested * 2
        ), ARRAY_A);
        foreach ($extra as $c) {
            if (count($deck) >= $requested) {
                break;
            }
            if (!isset($have[(int) $c['id']])) {
                $deck[] = $c;
                $have[(int) $c['id']] = true;
            }
        }
        $deck = gc_shuffle($deck);
    }

    if ($requested !== null) {
        $deck = array_slice($deck, 0, $requested);
    }

    if (!$deck) {
        return gc_err('Nenhuma carta disponível agora. As palavras que você marcou como fáceis voltam nos próximos dias.', 409);
    }

    $session = gc_create_session($requested, count($deck));
    return gc_json(['session' => gc_session_out($session), 'cards' => array_map('gc_card_out', $deck)], 201);
}

/**
 * Registra a resposta de uma carta. Card, revisão e sessão são gravados na
 * hora: se o app fechar no meio, nada se perde.
 */
function gc_api_study_review(WP_REST_Request $req)
{
    global $wpdb;
    $sessionId = (int) $req['id'];
    $b = gc_body($req);

    $cardId = isset($b['cardId']) ? $b['cardId'] : null;
    $rating = isset($b['rating']) ? $b['rating'] : null;
    $elapsed = array_key_exists('elapsedMs', $b) && $b['elapsedMs'] !== null ? $b['elapsedMs'] : 0;
    $sessionElapsed = array_key_exists('sessionElapsedMs', $b) ? $b['sessionElapsedMs'] : null;

    if (!gc_is_int_value($cardId) || $cardId <= 0) {
        return gc_err('Dados inválidos.', 400);
    }
    if ($rating !== 'easy' && $rating !== 'hard') {
        return gc_err("Invalid enum value. Expected 'easy' | 'hard'", 400);
    }
    if (!gc_is_int_value($elapsed) || $elapsed < 0 || $elapsed > 3600000) {
        return gc_err('Dados inválidos.', 400);
    }
    if ($sessionElapsed !== null && (!gc_is_int_value($sessionElapsed) || $sessionElapsed < 0)) {
        return gc_err('Dados inválidos.', 400);
    }

    $card = gc_get_card((int) $cardId);
    if (!$card) {
        return gc_err('Card não encontrado.', 404);
    }

    $now = gc_now();
    $next = gc_schedule(['intervalDays' => (float) $card['interval_days'], 'streak' => (int) $card['streak']], $rating, $now);
    $easy = $rating === 'easy' ? 1 : 0;
    $hard = $rating === 'hard' ? 1 : 0;

    $wpdb->update(gc_table('cards'), [
        'interval_days' => $next['intervalDays'],
        'streak' => $next['streak'],
        'due_at' => $next['dueAt'],
        'mastered' => $next['mastered'] ? 1 : 0,
        'retired' => $next['retired'] ? 1 : 0,
        'last_reviewed_at' => $now,
        'review_count' => (int) $card['review_count'] + 1,
        'easy_count' => (int) $card['easy_count'] + $easy,
        'hard_count' => (int) $card['hard_count'] + $hard,
        'updated_at' => $now,
    ], ['id' => (int) $cardId]);
    gc_check_db();

    $wpdb->insert(gc_table('reviews'), [
        'card_id' => (int) $cardId,
        'session_id' => $sessionId,
        'rating' => $rating,
        'interval_days' => $next['intervalDays'],
        'elapsed_ms' => (int) $elapsed,
        'left_deck' => $next['leftDeck'] ? 1 : 0,
        'reviewed_at' => $now,
    ]);
    gc_check_db();

    $left = $next['leftDeck'] ? 1 : 0;
    $extra = $sessionElapsed !== null ? $wpdb->prepare(', duration_ms = %d', (int) $sessionElapsed) : '';
    $wpdb->query($wpdb->prepare(
        'UPDATE ' . gc_table('study_sessions') . " SET studied_count = studied_count + 1, easy_count = easy_count + %d,
         hard_count = hard_count + %d, left_deck_count = left_deck_count + %d $extra WHERE id = %d",
        $easy, $hard, $left, $sessionId
    ));
    gc_check_db();

    return gc_json([
        'card' => gc_card_out(gc_get_card((int) $cardId)),
        'session' => gc_session_out(gc_get_session($sessionId)),
        'nextIn' => gc_describe_interval($next['intervalDays']),
        'leftDeck' => $next['leftDeck'],
        'retired' => $next['retired'],
    ]);
}

/** Salva o cronômetro periodicamente, mesmo sem resposta de carta. */
function gc_api_study_tick(WP_REST_Request $req)
{
    global $wpdb;
    $id = (int) $req['id'];
    $b = gc_body($req);
    $ms = isset($b['durationMs']) && is_numeric($b['durationMs']) ? max(0, (int) floor($b['durationMs'])) : 0;
    if (!gc_get_session($id)) {
        return gc_err('Sessão não encontrada.', 404);
    }
    $wpdb->update(gc_table('study_sessions'), ['duration_ms' => $ms], ['id' => $id]);
    gc_check_db();
    return gc_json(gc_session_out(gc_get_session($id)));
}

/** Finaliza ou interrompe a sessão. */
function gc_api_study_finish(WP_REST_Request $req)
{
    global $wpdb;
    $id = (int) $req['id'];
    $b = gc_body($req);
    $status = isset($b['status']) && $b['status'] === 'aborted' ? 'aborted' : 'completed';
    $ms = isset($b['durationMs']) && is_numeric($b['durationMs']) ? max(0, (int) floor($b['durationMs'])) : 0;
    if (!gc_get_session($id)) {
        return gc_err('Sessão não encontrada.', 404);
    }
    $wpdb->update(gc_table('study_sessions'), ['status' => $status, 'duration_ms' => $ms, 'ended_at' => gc_now()], ['id' => $id]);
    gc_check_db();
    return gc_json(gc_session_out(gc_get_session($id)));
}

/** Histórico de sessões para a aba de estatísticas. */
function gc_api_study_sessions(WP_REST_Request $req)
{
    global $wpdb;
    $limit = (int) $req->get_param('limit');
    $limit = max(1, min($limit ?: 20, 100));
    $rows = $wpdb->get_results('SELECT * FROM ' . gc_table('study_sessions') . " ORDER BY started_at DESC, id DESC LIMIT $limit", ARRAY_A);
    gc_check_db();
    return gc_json(['sessions' => array_map('gc_session_out', $rows)]);
}

/* =====================================================================
 * Estatísticas (em UTC, como no servidor original)
 * ===================================================================== */

function gc_period(WP_REST_Request $req)
{
    $p = (string) $req->get_param('period');
    return in_array($p, ['day', 'week', 'month'], true) ? $p : 'day';
}

/** Início do período: hoje à meia-noite, ou 7/30 dias atrás. */
function gc_since($period)
{
    $now = new DateTimeImmutable('now', gc_utc());
    if ($period === 'day') {
        return gc_db_date($now->setTime(0, 0, 0));
    }
    $days = $period === 'week' ? 7 : 30;
    return gc_add_ms(gc_db_date($now), -$days * 24 * 60 * 60 * 1000);
}

function gc_api_stats_overview(WP_REST_Request $req)
{
    global $wpdb;
    $period = gc_period($req);
    $from = gc_since($period);

    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS studied, COUNT(DISTINCT card_id) AS distinct_cards,
                COALESCE(SUM(rating = 'easy'), 0) AS easy, COALESCE(SUM(rating = 'hard'), 0) AS hard,
                COUNT(DISTINCT CASE WHEN left_deck = 1 THEN card_id END) AS left_deck,
                COALESCE(SUM(elapsed_ms), 0) AS time_ms
         FROM " . gc_table('reviews') . ' WHERE reviewed_at >= %s',
        $from
    ), ARRAY_A);
    gc_check_db();
    $s = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS sessions, COALESCE(SUM(status = 'completed'), 0) AS completed,
                COALESCE(SUM(status = 'aborted'), 0) AS aborted, COALESCE(SUM(duration_ms), 0) AS duration_ms
         FROM " . gc_table('study_sessions') . ' WHERE started_at >= %s',
        $from
    ), ARRAY_A);
    $created = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . gc_table('cards') . ' WHERE created_at >= %s', $from));

    return gc_json([
        'period' => $period,
        'from' => gc_iso($from),
        'reviews' => [
            'studied' => (int) $r['studied'],
            'distinctCards' => (int) $r['distinct_cards'],
            'easy' => (int) $r['easy'],
            'hard' => (int) $r['hard'],
            'leftDeck' => (int) $r['left_deck'],
            'timeMs' => (int) $r['time_ms'],
        ],
        'sessions' => [
            'total' => (int) $s['sessions'],
            'completed' => (int) $s['completed'],
            'aborted' => (int) $s['aborted'],
            'durationMs' => (int) $s['duration_ms'],
        ],
        'cardsCreated' => $created,
    ]);
}

/** Série diária dos últimos N dias, com zero nos dias sem estudo. */
function gc_api_stats_daily(WP_REST_Request $req)
{
    global $wpdb;
    $days = (int) $req->get_param('days');
    $days = min(max($days ?: 30, 7), 120);
    $start = (new DateTimeImmutable('now', gc_utc()))->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(reviewed_at) AS day, COUNT(*) AS studied,
                COALESCE(SUM(rating = 'easy'), 0) AS easy, COALESCE(SUM(rating = 'hard'), 0) AS hard
         FROM " . gc_table('reviews') . ' WHERE reviewed_at >= %s GROUP BY DATE(reviewed_at) ORDER BY DATE(reviewed_at)',
        gc_db_date($start)
    ), ARRAY_A);
    gc_check_db();

    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r['day']] = $r;
    }
    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $key = $start->modify("+{$i} days")->format('Y-m-d');
        $hit = isset($byDay[$key]) ? $byDay[$key] : null;
        $series[] = [
            'day' => $key,
            'studied' => $hit ? (int) $hit['studied'] : 0,
            'easy' => $hit ? (int) $hit['easy'] : 0,
            'hard' => $hit ? (int) $hit['hard'] : 0,
        ];
    }
    return gc_json(['series' => $series]);
}

/** Palavras efetivamente estudadas no período, com a nota que receberam. */
function gc_api_stats_words(WP_REST_Request $req)
{
    global $wpdb;
    $from = gc_since(gc_period($req));
    $limit = (int) $req->get_param('limit');
    $limit = max(1, min($limit ?: 200, 500));
    $c = gc_table('cards');
    $r = gc_table('reviews');

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT c.id AS card_id, c.word, c.translation, c.phrase, c.mastered, c.interval_days, c.due_at,
                COUNT(r.id) AS times, COALESCE(SUM(r.rating = 'easy'), 0) AS easy, COALESCE(SUM(r.rating = 'hard'), 0) AS hard,
                MAX(r.reviewed_at) AS last_at, MAX(r.left_deck) AS left_deck
         FROM $r r INNER JOIN $c c ON c.id = r.card_id
         WHERE r.reviewed_at >= %s
         GROUP BY c.id, c.word, c.translation, c.phrase, c.mastered, c.interval_days, c.due_at
         ORDER BY MAX(r.reviewed_at) DESC
         LIMIT %d",
        $from, $limit
    ), ARRAY_A);
    gc_check_db();

    $words = array_map(function ($w) {
        return [
            'cardId' => (int) $w['card_id'],
            'word' => $w['word'],
            'translation' => $w['translation'],
            'phrase' => $w['phrase'],
            'mastered' => (bool) (int) $w['mastered'],
            'intervalDays' => (float) $w['interval_days'],
            'dueAt' => gc_iso($w['due_at']),
            'times' => (int) $w['times'],
            'easy' => (int) $w['easy'],
            'hard' => (int) $w['hard'],
            'lastAt' => gc_iso($w['last_at']),
            'leftDeck' => (bool) (int) $w['left_deck'],
        ];
    }, $rows);
    return gc_json(['words' => $words]);
}

/* =====================================================================
 * Áudio
 * ===================================================================== */

/**
 * Devolve { audio, mime, cached } em base64. Sem chave do Google (ou se a
 * chamada falhar), responde 204 e o app usa a voz do navegador.
 */
function gc_api_tts(WP_REST_Request $req)
{
    $b = gc_body($req);
    $text = isset($b['text']) && is_string($b['text']) ? gc_trim($b['text']) : '';
    $voice = isset($b['voice']) && is_string($b['voice']) ? gc_trim($b['voice']) : null;
    if ($text === '' || mb_strlen($text) > 400 || ($voice !== null && mb_strlen($voice) > 60)) {
        return gc_err('Texto inválido.', 400);
    }
    try {
        $audio = gc_tts_synthesize($text, $voice ?: null);
    } catch (Throwable $e) {
        error_log('[galaxy-cards tts] ' . $e->getMessage());
        $audio = null;
    }
    if (!$audio) {
        return new WP_REST_Response(null, 204);
    }
    return gc_json(['audio' => $audio['data'], 'mime' => $audio['mime'], 'cached' => $audio['cached']]);
}
