@extends(config('r2-backup.layout') ?: 'r2-backup::layout')

@section(config('r2-backup.section', 'content'))

@php
    use Riasath\R2Backup\BackupService;
@endphp

{{--
    All styles are scoped under .r2b- class names and a single wrapper, so this
    page can drop into any host layout without colliding with its CSS.
--}}
<style>
    .r2b { --r2b-card: #fff; --r2b-border: #e4e4e7; --r2b-muted: #6b7280; --r2b-fg: #18181b;
           --r2b-accent: #f6821f; --r2b-accent-fg: #fff; --r2b-soft: #fafafa;
           --r2b-ok-bg: #ecfdf5; --r2b-ok-fg: #065f46; --r2b-ok-br: #a7f3d0;
           --r2b-bad-bg: #fef2f2; --r2b-bad-fg: #991b1b; --r2b-bad-br: #fecaca;
           --r2b-warn-bg: #fffbeb; --r2b-warn-fg: #92400e; --r2b-warn-br: #fde68a;
           color: var(--r2b-fg); }
    @media (prefers-color-scheme: dark) {
        .r2b { --r2b-card: #17171b; --r2b-border: #2c2c33; --r2b-muted: #9ca3af; --r2b-fg: #ededf0;
               --r2b-soft: #1e1e23;
               --r2b-ok-bg: #052e22; --r2b-ok-fg: #6ee7b7; --r2b-ok-br: #065f46;
               --r2b-bad-bg: #300f0f; --r2b-bad-fg: #fca5a5; --r2b-bad-br: #7f1d1d;
               --r2b-warn-bg: #2b1d05; --r2b-warn-fg: #fcd34d; --r2b-warn-br: #78350f; }
    }

    .r2b__head { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start;
                 justify-content: space-between; margin-bottom: 1.5rem; }
    .r2b__title { margin: 0; font-size: 1.375rem; font-weight: 700; letter-spacing: -0.01em; }
    .r2b__sub { margin: 0.35rem 0 0; color: var(--r2b-muted); font-size: 0.875rem; }
    .r2b__sub code { background: var(--r2b-soft); border: 1px solid var(--r2b-border);
                     border-radius: 4px; padding: 0.05rem 0.35rem; font-size: 0.8125rem; }

    .r2b__card { background: var(--r2b-card); border: 1px solid var(--r2b-border);
                 border-radius: 12px; overflow: hidden; }

    .r2b__btn { display: inline-flex; align-items: center; gap: 0.5rem; border: 1px solid transparent;
                border-radius: 8px; padding: 0.6rem 1.1rem; font-size: 0.875rem; font-weight: 600;
                font-family: inherit; cursor: pointer; text-decoration: none; line-height: 1.2;
                transition: opacity .15s ease, background .15s ease; }
    .r2b__btn--primary { background: var(--r2b-accent); color: var(--r2b-accent-fg); }
    .r2b__btn--primary:hover { opacity: .9; }
    .r2b__btn--primary[disabled] { opacity: .6; cursor: progress; }
    .r2b__btn--quiet { background: transparent; color: var(--r2b-fg); border-color: var(--r2b-border);
                       padding: 0.35rem 0.7rem; font-size: 0.8125rem; }
    .r2b__btn--quiet:hover { background: var(--r2b-soft); }
    .r2b__btn--danger { color: var(--r2b-bad-fg); }
    .r2b__btn:focus-visible { outline: 2px solid var(--r2b-accent); outline-offset: 2px; }

    .r2b__flash { display: flex; gap: 0.6rem; align-items: flex-start; border-radius: 10px;
                  padding: 0.8rem 1rem; margin-bottom: 1.25rem; font-size: 0.875rem; border: 1px solid; }
    .r2b__flash--ok { background: var(--r2b-ok-bg); color: var(--r2b-ok-fg); border-color: var(--r2b-ok-br); }
    .r2b__flash--bad { background: var(--r2b-bad-bg); color: var(--r2b-bad-fg); border-color: var(--r2b-bad-br); }
    .r2b__flash--warn { background: var(--r2b-warn-bg); color: var(--r2b-warn-fg); border-color: var(--r2b-warn-br); }
    .r2b__flash code { font-size: 0.8125rem; }

    .r2b__scroll { overflow-x: auto; }
    .r2b__table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
    .r2b__table th { text-align: left; font-size: 0.75rem; text-transform: uppercase;
                     letter-spacing: 0.04em; color: var(--r2b-muted); font-weight: 600;
                     padding: 0.75rem 1rem; border-bottom: 1px solid var(--r2b-border);
                     background: var(--r2b-soft); white-space: nowrap; }
    .r2b__table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--r2b-border);
                     vertical-align: middle; }
    .r2b__table tr:last-child td { border-bottom: 0; }
    .r2b__name { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem; }
    .r2b__num { text-align: right; white-space: nowrap; color: var(--r2b-muted); }
    .r2b__when { white-space: nowrap; color: var(--r2b-muted); }
    .r2b__actions { text-align: right; white-space: nowrap; }
    .r2b__actions form { display: inline; }

    .r2b__empty { padding: 3rem 1.5rem; text-align: center; color: var(--r2b-muted); }
    .r2b__empty p { margin: 0.4rem 0 0; font-size: 0.875rem; }
    .r2b__empty strong { display: block; color: var(--r2b-fg); font-size: 0.9375rem; }

    .r2b__list { margin: 0.5rem 0 0; padding-left: 1.1rem; }
    .r2b__list li { margin-top: 0.2rem; }

    /* Visible to screen readers only — the actions column needs a name. */
    .r2b__sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
               overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
