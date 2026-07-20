<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Server-side data for the ZeroBook Gateway shell. Everything here is static
 * page-load data (identity, working date/period, Go To destinations). It never
 * participates in the keyboard interaction loop — that is 100% client-side.
 */
class Shell
{
    /** Config surfaced to the client as window.ZB_CONFIG. */
    public static function config(): array
    {
        $today = Carbon::today();

        // Phase 12A — the ACTIVE company's fiscal year (financial_year_start_month,
        // default 4 = the pre-12A Indian Apr–Mar rule), and its name in the top bar.
        $company = activeCompany();
        $fyStart = \App\Models\Voucher::fyOpenFor(\App\Models\Voucher::fyStartFor($today));
        $fyEnd = (clone $fyStart)->addYear()->subDay();

        return [
            'product' => 'ZeroBook',
            'company' => $company?->name ?? 'ZeroBook',
            // Phase 12B — the active company's group name (null when ungrouped). A
            // SEPARATE key: the top bar appends it, but print letterheads and the
            // picker keep the clean company name.
            'companyGroup' => $company ? \App\Models\CompanyGroup::forCompany($company->id)?->name : null,
            'activeCompanyId' => $company?->id,
            // Every ACTIVE company in the tenant — the F3 company picker's list.
            'companies' => \App\Models\Company::where('is_active', true)
                ->orderBy('name')->get()->map->toCache()->all(),
            'date'    => $today->format('d-M-Y'),
            'period'  => $fyStart->format('d-M-Y') . ' to ' . $fyEnd->format('d-M-Y'),
            'urls'    => [
                // '__type__' is substituted client-side by the engine's openVoucher().
                'voucherCreate' => route('vouchers.create', ['type' => '__type__']),
                'dayBook'       => route('daybook'),
                'gateway'       => route('gateway'),
                'features'      => route('features'),
                'companySwitch' => route('company.switch'),   // Phase 12A — POST {company_id}
                'companies'     => route('companies'),        // Phase 12A — management screen
            ],
            'features' => \App\Models\CompanyFeature::current()->toFlags(),
            'gst' => self::gstConfig(),
            'vat' => self::vatConfig(),
        ];
    }

    /**
     * GST context for the client: the profile (enabled + company state/GSTIN) and
     * the six duty-ledger ids keyed role.type, so invoice mode can compute tax and
     * inject the correct tax ledger lines with zero network round-trips.
     */
    public static function gstConfig(): array
    {
        $svc = app(\App\Services\GstService::class);

        return array_merge($svc->company()->gstProfile(), [
            'tax_ledgers' => $svc->taxLedgerMap(), // { "output.central": id, ... }
        ]);
    }

    /**
     * VAT context for the client: the profile (enabled + company PAN) and the two
     * VAT duty-ledger ids, so invoice mode computes the single VAT line and injects
     * it with zero network round-trips (mirrors gstConfig).
     */
    public static function vatConfig(): array
    {
        $svc = app(\App\Services\VatService::class);

        return array_merge($svc->company()->vatProfile(), [
            'tax_ledgers' => $svc->taxLedgerMap(), // { "output.vat": id, "input.vat": id }
        ]);
    }

    /** Phase 12C-2 — group-report entries exist ONLY for grouped companies. */
    private static function activeCompanyGrouped(): bool
    {
        $company = activeCompany();

        return $company !== null && \App\Models\CompanyGroup::forCompany($company->id) !== null;
    }

    /**
     * The active company's F11 flags, read once per (company × database).
     *
     * CompanyFeature::current() is a firstOrCreate, so it hits the database on
     * every call. Each menu-gating flag used to call it separately; now that the
     * Gateway and the reports tree gate a dozen entries on flags, that would be a
     * dozen queries to render one menu.
     *
     * THE KEY INCLUDES THE DATABASE NAME, not just the company id — the same
     * hazard ActiveCompany::company() documents. One artisan process can walk
     * several tenants in a row and every tenant's first company is id 1, so an
     * id-only memo would serve tenant A's feature flags while tenant B is active.
     *
     * The memo is also dropped whenever a CompanyFeature row is written (see
     * CompanyFeature::booted()). Without that, anything that toggles a flag and
     * re-reads the menu in the same process — the F11 screen saving, a proof
     * command, an import — would render from stale flags.
     */
    private static ?array $featureCache = null;

