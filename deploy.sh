#!/usr/bin/env bash
#
# ZeroBook — one-click deploy to https://zerobook.in
# -------------------------------------------------------------------------
# Builds front-end assets locally, uploads the app code to the live Hostinger
# server, installs prod deps, runs any NEW migrations (never migrate:fresh),
# re-caches config/routes/views, and verifies the site is up.
#
# SAFE, repeatable, and NON-destructive:
#   * the server .env is never overwritten (excluded from the upload)
#   * the database is preserved — only new migrations run (no fresh/seed)
#   * uploaded files, vendor/ and storage/ on the server are kept
#   * the site is put in maintenance mode during the swap and always brought
#     back up (even if a step fails)
#
# Run by double-clicking deploy.bat, or:   bash deploy.sh
# -------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")"

# ---- live target (zerobook.in) ------------------------------------------
KEY=~/.ssh/zerobook_deploy
REMOTE=u958726172@187.127.200.139
PORT=65002
DOMAIN=/home/u958726172/domains/zerobook.in
APPDIR="$DOMAIN/app"
PHP83=/opt/alt/php83/usr/bin/php

# ---- local toolchain (Laragon) ------------------------------------------
export PATH="/c/laragon/bin/nodejs/node-v22:/c/Program Files/nodejs:$PATH"
export NODE_TLS_REJECT_UNAUTHORIZED=0   # Laragon cacert workaround for npm

SSH="ssh -i $KEY -p $PORT -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30"

echo "=================================================="
echo "  Deploying ZeroBook  ->  https://zerobook.in"
echo "=================================================="

echo ""
echo "[1/5] Building front-end assets locally..."
npm run build

