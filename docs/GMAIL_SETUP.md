# Configurer Gmail pour les e-mails Lonto Academy

Sans SMTP, Laravel utilise le driver `log` : les e-mails sont écrits dans `storage/logs/laravel.log` (utile en local).

## 1. Mot de passe d'application Google

1. Activez la validation en 2 étapes sur votre compte Gmail.
2. Allez sur [https://myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
3. Créez un mot de passe d'application nommé `Lonto Academy`.
4. Copiez le code à 16 caractères.

## 2. Variables `.env` (API)

```env
APP_NAME="Lonto Academy"
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=votre.email@gmail.com
MAIL_PASSWORD=xxxx xxxx xxxx xxxx
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=votre.email@gmail.com
MAIL_FROM_NAME="${APP_NAME}"
```

Puis :

```bash
php artisan config:clear
```

## 3. Test

1. Page `/mot-de-passe-oublie` → saisissez un e-mail existant.
2. Vérifiez la boîte Gmail (et les spams).
3. Ou consultez `storage/logs/laravel.log` si `MAIL_MAILER=log`.

## Remarques

- Ne committez jamais le mot de passe d'application.
- Pour la prod, préférez un service dédié (Brevo, Mailgun, Resend) plus tard.
