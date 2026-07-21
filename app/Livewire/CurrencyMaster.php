<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsActiveCompany;
use App\Livewire\Concerns\TogglesMasterActive;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Phase 11 — the Currency master. Add currencies, mark exactly one as the base (reporting)
 * currency, and maintain each currency's exchange-rate history. Rates are entered manually;
 * the voucher screen reads the latest ones for its 0-network live conversion.
 */
class CurrencyMaster extends Component
{
    use GuardsActiveCompany;
    use TogglesMasterActive;

    // New currency form.
    public string $code = '';
    public string $symbol = '';
    public string $name = '';
    public int $decimal_places = 2;

    // New rate form.
    public ?int $rateCurrencyId = null;
    public string $rateDate = '';
    public string $rateValue = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->rateDate = Carbon::today()->toDateString();
        $this->rateCurrencyId = Currency::where('is_base', false)->orderBy('code')->value('id');
    }

    public function addCurrency(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'size:3', Rule::unique('currencies', 'code')->where('company_id', \App\Support\ActiveCompany::check())],
            'symbol' => ['nullable', 'string', 'max:8'],
            'name' => ['required', 'string', 'max:60'],
            'decimal_places' => ['required', 'integer', 'between:0,4'],
        ]);

        Currency::create([
            'code' => strtoupper(trim($this->code)),
            'symbol' => $this->symbol ? trim($this->symbol) : null,
            'name' => trim($this->name),
            'decimal_places' => $this->decimal_places,
            'is_base' => false,
        ]);
        $this->reset('code', 'symbol', 'name');
        $this->decimal_places = 2;
        $this->flash = 'Currency added.';
    }

    /** Mark a currency as the base (reporting) currency — exactly one may be base. */
    public function makeBase(int $currencyId): void
    {
        DB::transaction(function () use ($currencyId) {
            // Both updates run through the scoped Currency model, so only THIS
            // company's rows are touched — another company's base flag is untouchable.
            Currency::query()->update(['is_base' => false]);
            Currency::whereKey($currencyId)->update(['is_base' => true]);

            // Phase 12A — companies.base_currency_id mirrors the is_base flag.
            activeCompany()?->update(['base_currency_id' => $currencyId]);
            \App\Support\ActiveCompany::refresh();
        });
        $this->flash = 'Base currency changed. Ledgers in the old base are now foreign-currency ledgers.';
    }

    public function addRate(): void
    {
        $this->validate([
            'rateCurrencyId' => ['required', 'integer', Rule::exists('currencies', 'id')->where('company_id', \App\Support\ActiveCompany::check())],
            'rateDate' => ['required', 'date'],
            'rateValue' => ['required', 'numeric', 'gt:0'],
        ], [], ['rateCurrencyId' => 'currency', 'rateValue' => 'rate']);

        if (Currency::whereKey($this->rateCurrencyId)->value('is_base')) {
            $this->addError('rateValue', 'The base currency does not need a rate (it is always 1).');

            return;
        }

        ExchangeRate::updateOrCreate(
            ['currency_id' => $this->rateCurrencyId, 'date' => $this->rateDate],
            ['rate' => round((float) $this->rateValue, 6)],
        );
        $this->rateValue = '';
        $this->flash = 'Exchange rate recorded.';
    }

    public function render()
    {
        $rates = app(ExchangeRateService::class);
        $currencies = Currency::orderByDesc('is_base')->orderBy('code')->get()->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'symbol' => $c->symbol,
            'name' => $c->name,
            'is_base' => (bool) $c->is_base,
            'latest' => $c->is_base ? null : ($rates->latestRates()[$c->id]['rate'] ?? null),
        ])->all();

        $history = $this->rateCurrencyId ? $rates->history($this->rateCurrencyId) : [];

        return view('livewire.currency-master', [
            'currencies' => $currencies,
            'nonBase' => Currency::where('is_base', false)->orderBy('code')->get(),
            'history' => $history,
        ]);
    }

    /** The model TogglesMasterActive retires and restores. */
    protected function masterModelClass(): string
    {
        return \App\Models\Currency::class;
    }
}
