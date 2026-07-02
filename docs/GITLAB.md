# Push MedConnect to GitLab

## 1. Create a GitLab project

Create an empty project on GitLab (no README) and copy the remote URL.

## 2. First push from your PC

```powershell
cd c:\xampp\htdocs\medconnect

git remote add origin https://gitlab.com/YOUR_GROUP/medconnect.git
# or: git@gitlab.com:YOUR_GROUP/medconnect.git

git push -u origin master
```

If `origin` already exists, update it:

```powershell
git remote set-url origin https://gitlab.com/YOUR_GROUP/medconnect.git
git push -u origin master
```

## 3. Secrets (never commit)

Copy `.env.example` to `.env` locally. In GitLab go to **Settings → CI/CD → Variables** and add:

| Variable | Notes |
|----------|--------|
| `GROQ_API_KEY` | Masked |
| `OCR_SPACE_API_KEY` | Masked |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | For deploy |
| `MEDCONNECT_AI_SERVICE_URL` | FastAPI public URL after deploy |

## 4. What GitLab CI does

On each push, `.gitlab-ci.yml` runs:

- PHP syntax check
- FastAPI import smoke test

Deploy is **not** automatic — host PHP and FastAPI on Railway, Render, or a VPS after push.

## 5. Production deploy (summary)

| Service | Suggested host |
|---------|----------------|
| PHP app | Railway / Render / VPS |
| FastAPI (`ai_service/`) | Railway / Render |
| MySQL | Railway MySQL / managed DB |

PHP `.env` on the server:

```env
MEDCONNECT_AI_SERVICE_ENABLED=true
MEDCONNECT_AI_SERVICE_URL=https://your-fastapi.example.com
MEDCONNECT_AI_AUTO_START=false
MEDCONNECT_AI_REQUIRE_PYTHON=false
```

Import `database/schema.sql` and migrations on the production database.