    private static ?string $featureCacheKey = null;

    private static function feature(string $key): bool
    {
        $company = activeCompany();
        if ($company === null) {
            return false;
        }

        $cacheKey = \Illuminate\Support\Facades\DB::connection()->getDatabaseName().'#'.$company->id;
        if (self::$featureCache === null || self::$featureCacheKey !== $cacheKey) {
            self::$featureCache = \App\Models\CompanyFeature::current()->toFlags();
            self::$featureCacheKey = $cacheKey;
        }

        return (bool) (self::$featureCache[$key] ?? false);
    }

    /** Drop the memo. Called on every CompanyFeature write and company switch. */
    public static function forgetFeatures(): void
    {
        self::$featureCache = null;
        self::$featureCacheKey = null;
    }

    /** Phase 15C — the Scenarios destinations (Go To palette rows), gated by the F11 flag. */
    private static function scenarioNav(): array
    {
        return [
            ['label' => 'Scenarios',          'sub' => 'Scenarios', 'kind' => 'nav', 'href' => route('reports.scenario-master'),  'icon' => 'ti-flask',           'keywords' => 'scenario scenarios what-if what if provisional draft budget proposal simulation master create manage delete'],
            ['label' => 'Scenario Manager',   'sub' => 'Scenarios', 'kind' => 'nav', 'href' => route('reports.scenario-manager'), 'icon' => 'ti-git-merge',       'keywords' => 'scenario manager promote commit apply provisional vouchers into real books one-way'],
            ['label' => 'Scenario Impact',    'sub' => 'Scenarios', 'kind' => 'nav', 'href' => route('reports.scenario-impact'),  'icon' => 'ti-arrows-diff',     'keywords' => 'scenario impact delta difference real vs with what-if provisional effect profit assets drill'],
        ];
    }

    /** Phase 15B — the Ratio Analysis destinations (Go To palette rows), gated by the F11 flag. */
    private static function ratioNav(): array
    {
        return [
            ['label' => 'Ratio Analysis',    'sub' => 'Ratios', 'kind' => 'nav', 'href' => route('reports.ratio-dashboard'),  'icon' => 'ti-heart-rate-monitor', 'keywords' => 'ratio ratios analysis financial health liquidity solvency profitability efficiency current quick debt equity margin roe roa dashboard'],
            ['label' => 'Ratio Thresholds',  'sub' => 'Ratios', 'kind' => 'nav', 'href' => route('reports.ratio-thresholds'), 'icon' => 'ti-adjustments',       'keywords' => 'ratio thresholds health colour green amber red bands settings configure'],
        ];
    }

    /** Phase 15A — the four Budgets destinations (Go To palette rows), gated by the F11 flag. */
    private static function budgetNav(): array
    {
        return [
            ['label' => 'Budgets',            'sub' => 'Budgets', 'kind' => 'nav', 'href' => route('reports.budget-list'),     'icon' => 'ti-target',       'keywords' => 'budget budgets target list primary manage plan variance fy'],
            ['label' => 'New / Edit Budget',  'sub' => 'Budgets', 'kind' => 'nav', 'href' => route('reports.budget-editor'),   'icon' => 'ti-edit',         'keywords' => 'budget editor new create edit target allocation month grid revise'],
            ['label' => 'Budget vs Actual',   'sub' => 'Budgets', 'kind' => 'nav', 'href' => route('reports.budget-variance'), 'icon' => 'ti-chart-bar',    'keywords' => 'budget variance actual target over under performance report drill favorable'],
            ['label' => 'Budget Summary',     'sub' => 'Budgets', 'kind' => 'nav', 'href' => route('reports.budget-summary'),  'icon' => 'ti-report-analytics', 'keywords' => 'budget summary revenue expense net profit projected ytd variance'],
        ];
    }

