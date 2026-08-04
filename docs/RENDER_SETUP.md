# Déploiement API sur Render

## Prérequis
- Repo GitHub à jour (Dockerfile inclus)
- Compte [Render](https://dashboard.render.com) lié à GitHub

## 1. Postgres
1. **New +** → **Postgres**
2. Name : `lonto-academy-db`
3. Plan : **Free**
4. Copier **Internal Database URL**

## 2. APP_KEY (en local)
```bash
php artisan key:generate --show
```

## 3. Web Service
1. **New +** → **Web Service**
2. Repo : `lonto-academy-api`, branch `main`
3. Runtime : **Docker**
4. Plan : **Free**
5. Region : **identique** à la DB

## 4. Variables d'environnement

| Key | Value |
|-----|--------|
| `APP_NAME` | `Lonto Academy` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | sortie de `key:generate --show` |
| `APP_URL` | `https://<service>.onrender.com` |
| `FRONTEND_URL` | `http://localhost:5173` (puis URL Vercel) |
| `CORS_ALLOWED_ORIGINS` | mêmes origines, séparées par des virgules |
| `DB_CONNECTION` | `pgsql` |
| `DATABASE_URL` | Internal Database URL (Postgres) |
| `LOG_CHANNEL` | `stderr` |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `FILESYSTEM_DISK` | `local` |

## 5. Vérification
- Health : `https://<service>.onrender.com/up`
- Logs Render en cas d'échec (souvent `APP_KEY` ou DB)

## Notes
- Free = sleep après ~15 min d'inactivité
- Postgres Free expire après 30 jours
- Vidéos R2 : à brancher plus tard (`FILESYSTEM` / disque dédié)
