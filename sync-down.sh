#!/usr/bin/env bash
#
# ZeroBook — one-click REVERSE sync:  live server  ->  local
# -------------------------------------------------------------------------
# The mirror image of deploy.sh. Pulls changes you made ONLINE (directly on
# https://zerobook.in) back down into this local project at C:\laragon\www\tally.
#
# SAFE and NON-destructive by design:
#   * NEVER deletes a local file (only adds / updates).
#   * NEVER overwrites a local file that is NEWER than the server's copy
#     (your unsynced local edits win — they're reported as CONFLICTs to review).
#   * Backs up every file it is about to overwrite, first.
#   * Skips known junk: the server .env, compiled assets, vendor/, storage/,
#     and stale "orphan" migrations left behind by overlay deploys.
#   * Shows you the full plan and asks before changing anything.
#   * Runs the right migrations + clears caches afterwards.
#
# Run by double-clicking sync-down.bat, or:   bash sync-down.sh
# Flags:  --dry-run       show the plan, change nothing
#         --yes | -y      don't ask for confirmation (for automation)
#         --no-migrate    copy files but don't touch the local database/caches
#         --include-stale also pull old server-only files (default: skipped)
# -------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")"
PROJ="$(pwd)"

# ---- live source (zerobook.in) — same box as deploy.sh -------------------
KEY=~/.ssh/zerobook_deploy
REMOTE=u958726172@187.127.200.139
PORT=65002
APPDIR=/home/u958726172/domains/zerobook.in/app
SSH="ssh -i $KEY -p $PORT -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30"

# ---- tunables -----------------------------------------------------------
GRACE=172800     # a server-only file older than (newest-local-mtime - 2 days) is treated as stale/orphan
SKEW=3           # clock-skew guard (seconds) before calling the server copy "newer"

# ---- args ---------------------------------------------------------------
DRY=0; ASSUME_YES=0; DO_MIGRATE=1; INCLUDE_STALE=0
for a in "$@"; do case "$a" in
  --dry-run)       DRY=1 ;;
  --yes|-y)        ASSUME_YES=1 ;;
  --no-migrate)    DO_MIGRATE=0 ;;
  --include-stale) INCLUDE_STALE=1 ;;
  *) echo "unknown flag: $a"; exit 2 ;;
esac; done

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

echo "=================================================="
echo "  ZeroBook  reverse sync   zerobook.in  ->  local"
echo "=================================================="

# ---- 0) connectivity ----------------------------------------------------
if [ ! -f "$KEY" ]; then echo "!! SSH key not found: $KEY"; exit 1; fi
$SSH "$REMOTE" "test -d '$APPDIR'" || { echo "!! cannot reach server or app dir missing"; exit 1; }

# ---- the shared file-manifest snippet (runs identically on both sides) ---
# Emits: sha1 hashes, a @@SPLIT@@ marker, then mtimes. Excludes the same noise
# deploy.sh excludes, plus compiled assets and these two sync scripts.
MANIFEST_SNIPPET='
FIND() { find . -type f \
  -not -path "./.git/*" -not -path "./node_modules/*" -not -path "./vendor/*" \
  -not -path "./storage/*" -not -path "./bootstrap/cache/*" \
  -not -path "./public/build/*" -not -path "./public/hot" -not -path "./public/storage/*" \
  -not -path "./tests/*" -not -path "./.claude/*" \
  -not -name ".env" -not -name ".env.*" -not -name "*.sqlite" \
  -not -name "deploy.sh" -not -name "deploy.bat" \
  -not -name "sync-down.sh" -not -name "sync-down.bat" \
  -not -path "./public/default.php" -not -name "tmp_*.php" -not -name ".phpunit.result.cache" "$@"; }
FIND -print0 | xargs -0 sha1sum
echo "@@SPLIT@@"
FIND -printf "%T@\t%p\n"
'

