# Galaxy Cards para WordPress

Plugin que coloca o Galaxy Cards dentro de um WordPress, idêntico ao da Vercel:

- **Mesma interface.** É o mesmo build React do projeto `galaxy-cards` (mesmo CSS, mesmos
  componentes, versão de teste incluída). A única mudança no código do app é que o endereço da
  API passou a ser configurável (`src/lib/api.ts`); na Vercel continua `/api`.
- **Mesmas regras.** A API do servidor Express foi reescrita em PHP rota por rota: escada de
  15/30/45/60 dias, permanentes, difícil volta na hora, uma carta por baralho, retomar baralho,
  bloqueio de repetidos, importação em massa, estatísticas e histórico.
- **Mesmos dados.** O plugin já leva os cards, os áudios gerados e as configurações do banco atual
  (Neon), com os mesmos ids e datas.
- **Login do WordPress** (1.1). No lugar do código de acesso, entra quem está logado como
  administrador do WordPress. A API recebe o nonce `wp_rest` (cabeçalho `X-WP-Nonce`), renovado a
  cada resposta (`X-GC-Nonce`). Na Vercel o app continua com o código de acesso.

## Instalar na HostGator

1. **WordPress:** no cPanel, instale um WordPress limpo no domínio (*WordPress Manager* /
   *Softaculous*). Ative o SSL gratuito (AutoSSL) se ainda não estiver ativo.
2. **Plugin:** no painel do WordPress, *Plugins → Adicionar novo → Enviar plugin*, escolha
   `dist/galaxy-cards.zip` e clique em **Ativar**.
3. **Pronto:** abra `https://seudominio.com.br/galaxy-cards/` e entre com o usuário do WordPress.

Na ativação o plugin cria as tabelas (`wp_gc_cards`, `wp_gc_study_sessions`, `wp_gc_reviews`,
`wp_gc_audio_cache`), importa os dados e, se os links permanentes estiverem em "Simples", liga
"Nome do post" (sem isso o endereço `/galaxy-cards/` não existe).

## Configurações → Galaxy Cards

- **Endereço do app** (padrão `galaxy-cards`) ou **abrir na página inicial do site**.
- **Áudio:** chave do Google Cloud TTS, voz e velocidade (vêm preenchidas com as da Vercel).
- **Backup:** baixar um `.json` com tudo e restaurar a partir dele (a chave do Google não vai no
  backup).

## Atualizar os dados antes de instalar

O zip leva os cards de quando foi gerado. Se cadastrar mais cards na Vercel antes de migrar,
gere de novo com os dados atuais:

```bash
bash empacotar.sh --dados
```

Depois da migração os dois sites ficam independentes: o que for estudado em um não aparece no
outro. Use só o do WordPress a partir daí.

## Cuidados

- O zip contém a chave do Google TTS (em `data/dados-iniciais.php`,
  que o servidor nunca entrega ao navegador). Não compartilhe o zip.
- Desativar ou apagar o plugin **não** apaga as tabelas; os cards continuam no banco.
- Plugins de cache (LiteSpeed Cache, WP Super Cache…) não devem cachear `/galaxy-cards/` nem
  `/wp-json/galaxy-cards/`. O plugin já manda os cabeçalhos de não-cachear.

## Estrutura

```
galaxy-cards/                 o plugin (é isto que vai no zip)
  galaxy-cards.php            ativação e carregamento
  includes/api.php            as rotas da API (/wp-json/galaxy-cards/v1/…)
  includes/srs.php            escada de 15 dias e detecção de repetidos
  includes/auth.php           acesso pelo login do WordPress (administradores)
  includes/tts.php            Google TTS com cache no banco
  includes/frontend.php       serve o app em /galaxy-cards/ (ou na página inicial)
  includes/admin.php          tela de configurações, backup e restauração
  includes/importar.php       importação/exportação dos dados
  app/                        build do app React
  data/dados-iniciais.php     cards, áudios e configurações exportados do Neon
empacotar.sh                  refaz o build e gera dist/galaxy-cards.zip
tools/exportar-dados.mjs      exporta o banco do Neon para o plugin
```
