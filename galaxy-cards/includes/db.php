<?php
/**
 * Tabelas e conversões. Mesma estrutura do banco original (Postgres/Neon),
 * com as datas guardadas em UTC e milissegundos.
 */

if (!defined('ABSPATH')) {
    exit;
}

function gc_table($name)
{
    global $wpdb;
    return $wpdb->prefix . 'gc_' . $name;
}

function gc_install_schema()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $cards = gc_table('cards');
    $sessions = gc_table('study_sessions');
    $reviews = gc_table('reviews');
    $audio = gc_table('audio_cache');

    dbDelta("CREATE TABLE $cards (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  word text NOT NULL,
  translation text NOT NULL,
  phrase text NULL,
  phrase_translation text NULL,
  notes text NULL,
  interval_days double NOT NULL DEFAULT 0,
  due_at datetime(3) NOT NULL,
  easy_count int(11) NOT NULL DEFAULT 0,
  hard_count int(11) NOT NULL DEFAULT 0,
  review_count int(11) NOT NULL DEFAULT 0,
  streak int(11) NOT NULL DEFAULT 0,
  mastered tinyint(1) NOT NULL DEFAULT 0,
  retired tinyint(1) NOT NULL DEFAULT 0,
  archived tinyint(1) NOT NULL DEFAULT 0,
  last_reviewed_at datetime(3) NULL,
  created_at datetime(3) NOT NULL,
  updated_at datetime(3) NOT NULL,
  PRIMARY KEY  (id),
  KEY cards_due_idx (due_at),
  KEY cards_archived_idx (archived),
  KEY cards_retired_idx (retired)
) $c;");

    dbDelta("CREATE TABLE $sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  requested_count int(11) NULL,
  queued_count int(11) NOT NULL DEFAULT 0,
  studied_count int(11) NOT NULL DEFAULT 0,
  easy_count int(11) NOT NULL DEFAULT 0,
  hard_count int(11) NOT NULL DEFAULT 0,
  left_deck_count int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  duration_ms bigint(20) NOT NULL DEFAULT 0,
  started_at datetime(3) NOT NULL,
  ended_at datetime(3) NULL,
  PRIMARY KEY  (id),
  KEY sessions_started_idx (started_at),
  KEY sessions_status_idx (status)
) $c;");

    dbDelta("CREATE TABLE $reviews (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  card_id bigint(20) unsigned NOT NULL,
  session_id bigint(20) unsigned NULL,
  rating varchar(10) NOT NULL,
  interval_days double NOT NULL DEFAULT 0,
  elapsed_ms int(11) NOT NULL DEFAULT 0,
  left_deck tinyint(1) NOT NULL DEFAULT 0,
  reviewed_at datetime(3) NOT NULL,
  PRIMARY KEY  (id),
  KEY reviews_reviewed_idx (reviewed_at),
  KEY reviews_card_idx (card_id),
  KEY reviews_session_idx (session_id)
) $c;");

    dbDelta("CREATE TABLE $audio (
  cache_key varchar(64) NOT NULL,
  text text NOT NULL,
  voice varchar(80) NOT NULL,
  mime varchar(40) NOT NULL DEFAULT 'audio/mpeg',
  data longtext NOT NULL,
  bytes int(11) NOT NULL DEFAULT 0,
  hits int(11) NOT NULL DEFAULT 0,
  created_at datetime(3) NOT NULL,
  PRIMARY KEY  (cache_key)
) $c;");

    update_option('gc_db_version', GC_DB_VERSION);
}

/* ---------- Datas (sempre UTC, com milissegundos) ---------- */

function gc_utc()
{
    static $tz = null;
    return $tz ?: ($tz = new DateTimeZone('UTC'));
}

/** Agora, no formato do banco. */
function gc_now()
{
    return gc_db_date(new DateTimeImmutable('now', gc_utc()));
}

function gc_db_date(DateTimeInterface $d)
{
    $d = DateTimeImmutable::createFromFormat('U.u', $d->format('U.u'), gc_utc());
    return $d->format('Y-m-d H:i:s.v');
}

/** Data do banco → ISO 8601 (o mesmo formato que o app recebia antes). */
function gc_iso($v)
{
    if ($v === null || $v === '') {
        return null;
    }
    $d = new DateTimeImmutable($v . ' UTC');
    return $d->setTimezone(gc_utc())->format('Y-m-d\TH:i:s.v\Z');
}

/** ISO 8601 (ou qualquer data reconhecível) → formato do banco. */
function gc_from_iso($v)
{
    if ($v === null || $v === '') {
        return null;
    }
    return gc_db_date(new DateTimeImmutable((string) $v, gc_utc()));
}

/** Soma milissegundos a uma data do banco. */
function gc_add_ms($dbDate, $ms)
{
    $d = new DateTimeImmutable($dbDate . ' UTC');
    $u = (float) $d->format('U.u') + $ms / 1000;
    return gc_db_date(DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $u), gc_utc()));
}

/* ---------- Linhas do banco → JSON do app (camelCase) ---------- */

function gc_card_out($r)
{
    if (!$r) {
        return null;
    }
    $r = (array) $r;
    return [
        'id' => (int) $r['id'],
        'word' => $r['word'],
        'translation' => $r['translation'],
        'phrase' => $r['phrase'],
        'phraseTranslation' => $r['phrase_translation'],
        'notes' => $r['notes'],
        'intervalDays' => (float) $r['interval_days'],
        'dueAt' => gc_iso($r['due_at']),
        'easyCount' => (int) $r['easy_count'],
        'hardCount' => (int) $r['hard_count'],
        'reviewCount' => (int) $r['review_count'],
        'streak' => (int) $r['streak'],
        'mastered' => (bool) (int) $r['mastered'],
        'retired' => (bool) (int) $r['retired'],
        'archived' => (bool) (int) $r['archived'],
        'lastReviewedAt' => gc_iso($r['last_reviewed_at']),
        'createdAt' => gc_iso($r['created_at']),
        'updatedAt' => gc_iso($r['updated_at']),
    ];
}

function gc_session_out($r)
{
    if (!$r) {
        return null;
    }
    $r = (array) $r;
    return [
        'id' => (int) $r['id'],
        'requestedCount' => $r['requested_count'] === null ? null : (int) $r['requested_count'],
        'queuedCount' => (int) $r['queued_count'],
        'studiedCount' => (int) $r['studied_count'],
        'easyCount' => (int) $r['easy_count'],
        'hardCount' => (int) $r['hard_count'],
        'leftDeckCount' => (int) $r['left_deck_count'],
        'status' => $r['status'],
        'durationMs' => (int) $r['duration_ms'],
        'startedAt' => gc_iso($r['started_at']),
        'endedAt' => gc_iso($r['ended_at']),
    ];
}

function gc_get_card($id)
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gc_table('cards') . ' WHERE id = %d', $id), ARRAY_A);
}

function gc_get_session($id)
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . gc_table('study_sessions') . ' WHERE id = %d', $id), ARRAY_A);
}

/** Lança erro se a última consulta falhou (vira um 500 genérico na API). */
function gc_check_db()
{
    global $wpdb;
    if ($wpdb->last_error) {
        throw new RuntimeException('Banco: ' . $wpdb->last_error);
    }
}