# Build a "path<TAB>hash<TAB>mtime" manifest from a raw (hashes + @@SPLIT@@ + mtimes) dump.
# NOTE: sha1sum prints " *./path" (binary mode, Windows) or "  ./path" (text mode, Linux);
#       both are exactly 2 separator chars, so hash=chars 1-40, path=chars 43+.
build_manifest() {  # $1 = raw dump, $2 = output manifest
  sed '/^@@SPLIT@@$/q' "$1" | sed '$d'    > "$TMP/h.$$"    # hashes (marker dropped)
  sed '1,/^@@SPLIT@@$/d'   "$1"           > "$TMP/m.$$"    # mtimes
  awk '
    FNR==NR { h=substr($0,1,40); p=substr($0,43); sub(/^\.\//,"",p); H[p]=h; next }
    { i=index($0,"\t"); m=substr($0,1,i-1); p=substr($0,i+1); sub(/^\.\//,"",p);
      if (p in H) print p"\t"H[p]"\t"m }
  ' "$TMP/h.$$" "$TMP/m.$$" | sort > "$2"
  rm -f "$TMP/h.$$" "$TMP/m.$$"
}

echo ""
echo "[1/4] Fingerprinting server and local files..."
printf '%s' "$MANIFEST_SNIPPET" | $SSH "$REMOTE" "cd '$APPDIR' && bash -s" > "$TMP/server_raw"
( cd "$PROJ" && bash -c "$MANIFEST_SNIPPET" )                              > "$TMP/local_raw"
build_manifest "$TMP/server_raw" "$TMP/server_man"
build_manifest "$TMP/local_raw"  "$TMP/local_man"
echo "      server: $(wc -l < "$TMP/server_man") files | local: $(wc -l < "$TMP/local_man") files"

# ---- 2) classify --------------------------------------------------------
# UPDATE   = on both sides, differs, server newer         -> pull
# NEW      = only on server, recent, not an orphan        -> pull
# CONFLICT = differs but LOCAL is newer/same-age          -> skip (your edits win)
# STALE    = only on server, old (likely leftover)        -> skip unless --include-stale
# ORPHAN   = server migration duplicated under tenant/    -> skip (deploy leftover)
awk -F'\t' -v grace="$GRACE" -v skew="$SKEW" -v inclstale="$INCLUDE_STALE" '
  FNR==NR {
    lp[$1]=1; lh[$1]=$2; lm[$1]=$3+0;
    if ($3+0 > newest) newest=$3+0;
    if ($1 ~ /^database\/migrations\/tenant\//) { n=split($1,a,"/"); tb[a[n]]=1 }
    next
  }
  {
    p=$1; h=$2; m=$3+0;
    if (!(p in lp)) {
      if (p ~ /^database\/migrations\/[^\/]+\.php$/) { n=split(p,a,"/"); if (a[n] in tb) { print "ORPHAN\t"p; next } }
      if (m < newest-grace && inclstale==0) { print "STALE\t"p; next }
      print "NEW\t"p; next
    } else if (h != lh[p]) {
      if (m > lm[p]+skew) print "UPDATE\t"p; else print "CONFLICT\t"p
    }
  }
' "$TMP/local_man" "$TMP/server_man" | sort > "$TMP/class"

pick() { awk -F'\t' -v k="$1" '$1==k{print $2}' "$TMP/class"; }
pick UPDATE   > "$TMP/updates.txt"
pick NEW      > "$TMP/news.txt"
pick CONFLICT > "$TMP/conflicts.txt"
pick STALE    > "$TMP/stales.txt"
pick ORPHAN   > "$TMP/orphans.txt"
cat "$TMP/updates.txt" "$TMP/news.txt" | sort -u > "$TMP/pull.txt"

nU=$(wc -l < "$TMP/updates.txt"); nN=$(wc -l < "$TMP/news.txt")
nC=$(wc -l < "$TMP/conflicts.txt"); nS=$(wc -l < "$TMP/stales.txt"); nO=$(wc -l < "$TMP/orphans.txt")
nP=$(wc -l < "$TMP/pull.txt")

# ---- 3) show the plan ---------------------------------------------------
echo ""
echo "[2/4] Plan:"
show() { local t="$1" f="$2"; [ -s "$f" ] && { echo "   $t"; sed 's/^/       /' "$f"; }; return 0; }
show "UPDATE (server newer, $nU):"   "$TMP/updates.txt"
show "NEW (add to local, $nN):"      "$TMP/news.txt"
[ "$nC" -gt 0 ] && show "CONFLICT — LOCAL newer, SKIPPED ($nC) — reconcile by hand:" "$TMP/conflicts.txt"
[ "$nS" -gt 0 ] && show "STALE server-only, SKIPPED ($nS) — re-run with --include-stale to pull:" "$TMP/stales.txt"
[ "$nO" -gt 0 ] && show "ORPHAN migrations, SKIPPED ($nO) — old deploy leftovers:" "$TMP/orphans.txt"

if [ "$nP" -eq 0 ]; then
  echo ""
  echo "   Nothing to pull — local is already up to date with the server."
  [ "$nC" -gt 0 ] && echo "   (You still have $nC conflicting local-newer file(s) above.)"
  exit 0
fi

if [ "$DRY" -eq 1 ]; then
  echo ""
  echo "   --dry-run: no changes made. $nP file(s) would be pulled."
  exit 0
fi

if [ "$ASSUME_YES" -ne 1 ]; then
  echo ""
  read -r -p "   Pull $nP file(s) into local (backup taken first)? [y/N] " ans
  case "${ans:-}" in [Yy]*) ;; *) echo "   Aborted — nothing changed."; exit 0 ;; esac
fi

# ---- 4) apply: backup -> pull -> extract -> migrate ---------------------
TS=$(date +%Y%m%d-%H%M%S)
BK="$HOME/.zerobook-sync-backups/$TS"
mkdir -p "$BK/overwritten"
cp "$TMP/class" "$BK/plan.txt"; cp "$TMP/news.txt" "$BK/added-files.txt"

echo ""
echo "[3/4] Backing up $nU file(s) to be overwritten -> $BK/overwritten"
if [ -s "$TMP/updates.txt" ]; then
  ( cd "$PROJ" && while IFS= read -r f; do cp --parents "$f" "$BK/overwritten/"; done < "$TMP/updates.txt" )
fi

echo "      Pulling $nP file(s) from server..."
$SSH "$REMOTE" "cd '$APPDIR' && tar czf - --warning=no-file-changed -T -" < "$TMP/pull.txt" > "$TMP/pulled.tar.gz"
tar xzf "$TMP/pulled.tar.gz" -C "$PROJ"

# verify byte-for-byte
bad=0
while IFS= read -r f; do
  rh=$(awk -F'\t' -v p="$f" '$1==p{print $2}' "$TMP/server_man")
  lh=$(cd "$PROJ" && sha1sum "$f" | cut -c1-40)
  [ "$rh" = "$lh" ] || { echo "      !! mismatch after copy: $f"; bad=1; }
done < "$TMP/pull.txt"
[ "$bad" -eq 0 ] && echo "      OK — all $nP file(s) match the server."

cat > "$BK/UNDO.txt" <<UNDO
To revert this sync:
  1. Restore overwritten files:  cp -r "$BK/overwritten/." "$PROJ/"
  2. Remove files this sync ADDED (listed in added-files.txt), e.g.:
       cd "$PROJ" && while read f; do rm -f "\$f"; done < "$BK/added-files.txt"
  3. If migrations ran, roll them back manually with:  php artisan migrate:rollback
UNDO

# ---- migrations + caches ------------------------------------------------
echo ""
echo "[4/4] Local database + caches..."
if [ "$DO_MIGRATE" -ne 1 ]; then
  echo "      --no-migrate: skipped. Run 'php artisan migrate' + 'php artisan optimize:clear' yourself."
else
  # Prefer Laragon's PHP 8.3 (what deploy.sh + every artisan command here use);
  # only fall back to whatever 'php' is on PATH (may be an unrelated XAMPP build).
  PHP_BIN=""
  for c in /c/laragon/bin/php/php-8.3*/php.exe /c/laragon/bin/php/php-8.*/php.exe; do [ -x "$c" ] && { PHP_BIN="$c"; break; }; done
  [ -z "$PHP_BIN" ] && PHP_BIN="$(command -v php || true)"
  if [ -z "$PHP_BIN" ]; then
    echo "      !! php not found — run 'php artisan migrate' + 'php artisan optimize:clear' manually."
  else
    ( cd "$PROJ"
      if grep -qE '^database/migrations/[^/]+\.php$' "$TMP/pull.txt"; then
        echo "      --- migrate (central) ---";      "$PHP_BIN" artisan migrate --force 2>&1 | tail -8 || true
      fi
      if grep -q '^database/migrations/tenant/' "$TMP/pull.txt"; then
        echo "      --- tenants:migrate ---";         "$PHP_BIN" artisan tenants:migrate --force 2>&1 | tail -8 || true
      fi
      echo "      --- clearing caches ---";           "$PHP_BIN" artisan optimize:clear 2>&1 | tail -6 || true
    )
  fi
fi

echo ""
echo "=================================================="
echo "  DONE — pulled $nP file(s) from zerobook.in."
[ "$nC" -gt 0 ] && echo "  NOTE: $nC local-newer CONFLICT file(s) were left untouched (see plan above)."
echo "  Backup + undo instructions: $BK"
echo "=================================================="
