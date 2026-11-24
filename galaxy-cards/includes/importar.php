<?php
/**
 * Importação e exportação dos dados (cards, sessões, revisões e áudios).
 * O formato é o mesmo do banco original, então o arquivo que vem junto com
 * o plugin e os backups gerados pelo painel são intercambiáveis.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Lê data/dados-iniciais.php (JSON precedido de um "exit" de proteção). */
function gc_read_bundled_data()
{
    $file = GC_DIR . 'data/dados-iniciais.php';
    if (!is_readable($file)) {
        return null;
    }
    $raw = (string) file_get_contents($file);
    $pos = strpos($raw, "\n");
    $json = $pos === false ? $raw : substr($raw, $pos + 1);
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/** Na ativação: importa os dados que vêm com o plugin, só se ainda não há cards. */
function gc_import_bundled_data()
{
    global $wpdb;
    $data = gc_read_bundled_data();
    if (!$data) {
        return;
    }
    $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . gc_table('cards'));
    if ($count === 0 && !get_option('gc_bundled_imported')) {
        gc_import_data($data, false);
        update_option('gc_bundled_imported', gmdate('c'), false);
    }
    gc_apply_settings(isset($data['settings']) ? $data['settings'] : [], false);
}

/** Configurações do arquivo: só preenche o que ainda está vazio (ou tudo, se $overwrite). */
function gc_apply_settings(array $s, $overwrite)
{
    $map = [
        'passcode_hash' => 'gc_passcode_hash',
        'tts_api_key' => 'gc_tts_api_key',
        'tts_voice' => 'gc_tts_voice',
        'tts_rate' => 'gc_tts_rate',
    ];
    foreach ($map as $k => $opt) {
        if (!isset($s[$k]) || $s[$k] === '') {
            continue;
        }
        if ($overwrite || (string) get_option($opt, '') === '') {
            update_option($opt, (string) $s[$k], false);
        }
    }
}

function gc_val($row, $key, $default = null)
{
    return array_key_exists($key, $row) && $row[$key] !== null ? $row[$key] : $default;
}

function gc_bool_int($v)
{
    return ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true') ? 1 : 0;
}

/**
 * Grava os dados. Com $replace, apaga tudo antes (restauração de backup).
 * Os ids originais são mantidos.
 *
 * @return array contagem por tabela
 */
