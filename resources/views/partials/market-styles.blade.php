{{-- The market pages' shared look (#721): chips, styled selects, the goods board, source lines --}}
@once
@push('styles')
    <style>
        .mk-lead { color: #c3c9d8; max-width: 46em; white-space: normal; margin: 0 0 .75rem; }
        .mk-src { color: #9aa3b8; font-size: 12px; margin: .4rem 0 0; white-space: normal; }
        .mk-src code { color: inherit; background: none; padding: 0; }
        .mk-bar { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem 1.5rem; margin: 1rem 0 .5rem; }
        .mk-chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; padding: 0; list-style: none; }
        .mk-chip { display: inline-block; border: 1px solid #263052; background: #111729; color: #e6e9f2 !important; border-radius: 99px;
              padding: 5px 13px; font-size: 14px; text-decoration: none !important; white-space: nowrap; }
        .mk-chip:hover, .mk-chip:focus-visible { border-color: #ffb547; }
        .mk-chip[aria-current="page"] { background: #ffb547; border-color: #ffb547; color: #1a1200 !important; font-weight: 700; }
        .mk-chip small { color: inherit; opacity: .7; font-size: 12px; margin-left: 2px; }
        .mk-form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0; }
        .mk-form label { color: #9aa3b8; font-size: 14px; margin: 0; }
        .mk-select { appearance: none; -webkit-appearance: none; background-color: #111729; color: #e6e9f2; border: 1px solid #263052;
              border-radius: 8px; padding: 6px 34px 6px 12px; font: inherit; font-size: 15px; max-width: 100%; min-width: 0;
              background-image: linear-gradient(45deg, transparent 50%, #9aa3b8 50%), linear-gradient(135deg, #9aa3b8 50%, transparent 50%);
              background-position: calc(100% - 17px) 55%, calc(100% - 12px) 55%; background-size: 5px 5px; background-repeat: no-repeat; }
        .mk-select:focus { outline: none; border-color: #4cc9f0; box-shadow: 0 0 0 .2rem rgba(76, 201, 240, .25); }
        .mk-select option { background: #111729; color: #e6e9f2; }
        .mk-button { background: transparent; color: #e6e9f2; border: 1px solid #263052; border-radius: 8px; padding: 6px 14px;
              font: inherit; font-size: 15px; font-weight: 600; cursor: pointer; }
        .mk-button:hover, .mk-button:focus-visible { border-color: #ffb547; color: #ffb547; }
        .mk-key { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px; color: #9aa3b8; margin: .25rem 0 .75rem; }
        .mk-key i { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; vertical-align: -1px; }
        .mk-key i.mk-ring { background: transparent !important; border: 2px solid #9aa3b8; }
        .mk-board { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
        .mk-row { display: grid; grid-template-columns: minmax(170px, 220px) minmax(0, 1fr) 180px 100px; gap: 8px 18px; align-items: center;
              background: #111729; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 10px 16px;
              color: inherit !important; text-decoration: none !important; }
        .mk-row:hover, .mk-row:focus-visible { border-color: #ffb547; }
        .mk-name b { display: block; color: #fff; font-family: "Space Grotesk", system-ui, sans-serif; }
        .mk-name small { color: #9aa3b8; font-size: 13px; }
        .mk-spread { position: relative; height: 44px; min-width: 0; margin: 0 8px; }
        .mk-axis { position: absolute; left: 0; right: 0; top: 21px; height: 2px; background: #263052; }
        .mk-dot { position: absolute; top: 15px; width: 14px; height: 14px; border-radius: 50%; transform: translateX(-50%); }
        .mk-dot.mk-sell { background: currentColor; }
        .mk-dot.mk-buy { border: 3px solid currentColor; background: #111729; }
        .mk-dot em { position: absolute; left: 50%; transform: translateX(-50%); font-style: normal; font-size: 12px; color: #c3c9d8; white-space: nowrap; }
        .mk-dot.mk-sell em { top: -17px; } .mk-dot.mk-buy em { top: 14px; }
        .mk-dot em.mk-l { left: 0; transform: none; } .mk-dot em.mk-r { left: auto; right: 0; transform: none; }
        .mk-deal { font-size: 13px; color: #9aa3b8; line-height: 1.45; }
        .mk-deal b { color: #e6e9f2; font-size: 15px; }
        .mk-deal .mk-gain { color: #5ad19a; }
        .mk-trend { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; }
        .mk-trend small { display: block; font-weight: 400; color: #9aa3b8; font-size: 12px; }
        .mk-up { color: #5ad19a; } .mk-down { color: #ff8a7a; } .mk-flat { color: #c3c9d8; }
        .mk-empty { color: #9aa3b8; padding: 1rem 0; }
        .mk-links { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; margin: 1rem 0; padding: 0; list-style: none; }
        .mk-fold { margin: 1.25rem 0 0; }
        .mk-fold summary { cursor: pointer; font-family: "Space Grotesk", system-ui, sans-serif; font-weight: 700; font-size: 1.15rem; color: #fff; }
        .mk-fold summary:focus-visible { outline: 2px solid #4cc9f0; outline-offset: 2px; }
        .mk-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .mk-table th { color: #9aa3b8; font-size: 13px; font-weight: 600; text-align: left; padding: 7px 8px; border-bottom: 1px solid #263052; white-space: nowrap; }
        .mk-table td { padding: 7px 8px; border-bottom: 1px solid rgba(255,255,255,.08); vertical-align: top; }
        .mk-table .num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .mk-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 0 7px; border-radius: 99px; background: rgba(76,201,240,.15);
              color: #4cc9f0; margin-left: 4px; vertical-align: 1px; }
        .mk-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .sr-only-mk { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        @media (max-width: 860px) {
            .mk-row { grid-template-columns: minmax(0, 1fr) auto; padding: 10px 12px; }
            .mk-spread { grid-column: 1 / -1; order: 3; margin: 4px 10px 6px; }
            .mk-deal { order: 4; grid-column: 1 / -1; }
            .mk-trend { order: 2; }
        }
        @media (max-width: 600px) {
            /* A phone: the chips scroll sideways in their own strip, not the page */
            .mk-bar nav { width: 100%; min-width: 0; }
            .mk-chips { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
            .mk-chips::-webkit-scrollbar { display: none; }
            /* A phone: each row of a table is a small card of label and value */
            .mk-table.mk-cards thead { display: none; }
            .mk-table.mk-cards tr { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 12px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,.08); }
            .mk-table.mk-cards td { border: 0; padding: 1px 0; text-align: left !important; white-space: normal; }
            .mk-table.mk-cards td:first-child { grid-column: 1 / -1; font-weight: 600; }
            .mk-table.mk-cards td[data-label]::before { content: attr(data-label) ": "; color: #9aa3b8; font-size: 12px; }
        }
    </style>
@endpush
@endonce
