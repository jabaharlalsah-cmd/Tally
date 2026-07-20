# ZeroBook — Tally sample exports

This folder holds the synthetic Tally XML exports the migration tool is proven
against. They model a documented **TallyPrime "All Masters + Day Book"** combined
export (an `<ENVELOPE>` of `<TALLYMESSAGE>` records).

| File | What it is |
|------|-----------|
| `prove.xml` | A tiny but complete book: 3 custom groups (one nested two levels deep), 5 ledgers (one Dr opening, two Cr), 2 stock items (one with opening qty), the reused `Main Location` godown, 1 cost centre, and 4 vouchers (Payment, Purchase, Sales-as-invoice, Journal) **emitted deliberately out of date order** so the chronological sort is load-bearing. Also contains a stock category + cost category that ZeroBook reports as *not imported*. |
| `broken.xml` | A single Journal that debits 1,000 but credits 900. Proves the importer **rejects** an out-of-balance voucher by name and rolls the whole import back. |
| *(large sample)* | Generated at runtime by `zerobook:prove-tally-import` (300 balanced Journals in reverse date order) to prove streaming + the bulk-mode performance path. Not committed to the repo. |

Run the full proof:

```bash
php artisan zerobook:prove-tally-import        # asserts every figure, then rolls back
php artisan zerobook:prove-tally-import --keep # …and keeps prove.xml's book in the DB
```

---

## How to export YOUR own company from Tally

> ⚠️ **Before a real migration, replace `prove.xml` with a genuine export from your
> Tally and confirm the parser against it.** The synthetic sample models the
> documented format, but the exact tags drift between Tally ERP 9 and TallyPrime.
> The importer is tolerant of the known variants (see `PHASE7A_README.md`), but the
> **amount / opening-balance sign convention** (negative = Debit) is the one thing
> to eyeball first.

### TallyPrime
1. Open your company. `Alt + G` (Go To) → **Export** → **Masters** (or **Day Book**
   for vouchers).
2. Set **File Format = XML (data interchange)**.
3. For masters: **Type of Masters = All Masters**. For vouchers: open **Day Book**,
   press `Alt + F2` and widen the period to cover **all** years you want to migrate,
   then Export.
4. Note the output file name/folder and copy the `.xml` here.

### Tally ERP 9
1. `Gateway of Tally` → **Display** → **Day Book** (for vouchers) or any Masters
   report → `Alt + E` (Export).
2. **Format = XML**. Widen the period (`Alt + F2`) to cover every year.
3. Export masters and vouchers; combine or import them one after another.

You can export masters and vouchers as **one combined file** or two separate files.
The importer reads whatever masters and vouchers a file contains.

---

## Import into ZeroBook

```bash
# 1. Preview — parses, resolves, validates, and prints the full summary.
#    Writes NOTHING. This is the report a CA reviews before committing.
php artisan zerobook:tally-import path/to/company.xml --dry-run

# 2. If the dry-run is clean (no rejected vouchers), commit for real:
php artisan zerobook:tally-import path/to/company.xml

#    add -v for the chronological post order.
```

* The whole import runs in **one transaction** — you get a complete, verified book
  or the database you started with. Never a half-import.
* Any voucher ZeroBook can't validate (out of balance, unknown ledger, a tax that
  doesn't reconcile) is **rejected by name** and the import rolls back. Fix it in
  Tally, re-export, re-run.
* The summary lists everything **not imported** (stock categories, payroll, budgets,
  price levels, …) so nothing is silently dropped.
