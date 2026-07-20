{{--
    Phase 16A — the request log for one API key.
    Plain layout: only .zb-card, .btn, .pill, .muted, .grid, .zb-hint and bare table/th/td exist.
--}}
<div>
    <style>
        .zb-stat { border: 1px solid var(--line); border-radius: 10px; padding: .9rem 1rem; background: #fff; }
        .zb-stat .n { font-size: 1.5rem; font-weight: 700; color: var(--zb); line-height: 1.2; }
        .zb-stat .l { font-size: .74rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .st-ok { background: #e8f6ef; color: var(--zb-dark); }
        .st-err { background: #fdeceb; color: #b23b32; }
        .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .78rem; }
        .zb-empty { text-align: center; padding: 2rem 1rem; color: var(--muted); }
    </style>

    <div class="zb-card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem">
            <div>
                <h1 style="font-size:1.2rem">{{ $apiKey->name }}</h1>
                <p class="sub" style="margin-bottom:0">
                    <code class="muted mono">{{ $apiKey->maskedKey() }}</code>
                    @if ($apiKey->isRevoked())
                        <span class="pill st-err" style="margin-left:.4rem">revoked</span>
                    @endif
                </p>
            </div>
            <a href="{{ route('account.api-keys') }}" wire:navigate class="btn" style="background:#fff; color:var(--ink); border:1px solid var(--line)">
                Back to keys
            </a>
        </div>

        <div class="grid" style="margin-top:1.3rem">
            <div class="zb-stat"><div class="n">{{ number_format($total) }}</div><div class="l">Total requests</div></div>
            <div class="zb-stat"><div class="n">{{ number_format($lastDay) }}</div><div class="l">Last 24 hours</div></div>
            <div class="zb-stat"><div class="n">{{ number_format($errors) }}</div><div class="l">Errors (4xx / 5xx)</div></div>
            <div class="zb-stat"><div class="n">{{ $errorRate }}%</div><div class="l">Error rate</div></div>
        </div>
    </div>

    <div class="zb-card" style="margin-top:1.2rem">
        <h1 style="font-size:1.05rem">Recent requests</h1>
        <p class="sub">
            Quote the request id when contacting support. Request and response bodies are never
            stored — only a fingerprint of the request body.
        </p>

        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Request id</th>
                        <th>Method</th>
                        <th>Path</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th>IP</th>
                        <th>Body</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td class="muted" style="font-size:.8rem; white-space:nowrap">
                                {{ $row->created_at?->format('d-M H:i:s') }}
                            </td>
                            <td><code class="mono">{{ $row->request_id }}</code></td>
                            <td style="font-size:.8rem">{{ $row->method }}</td>
                            <td><code class="mono">{{ $row->path }}{{ $row->query_string ? '?'.$row->query_string : '' }}</code></td>
                            <td>
                                <span class="pill {{ $row->isError() ? 'st-err' : 'st-ok' }}">{{ $row->response_status }}</span>
                            </td>
                            <td class="muted" style="font-size:.8rem">{{ $row->duration_ms }} ms</td>
                            <td class="muted mono">{{ $row->ip }}</td>
                            <td class="muted mono" title="SHA-256 of the request body">
                                {{ $row->request_body_hash ? substr($row->request_body_hash, 0, 8) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="zb-empty">
                                This key has not been used yet.<br>
                                <span style="font-size:.85rem">Try <code class="mono">GET /api/v1/ping</code> with it to check your setup.</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div style="margin-top:1rem">{{ $rows->links() }}</div>
        @endif
    </div>
</div>
