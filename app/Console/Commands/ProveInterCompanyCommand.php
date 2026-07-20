<?php

namespace App\Console\Commands;

use App\Livewire\LedgerWorkspace;
use App\Livewire\VoucherScreen;
use App\Models\AccountGroup;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Ledger;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherIntercompanyTag;
use App\Services\BalanceService;
use App\Services\CompanyProvisioner;
use App\Services\InterCompanyService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\ActiveCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 12B — THE inter-company tagging proof. Companies A, B, C in one tenant:
 * A+B grouped, C outside. It asserts, to the row:
 *
 *   • UNGROUPED IS INERT — a linked ledger without a group requires no tag,
 *     creates none, and a declared tag is refused (the CA-firm guarantee);
 *   • group CRUD — one group per company (DB-enforced), delete cascades the
 *     membership and touches no company;
 *   • ledger linking guards — same-group only, party-tracking groups only;
 *   • the SERVER-DERIVED tag — posted with the right counterparty it lands in
 *     voucher_intercompany_tags; tampered/missing/false-positive/two-counterparty
 *     payloads are each rejected with their specific reason;
 *   • reciprocal ledgers — one click mirrors the party into the counterparty
 *     company, and subsequent tags record counterparty_ledger_id;
 *   • alter re-derives the tag, cancel cascades it away;
 *   • the Trial Balance balances per company throughout — and the 12A isolation
 *     invariants hold untouched.
 */
class ProveInterCompanyCommand extends Command
{
    protected $signature = 'zerobook:prove-intercompany {--keep : keep the ictest tenant provisioned}';

    protected $description = 'Prove Phase 12B: company groups + server-derived inter-company transaction tagging, with every tamper case rejected and ungrouped tenants untouched';

    private bool $ok = true;

    private int $a;

    private int $b;

    private int $c;

