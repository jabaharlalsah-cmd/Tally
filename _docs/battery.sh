#!/bin/bash
# Full zerobook:prove-* regression, run the way each command actually needs.
#   - 13 need a tenant DB context:  DB_DATABASE=tenant<slug> php artisan …
#   - 1  needs a flag:              prove-tally-import --tenant=<slug>
#   - the rest self-provision and run bare.
# (Running the tenant-context ones bare fails with "This database has no companies table" —
#  database.default is the CENTRAL connection, which has no companies table.)
export PATH="/d/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/d/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
cd /d/laragon/www/tally || exit 1

OUT="$1"
SLUG=regr
: > "$OUT"

TENANTCTX="balance sales-purchase gst vat billwise costcentre item-invoice stock-journal inventory-integration budgets ratios scenarios fifo-lifo"

# A throwaway tenant for the context-needing proofs (they wrap in a transaction + make their own
# throwaway company, so they roll back and leave it clean).
php artisan tinker --execute="
  \$p = app(App\Services\Tenancy\TenantProvisioner::class);
  \$p->teardown('$SLUG');
  \$p->provision('$SLUG', 'Regression', 'enterprise');
  echo 'provisioned';
" >/dev/null 2>&1

CMDS=$(php artisan list --raw 2>/dev/null | grep -oE '^zerobook:prove-[a-z0-9-]+' | sort -u)
TOTAL=$(echo "$CMDS" | wc -l)
echo "### $TOTAL prove commands" >> "$OUT"

PASS=0; FAIL=0; FAILED_CMDS=""
for c in $CMDS; do
  name=${c#zerobook:prove-}
  if [ "$name" = "tally-import" ]; then
    RES=$(php artisan "$c" --tenant=$SLUG 2>&1); MODE=FLAG
  elif echo "$TENANTCTX" | grep -qw "$name"; then
    RES=$(DB_DATABASE=tenant$SLUG php artisan "$c" 2>&1); MODE=TENANT
  else
    RES=$(php artisan "$c" 2>&1); MODE=BARE
  fi

  LAST=$(echo "$RES" | grep -E "ALL .*PASSED|PASSED\.|FAILED|Fatal" | tail -1)
  if echo "$RES" | grep -qE "PASSED"; then
    PASS=$((PASS+1)); echo "OK   [$MODE] $c :: $LAST" >> "$OUT"
  else
    FAIL=$((FAIL+1)); FAILED_CMDS="$FAILED_CMDS $c"
    echo "FAIL [$MODE] $c :: $LAST" >> "$OUT"
    echo "$RES" | grep -E "\[FAIL\]|Fatal|no companies" | head -4 | sed 's/^/       /' >> "$OUT"
  fi
done

# A single FAIL in a tight batch is often a provisioning/lock race, not a regression — re-run each
# failure standalone before believing it.
if [ -n "$FAILED_CMDS" ]; then
  echo "" >> "$OUT"; echo "--- re-running failures standalone ---" >> "$OUT"
  for c in $FAILED_CMDS; do
    name=${c#zerobook:prove-}
    if [ "$name" = "tally-import" ]; then RES=$(php artisan "$c" --tenant=$SLUG 2>&1)
    elif echo "$TENANTCTX" | grep -qw "$name"; then RES=$(DB_DATABASE=tenant$SLUG php artisan "$c" 2>&1)
    else RES=$(php artisan "$c" 2>&1); fi
    if echo "$RES" | grep -qE "PASSED"; then
      PASS=$((PASS+1)); FAIL=$((FAIL-1)); echo "RETRY-OK   $c" >> "$OUT"
    else
      echo "RETRY-FAIL $c" >> "$OUT"; echo "$RES" | grep -E "\[FAIL\]|Fatal" | head -4 | sed 's/^/       /' >> "$OUT"
    fi
  done
fi

php artisan tinker --execute="app(App\Services\Tenancy\TenantProvisioner::class)->teardown('$SLUG');" >/dev/null 2>&1

echo "" >> "$OUT"
echo "=== VERDICT: ${PASS}/${TOTAL} passed, ${FAIL} failed ===" >> "$OUT"