    /** Go To (Alt+G) destinations, surfaced as window.ZB_NAV. */
    public static function nav(): array
    {
        $grouped = self::activeCompanyGrouped();

        return array_merge([
            ['label' => 'Gateway of ZeroBook', 'sub' => 'Home',      'kind' => 'nav', 'href' => route('gateway'),        'icon' => 'ti-home',        'keywords' => 'gateway home hub'],
            ['label' => 'Chart of Accounts',   'sub' => 'Masters',   'kind' => 'nav', 'href' => route('masters.index'),  'icon' => 'ti-sitemap',     'keywords' => 'chart accounts masters coa'],
            ['label' => 'Groups',              'sub' => 'Masters',   'kind' => 'nav', 'href' => route('masters.groups'), 'icon' => 'ti-folders',     'keywords' => 'group master account groups create'],
            ['label' => 'Ledgers',             'sub' => 'Masters',   'kind' => 'nav', 'href' => route('masters.ledgers'),'icon' => 'ti-book',        'keywords' => 'ledger master account create'],
            ['label' => 'Cost Centres',        'sub' => 'Masters',   'kind' => 'nav', 'href' => route('masters.cost-centres'), 'icon' => 'ti-sitemap',  'keywords' => 'cost centre center master allocation analytical create'],
            // Phase 10A — the TDS rate table. Rates and thresholds are data, edited here.
            ['label' => 'TDS Sections',        'sub' => 'Masters',   'kind' => 'nav', 'href' => route('masters.tds-sections'), 'icon' => 'ti-percentage', 'keywords' => 'tds section rate table threshold 194j 194c 194i 194q 393 deduct source withholding master finance act'],
            // Phase 11 — the currency + exchange-rate master.
            ['label' => 'Currencies',           'sub' => 'Masters',   'kind' => 'nav', 'href' => route('masters.currencies'),   'icon' => 'ti-coin',         'keywords' => 'currency currencies forex exchange rate multi foreign usd eur base master'],
            ['label' => 'Inventory Info',      'sub' => 'Inventory', 'kind' => 'nav', 'href' => route('inventory.index'),       'icon' => 'ti-packages',     'keywords' => 'inventory stock masters items groups units godowns hub'],
            ['label' => 'Stock Items',         'sub' => 'Inventory', 'kind' => 'nav', 'href' => route('inventory.stock-items'),  'icon' => 'ti-package',      'keywords' => 'stock item inventory product goods master create'],
            ['label' => 'Stock Groups',        'sub' => 'Inventory', 'kind' => 'nav', 'href' => route('inventory.stock-groups'), 'icon' => 'ti-stack',        'keywords' => 'stock group inventory category master create'],
            ['label' => 'Units of Measure',    'sub' => 'Inventory', 'kind' => 'nav', 'href' => route('inventory.units'),        'icon' => 'ti-ruler',        'keywords' => 'unit measure inventory nos kg box master create'],
            ['label' => 'Godowns',             'sub' => 'Inventory', 'kind' => 'nav', 'href' => route('inventory.godowns'),      'icon' => 'ti-building-warehouse', 'keywords' => 'godown location warehouse inventory stock master create'],
            ['label' => 'Contra Voucher',      'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'contra']),  'icon' => 'ti-transfer',      'keywords' => 'contra f4 voucher transfer bank cash'],
            ['label' => 'Payment Voucher',     'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'payment']), 'icon' => 'ti-arrow-up-right','keywords' => 'payment f5 voucher money out pay'],
            ['label' => 'Receipt Voucher',     'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'receipt']), 'icon' => 'ti-arrow-down-left','keywords' => 'receipt f6 voucher money in receive'],
            ['label' => 'Journal Voucher',     'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'journal']), 'icon' => 'ti-adjustments',   'keywords' => 'journal f7 voucher adjustment'],
            ['label' => 'Sales Voucher',       'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'sales']),   'icon' => 'ti-receipt',       'keywords' => 'sales f8 voucher invoice bill customer debtor income'],
            ['label' => 'Purchase Voucher',    'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'purchase']),'icon' => 'ti-shopping-cart', 'keywords' => 'purchase f9 voucher invoice bill supplier creditor expense'],
            ['label' => 'Credit Note',         'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'credit_note']), 'icon' => 'ti-arrow-back-up',   'keywords' => 'credit note ctrl f8 sales return refund customer debtor adjustment discount reduce returns'],
            ['label' => 'Debit Note',          'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'debit_note']),  'icon' => 'ti-arrow-back-up',   'keywords' => 'debit note ctrl f9 purchase return supplier creditor adjustment reduce returns'],
            ['label' => 'Stock Journal',       'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'stock_journal']),  'icon' => 'ti-arrows-exchange',  'keywords' => 'stock journal transfer godown consumption issue wastage movement inventory voucher'],
            ['label' => 'Physical Stock',      'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'physical_stock']), 'icon' => 'ti-clipboard-check',  'keywords' => 'physical stock take count variance reconcile shortage excess inventory voucher'],
            // Phase 8B — inventory-workflow vouchers (zero accounting; commitment/stock only).
            ['label' => 'Sales Order',         'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'sales_order']),    'icon' => 'ti-clipboard-list',    'keywords' => 'sales order alt f6 so commitment customer pending outstanding no accounting'],
            ['label' => 'Purchase Order',      'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'purchase_order']), 'icon' => 'ti-clipboard-list',    'keywords' => 'purchase order alt f7 po commitment supplier pending outstanding no accounting'],
            ['label' => 'Delivery Note',       'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'delivery_note']),  'icon' => 'ti-truck-delivery',    'keywords' => 'delivery note alt f8 goods out challan despatch stock move customer no accounting'],
            ['label' => 'Receipt Note',        'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'receipt_note']),   'icon' => 'ti-package-import',    'keywords' => 'receipt note alt f5 goods in grn stock move supplier provisional no accounting'],
            ['label' => 'Rejections In',       'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'rejection_in']),    'icon' => 'ti-package-import',    'keywords' => 'rejection in ctrl f5 customer returned goods sales return stock in no accounting'],
            ['label' => 'Rejections Out',      'sub' => 'Inventory Vouchers', 'kind' => 'nav', 'href' => route('vouchers.create', ['type' => 'rejection_out']),   'icon' => 'ti-package-export',    'keywords' => 'rejection out ctrl f6 return to supplier purchase return stock out no accounting'],
            ['label' => 'Day Book',            'sub' => 'Vouchers',  'kind' => 'nav', 'href' => route('daybook'),        'icon' => 'ti-book-2',      'keywords' => 'day book vouchers list review register'],
            ['label' => 'Trial Balance',       'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.trial-balance'), 'icon' => 'ti-scale',       'keywords' => 'trial balance report tb closing'],
            ['label' => 'Balance Sheet',       'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.balance-sheet'), 'icon' => 'ti-report-money', 'keywords' => 'balance sheet report bs assets liabilities'],
            ['label' => 'Profit & Loss A/c',   'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.profit-loss'),   'icon' => 'ti-chart-bar',    'keywords' => 'profit loss report pl income expense'],
            ['label' => 'GST Summary',         'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.gst-summary'),   'icon' => 'ti-receipt-tax',  'keywords' => 'gst summary tax report output input itc cgst sgst igst liability payable'],
            ['label' => 'VAT Summary',         'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.vat-summary'),   'icon' => 'ti-receipt-tax',  'keywords' => 'vat summary tax nepal report output input net payable'],
            ['label' => 'Receivables',         'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.receivables'),   'icon' => 'ti-cash',         'keywords' => 'receivable outstanding bills due debtors owed to us aging overdue'],
            ['label' => 'Payables',            'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.payables'),      'icon' => 'ti-cash-off',     'keywords' => 'payable outstanding bills due creditors we owe suppliers aging overdue'],
            ['label' => 'Cost Centre Breakup', 'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.cost-breakup'),   'icon' => 'ti-chart-donut',  'keywords' => 'cost centre center breakup report allocation analytical'],
            // Phase 10A — TDS deducted per section and deductee; 26Q is the Phase 10B export.
            ['label' => 'TDS Deduction Summary', 'sub' => 'Reports', 'kind' => 'nav', 'href' => route('reports.tds-summary'),  'icon' => 'ti-percentage',   'keywords' => 'tds deduction summary report 26q deductee section payable outstanding withholding remittance challan pan 206aa'],
            // Phase 10B — the quarterly 26Q return-file exporter.
            ['label' => 'TDS Returns (26Q)',   'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.tds-returns'),  'icon' => 'ti-file-export',  'keywords' => 'tds return 26q quarterly form 140 fvu challan bsr token export file protean nsdl quarter q1 q2 q3 q4 deductor'],
            // Phase 11 — unrealised forex gain/loss on open foreign bills.
            ['label' => 'Forex Revaluation',    'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.forex-revaluation'), 'icon' => 'ti-refresh',  'keywords' => 'forex revaluation unrealised unrealized gain loss exchange currency foreign period end open bills usd'],
            // Phase 12C-1 — inter-company inventory provenance (the 12C-2 elimination trace).
            ['label' => 'Lot Provenance',       'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.lot-provenance'), 'icon' => 'ti-stack-2',  'keywords' => 'lot provenance inter-company intercompany inventory fifo unrealised elimination consolidation group stock trace'],
            // Phase 13 — the FIFO/LIFO lot ledger for any perpetual-costed stock item.
            ['label' => 'Lot Ledger',           'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.lot-ledger'), 'icon' => 'ti-stack-2',  'keywords' => 'lot ledger fifo lifo lots costing inventory tranche remaining rate'],
            ['label' => 'Stock Summary',       'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.stock-summary'), 'icon' => 'ti-packages',     'keywords' => 'stock summary inventory report quantity value closing stock in hand items groups godown'],
            ['label' => 'Notes Register',      'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.notes-register'), 'icon' => 'ti-notes',       'keywords' => 'notes register debit credit note returns list sales purchase return party adjustment'],
            // Phase 9A — GST return filing (GSTR-1 / GSTR-3B JSON export).
            ['label' => 'GST Returns',         'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.gst-returns'),   'icon' => 'ti-file-export',  'keywords' => 'gstr gstr1 gstr3b gst return filing json export outward supplies summary arn portal offline tool file returns'],
            // Phase 9B — Nepal VAT return (अनुसूची-१० / Schedule 10).
            ['label' => 'VAT Return (Nepal)',  'sub' => 'Reports',   'kind' => 'nav', 'href' => route('reports.vat-return'),    'icon' => 'ti-file-export',  'keywords' => 'vat return nepal ird anusuchi schedule 10 mulya abhibriddhi kar bikri kharid taxpayer portal submission reference filing'],
            ['label' => 'Sales Orders Outstanding',    'sub' => 'Reports', 'kind' => 'nav', 'href' => route('reports.orders-outstanding', ['scope' => 'sales']),    'icon' => 'ti-clipboard-list', 'keywords' => 'sales orders outstanding pending delivery reconciliation report so undelivered'],
            ['label' => 'Purchase Orders Outstanding', 'sub' => 'Reports', 'kind' => 'nav', 'href' => route('reports.orders-outstanding', ['scope' => 'purchase']), 'icon' => 'ti-clipboard-list', 'keywords' => 'purchase orders outstanding pending receipt reconciliation report po unreceived'],
            ['label' => 'Company Features',    'sub' => 'Config',    'kind' => 'nav', 'href' => route('features'),               'icon' => 'ti-adjustments-cog', 'keywords' => 'f11 features gst cost centre bill wise'],
            // Phase 12A — multi-company: manage companies + switch the active one (F3).
            ['label' => 'Companies',           'sub' => 'Config',    'kind' => 'nav', 'href' => route('companies'),              'icon' => 'ti-building',        'keywords' => 'company companies create rename deactivate multi manage books client'],
            // Phase 12B — groups + inter-company tagging (the 12C consolidation plumbing).
            ['label' => 'Company Groups',      'sub' => 'Config',    'kind' => 'nav', 'href' => route('companies.groups'),       'icon' => 'ti-topology-star',   'keywords' => 'group groups consolidation inter-company intercompany related subsidiaries holding tag'],
            ['label' => 'Select Company',      'sub' => 'Tool',      'act'  => 'company',                                        'icon' => 'ti-building-store',  'keywords' => 'select switch company change active f1 picker book client'],
            ['label' => 'Keyboard Harness',    'sub' => 'Developer', 'kind' => 'nav', 'href' => route('dev.harness'),    'icon' => 'ti-keyboard',    'keywords' => 'harness engine test verify dev'],
            ['label' => 'Calculator',          'sub' => 'Tool',      'act'  => 'calc',                                   'icon' => 'ti-calculator',  'keywords' => 'calc arithmetic ctrl n'],
            ['label' => 'Change Period',       'sub' => 'Tool',      'act'  => 'period',                                 'icon' => 'ti-calendar',    'keywords' => 'date period f2 fiscal year'],
        ], $grouped ? [
            // Phase 12C-2 — consolidation reports (visible only inside a group).
            ['label' => 'Group Trial Balance', 'sub' => 'Group Reports', 'kind' => 'nav', 'href' => route('reports.group-trial-balance'), 'icon' => 'ti-scale',        'keywords' => 'group trial balance consolidation consolidated tb elimination'],
            ['label' => 'Group Balance Sheet', 'sub' => 'Group Reports', 'kind' => 'nav', 'href' => route('reports.group-balance-sheet'), 'icon' => 'ti-report-money', 'keywords' => 'group balance sheet consolidation consolidated bs elimination unrealised'],
            ['label' => 'Group P&L',           'sub' => 'Group Reports', 'kind' => 'nav', 'href' => route('reports.group-profit-loss'),   'icon' => 'ti-chart-bar',    'keywords' => 'group profit loss consolidation consolidated p&l pl elimination'],
        ] : [], self::feature('budgets') ? self::budgetNav() : [], self::feature('ratio_analysis') ? self::ratioNav() : [], self::feature('scenarios') ? self::scenarioNav() : []);
    }

