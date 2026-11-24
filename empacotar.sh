#!/usr/bin/env bash
# Gera dist/galaxy-cards.zip (o plugin para enviar no WordPress).
#
#   bash empacotar.sh            → refaz o build do app e empacota
#   bash empacotar.sh --dados    → também baixa os cards atuais do banco da Vercel (Neon)
set -euo pipefail
cd "$(dirname "$0")"
W=$(pwd)
APP=$W/../galaxy-cards
P=$W/galaxy-cards

echo "› build do app (mesmo código do galaxy-cards, só a base da API é configurável)"
(cd "$APP" && npx vite build --outDir "$W/build-front" --emptyOutDir >/dev/null)
rm -rf "$P/app" && cp -r "$W/build-front" "$P/app"
for d in "$P/app" "$P/app/assets"; do printf '<?php\n// Silence is golden.\n' > "$d/index.php"; done

if [[ "${1:-}" == "--dados" ]]; then
  echo "› exportando cards, revisões e áudios do banco atual"
  # Roda de dentro da pasta do app, que tem o .env e as dependências (dotenv, neon).
  cp "$W/tools/exportar-dados.mjs" "$APP/.exportar-dados.mjs"
  (cd "$APP" && node .exportar-dados.mjs "$P/data/dados-iniciais.php"); rm -f "$APP/.exportar-dados.mjs"
fi

mkdir -p dist && rm -f dist/galaxy-cards.zip
(cd "$W" && python3 -I -c "
import os, zipfile
with zipfile.ZipFile('dist/galaxy-cards.zip', 'w', zipfile.ZIP_DEFLATED) as z:
    for base, _, files in os.walk('galaxy-cards'):
        for f in files:
            z.write(os.path.join(base, f))
")
ls -lh dist/galaxy-cards.zip
