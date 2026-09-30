@php
    /**
     * @var \GonbiDigital\Cooee\Health\HealthReport $report
     * @var \GonbiDigital\Cooee\Health\Status $status
     * @var array<string, mixed> $payload
     */
    $tone = ['ok' => 'ok', 'degraded' => 'warn', 'down' => 'bad'][$status->value];
@endphp
    <!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Health · {{ config('app.name') }}</title>
    {{-- Standalone: no layout, no Vite bundle, no webfont. This has to render on the deploy that
         broke the frontend build, which is the deploy people open it on. --}}
    <style>
        :root {
            --app: #0e111a; --panel: #161a26; --line: #242a3a; --raised: #1c2130;
            --ink: #e8ebf2; --dim: #98a0b3; --faint: #737b8e;
            --brand: #7aa3d1; --ok: #56b184; --bad: #e07a8d; --warn: #d8a75c;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Helvetica, Arial, sans-serif;
            font-size: 14px; line-height: 1.6; color: var(--ink); background: var(--app);
            padding: 32px 20px 48px; -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 980px; margin: 0 auto; }
        header { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; }
        h1 { font-size: 20px; font-weight: 600; margin: 0; letter-spacing: -0.01em; }
        .eyebrow { color: var(--faint); font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; margin: 0 0 2px; }
        .spacer { flex: 1; }
        .pill {
            display: inline-flex; align-items: center; gap: 7px; height: 26px; padding: 0 11px; border-radius: 999px;
            font-size: 12px; font-weight: 500; border: 1px solid var(--line); background: var(--raised); color: var(--dim);
        }
        .pill.version { font-variant-numeric: tabular-nums; color: var(--ink); }
        .pill.ok { color: var(--ok); border-color: color-mix(in srgb, var(--ok) 40%, var(--line)); }
        .pill.bad { color: var(--bad); border-color: color-mix(in srgb, var(--bad) 40%, var(--line)); }
        .pill.warn { color: var(--warn); border-color: color-mix(in srgb, var(--warn) 40%, var(--line)); }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex: none; }
        .lede { color: var(--dim); margin: 0 0 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 18px 20px; }
        .card h2 { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--faint); margin: 0 0 14px; }
        dl { margin: 0; display: grid; grid-template-columns: minmax(96px, auto) 1fr; gap: 8px 16px; }
        dt { color: var(--dim); }
        dd { margin: 0; text-align: right; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
        dd.none { color: var(--faint); }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        .checks { display: grid; gap: 10px; }
        .check { display: flex; align-items: flex-start; gap: 10px; padding: 11px 12px; border-radius: 8px; background: var(--raised); border: 1px solid var(--line); }
        .check .dot { margin-top: 8px; }
        .check.ok .dot { color: var(--ok); }
        .check.warn { border-color: color-mix(in srgb, var(--warn) 40%, var(--line)); }
        .check.warn .dot { color: var(--warn); }
        .check.warn .detail { color: var(--warn); }
        .check.bad { border-color: color-mix(in srgb, var(--bad) 40%, var(--line)); }
        .check.bad .dot { color: var(--bad); }
        .check.bad .detail { color: var(--bad); }
        .check .body { flex: 1; min-width: 0; }
        .check .name { font-weight: 500; text-transform: capitalize; }
        .check .detail { color: var(--dim); font-size: 13px; overflow-wrap: anywhere; }
        .check .ms { color: var(--faint); font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .wide { grid-column: 1 / -1; }
        footer { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-top: 24px; color: var(--faint); font-size: 12px; }
        a { color: var(--brand); }
    </style>
</head>
<body>
<main class="wrap">
    <header>
        <div>
            <p class="eyebrow">{{ config('app.name') }}</p>
            <h1>Health</h1>
        </div>
        <div class="spacer"></div>
        @if (! empty($payload['deploy']['version']))
            <span class="pill version">v{{ $payload['deploy']['version'] }}</span>
        @endif
        <span @class(['pill', 'warn' => $payload['app']['environment'] !== 'production'])>{{ $payload['app']['environment'] }}</span>
        @if ($payload['app']['debug'])
            <span class="pill warn"><span class="dot"></span> debug on</span>
        @endif
        @if ($payload['app']['maintenance'])
            <span class="pill warn"><span class="dot"></span> maintenance</span>
        @endif
        <span @class(['pill', $tone])><span class="dot"></span> {{ $status->value }}</span>
    </header>

    <p class="lede">What this server is running, and whether the things it depends on answer.</p>

    <div class="grid">
        <section class="card wide">
            <h2>Health checks</h2>
            <div class="checks">
                @foreach ($report->results() as $check)
                    <div @class(['check', ['ok' => 'ok', 'degraded' => 'warn', 'down' => 'bad'][$check->status->value]])>
                        <span class="dot"></span>
                        <div class="body">
                            <div class="name">{{ $check->name }}</div>
                            <div class="detail">{{ $check->message ?? $check->status->label() }}</div>
                        </div>
                        <span class="ms">{{ $check->ms }} ms</span>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Only when this deploy left something behind. Four "not recorded" rows read as a fault
             in the page rather than an absence in the pipeline. --}}
        @if ($payload['deploy'])
            <section class="card">
                <h2>Deploy</h2>
                <dl>
                    @foreach ($payload['deploy'] as $key => $value)
                        <dt>{{ ucfirst($key) }}</dt>
                        <dd>
                            @if ($key === 'sha')
                                <code>{{ substr($value, 0, 12) }}</code>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    @endforeach
                </dl>
            </section>
        @endif

        <section class="card">
            <h2>Runtime</h2>
            <dl>
                @foreach ($payload['runtime'] as $name => $value)
                    <dt>{{ ucfirst($name) }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
        </section>

        <section class="card">
            <h2>Services</h2>
            <dl>
                @foreach ($payload['services'] as $name => $driver)
                    <dt>{{ ucfirst($name) }}</dt>
                    <dd>{{ $driver }}</dd>
                @endforeach
            </dl>
        </section>

        <section class="card">
            <h2>Caches</h2>
            <dl>
                @foreach ($payload['caches'] as $name => $cached)
                    <dt>{{ ucfirst($name) }}</dt>
                    <dd @class(['none' => ! $cached])>{{ $cached ? 'cached' : 'not cached' }}</dd>
                @endforeach
            </dl>
        </section>
    </div>

    <footer>
        <span>Server time {{ $payload['checked_at'] }}</span>
        @if ($payload['render_ms'] !== null)
            <span>Rendered in {{ $payload['render_ms'] }} ms</span>
        @endif
        <span><code>{{ '/'.ltrim((string) config('cooee.health.path', 'health'), '/') }}</code> is the machine endpoint</span>
    </footer>
</main>
</body>
</html>
