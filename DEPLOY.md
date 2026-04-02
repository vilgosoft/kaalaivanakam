# Deploy to Hostinger — kaalaivanakam.in

## Quick Deploy (automated)

```bash
./deploy.sh
```

This builds the frontend, packages everything into `dist/kaalaivanakam-deploy.zip`, and you upload it to Hostinger.

---

## Manual Step-by-Step

### 1. Build the frontend

```bash
cd frontend
npm ci
npm run build
```

### 2. Hostinger `public_html/` structure

Upload files so your `public_html/` looks like this:

```
public_html/
├── .htaccess            ← from repo root .htaccess
├── index.html           ← from frontend/dist/ (or frontend/)
├── favicon.svg          ← from frontend/dist/ (or frontend/public/)
├── icons.svg
├── assets/
│   ├── index-*.js
│   └── index-*.css
└── api/
    ├── .htaccess        ← from api/.htaccess (blocks .env)
    ├── .env             ← create from .env.example
    ├── bootstrap.php
    ├── composer.json
    ├── composer.lock
    ├── index.php
    ├── config/
    ├── src/
    ├── vendor/          ← after composer install
    └── public/
        ├── .htaccess    ← from api/public/.htaccess
        └── index.php
```

### 3. Install PHP dependencies

SSH into Hostinger (or use their Terminal):

```bash
cd ~/public_html/api
composer install --no-dev --optimize-autoloader
```

### 4. Configure environment

Create `public_html/api/.env` from the example:

```bash
cp api/.env.example api/.env
nano api/.env
```

Set your Hostinger MySQL credentials:

```env
APP_ENV=production
APP_DEBUG=false

DB_HOST=localhost
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

JWT_SECRET=generate-a-long-random-string-here
JWT_ACCESS_TTL=3600
```

> Find your DB credentials in **Hostinger hPanel → Databases → MySQL Databases**.

### 5. Set up MySQL database

1. Go to **Hostinger hPanel → Databases → MySQL Databases**
2. Create a new database (or use an existing one)
3. Note the database name, username, and password
4. Run any migration scripts if needed:
   ```bash
   cd ~/public_html/api
   php scripts/migrate_subscriptions_agent_cascade.php
   ```

### 6. Verify deployment

- **Frontend**: https://www.kaalaivanakam.in/
- **API health**: https://www.kaalaivanakam.in/v1/health

### 7. Domain & SSL

In Hostinger hPanel:
1. Go to **Domains** → ensure `kaalaivanakam.in` points to your hosting
2. Go to **SSL** → enable free SSL certificate
3. Force HTTPS redirect (usually auto-enabled)

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| 404 on page refresh | Check that `.htaccess` is in `public_html/` and `mod_rewrite` is enabled |
| 500 error on `/v1/*` | Check `api/.env` credentials and run `composer install` |
| CORS errors | The API already sends CORS headers via `Response::cors()` |
| `.env` exposed | Verify `api/.htaccess` blocks dotfiles |
| Blank page | Check browser console; ensure `assets/` files are uploaded |
