// Exporta o banco do galaxy-cards (Neon) para o arquivo de dados do plugin.
// Rodar de dentro da pasta galaxy-cards (usa o .env e as dependências dela):
//   node ../galaxy-cards-wp/tools/exportar-dados.mjs ../galaxy-cards-wp/galaxy-cards/data/dados-iniciais.php
import 'dotenv/config';
import { neon } from '@neondatabase/serverless';
import fs from 'node:fs';

const out = process.argv[2];
if (!out) throw new Error('Informe o arquivo de saída.');
const sql = neon(process.env.DATABASE_URL);
const iso = (v) => (v instanceof Date ? v.toISOString() : v);
const fix = (rows) => rows.map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, iso(v)])));

const data = {
  exportedAt: new Date().toISOString(),
  cards: fix(await sql`SELECT * FROM cards ORDER BY id`),
  study_sessions: fix(await sql`SELECT * FROM study_sessions ORDER BY id`),
  reviews: fix(await sql`SELECT * FROM reviews ORDER BY id`),
  audio_cache: fix(await sql`SELECT * FROM audio_cache`),
  settings: {
    passcode_hash: process.env.PASSCODE_HASH || '',
    tts_api_key: process.env.GOOGLE_TTS_API_KEY || '',
    tts_voice: process.env.TTS_VOICE || 'en-US-Neural2-F',
    tts_rate: process.env.TTS_RATE || '0.95',
  },
};

// .php com "exit" na primeira linha: o navegador nunca consegue ler o conteúdo.
fs.writeFileSync(out, '<?php exit; ?>\n' + JSON.stringify(data));
console.log(`${data.cards.length} cards, ${data.study_sessions.length} baralhos, ${data.reviews.length} revisões, ${data.audio_cache.length} áudios`);
