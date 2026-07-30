#!/usr/bin/env bash
# Sync MedConnect repo to production over FTP/FTPS (lftp).
# Does NOT delete remote-only files (.env, uploads, logs stay on the server).
set -euo pipefail

: "${DEPLOY_FTP_HOST:?DEPLOY_FTP_HOST is required}"
: "${DEPLOY_FTP_USER:?DEPLOY_FTP_USER is required}"
: "${DEPLOY_FTP_PASS:?DEPLOY_FTP_PASS is required}"

PORT="${DEPLOY_FTP_PORT:-21}"
REMOTE_DIR="${DEPLOY_FTP_REMOTE_DIR:-.}"
USE_SSL="${DEPLOY_FTP_SSL:-true}"

echo "Deploying to ${DEPLOY_FTP_HOST}:${PORT} → ${REMOTE_DIR}"

SSL_OPTS=""
if [ "$USE_SSL" = "true" ] || [ "$USE_SSL" = "1" ]; then
  SSL_OPTS="set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate no;"
else
  SSL_OPTS="set ftp:ssl-allow false;"
fi

# Exclude secrets, tooling, local junk, and runtime upload dirs.
# No --delete: preserve server .env, storage uploads, and host-only files.
lftp -c "
set cmd:fail-exit true;
set net:max-retries 3;
set net:timeout 30;
${SSL_OPTS}
open -u \"${DEPLOY_FTP_USER}\",\"${DEPLOY_FTP_PASS}\" -p ${PORT} ftp://${DEPLOY_FTP_HOST};
lcd .;
cd ${REMOTE_DIR};
mirror -R --verbose --parallel=4 \
  --exclude-glob .git/ \
  --exclude-glob .git/** \
  --exclude-glob .env \
  --exclude-glob .env.* \
  --exclude-glob .cursor/ \
  --exclude-glob .cursor/** \
  --exclude-glob .vercel/ \
  --exclude-glob .vercel/** \
  --exclude-glob node_modules/ \
  --exclude-glob node_modules/** \
  --exclude-glob vendor/ \
  --exclude-glob vendor/** \
  --exclude-glob .venv/ \
  --exclude-glob .venv/** \
  --exclude-glob ai_service/.venv/ \
  --exclude-glob ai_service/.venv/** \
  --exclude-glob ai_service/__pycache__/ \
  --exclude-glob ai_service/**/__pycache__/ \
  --exclude-glob storage/uploads/ \
  --exclude-glob storage/uploads/** \
  --exclude-glob storage/logs/ \
  --exclude-glob storage/logs/** \
  --exclude-glob storage/cache/ \
  --exclude-glob storage/cache/** \
  --exclude-glob storage/temp/ \
  --exclude-glob storage/temp/** \
  --exclude-glob storage/recordings/ \
  --exclude-glob storage/recordings/** \
  --exclude-glob **/*.bak \
  --exclude-glob **/*.prev \
  --exclude-glob public/genhash.php \
  --exclude-glob public/setup_admin.php \
  --exclude-glob public/seed_provider.php \
  --exclude-glob public/test_db_connection.php \
  --exclude-glob public/debug_profile.php \
  --exclude-glob public/delete_user.php \
  ./ .;
bye
"

echo "Deploy finished successfully."
