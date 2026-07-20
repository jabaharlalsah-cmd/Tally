<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'ZeroBook' }}</title>
    <style>
        :root { --zb: #0B6E4F; --zb-dark: #084f39; --ink: #16211d; --muted: #5c6b63; --line: #dde5e0; --bg: #f6f8f7; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
        a { color: var(--zb); text-decoration: none; }
        .zb-top { background: var(--zb); color: #fff; padding: .8rem 1.4rem; display: flex; align-items: center; justify-content: space-between; }
        .zb-brand { font-family: Spectral, Georgia, serif; font-weight: 700; font-size: 1.25rem; letter-spacing: .01em; color: #fff; display: flex; align-items: center; gap: .5rem; }
        .zb-brand .dot { width: 11px; height: 11px; border-radius: 50%; background: #7fd8b4; display: inline-block; }
        .zb-top a { color: #d7f0e6; font-size: .86rem; }
        .zb-wrap { max-width: 960px; margin: 0 auto; padding: 2rem 1.4rem 3rem; }
        .zb-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 1.6rem; box-shadow: 0 1px 3px rgba(16,33,29,.05); }
        .zb-auth { max-width: 400px; margin: 3rem auto; }
        h1 { font-family: Spectral, Georgia, serif; font-weight: 700; margin: 0 0 .3rem; }
        .sub { color: var(--muted); margin: 0 0 1.3rem; font-size: .92rem; }
        label { display: block; font-size: .8rem; font-weight: 600; color: var(--muted); margin: .8rem 0 .25rem; }
        input[type=text], input[type=email], input[type=password], select {
            width: 100%; padding: .6rem .7rem; border: 1px solid var(--line); border-radius: 8px; font-size: .95rem; font-family: inherit; background: #fff; }
        input:focus, select:focus { outline: none; border-color: var(--zb); box-shadow: 0 0 0 3px rgba(11,110,79,.12); }
        .btn { display: inline-block; background: var(--zb); color: #fff; border: 0; border-radius: 8px; padding: .62rem 1.1rem; font-size: .92rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .btn:hover { background: var(--zb-dark); }
        .btn-block { width: 100%; margin-top: 1.1rem; }
        .err { background: #fdeceb; color: #b23b32; border: 1px solid #f5c6c2; border-radius: 8px; padding: .55rem .7rem; font-size: .85rem; margin-bottom: 1rem; }
        .flash { background: #e8f6ef; color: var(--zb-dark); border: 1px solid #b8e2cf; border-radius: 8px; padding: .55rem .7rem; font-size: .88rem; margin-bottom: 1.1rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { text-align: left; padding: .6rem .7rem; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-size: .74rem; text-transform: uppercase; letter-spacing: .04em; }
        .pill { display: inline-block; padding: .12rem .5rem; border-radius: 20px; font-size: .74rem; font-weight: 600; }
        .pill-active { background: #e8f6ef; color: var(--zb-dark); }
        .pill-prov { background: #fff4e0; color: #97590a; }
        .muted { color: var(--muted); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .plan { border: 1px solid var(--line); border-radius: 10px; padding: 1.1rem; background: #fff; }
        .plan h3 { margin: 0 0 .2rem; font-family: Spectral, serif; }
        .plan .price { font-size: 1.4rem; font-weight: 700; color: var(--zb); }
        .plan ul { margin: .6rem 0 0; padding-left: 1.1rem; font-size: .84rem; color: var(--muted); }
        .foot { text-align: center; color: var(--muted); font-size: .78rem; margin-top: 2rem; }
        /* Remove number-input spinner arrows — select dropdown arrows are untouched. */
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
        /* Action-card section headings. */
        .zb-agroup { margin: 1.3rem 0 .6rem; font-size: .72rem; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: var(--muted); border-bottom: 1px solid var(--line); padding-bottom: .35rem; }
        .zb-agroup:first-of-type { margin-top: .8rem; }
        /* Each action = a column whose button sits flush at the bottom, so buttons align across a row. */
        .zb-action { display: flex; flex-direction: column; height: 100%; }
        .zb-action .btn { margin-top: auto; }
        .zb-hint { font-size: .72rem; color: var(--muted); margin: .35rem 0 .6rem; }
        /* Show/hide password eye toggle (auto-attached to every password field below). */
        .zb-pw-wrap { position: relative; display: block; width: 100%; }
        .zb-pw-wrap input { padding-right: 2.6rem; }
        .zb-pw-toggle { position: absolute; top: 50%; right: .5rem; transform: translateY(-50%);
            display: inline-flex; align-items: center; justify-content: center;
            background: none; border: 0; padding: 4px; margin: 0; cursor: pointer; color: var(--muted); line-height: 0; }
        .zb-pw-toggle:hover, .zb-pw-toggle:focus { color: var(--zb); outline: none; }
    </style>
</head>
<body>
    <div class="zb-top">
        <span class="zb-brand"><span class="dot"></span> ZeroBook</span>
        <span>{!! $topRight ?? '' !!}</span>
    </div>
    <div class="zb-wrap">
        {{ $slot }}
        <div class="foot">ZeroBook · keyboard-first accounting · Phase 7B multi-tenant</div>
    </div>

    <script>
        // Progressive enhancement: attach a show/hide eye toggle to every password input.
        (function () {
            var EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
            var EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
            function attach(input) {
                if (input.dataset.zbPw) return;
                input.dataset.zbPw = '1';
                var wrap = document.createElement('div');
                wrap.className = 'zb-pw-wrap';
                input.parentNode.insertBefore(wrap, input);
                wrap.appendChild(input);
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'zb-pw-toggle';
                btn.tabIndex = -1;
                btn.setAttribute('aria-label', 'Show password');
                btn.innerHTML = EYE;
                wrap.appendChild(btn);
                btn.addEventListener('click', function () {
                    var reveal = input.type === 'password';
                    input.type = reveal ? 'text' : 'password';
                    btn.innerHTML = reveal ? EYE_OFF : EYE;
                    btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
                });
            }
            document.querySelectorAll('input[type=password]').forEach(attach);
        })();
    </script>
</body>
</html>