    /** Highlighted-letter menu shown on the Gateway hub. */
    /** One Gateway/menu row. */
    private static function item(string $letter, string $label, string $desc, string $href): array
    {
        return ['letter' => $letter, 'label' => $label, 'desc' => $desc, 'kind' => 'nav', 'href' => $href];
    }

    /** Drop the nulls left by feature-gating and reindex. */
    private static function only(array $items): array
    {
        return array_values(array_filter($items));
    }

    /**
     * The Gateway, as four sections — the arrangement of the approved build
     * (Masters / Transactions / Utilities / Reports), which is TallyPrime's own.
     *
     * WHY SECTIONS, NOT A FLAT LIST
     * The previous Gateway was a single flat list of 37 entries that had run out
     * of hot letters: the source comments recorded "every A–Z letter is already
     * claimed", 'C' and 'D' were each claimed twice and '0' three times. Because
     * the registry is last-wins, Subscription and Data & Privacy were silently
     * unreachable by their advertised letter, and which report '0' opened changed
     * with the F11 flags. Sections fix that at the root: the top level now holds
     * ~17 entries and the long tail lives under Display More Reports, where the
     * letters start again.
     *
     * Hot letters are unique across the WHOLE level, not per section, because
     * letter-jump scans every row — the same rule the approved build follows (its
     * Banking is 'K' precisely so 'B' can stay with Balance Sheet).
     *
     * Everything optional is gated on its F11 switch, so an accounts-only company
     * sees an accounts-only Gateway. The Go To / Calculator / Date entries the old
     * menu carried are gone: they are global shortcuts (Alt+G, Ctrl+N, F2), they
     * are not menu items in Tally, and they were the source of the 'C'/'D' clashes.
     */
    public static function gatewayMenu(): array
    {
        return self::only([
            [
                'key' => 'masters',
                'label' => 'Masters',
                'items' => self::only([
                    self::item('H', 'Chart of Accounts', 'Groups & Ledgers', route('masters.index')),
                    self::feature('inventory') ? self::item('I', 'Inventory Info', 'Stock items · groups · units · godowns', route('inventory.index')) : null,
                    self::feature('cost_centres') ? self::item('O', 'Cost Centres', 'Analytical allocation masters', route('masters.cost-centres')) : null,
                    self::feature('multi_currency') ? self::item('U', 'Currencies', 'Foreign currencies & exchange rates', route('masters.currencies')) : null,
                    self::feature('tds') ? self::item('E', 'TDS Sections', 'Rate table by section', route('masters.tds-sections')) : null,
                ]),
            ],
            [
                'key' => 'transactions',
                'label' => 'Transactions',
                'items' => self::only([
                    self::item('V', 'Vouchers', 'Contra · Payment · Receipt · Journal · F4–F9', route('vouchers.create', ['type' => 'payment'])),
                    self::item('D', 'Day Book', 'Review & alter vouchers', route('daybook')),
                ]),
            ],
            [
                'key' => 'utilities',
                'label' => 'Utilities',
                'items' => self::only([
                    self::item('C', 'Companies', 'Create · rename · deactivate · switch with F3', route('companies')),
                    self::item('F', 'Features', 'Company feature switches · F11', route('features')),
                    self::item('N', 'Subscription', 'Plan · record a payment · renew', route('subscription')),
                    self::item('T', 'Data & Privacy', 'Download all your data · close account', route('account.data')),
                ]),
            ],
            [
                'key' => 'reports',
                'label' => 'Reports',
                'items' => self::only([
                    self::item('B', 'Balance Sheet', 'Liabilities | Assets', route('reports.balance-sheet')),
                    self::item('P', 'Profit & Loss A/c', 'Expenses | Income', route('reports.profit-loss')),
                    // TallyPrime places Stock Summary between P&L and Ratio Analysis.
                    self::feature('inventory') ? self::item('S', 'Stock Summary', 'Inventory quantity & value', route('reports.stock-summary')) : null,
                    self::feature('ratio_analysis') ? self::item('R', 'Ratio Analysis', 'Financial health ratios & trends', route('reports.ratio-dashboard')) : null,
                    self::item('M', 'Display More Reports', 'Account books · statements · analytical', route('reports.more')),
                ]),
            ],
        ]);
    }

