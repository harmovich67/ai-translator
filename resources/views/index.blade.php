<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Translator</title>
    <style>
        :root{color-scheme:light;--ink:#172033;--muted:#687386;--line:#dde3ec;--soft:#f5f7fa;--brand:#4158d0;--ok:#117a52;--bad:#b42318}*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:var(--ink);font:14px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}.shell{width:min(1180px,calc(100% - 32px));margin:32px auto 72px}.top{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:22px}.top h1{margin:0;font-size:28px}.top p{margin:3px 0 0;color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;box-shadow:0 8px 25px rgba(24,35,56,.05)}.wide{grid-column:1/-1}.card h2{font-size:18px;margin:0 0 16px}.row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}.field label{font-weight:700}.field small,.help{color:var(--muted)}input[type=text],input[type=password],textarea{width:100%;border:1px solid var(--line);border-radius:9px;padding:10px 12px;font:inherit;background:#fff}textarea{min-height:78px;resize:vertical}.check{display:flex;align-items:center;gap:9px;margin:10px 0}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:9px;background:var(--brand);color:#fff;padding:10px 15px;font-weight:700;text-decoration:none;cursor:pointer}.btn.secondary{background:#fff;color:var(--ink);border:1px solid var(--line)}.btn.danger{background:#fff1f0;color:var(--bad);border:1px solid #ffd5d2;padding:7px 10px}.actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.notice{padding:12px 15px;border-radius:10px;margin-bottom:16px;background:#e9f8f1;color:var(--ok);border:1px solid #b9ead5}.error{background:#fff1f0;color:var(--bad);border-color:#ffd5d2}table{width:100%;border-collapse:collapse}th,td{text-align:start;padding:11px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}.scroll{overflow:auto}.badge{display:inline-block;background:var(--soft);border:1px solid var(--line);padding:2px 7px;border-radius:999px;font-size:12px}.tabs details{border:1px solid var(--line);border-radius:10px;margin:8px 0;background:#fff}.tabs summary{cursor:pointer;font-weight:750;padding:12px}.tabs details>div{padding:0 12px 12px}.translation-grid{display:grid;grid-template-columns:180px repeat(var(--langs),minmax(220px,1fr));gap:8px;align-items:start;min-width:700px}.translation-grid>div{padding:5px}.key{font:12px/1.4 ui-monospace,SFMono-Regular,monospace;overflow-wrap:anywhere}.footer-note{margin-top:18px;color:var(--muted)}@media(max-width:760px){.grid,.row{grid-template-columns:1fr}.wide{grid-column:auto}.top{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
@php($routeName = (string) config('ai-translator.route_name', 'ai-translator.'))
<main class="shell" data-lt-skip>
    <div class="top">
        <div>
            <h1>AI Translator</h1>
            <p>File translations, live inline editing, dictionary replacements, and full-page Gemini translation.</p>
        </div>
        @aiTranslatorButton
    </div>

    @if(session('status'))
        <div class="notice">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="notice error">{{ $errors->first() }}</div>
    @endif

    <div class="grid">
        <section class="card">
            <h2>Package settings</h2>
            <form method="post" action="{{ route($routeName . 'settings') }}">
                @csrf
                <label class="check"><input type="checkbox" name="enabled" value="1" @checked($enabled)> Enable live translation</label>
                <label class="check"><input type="checkbox" name="ai_enabled" value="1" @checked($aiEnabled)> Enable Gemini translation</label>

                <div class="field">
                    <label for="ai_model">Gemini model</label>
                    <input id="ai_model" type="text" name="ai_model" value="{{ $aiModel }}" autocomplete="off">
                </div>
                <div class="field">
                    <label for="ai_key">Gemini API key</label>
                    <input id="ai_key" type="password" name="ai_key" value="" autocomplete="new-password" placeholder="{{ $maskedKey ?: 'Paste a key to save it' }}">
                    <small>A blank value keeps the current key. Prefer AI_TRANSLATOR_GEMINI_KEY in production.</small>
                </div>
                @if($maskedKey)
                    <label class="check"><input type="checkbox" name="remove_ai_key" value="1"> Remove saved database key</label>
                @endif
                <button class="btn" type="submit">Save settings</button>
            </form>
        </section>

        <section class="card">
            <h2>Dictionary import/export</h2>
            <p class="help">The dictionary translates text written directly in templates. Import merges valid entries; export creates a portable JSON backup.</p>
            <div class="actions" style="margin-bottom:14px">
                <a class="btn secondary" href="{{ route($routeName . 'export') }}">Export JSON</a>
            </div>
            <form method="post" action="{{ route($routeName . 'import') }}" enctype="multipart/form-data">
                @csrf
                <div class="field">
                    <label for="file">Import JSON file</label>
                    <input id="file" type="file" name="file" accept="application/json,.json">
                </div>
                <div class="field">
                    <label for="json">or paste JSON</label>
                    <textarea id="json" name="json"></textarea>
                </div>
                <button class="btn" type="submit">Import</button>
            </form>
        </section>

        <section class="card wide">
            <h2>Site-wide dictionary <span class="badge">{{ count($entries) }}</span></h2>
            <div class="scroll">
                <table>
                    <thead><tr><th>Source</th>@foreach($languages as $language)<th>{{ $language['name'] }}</th>@endforeach<th></th></tr></thead>
                    <tbody>
                    @forelse($entries as $id => $entry)
                        <tr>
                            <td>{{ $entry['source'] ?? collect($entry['aliases'] ?? [])->first() }}</td>
                            @foreach($languages as $language)
                                <td>{{ $entry['values'][$language['code']] ?? '—' }}</td>
                            @endforeach
                            <td>
                                <form method="post" action="{{ route($routeName . 'dictionary.delete', ['id' => $id]) }}" onsubmit="return confirm('Delete this dictionary entry?')">
                                    @csrf
                                    <button class="btn danger" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($languages) + 2 }}">No dictionary entries yet. Turn on Translate mode and click text on a page.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card wide" id="file-translations">
            <h2>File translations <span class="badge">{{ count($translationLines) }}</span></h2>
            <p class="help">These values override files under <code>lang/&lt;locale&gt;/*.php</code> without modifying the files. Laravel's native <code>__()</code> receives the overrides automatically.</p>
            <form method="post" action="{{ route($routeName . 'translations') }}">
                @csrf
                <div class="scroll">
                    <div class="translation-grid" style="--langs:{{ count($languages) }}">
                        <div><strong>Key</strong></div>
                        @foreach($languages as $language)<div><strong>{{ $language['name'] }}</strong></div>@endforeach
                        @foreach($translationLines as $key => $values)
                            @php($encodedKey = rtrim(strtr(base64_encode($key), '+/', '-_'), '='))
                            <div class="key">{{ $key }}</div>
                            @foreach($languages as $language)
                                <div><textarea name="translations[{{ $encodedKey }}][{{ $language['code'] }}]" dir="{{ $language['rtl'] ? 'rtl' : 'ltr' }}">{{ $values[$language['code']] ?? '' }}</textarea></div>
                            @endforeach
                        @endforeach
                    </div>
                </div>
                @if($translationLines)
                    <button class="btn" type="submit" style="margin-top:14px">Save file translations</button>
                @endif
            </form>
        </section>
    </div>

    <p class="footer-note">Add <code>@aiTranslatorScripts</code> before <code>&lt;/body&gt;</code> on every layout where runtime translations or live editing should work.</p>
</main>
@aiTranslatorScripts
</body>
</html>
