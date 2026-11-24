<?php
/**
 * Agendamento por degraus fixos de 15 dias e detecção de cards repetidos.
 * Tradução fiel de server/lib/srs.ts e server/lib/duplicates.ts.
 *
 *   1º fácil → 15 dias · 2º → 30 · 3º → 45 · 4º → 60 = permanente
 *   Difícil → sequência zera e a carta volta ao baralho na hora.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GC_STEP_DAYS = 15;
const GC_PERMANENT_AFTER_DAYS = 60;
const GC_MASTERED_THRESHOLD_DAYS = 15;

function gc_interval_for_streak($streak)
{
    if ($streak <= 0) {
        return 0;
    }
    return min($streak * GC_STEP_DAYS, GC_PERMANENT_AFTER_DAYS);
}

/**
 * @param array  $state  ['intervalDays' => float, 'streak' => int]
 * @param string $rating 'easy' | 'hard'
 * @param string $now    data do banco (UTC)
 */
function gc_schedule($state, $rating, $now)
{
    if ($rating === 'hard') {
        return [
            'intervalDays' => 0,
            'streak' => 0,
            'dueAt' => $now,
            'mastered' => false,
            'leftDeck' => false,
            'retired' => false,
        ];
    }
    $streak = (int) $state['streak'] + 1;
    $interval = gc_interval_for_streak($streak);
    return [
        'intervalDays' => $interval,
        'streak' => $streak,
        'dueAt' => gc_add_ms($now, $interval * 24 * 60 * 60 * 1000),
        'mastered' => $interval >= GC_MASTERED_THRESHOLD_DAYS,
        'leftDeck' => true,
        'retired' => $interval >= GC_PERMANENT_AFTER_DAYS,
    ];
}

function gc_describe_interval($days)
{
    if ($days <= 0) {
        return 'agora';
    }
    if ($days == 1) {
        return '1 dia';
    }
    if ($days < 30) {
        return $days . ' dias';
    }
    if ($days == 30) {
        return '1 mês';
    }
    if ($days == 60) {
        return '2 meses';
    }
    if ($days < 365) {
        return round($days / 30) . ' meses';
    }
    $y = (int) round($days / 365);
    return $y === 1 ? '1 ano' : $y . ' anos';
}

/** Fisher-Yates: a ordem do baralho é sempre aleatória. */
function gc_shuffle(array $arr)
{
    $a = array_values($arr);
    for ($i = count($a) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $t = $a[$i];
        $a[$i] = $a[$j];
        $a[$j] = $t;
    }
    return $a;
}

/** trim() do JavaScript: também remove espaços Unicode. */
function gc_trim($v)
{
    return preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', (string) $v);
}

/**
 * Chave de comparação: ignora maiúsculas, espaços sobrando, acentos,
 * pontuação e hífen, e trata apóstrofo curvo e reto como iguais.
 */
function gc_normalize_word($value)
{
    $v = (string) $value;
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($v, Normalizer::FORM_KD);
        if ($n !== false) {
            $v = $n;
        }
        $v = preg_replace('/\p{M}+/u', '', $v);
    } else {
        $v = remove_accents($v);
    }
    $v = preg_replace("/[’‘`´]/u", "'", $v);
    $v = mb_strtolower($v, 'UTF-8');
    $v = gc_trim(preg_replace("/[^\p{L}\p{N}']+/u", ' ', $v));
    return $v !== '' ? $v : mb_strtolower(gc_trim($value), 'UTF-8');
}

/** Palavras já cadastradas, arquivadas incluídas, para barrar repetidos. */
function gc_existing_words()
{
    global $wpdb;
    return $wpdb->get_results('SELECT id, word, archived FROM ' . gc_table('cards'), ARRAY_A);
}

function gc_find_duplicate($word, array $existing, $ignoreId = null)
{
    $key = gc_normalize_word($word);
    foreach ($existing as $card) {
        if ((int) $card['id'] !== (int) $ignoreId && gc_normalize_word($card['word']) === $key) {
            return $card;
        }
    }
    return null;
}

function gc_duplicate_message($card)
{
    return '“' . $card['word'] . '” já está cadastrada' . ((int) $card['archived'] ? ' (arquivada)' : '') . '.';
}