    /**
     * "Display More Reports" — the second level of the Reports tree, grouped the
     * way the approved build groups it (Account Books · Statements of Accounts ·
     * …), extended with the report families ZeroBook has and that build does not
     * (it excludes inventory and all statutory work by design).
     *
     * Hot letters are unique across the whole screen, as on the Gateway.
     */
    public static function reportsMenu(): array
    {
        $grouped = self::activeCompanyGrouped();

        $statutory = self::only([
            self::feature('gst') ? self::item('G', 'GST Summary', 'Output/input tax · net payable', route('reports.gst-summary')) : null,
            self::feature('gst') ? self::item('E', 'GST Returns', 'GSTR-1 / GSTR-3B · preview & JSON export', route('reports.gst-returns')) : null,
            self::feature('vat') ? self::item('V', 'VAT Summary', 'Nepal VAT · net payable', route('reports.vat-summary')) : null,
            self::feature('vat') ? self::item('A', 'VAT Return', 'Nepal · anusuchi-10 · preview & transcription', route('reports.vat-return')) : null,
            self::feature('tds') ? self::item('D', 'TDS Deductions', 'Per section & deductee · still payable', route('reports.tds-summary')) : null,
            self::feature('tds') ? self::item('Q', 'TDS Returns (26Q)', 'Quarterly · Form 140 · export .txt', route('reports.tds-returns')) : null,
        ]);

        $inventory = self::feature('inventory') ? self::only([
            self::item('S', 'Stock Summary', 'Inventory quantity & value', route('reports.stock-summary')),
            self::item('O', 'Orders Outstanding', 'Pending Sales / Purchase Orders', route('reports.orders-outstanding', ['scope' => 'sales'])),
            self::item('L', 'Lot Provenance', 'Inter-company inventory lots (FIFO trace)', route('reports.lot-provenance')),
        ]) : [];

        $analytical = self::only([
            self::feature('cost_centres') ? self::item('C', 'Cost Centre Breakup', 'Analytical cost allocation', route('reports.cost-breakup')) : null,
            self::feature('multi_currency') ? self::item('F', 'Forex Revaluation', 'Unrealised gain/loss on open foreign bills', route('reports.forex-revaluation')) : null,
            self::feature('budgets') ? self::item('B', 'Budgets', 'Targets & actual-vs-budget variance', route('reports.budget-list')) : null,
            self::feature('scenarios') ? self::item('I', 'Scenarios', 'What-if · provisional vouchers · impact', route('reports.scenario-master')) : null,
            $grouped ? self::item('U', 'Group Reports', 'Consolidated TB / BS / P&L with eliminations', route('reports.group-trial-balance')) : null,
        ]);

        return self::only([
            [
                'key' => 'account-books',
                'label' => 'Account Books',
                'items' => self::only([
                    self::item('T', 'Trial Balance', 'Closing balances, grouped', route('reports.trial-balance')),
                    self::item('N', 'Notes Register', 'Debit & credit notes', route('reports.notes-register')),
                ]),
            ],
            [
                'key' => 'statements',
                'label' => 'Statements of Accounts',
                'items' => self::only([
                    self::item('R', 'Receivables', 'Outstandings · bills owed to us', route('reports.receivables')),
                    self::item('P', 'Payables', 'Outstandings · bills we owe', route('reports.payables')),
                ]),
            ],
            $statutory ? ['key' => 'statutory', 'label' => 'Statutory', 'items' => $statutory] : null,
            $inventory ? ['key' => 'inventory', 'label' => 'Inventory', 'items' => $inventory] : null,
            $analytical ? ['key' => 'analytical', 'label' => 'Analytical', 'items' => $analytical] : null,
        ]);
    }
}
