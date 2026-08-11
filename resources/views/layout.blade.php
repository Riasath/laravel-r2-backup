{{--
    Self-contained fallback layout, used when config('r2-backup.layout') is
    null. Point that config at your own admin layout and this file is bypassed
    entirely — the page then renders inside your app's chrome.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Database Backup · {{ config('app.name') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 2.5rem 1.25rem;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--r2b-page, #f6f7f9);
            color: var(--r2b-text, #18181b);
        }
        @media (prefers-color-scheme: dark) {
            body { --r2b-page: #0d0d10; --r2b-text: #ededf0; }
        }
        .r2b-shell { max-width: 62rem; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="r2b-shell">
        @yield(config('r2-backup.section', 'content'))
    </div>
</body>
</html>