echo ""
echo "[2/5] Uploading code (server .env / vendor / uploads preserved)..."
tar czf - \
  --warning=no-timestamp \
  --exclude=./node_modules \
  --exclude=./.git \
  --exclude=./vendor \
  --exclude=./.env \
  --exclude='./.env.*' \
  --exclude='./storage/logs/*' \
  --exclude='./storage/framework/cache/data/*' \
  --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' \
  --exclude='./bootstrap/cache/*.php' \
  --exclude=./public/hot \
  --exclude=./public/storage \
  --exclude=./tests \
  --exclude=./.claude \
  --exclude=./deploy.bat \
  --exclude=./deploy.sh \
  --exclude='*.sqlite' \
  . | $SSH "$REMOTE" "mkdir -p $APPDIR && tar xzf - -C $APPDIR"

echo ""
echo "[3/5] Server: maintenance, deps, migrations, caches..."
$SSH "$REMOTE" "
  cd $APPDIR
  # Always bring the site back up on exit, even if a step below fails.
  trap '$PHP83 artisan up >/dev/null 2>&1 || true' EXIT
  $PHP83 artisan down --retry=15 >/dev/null 2>&1 || true

  echo '--- composer (prod, optimized) ---'
  $PHP83 \$(command -v composer) install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -3

  # Keep the web root + storage symlinks intact across deploys.
  [ -L $DOMAIN/public_html ] || { rm -rf $DOMAIN/public_html; ln -s $APPDIR/public $DOMAIN/public_html; }
  [ -e public/storage ] || $PHP83 artisan storage:link >/dev/null 2>&1 || true
  mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

  echo '--- migrate (new migrations only; data preserved) ---'
  if $PHP83 artisan migrate --force 2>&1 | tail -20; then echo 'migrations OK'; else echo '!! MIGRATIONS FAILED — check server .env DB creds'; fi

  # Phase 16A — every TENANT database, too.
  #
  # `migrate` above only runs database/migrations/*.php, i.e. the CENTRAL schema. Tenant
  # migrations live on a separate path (config/tenancy.php migration_parameters --path =
  # database/migrations/tenant) and plain migrate never touches them, so until now every deploy
  # that added a per-tenant table shipped the code WITHOUT the table it needs. 16A puts api_keys
  # and api_request_log in the tenant DB, which would mean the API routes deploy fine and then
  # 500 on the first real call.
  #
  # tenants:migrate is idempotent (it skips tenants already at the latest batch) and safe to run
  # on every deploy. It is NOT silenced: a partial failure here means SOME tenants have the new
  # table and some do not, which must be visible in the deploy output rather than discovered by a
  # customer.
  echo '--- tenants:migrate (per-tenant schema) ---'
  if $PHP83 artisan tenants:migrate --force 2>&1 | tail -20; then echo 'tenant migrations OK'; else echo '!! TENANT MIGRATIONS FAILED — some tenants may be on an older schema'; fi

  # Phase 16C — install the scheduler cron if it is not already there.
  #
  # Until now NOTHING scheduled ran in production. routes/console.php has carried the cron line as
  # a COMMENT since 14A, but no deploy step ever installed it — so trial-check, subscription
  # reminders, nightly tenant backups, the offboarding sweep and 16B's idempotency prune have all
  # been dead on the server. 16C makes that untenable: webhook delivery IS a scheduled command, so
  # without cron a customer's endpoint would simply never be called and nothing would say why.
  #
  # Idempotent: greps for the marker first, so re-deploying never stacks duplicate entries.
  #
  # \$APPDIR IS ALREADY ABSOLUTE (DOMAIN=/home/.../domains/zerobook.in), which is why the rest of
  # this script cds to it directly. Do NOT prefix \$HOME — that yields '\$HOME//home/...', a path
  # that cannot exist, and cron would never be installed at all.
  echo '--- scheduler cron ---'
  CRONDIR=\"$APPDIR\"

  # NEVER let 'crontab -l | crontab -' run on a failed read. The 2>/dev/null that hides the benign
  # 'no crontab for user' also hides a real failure (spool lock, permissions, restricted host) — and
  # piping an empty read back in would REPLACE the user's crontab with just our line, silently
  # deleting Hostinger's own backup/certbot entries. So: capture, check the status, and only treat
  # an EMPTY read as 'no crontab yet'. A failed non-empty read aborts and asks for hands.
  CRON_EXISTING=\"\$(crontab -l 2>/dev/null || true)\"
  CRON_READ_OK=0; crontab -l >/dev/null 2>&1 && CRON_READ_OK=1
  # A backup regardless — cheap, and the one thing that makes a mistake here recoverable.
  [ -n \"\$CRON_EXISTING\" ] && printf '%s\n' \"\$CRON_EXISTING\" > \"\$HOME/crontab.backup.\$(date +%Y%m%d%H%M%S)\" 2>/dev/null || true

  if printf '%s' \"\$CRON_EXISTING\" | grep -q 'zerobook-scheduler'; then
    echo \"cron already installed\"
  elif [ ! -d \"\$CRONDIR\" ]; then
    echo \"!! NOT INSTALLING CRON — \$CRONDIR does not exist. Install it by hand once the path is known.\"
  elif [ \"\$CRON_READ_OK\" -eq 0 ] && [ -n \"\$CRON_EXISTING\" ]; then
    echo \"!! NOT INSTALLING CRON — 'crontab -l' failed but returned data; refusing to risk overwriting it.\"
  else
    printf '%s\n%s\n' \"\$CRON_EXISTING\" \"* * * * * cd \$CRONDIR && $PHP83 artisan schedule:run >/dev/null 2>&1 # zerobook-scheduler\" \
      | sed '/^\$/d' | crontab - \
      && echo \"cron installed: * * * * * cd \$CRONDIR && php artisan schedule:run\" \
      || echo \"!! COULD NOT INSTALL CRON — add it by hand in the Hostinger panel: * * * * * cd \$CRONDIR && $PHP83 artisan schedule:run\"
  fi

  echo '--- re-cache ---'
  $PHP83 artisan optimize:clear >/dev/null 2>&1 || true
  $PHP83 artisan config:cache 2>&1 | tail -1
  $PHP83 artisan route:cache  2>&1 | tail -1
  $PHP83 artisan view:cache   2>&1 | tail -1

  # Prove it — AFTER the re-cache, so this validates the config/route cache cron will actually boot
  # from, not the previous deploy's (bootstrap/cache is excluded from the upload, so before the
  # re-cache the old cache is still live and a self-check there proves nothing about this deploy).
  #
  # The status is captured from ARTISAN, not from the pipeline: '… | head -12' returns head's
  # status, which is 0 even when artisan fatals — so the alarm could only ever fire for a failed cd.
  echo '--- scheduler self-check ---'
  SCHED_OUT=\"\$(cd \"\$CRONDIR\" && $PHP83 artisan schedule:list 2>&1)\"; SCHED_RC=\$?
  printf '%s\n' \"\$SCHED_OUT\" | head -12
  if [ \"\$SCHED_RC\" -ne 0 ]; then
    echo \"!! schedule:list FAILED (exit \$SCHED_RC) from the cron path — cron will not work either\"
  elif ! printf '%s' \"\$SCHED_OUT\" | grep -q 'webhook-dispatch'; then
    echo '!! schedule:list ran but does not list webhook-dispatch — the scheduler is not seeing 16C'
  else
    echo 'self-check OK: cron path boots and webhook-dispatch is scheduled'
  fi

  $PHP83 artisan up >/dev/null 2>&1 || true
  trap - EXIT
  echo 'server steps done'
"

echo ""
echo "[4/5] Verifying live site..."
CODE=$($SSH "$REMOTE" "curl -s -o /dev/null -w '%{http_code}' https://zerobook.in/" 2>/dev/null || echo "000")
echo "     https://zerobook.in  ->  HTTP $CODE"

echo ""
if [ "$CODE" = "200" ]; then
  echo "[5/5] DONE — live and serving  ->  https://zerobook.in"
else
  echo "[5/5] DONE — but got HTTP $CODE. Check the log:"
  echo "     $SSH $REMOTE 'tail -30 $APPDIR/storage/logs/laravel.log'"
fi
