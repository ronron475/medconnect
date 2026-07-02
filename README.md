# MedConnect

Community healthcare and teleconsultation platform for Bago City.

## Project structure

```
medconnect/
├── app/                 # Application code
│   ├── api/             # JSON/REST endpoints
│   ├── controllers/     # Form handlers (auth, patient, provider)
│   ├── core/            # Domain services and engines
│   ├── includes/        # Shared helpers (mailer, audit_log, auth)
│   └── models/          # Data access layer
├── bootstrap/           # Application bootstrap (app.php)
├── config/              # Environment and database config (not web-accessible)
├── data/nlp/            # NLP training/reference datasets
├── database/            # Schema, migrations, exports/
├── docs/                # Internal documentation
├── modules/             # Optional modules (hospital referral, address selector)
├── public/              # Web document root (production)
│   ├── index.php        # Landing page entry
│   ├── view.php         # Portal view router (/views/*)
│   ├── assets/          # CSS, JS, images
│   └── app/api/         # Junction to ../app/api (create on deploy)
├── resources/
│   └── views/           # Portal templates (not directly web-accessible)
├── routes/              # Route maps (web + api)
├── scripts/             # Dev tools and data builders
├── storage/             # Logs, uploads, cache, recordings
├── vendor/              # Composer dependencies (PHPMailer, etc.)
└── ai_service/          # Python NLP microservice
```

## Local development (XAMPP)

1. Copy `.env.example` to `.env` and configure database credentials.
2. Import `database/schema.sql` and run migrations in `database/migrations/`.
3. Point browser to `http://localhost/medconnect/` (project root docroot).

### API junction (Windows, for public/ docroot testing)

```bat
mklink /J "c:\xampp\htdocs\medconnect\public\app\api" "c:\xampp\htdocs\medconnect\app\api"
```

On Linux:

```bash
ln -s ../../app/api public/app/api
```

## Production deployment

1. Set Apache/Nginx **document root** to `public/`.
2. Ensure `storage/` and `bootstrap/` are **outside** the web root.
3. Run `composer install --no-dev --optimize-autoloader`.
4. Set file permissions: `storage/` writable by the web server.
5. Configure `.env` with production database and `MEDCONNECT_AI_SERVICE_URL`.
6. Start the AI service separately (`ai_service/server.py`) or disable auto-start in `.env`.

### Apache example

```apache
DocumentRoot "C:/xampp/htdocs/medconnect/public"
<Directory "C:/xampp/htdocs/medconnect/public">
    AllowOverride All
    Require all granted
</Directory>
```

### URL compatibility

| URL pattern | Handler |
|-------------|---------|
| `/` | `public/index.php` |
| `/assets/*` | `public/assets/*` |
| `/views/*` | `public/view.php` → `resources/views/*` |
| `/app/api/*` | `app/api/*` (via junction when docroot is public/) |
| `/app/controllers/*` | `app/controllers/*` (form handlers) |
| `/controllers/*` | Legacy alias → `app/controllers/*` |
| `/hospital-referral/*` | `modules/hospital_referral/*` |

## Composer

```bash
composer install
```

PSR-4 autoload namespace: `App\` → `app/`

## AI service

```bash
cd ai_service
pip install -r requirements-nlp.txt
python server.py
```

Default URL: `http://127.0.0.1:8765`
