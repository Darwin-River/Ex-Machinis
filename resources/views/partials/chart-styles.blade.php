{{-- The charts' shared look (#595, #600): pushed once however many charts a page has --}}
@once
@push('styles')
    <style>
        .mh { --mh-text: #e9e9ef; --mh-muted: #a3a6b2; --mh-grid: #34353f; --mh-axis: #828590; --mh-panel: #24242c;
              --mh-bars: #8b93a7; color: var(--mh-text); }
        .mh-intro { white-space: normal; margin-bottom: 1em; max-width: 70ch; }
        .mh-controls { display: flex; flex-wrap: wrap; gap: 1em; margin: 1em 0; }
        .mh-controls label { display: grid; gap: .25em; font-size: 14px; color: var(--mh-muted); }
        .mh-controls select { background: var(--mh-panel); color: var(--mh-text); border: 1px solid var(--mh-axis);
              border-radius: 4px; padding: .3em .5em; font: inherit; font-size: 15px; max-width: 100%; }
        .mh-card { background: var(--mh-panel); border: 1px solid var(--mh-grid); border-radius: 6px; padding: 1em; margin-bottom: 1.25em; }
        .mh-card h3 { font-size: 18px; margin: 0; }
        .mh-note { color: var(--mh-muted); font-size: 14px; margin: .25em 0 .75em; white-space: normal; max-width: 70ch; }
        .mh-legend { display: flex; flex-wrap: wrap; gap: 1em; font-size: 13px; color: var(--mh-muted); margin-bottom: .5em; }
        .mh-legend i { display: inline-block; width: 12px; height: 4px; border-radius: 2px; vertical-align: middle; margin-right: 6px; }
        .mh-chart { position: relative; min-height: 260px; }
        .mh-legend i.mh-bar { width: 10px; height: 10px; border-radius: 2px; background: var(--mh-bars); opacity: .55; }
        .mh-chart svg { display: block; overflow: visible; }
        .mh-chart text { fill: var(--mh-muted); font-size: 12px; font-family: inherit; }
        .mh-tip { position: absolute; top: 0; pointer-events: none; background: #111116; border: 1px solid var(--mh-axis);
              border-radius: 4px; padding: .3em .6em; font-size: 13px; line-height: 1.5; white-space: nowrap; z-index: 2; color: var(--mh-text); }
        .mh-tip div:first-child { color: var(--mh-muted); }
        .mh-tip i { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .mh-empty { display: flex; align-items: center; justify-content: center; min-height: inherit; color: var(--mh-muted); font-size: 14px; }
        .mh-chart:focus-visible { outline: 2px solid var(--mh-axis); outline-offset: 2px; }
    </style>
@endpush
@endonce
