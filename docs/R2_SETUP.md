# Cloudflare R2 — médias persistants

Sur Render, le disque du Web Service est éphémère. Dès que `AWS_BUCKET` est
défini, le disque Laravel `public` envoie logos, miniatures, vidéos, etc. vers R2.

## 1. Bucket Cloudflare

1. [dash.cloudflare.com](https://dash.cloudflare.com) → **R2 Object Storage**
2. **Create bucket** (ex. `lonto-academy`)
3. Ouvre le bucket → **Settings** → **Public Development URL** → **Enable**
4. Tape `allow`, puis copie l’URL (`https://pub-xxxxx.r2.dev`)

## 2. Token API

1. R2 → **Manage R2 API Tokens** → **Create API token**
2. Permissions : **Object Read & Write**
3. Scope : ton bucket
4. Note : Access Key ID, Secret Access Key, Account ID

Endpoint :
`https://<ACCOUNT_ID>.r2.cloudflarestorage.com`

## 3. Variables Render (API)

| Key | Value |
|-----|--------|
| `AWS_ACCESS_KEY_ID` | Access Key ID |
| `AWS_SECRET_ACCESS_KEY` | Secret Access Key |
| `AWS_DEFAULT_REGION` | `auto` |
| `AWS_BUCKET` | `lonto-academy` |
| `AWS_ENDPOINT` | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` |
| `AWS_URL` | `https://pub-xxxxx.r2.dev` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |

Tu peux laisser `FILESYSTEM_DISK=local` : seuls les uploads via le disque
`public` partent sur R2 quand `AWS_BUCKET` est renseigné.

## 4. Variable Vercel (front)

| Key | Value |
|-----|--------|
| `VITE_MEDIA_URL` | `https://pub-xxxxx.r2.dev` (même valeur que `AWS_URL`) |

Redéploie le front après avoir ajouté la variable.

## 5. Après déploiement

Les anciens fichiers encore pointés en base mais stockés sur le disque Render
sont perdus. **Ré-uploade** logo, favicon, miniatures de cours, etc.

## Local

Laisse `AWS_BUCKET` vide dans `.env` pour continuer à utiliser
`storage/app/public` + `php artisan storage:link`.