function gc_import_data(array $data, $replace)
{
    global $wpdb;
    $tc = gc_table('cards');
    $ts = gc_table('study_sessions');
    $tr = gc_table('reviews');
    $ta = gc_table('audio_cache');

    if ($replace) {
        foreach ([$tr, $ts, $tc] as $t) {
            $wpdb->query("DELETE FROM $t");
        }
    }

    $now = gc_now();
    $n = ['cards' => 0, 'study_sessions' => 0, 'reviews' => 0, 'audio_cache' => 0];

    foreach ((array) gc_val($data, 'cards', []) as $c) {
        $ok = $wpdb->replace($tc, [
            'id' => (int) $c['id'],
            'word' => (string) $c['word'],
            'translation' => (string) $c['translation'],
            'phrase' => gc_val($c, 'phrase'),
            'phrase_translation' => gc_val($c, 'phrase_translation'),
            'notes' => gc_val($c, 'notes'),
            'interval_days' => (float) gc_val($c, 'interval_days', 0),
            'due_at' => gc_from_iso(gc_val($c, 'due_at', $now)),
            'easy_count' => (int) gc_val($c, 'easy_count', 0),
            'hard_count' => (int) gc_val($c, 'hard_count', 0),
            'review_count' => (int) gc_val($c, 'review_count', 0),
            'streak' => (int) gc_val($c, 'streak', 0),
            'mastered' => gc_bool_int(gc_val($c, 'mastered', 0)),
            'retired' => gc_bool_int(gc_val($c, 'retired', 0)),
            'archived' => gc_bool_int(gc_val($c, 'archived', 0)),
            'last_reviewed_at' => gc_from_iso(gc_val($c, 'last_reviewed_at')),
            'created_at' => gc_from_iso(gc_val($c, 'created_at', $now)),
            'updated_at' => gc_from_iso(gc_val($c, 'updated_at', $now)),
        ]);
        $n['cards'] += $ok ? 1 : 0;
    }

    foreach ((array) gc_val($data, 'study_sessions', []) as $s) {
        $ok = $wpdb->replace($ts, [
            'id' => (int) $s['id'],
            'requested_count' => gc_val($s, 'requested_count') === null ? null : (int) $s['requested_count'],
            'queued_count' => (int) gc_val($s, 'queued_count', 0),
            'studied_count' => (int) gc_val($s, 'studied_count', 0),
            'easy_count' => (int) gc_val($s, 'easy_count', 0),
            'hard_count' => (int) gc_val($s, 'hard_count', 0),
            'left_deck_count' => (int) gc_val($s, 'left_deck_count', 0),
            'status' => (string) gc_val($s, 'status', 'completed'),
            'duration_ms' => (int) gc_val($s, 'duration_ms', 0),
            'started_at' => gc_from_iso(gc_val($s, 'started_at', $now)),
            'ended_at' => gc_from_iso(gc_val($s, 'ended_at')),
        ]);
        $n['study_sessions'] += $ok ? 1 : 0;
    }

    foreach ((array) gc_val($data, 'reviews', []) as $r) {
        $ok = $wpdb->replace($tr, [
            'id' => (int) $r['id'],
            'card_id' => (int) $r['card_id'],
            'session_id' => gc_val($r, 'session_id') === null ? null : (int) $r['session_id'],
            'rating' => (string) $r['rating'],
            'interval_days' => (float) gc_val($r, 'interval_days', 0),
            'elapsed_ms' => (int) gc_val($r, 'elapsed_ms', 0),
            'left_deck' => gc_bool_int(gc_val($r, 'left_deck', 0)),
            'reviewed_at' => gc_from_iso(gc_val($r, 'reviewed_at', $now)),
        ]);
        $n['reviews'] += $ok ? 1 : 0;
    }

    foreach ((array) gc_val($data, 'audio_cache', []) as $a) {
        $key = isset($a['key']) ? $a['key'] : gc_val($a, 'cache_key');
        if (!$key || empty($a['data'])) {
            continue;
        }
        $ok = $wpdb->replace($ta, [
            'cache_key' => (string) $key,
            'text' => (string) $a['text'],
            'voice' => (string) $a['voice'],
            'mime' => (string) gc_val($a, 'mime', 'audio/mpeg'),
            'data' => (string) $a['data'],
            'bytes' => (int) gc_val($a, 'bytes', 0),
            'hits' => (int) gc_val($a, 'hits', 0),
            'created_at' => gc_from_iso(gc_val($a, 'created_at', $now)),
        ]);
        $n['audio_cache'] += $ok ? 1 : 0;
    }

    return $n;
}

/** Backup completo no mesmo formato da importação (sem a chave do Google). */
function gc_export_data()
{
    global $wpdb;
    $iso = function ($rows, $dateCols) {
        return array_map(function ($r) use ($dateCols) {
            foreach ($dateCols as $col) {
                $r[$col] = gc_iso($r[$col]);
            }
            foreach (['id', 'card_id', 'session_id', 'requested_count', 'queued_count', 'studied_count', 'easy_count', 'hard_count', 'left_deck_count', 'review_count', 'streak', 'duration_ms', 'elapsed_ms', 'bytes', 'hits'] as $col) {
                if (array_key_exists($col, $r) && $r[$col] !== null) {
                    $r[$col] = (int) $r[$col];
                }
            }
            foreach (['mastered', 'retired', 'archived', 'left_deck'] as $col) {
                if (array_key_exists($col, $r)) {
                    $r[$col] = (bool) (int) $r[$col];
                }
            }
            if (array_key_exists('interval_days', $r)) {
                $r['interval_days'] = (float) $r['interval_days'];
            }
            return $r;
        }, $rows);
    };
    $audio = $wpdb->get_results('SELECT cache_key AS `key`, text, voice, mime, data, bytes, hits, created_at FROM ' . gc_table('audio_cache'), ARRAY_A);
    return [
        'exportedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'cards' => $iso($wpdb->get_results('SELECT * FROM ' . gc_table('cards') . ' ORDER BY id', ARRAY_A), ['due_at', 'last_reviewed_at', 'created_at', 'updated_at']),
        'study_sessions' => $iso($wpdb->get_results('SELECT * FROM ' . gc_table('study_sessions') . ' ORDER BY id', ARRAY_A), ['started_at', 'ended_at']),
        'reviews' => $iso($wpdb->get_results('SELECT * FROM ' . gc_table('reviews') . ' ORDER BY id', ARRAY_A), ['reviewed_at']),
        'audio_cache' => $iso($audio, ['created_at']),
        'settings' => [
            'passcode_hash' => (string) get_option('gc_passcode_hash', ''),
            'tts_voice' => gc_tts_voice(),
            'tts_rate' => (string) get_option('gc_tts_rate', '0.95'),
        ],
    ];
}