    public function handle(TenantProvisioner $provisioner): int
    {
        $slug = 'ictest';
        try {
            $provisioner->teardown($slug);
            $provisioner->provision($slug, 'Alpha Ltd', 'professional');
            Tenant::find($slug)->run(fn () => $this->runProof());
        } catch (Throwable $e) {
            $this->ok = false;
            $this->error('Fatal: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());
        } finally {
            if (! $this->option('keep')) {
                $provisioner->teardown($slug);
            }
        }

        $this->line('');
        $this->info($this->ok ? 'ALL INTER-COMPANY ASSERTIONS PASSED.' : 'INTER-COMPANY ASSERTIONS FAILED.');

        return $this->ok ? self::SUCCESS : self::FAILURE;
    }

    private function runProof(): void
    {
        $this->a = Company::defaultCompany()->id;                       // Alpha Ltd
        $this->b = app(CompanyProvisioner::class)->create('Beta Mfg Co')->id;
        $this->c = app(CompanyProvisioner::class)->create('Gamma Traders')->id;
        ActiveCompany::set($this->a);

        $gid = fn (string $n) => AccountGroup::where('name', $n)->value('id');
        $mkLinked = function (string $name, string $group, ?int $linkedTo) use ($gid) {
            return Ledger::create(['name' => $name, 'group_id' => $gid($group), 'linked_company_id' => $linkedTo]);
        };

        // ═══ 0. UNGROUPED BASELINE — linking without grouping is inert ══════════
        $this->section('Ungrouped baseline (the CA-firm guarantee)');
        $ic = app(InterCompanyService::class);
        $this->expect('No group → enabled() false', $ic->enabled(), false);

        $betaParty = $mkLinked('Beta Mfg Co', 'Sundry Debtors', $this->b); // link WITHOUT a group
        $sales = Ledger::create(['name' => 'Sales A/c', 'group_id' => $gid('Sales Accounts')]);
        $mk = fn (array $extra = []) => array_merge([
            'type' => 'sales', 'date' => '2026-07-10',
            'lines' => [
                ['ledger_id' => $betaParty->id, 'dr_cr' => 'Dr', 'amount' => 5000],
                ['ledger_id' => $sales->id, 'dr_cr' => 'Cr', 'amount' => 5000],
            ],
        ], $extra);

        $v0 = (new VoucherScreen())->post($mk());
        $this->expect('Sale on the linked ledger posts WITHOUT a tag', $v0['voucher']['number'] > 0, true);
        $this->expect('…and NO tag row was created', VoucherIntercompanyTag::count(), 0);
        $declared = $this->rejectionFor($mk(['intercompany' => ['counterparty_company_id' => $this->b]]));
        $this->expect('…and a DECLARED tag is refused while ungrouped', str_contains((string) $declared, 'not in a company group'), true);

        // ═══ 1. GROUP CRUD ═══════════════════════════════════════════════════════
        $this->section('Group create / one-group-per-company / delete cascades');
        $g = CompanyGroup::create(['name' => 'Global Holdings', 'slug' => 'global-holdings']);
        $g->companies()->attach($this->a);
        $this->expect('Single-member group: enabled() true', app(InterCompanyService::class)->enabled(), true);
        $this->expect('…but no groupmates, so tagging cannot fire', app(InterCompanyService::class)->deriveCounterparties([
            ['ledger_id' => $betaParty->id],
        ]), []);
        $v1 = (new VoucherScreen())->post($mk());
        $this->expect('Single-member group: linked-ledger sale still needs no tag', VoucherIntercompanyTag::count(), 0);

        $g->companies()->attach($this->b);
        $this->expect('Group now has A and B', $g->companies()->count(), 2);

        $dupJoin = false;
        try {
            CompanyGroup::create(['name' => 'Second Group', 'slug' => 'second-group'])->companies()->attach($this->a);
        } catch (QueryException) {
            $dupJoin = true;
        }
        $this->expect('A company cannot join a SECOND group (DB unique)', $dupJoin, true);
        CompanyGroup::where('slug', 'second-group')->delete();

        $scratch = CompanyGroup::create(['name' => 'Scratch', 'slug' => 'scratch']);
        $scratch->companies()->attach($this->c);
        $scratch->delete();
        $this->expect('Deleting a group cascades its membership', DB::table('company_group_members')->where('company_id', $this->c)->count(), 0);
        $this->expect('…and leaves the company untouched', Company::find($this->c)?->name, 'Gamma Traders');

        // ═══ 2. LEDGER LINKING GUARDS (the master screen's rules) ════════════════
        $this->section('Ledger linking guards');
        $ws = new LedgerWorkspace();
        $ws->name = 'Gamma Traders';
        $ws->group_id = $gid('Sundry Debtors');
        $ws->linked_company_id = $this->c; // NOT a groupmate
        $this->expect('Linking to a company outside the group is refused', $ws->saveSingle(), null);
        $this->expect('…with a same-group message', str_contains($ws->getErrorBag()->first('linked_company_id'), 'not a member of this company'), true);

        $ws2 = new LedgerWorkspace();
        $ws2->name = 'Beta Freight Income';
        $ws2->group_id = $gid('Sales Accounts');   // NOT party-tracking
        $ws2->linked_company_id = $this->b;
        $this->expect('Linking a non-party ledger is refused', $ws2->saveSingle(), null);
        $this->expect('…with a party-tracking message', str_contains($ws2->getErrorBag()->first('linked_company_id'), 'party-tracking'), true);

        $ws3 = new LedgerWorkspace();
        $ws3->name = 'Beta Loan Account';
        $ws3->group_id = $gid('Unsecured Loans');  // child of Loans (Liability) — party-tracking
        $ws3->linked_company_id = $this->b;
        $loanLedger = $ws3->saveSingle();
        $this->expect('Linking a loan ledger (party-tracking descendant) is allowed', $loanLedger !== null, true);

        // ═══ 3. THE TAG — a grouped inter-company Sale ══════════════════════════
        $this->section('Inter-company Sale posts WITH the derived tag');
        $v3 = (new VoucherScreen())->post($mk(['intercompany' => ['counterparty_company_id' => $this->b]]));
        $tag = VoucherIntercompanyTag::where('voucher_id', $v3['voucher']['id'])->first();
        $this->expect('Tag row created', $tag !== null, true);
        $this->expect('Tag: posting company = A', (int) $tag->company_id, $this->a);
        $this->expect('Tag: counterparty = B', (int) $tag->counterparty_company_id, $this->b);
        $this->expect('Tag: no reciprocal ledger yet → null', $tag->counterparty_ledger_id, null);

        // ═══ 4–6. THE THREE REJECTIONS ═══════════════════════════════════════════
        $this->section('Tampered / missing / false-positive tags rejected');
        $msg = $this->rejectionFor($mk(['intercompany' => ['counterparty_company_id' => $this->c]]));
        $this->expect('TAMPERED (claims Gamma): rejected', $msg !== null, true);
        $this->expect('…naming both companies', str_contains((string) $msg, 'Beta Mfg Co') && str_contains((string) $msg, 'Gamma Traders'), true);

        $msg = $this->rejectionFor($mk()); // no tag at all
        $this->expect('MISSING tag: rejected', str_contains((string) $msg, 'tag is missing'), true);

        $cash = Ledger::where('name', 'Cash')->first();
        $exp = Ledger::create(['name' => 'Office Expense', 'group_id' => $gid('Indirect Expenses')]);
        $msg = $this->rejectionFor([
            'type' => 'payment', 'date' => '2026-07-10',
            'lines' => [
                ['ledger_id' => $exp->id, 'dr_cr' => 'Dr', 'amount' => 700],
                ['ledger_id' => $cash->id, 'dr_cr' => 'Cr', 'amount' => 700],
            ],
            'intercompany' => ['counterparty_company_id' => $this->b],
        ]);
        $this->expect('FALSE-POSITIVE tag (no linked line): rejected', str_contains((string) $msg, 'no line posts to a ledger linked'), true);

        // Two different counterparties in one voucher — a tag row holds one.
        ActiveCompany::runAs($this->c, fn () => null); // (no-op: keep C untouched)
        $g->companies()->detach($this->c); // safety: C stays outside
        $gammaPartyInGroup = null;
        $g2 = CompanyGroup::forCompany($this->a);
        $g2->companies()->attach($this->c); // C joins temporarily for this case
        $gammaPartyInGroup = $mkLinked('Gamma Traders', 'Sundry Debtors', $this->c);
        $msg = $this->rejectionFor([
            'type' => 'journal', 'date' => '2026-07-10',
            'lines' => [
                ['ledger_id' => $betaParty->id, 'dr_cr' => 'Dr', 'amount' => 100],
                ['ledger_id' => $gammaPartyInGroup->id, 'dr_cr' => 'Cr', 'amount' => 100],
            ],
            'intercompany' => ['counterparty_company_id' => $this->b],
        ]);
        $this->expect('TWO counterparties in one voucher: rejected', str_contains((string) $msg, 'cannot touch two inter-company counterparties'), true);
        $g2->companies()->detach($this->c);
        $gammaPartyInGroup->delete();

        // ═══ 7. RECIPROCAL LEDGER ═════════════════════════════════════════════════
        $this->section('Reciprocal ledger creation + counterparty_ledger_id');
        $lws = new LedgerWorkspace();

        // Adversarial-review fix: a same-name NON-party ledger in the counterparty
        // company must BLOCK the reciprocal (stamping the back-link onto it would
        // make B's real third-party postings derive as inter-company).
        $decoy = ActiveCompany::runAs($this->b, fn () => Ledger::create([
            'name' => 'Alpha Ltd', 'group_id' => AccountGroup::where('name', 'Sales Accounts')->value('id'),
        ]));
        $res = $lws->createReciprocal($betaParty->id);
        $this->expect('Reciprocal BLOCKED by a same-name NON-party ledger', $res['ok'], false);
        $this->expect('…with the non-party message', str_contains($res['message'], 'NON-party'), true);
        $this->expect('…and the decoy was NOT linked', ActiveCompany::runAs($this->b, fn () => Ledger::find($decoy->id)->linked_company_id), null);
        ActiveCompany::runAs($this->b, fn () => Ledger::find($decoy->id)->delete());

        $res = $lws->createReciprocal($betaParty->id);
        $this->expect('createReciprocal succeeds', $res['ok'], true);
        $mirror = ActiveCompany::runAs($this->b, fn () => Ledger::where('name', 'Alpha Ltd')->first());
        $this->expect('Mirror ledger exists in B, named after A', $mirror !== null, true);
        $this->expect('Mirror sits under Sundry Creditors', ActiveCompany::runAs($this->b, fn () => $mirror->group?->name), 'Sundry Creditors');
        $this->expect('Mirror links BACK to A', (int) $mirror->linked_company_id, $this->a);

        $v7 = (new VoucherScreen())->post($mk(['intercompany' => ['counterparty_company_id' => $this->b]]));
        $tag7 = VoucherIntercompanyTag::where('voucher_id', $v7['voucher']['id'])->first();
        $this->expect('Post-reciprocal tag records counterparty_ledger_id', (int) $tag7->counterparty_ledger_id, $mirror->id);

        // ═══ 8. ALTER + CANCEL ════════════════════════════════════════════════════
        $this->section('Alter re-derives, cancel cascades');
        (new VoucherScreen())->post($mk([
            'voucher_id' => $v7['voucher']['id'],
            'lines' => [
                ['ledger_id' => $betaParty->id, 'dr_cr' => 'Dr', 'amount' => 6200],
                ['ledger_id' => $sales->id, 'dr_cr' => 'Cr', 'amount' => 6200],
            ],
            'intercompany' => ['counterparty_company_id' => $this->b],
        ]));
        $tags = VoucherIntercompanyTag::where('voucher_id', $v7['voucher']['id'])->get();
        $this->expect('Alter leaves exactly ONE tag (re-derived)', $tags->count(), 1);
        $this->expect('…still against B with the mirror ledger', [(int) $tags[0]->counterparty_company_id, (int) $tags[0]->counterparty_ledger_id], [$this->b, $mirror->id]);

        Voucher::find($v7['voucher']['id'])->delete();
        $this->expect('Cancel cascades the tag away', VoucherIntercompanyTag::where('voucher_id', $v7['voucher']['id'])->count(), 0);

        // ═══ 9. CA-FIRM UNAFFECTED + 12A ISOLATION ═══════════════════════════════
        $this->section('Ungrouped company unaffected; 12A isolation intact');
        ActiveCompany::runAs($this->c, function () use ($gid) {
            $ic = app(InterCompanyService::class);
            $this->expect('[C/ungrouped] enabled() false', $ic->enabled(), false);
            $this->expect('[C/ungrouped] bootData shows disabled + empty group', [
                $ic->bootData()['interCompanyEnabled'], $ic->bootData()['interCompanyGroupCompanyIds'],
            ], [false, []]);
            $exp = Ledger::create(['name' => 'Rent', 'group_id' => AccountGroup::where('name', 'Indirect Expenses')->value('id')]);
            $res = (new VoucherScreen())->post(['type' => 'payment', 'date' => '2026-07-10', 'lines' => [
                ['ledger_id' => $exp->id, 'dr_cr' => 'Dr', 'amount' => 900],
                ['ledger_id' => Ledger::where('name', 'Cash')->value('id'), 'dr_cr' => 'Cr', 'amount' => 900],
            ]]);
            $this->expect('[C/ungrouped] ordinary posting untouched', $res['voucher']['number'], 1);
            // 12A isolation: A holds several vouchers by now; C's scope must see
            // exactly its OWN single voucher and none of A's.
            $this->expect('[C/ungrouped] sees ONLY its own voucher (12A isolation)', Voucher::count(), 1);
        });
        // Tag ledger across the WHOLE tenant: v3's tag survives; v7's cascaded away
        // with its cancel; C's ordinary posting added none. Exactly one row.
        $this->expect('C\'s posting created no tag anywhere (1 = v3 only)', VoucherIntercompanyTag::withoutGlobalScope('company')->count(), 1);

        // ═══ 9b. Reciprocal idempotence + revaluation exemption ══════════════════
        $this->section('Reciprocal idempotence + forex-revaluation exemption');
        $res = (new LedgerWorkspace())->createReciprocal($betaParty->id);
        $this->expect('Second reciprocal call reports already-linked, ok', [$res['ok'], str_contains($res['message'], 'already')], [true, true]);

        // A forex REVALUATION journal is a valuation adjustment — exempt from the
        // mandatory tag even when its lines touch a linked party; a declared tag on
        // one is still refused (no false tag can ride the exemption).
        $ic2 = app(InterCompanyService::class);
        $revalPayload = ['forex_revaluation' => true, 'lines' => [['ledger_id' => $betaParty->id, 'dr_cr' => 'Cr', 'amount' => 100]]];
        $this->expect('Revaluation journal needs NO tag despite the linked line', $ic2->verifyPayload($revalPayload), null);
        $revalDeclared = $revalPayload + ['intercompany' => ['counterparty_company_id' => $this->b]];
        $this->expect('…but a DECLARED tag on a revaluation is refused', str_contains((string) $ic2->verifyPayload($revalDeclared), 'valuation adjustment'), true);

        // ═══ 9c. Stale link after group deletion — inert AND still editable ═══════
        $this->section('Stale link (group deleted) stays inert and editable');
        CompanyGroup::forCompany($this->a)?->delete();
        $this->expect('Group gone → enabled() false again', app(InterCompanyService::class)->enabled(), false);
        $lws2 = new LedgerWorkspace();
        $lws2->loadForAlter($betaParty->id);
        $lws2->l_alias = 'beta-alias'; // edit an unrelated field, link UNCHANGED
        $this->expect('Alter with an UNCHANGED stale link still saves', $lws2->saveAlter() !== null, true);
        $lws3 = new LedgerWorkspace();
        $lws3->loadForAlter($betaParty->id);
        $lws3->l_linked_company_id = $this->c; // CHANGING the link while ungrouped
        $this->expect('CHANGING the link while ungrouped is refused', $lws3->saveAlter(), null);
        $lws4 = new LedgerWorkspace();
        $lws4->loadForAlter($betaParty->id);
        $lws4->l_linked_company_id = null; // clearing is always allowed
        $this->expect('CLEARING the stale link is allowed', $lws4->saveAlter() !== null, true);
        // restore for the final sections: re-create the group + the link
        $gr = CompanyGroup::create(['name' => 'Global Holdings', 'slug' => 'global-holdings-2']);
        $gr->companies()->attach($this->a);
        $gr->companies()->attach($this->b);
        Ledger::whereKey($betaParty->id)->update(['linked_company_id' => $this->b]);

        // ═══ 10. Group visibility + per-company TB ════════════════════════════════
        $this->section('Group visibility + Trial Balance per company');
        $this->expect('[A] group name surfaces for the shell', CompanyGroup::forCompany($this->a)?->name, 'Global Holdings');
        $this->expect('[C] no group name (top bar hides it)', CompanyGroup::forCompany($this->c), null);
        $bs = app(BalanceService::class);
        $tbA = $bs->trialBalance(Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31'));
        $this->expect('[A] TB balanced with all inter-company vouchers', $tbA['balanced'], true);
        $tbC = ActiveCompany::runAs($this->c, fn () => app(BalanceService::class)->trialBalance(Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31')));
        $this->expect('[C] TB balanced independently', $tbC['balanced'], true);
    }

    /** Post expecting a ValidationException; returns the intercompany error (or null). */
    private function rejectionFor(array $payload): ?string
    {
        try {
            (new VoucherScreen())->post($payload);

            return null;
        } catch (ValidationException $e) {
            return collect($e->errors()['intercompany'] ?? [])->first()
                ?? collect($e->errors())->flatten()->first();
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->line('');
        $this->line("── {$title} ".str_repeat('─', max(1, 60 - mb_strlen($title))));
    }

    private function expect(string $label, mixed $actual, mixed $expected): void
    {
        $pass = $actual === $expected;
        if (! $pass) {
            $this->ok = false;
        }
        $this->line(sprintf(' [%s] %s = %s%s',
            $pass ? 'PASS' : 'FAIL', $label, json_encode($actual),
            $pass ? '' : ' (expected '.json_encode($expected).')'));
    }
}
