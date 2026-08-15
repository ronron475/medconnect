# MedConnect deployment (GitLab + Hostinger + Railway)

Official production site: **https://medconnect.bccbsis.com**

| Component | Platform | How it deploys |
|-----------|----------|----------------|
| PHP app | Hostinger | GitLab CI → FTPS on push to `main` |
| Python AI | Railway | GitHub mirror or `railway up` from local clone |
| Source control | GitLab | `gitlab.bagocitycollege.com/bsis-capstone-2026/medconnect` |

Do **not** use InfinityFree or other free PHP hosts for production — OTP email, AI status checks, and reliability are limited there.

## 1. Push from your PC

```powershell
cd c:\xampp\htdocs\medconnect
git push origin main
```

`origin` should be:

`https://gitlab.bagocitycollege.com/bsis-capstone-2026/medconnect.git`

## 2. One-time: enable automatic online deploy

GitLab CI validates every push. On **`main`**, it also **auto-deploys** to the live site when FTP variables are set.

### Add CI/CD variables

In GitLab: **Settings → CI/CD → Variables → Add variable**

| Variable | Example / notes | Flags |
|----------|-----------------|--------|
| `DEPLOY_FTP_HOST` | FTP host from hPanel / cPanel (e.g. `ftp.bccbsis.com` or server IP) | Protected |
| `DEPLOY_FTP_USER` | FTP username | Protected + Masked |
| `DEPLOY_FTP_PASS` | FTP password | Protected + Masked |
| `DEPLOY_FTP_PORT` | `21` (or your host’s FTP port) | Optional |
| `DEPLOY_FTP_REMOTE_DIR` | Path to the site root on the server (often `public_html` or `domains/medconnect.bccbsis.com/public_html`) | Protected |
| `DEPLOY_FTP_SSL` | `true` (FTPS) or `false` if host only allows plain FTP | Optional |

Also keep app secrets as needed:

| Variable | Notes |
|----------|--------|
| `GROQ_API_KEY` | Masked |
| `OCR_SPACE_API_KEY` | Masked |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | DB (server `.env` is source of truth; do not overwrite via FTP) |
| `MEDCONNECT_AI_SERVICE_URL` | FastAPI public URL if used |

**Important:** Mark deploy variables as **Protected** and only protect the `main` branch (Settings → Repository → Protected branches), so deploys only run from `main`.

### Where to find FTP details

- **Hostinger:** hPanel → **Files** → **FTP Accounts**
- **cPanel:** **FTP Accounts**
- Remote directory must be the folder that serves `https://medconnect.bccbsis.com` (contains `index.php`)

## 3. What happens on each push to `main`

1. Pipeline runs **PHP syntax** (+ optional FastAPI smoke test)
2. **`deploy:production`** uploads changed project files via FTPS
3. Server `.env`, uploads, and logs are **not** deleted/overwritten by exclude rules

Check status: GitLab → **Build → Pipelines**

## 4. Alternative: Hostinger Git auto-deploy (webhook)

If the host uses **Advanced → Git**:

1. Connect repo `git@gitlab.bagocitycollege.com:bsis-capstone-2026/medconnect.git` (or HTTPS) branch `main`
2. Enable **Auto Deployment** and copy the webhook URL
3. In GitLab: **Settings → Webhooks** → paste URL → Push events → Add webhook

Then every push to `main` triggers a pull on the server (you can use this *or* the FTP CI job; both is redundant).

## 5. Production AI URL (Hostinger `.env` optional)

`config/app.php` auto-uses the Railway AI URL on `medconnect.bccbsis.com` when
`MEDCONNECT_AI_SERVICE_URL` is unset (so school Hostinger login is not required).

Optional Hostinger `.env` override (FTP deploy does **not** overwrite `.env`):

```env
MEDCONNECT_APP_URL=https://medconnect.bccbsis.com
MEDCONNECT_AI_SERVICE_ENABLED=true
MEDCONNECT_AI_SERVICE_URL=https://medconnect-production-a654.up.railway.app
MEDCONNECT_AI_AUTO_START=false
MEDCONNECT_AI_REQUIRE_PYTHON=true
GROQ_API_KEY=your_groq_key
MEDCONNECT_GROQ_MODEL=openai/gpt-oss-120b
```

Import `database/schema.sql` and migrations on the production database when schema changes.

## 6. Python AI on Railway

PHP on Hostinger cannot run Python. Deploy `ai_service/` separately on [Railway](https://railway.app).

### Railway variables (Python service only)

| Variable | Value |
|----------|--------|
| `GROQ_API_KEY` | From Groq console |
| `MEDCONNECT_GROQ_MODEL` | `openai/gpt-oss-120b` |
| `MEDCONNECT_AI_INTERPRETER` | `1` |
| `MEDCONNECT_AI_PROVIDER_ORDER` | `groq,openai,local` |
| `MEDCONNECT_AI_HOST` | `0.0.0.0` |

Do **not** set `MEDCONNECT_AI_SERVICE_URL` on Railway — that belongs on Hostinger `.env` only.

### Deploy from GitLab clone (no GitHub required)

```powershell
cd c:\xampp\htdocs\medconnect
npm install -g @railway/cli
railway login
railway link
railway up
```

Or connect a GitHub mirror of this repo; Railway builds `ai_service/Dockerfile` (see `railway.toml`).

### Verify

1. `https://your-service.up.railway.app/health` → `"status": "ok"`
2. `https://medconnect.bccbsis.com/app/api/ai/groq_health.php` → `"groq": true`

## 7. Push code changes

```powershell
git push origin main
```

GitLab CI redeploys PHP to Hostinger. Re-run `railway up` (or push to GitHub) when `ai_service/` changes.
