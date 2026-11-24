<?php
/**
 * Áudio com Google Cloud TTS (vozes neurais), com cache no banco: a mesma
 * palavra nunca custa uma segunda chamada. Sem chave, o app usa a voz do
 * navegador. A chave do cache é a mesma do original, então os áudios
 * importados continuam valendo.
 */

if (!defined('ABSPATH')) {
    exit;
}

const GC_TTS_ENDPOINT = 'https://texttospeech.googleapis.com/v1/text:synthesize';
const GC_TTS_MAX_CHARS = 400;

function gc_tts_voice()
{
    $v = gc_trim(get_option('gc_tts_voice', ''));
    return $v !== '' ? $v : 'en-US-Neural2-F';
}

function gc_tts_rate()
{
    $r = get_option('gc_tts_rate', '');
    return is_numeric($r) ? (float) $r : 0.95;
}

function gc_tts_key()
{
    return gc_trim(get_option('gc_tts_api_key', ''));
}

function gc_has_google_tts()
{
    return gc_tts_key() !== '';
}

/** Número como o JavaScript escreve (0.95, 1, 1.1). */
function gc_js_number($n)
{
    $s = rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

/** @return array|null ['data' => base64, 'mime' => string, 'cached' => bool] */
function gc_tts_synthesize($rawText, $voice = null)
{
    global $wpdb;
    $text = mb_substr(gc_trim($rawText), 0, GC_TTS_MAX_CHARS);
    if ($text === '') {
        return null;
    }
    $voice = $voice ?: gc_tts_voice();
    $rate = gc_tts_rate();
    $key = hash('sha256', $text . '|' . $voice . '|' . gc_js_number($rate));
    $t = gc_table('audio_cache');

    $hit = $wpdb->get_row($wpdb->prepare("SELECT data, mime FROM $t WHERE cache_key = %s", $key), ARRAY_A);
    if ($hit) {
        $wpdb->query($wpdb->prepare("UPDATE $t SET hits = hits + 1 WHERE cache_key = %s", $key));
        return ['data' => $hit['data'], 'mime' => $hit['mime'], 'cached' => true];
    }

    $apiKey = gc_tts_key();
    if ($apiKey === '') {
        return null;
    }

    $parts = array_slice(explode('-', $voice), 0, 2);
    $languageCode = implode('-', $parts) ?: 'en-US';

    $res = wp_remote_post(GC_TTS_ENDPOINT . '?key=' . rawurlencode($apiKey), [
        'timeout' => 20,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'input' => ['text' => $text],
            'voice' => ['languageCode' => $languageCode, 'name' => $voice],
            'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => $rate, 'pitch' => 0],
        ]),
    ]);
    if (is_wp_error($res)) {
        throw new RuntimeException('Google TTS: ' . $res->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Google TTS $code: " . substr(wp_remote_retrieve_body($res), 0, 300));
    }
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if (empty($json['audioContent'])) {
        throw new RuntimeException('Google TTS não devolveu áudio.');
    }
    $data = $json['audioContent'];

    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $t (cache_key, text, voice, mime, data, bytes, hits, created_at) VALUES (%s, %s, %s, 'audio/mpeg', %s, %d, 0, %s)",
        $key, $text, $voice, $data, (int) floor(strlen($data) * 3 / 4), gc_now()
    ));

    return ['data' => $data, 'mime' => 'audio/mpeg', 'cached' => false];
}