</style>

<div class="r2b">

    <div class="r2b__head">
        <div>
            <h1 class="r2b__title">Database backup</h1>
            <p class="r2b__sub">
                Dumps the database, compresses it, and uploads it to Cloudflare R2
                @if ($bucket) — bucket <code>{{ $bucket }}</code> @endif.
            </p>
        </div>

        @if ($configured)
            <form method="POST" action="{{ route('r2-backup.store') }}" id="r2bRunForm">
                @csrf
                <button type="submit" class="r2b__btn r2b__btn--primary" id="r2bRunBtn">
                    Back up now
                </button>
            </form>
        @endif
    </div>

    @if (session('r2-backup.status'))
        <div class="r2b__flash r2b__flash--ok" role="status">
            <span aria-hidden="true">✓</span>
            <span>{{ session('r2-backup.status') }}</span>
        </div>
    @endif

    @if (session('r2-backup.error'))
        <div class="r2b__flash r2b__flash--bad" role="alert">
            <span aria-hidden="true">!</span>
            <span>{{ session('r2-backup.error') }}</span>
        </div>
    @endif

    @unless ($configured)
        {{-- Say exactly which variables are blank rather than failing on click. --}}
        <div class="r2b__flash r2b__flash--warn" role="alert">
            <span aria-hidden="true">!</span>
            <span>
                <strong>Cloudflare R2 is not configured yet.</strong>
                Add these to your <code>.env</code> file, then reload this page:
                <ul class="r2b__list">
                    @foreach ($missing as $variable)
                        <li><code>{{ $variable }}</code></li>
                    @endforeach
                </ul>
            </span>
        </div>
    @endunless

    @if ($listError)
        <div class="r2b__flash r2b__flash--bad" role="alert">
            <span aria-hidden="true">!</span>
            <span>Could not list existing backups: {{ $listError }}</span>
        </div>
    @endif

    @if ($configured)
        <div class="r2b__card">
            @if ($backups->isEmpty())
                <div class="r2b__empty">
                    <strong>No backups yet</strong>
                    <p>Press “Back up now” to send your first one to R2.</p>
                </div>
            @else
                <div class="r2b__scroll">
                    <table class="r2b__table">
                        <thead>
                            <tr>
                                <th scope="col">Backup</th>
                                <th scope="col" class="r2b__num">Size</th>
                                <th scope="col">Taken</th>
                                <th scope="col"><span class="r2b__sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($backups as $backup)
                                <tr>
                                    <td class="r2b__name">{{ $backup['name'] }}</td>
                                    <td class="r2b__num">{{ BackupService::humanBytes($backup['bytes']) }}</td>
                                    <td class="r2b__when">
                                        @if ($backup['at'])
                                            <time datetime="{{ $backup['at']->toIso8601String() }}"
                                                  title="{{ $backup['at']->toDayDateTimeString() }}">
                                                {{ $backup['at']->diffForHumans() }}
                                            </time>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="r2b__actions">
                                        <a class="r2b__btn r2b__btn--quiet"
                                           href="{{ route('r2-backup.download', $backup['name']) }}">Download</a>

                                        <form method="POST"
                                              action="{{ route('r2-backup.destroy', $backup['name']) }}"
                                              onsubmit="return confirm('Delete {{ $backup['name'] }}? This cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="r2b__btn r2b__btn--quiet r2b__btn--danger">
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

</div>

<script>
    // The dump runs inline, so the request can take a while on a large
    // database. Show that something is happening and block a second submit.
    (function () {
        var form = document.getElementById('r2bRunForm');
        var button = document.getElementById('r2bRunBtn');
        if (!form || !button) return;

        form.addEventListener('submit', function () {
            button.disabled = true;
            button.textContent = 'Backing up…';
        });
    })();
</script>

@endsection
