<x-layouts.plain title="Data & Privacy — ZeroBook">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <a href="{{ route('gateway') }}" class="muted" style="font-size:.85rem">← Back to Gateway</a>
        <a href="{{ route('subscription') }}" class="muted" style="font-size:.85rem">Subscription</a>
    </div>

    @if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif
    @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif

    <div class="zb-card">
        <h1>Data &amp; Privacy</h1>
        <p class="sub">{{ $tenant->name }} · {{ $tenant->id }}. Your data is yours — download a complete copy any time, or close your account.</p>
    </div>

    {{-- Download my data --}}
    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.1rem">Download my data</h1>
        <p class="sub">A complete archive of your books — every ledger, voucher, stock movement, and statutory record as CSV, plus report snapshots and a full database dump. Generated in the background; we'll email you a secure download link.</p>
        <form method="POST" action="{{ route('account.data.export') }}">
            @csrf
            <button type="submit" class="btn" {{ $canExport ? '' : 'disabled style=opacity:.5' }}>Prepare my data export</button>
            @unless ($canExport)<span class="muted" style="font-size:.8rem;margin-left:.6rem">You can request one export per {{ (int) config('zerobook.export_min_hours', 24) }} hours.</span>@endunless
        </form>

        @if ($exports->isNotEmpty())
            <div style="overflow-x:auto;margin-top:1rem">
                <table>
                    <thead><tr><th>Requested</th><th>Status</th><th>Size</th><th>Link expires</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($exports as $exp)
                            @php $es = match($exp->status){'completed'=>['#e8f6ef','#084f39'],'failed'=>['#fdeceb','#b23b32'],default=>['#fff4e0','#97590a']}; @endphp
                            <tr>
                                <td class="muted">{{ $exp->created_at?->format('d-M-Y H:i') }}</td>
                                <td><span class="pill" style="background:{{ $es[0] }};color:{{ $es[1] }}">{{ $exp->status }}</span></td>
                                <td class="muted">{{ $exp->file_size_bytes ? $exp->humanSize() : '—' }}</td>
                                <td class="muted">{{ $exp->download_expires_at?->format('d-M-Y') ?? '—' }}</td>
                                <td>@if (isset($links[$exp->id]))<a href="{{ $links[$exp->id] }}">Download</a>@elseif($exp->isCompleted())<span class="muted">expired</span>@else<span class="muted">—</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Close my account --}}
    <div class="zb-card" style="margin-top:1.2rem;border-color:#f5c6c2">
        <h1 style="font-size:1.1rem;color:#b23b32">Close my account</h1>
        @if ($tenant->isOffboarding())
            <p class="sub">Account closure is already in progress. Your books are read-only. Contact support to cancel and reactivate.</p>
            <p class="muted">Scheduled to archive on <strong>{{ $tenant->archive_scheduled_for?->format('d-M-Y') }}</strong>.</p>
        @else
            <p class="sub">This starts a closure: your books become read-only immediately, we prepare a final backup and data export (emailed to you), and after {{ (int) config('zerobook.offboarding.archive_days', 30) }} days the account is archived. You can reactivate any time before then by contacting support.</p>
            <form method="POST" action="{{ route('account.close') }}" onsubmit="return confirm('Start closing this account? Your books will become read-only.')">
                @csrf
                <label for="reason">Reason (optional)</label>
                <input id="reason" type="text" name="reason" value="{{ old('reason') }}" placeholder="Why are you leaving? (helps us improve)">
                <label for="confirm">Type your subdomain “<strong>{{ $tenant->id }}</strong>” to confirm</label>
                <input id="confirm" type="text" name="confirm" value="{{ old('confirm') }}" autocomplete="off" placeholder="{{ $tenant->id }}" required>
                <button type="submit" class="btn btn-block" style="background:#b23b32;margin-top:.8rem">Close my account</button>
            </form>
        @endif
    </div>
</x-layouts.plain>
