<style>
    .ai-usage, .ai-usage-detail { --ai-usage-line: var(--gray-200); --ai-usage-muted: var(--gray-600); --ai-usage-surface: #fff; min-width: 0; font-size: .8125rem; line-height: 1.5; }
    .dark .ai-usage, .dark .ai-usage-detail { --ai-usage-line: var(--gray-800); --ai-usage-muted: var(--gray-400); --ai-usage-surface: #111113; }
    .ai-usage { display: grid; gap: 1rem; }
    .ai-usage-toolbar, .ai-usage-controls { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; }
    .ai-usage-toolbar { justify-content: space-between; }
    .ai-usage-muted { color: var(--ai-usage-muted); font-size: .75rem; overflow-wrap: anywhere; }
    .ai-usage-period { min-width: 9rem; }
    .ai-usage-coverage summary:focus-visible { outline: 2px solid var(--primary-500); outline-offset: 3px; }
    .ai-usage-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
    .ai-usage-metrics > div { min-width: 0; padding: 1rem; border: 1px solid var(--ai-usage-line); border-radius: .625rem; background: var(--ai-usage-surface); box-shadow: 0 1px 2px rgb(0 0 0 / 3%); }
    .ai-usage-metrics dt { font-weight: 500; color: var(--ai-usage-muted); }
    .ai-usage-value { font-size: 1.5rem; line-height: 1.4; font-weight: 500; margin: .25rem 0; letter-spacing: -.025em; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .ai-usage-coverage { margin-top: .25rem; }
    .ai-usage-coverage summary { cursor: pointer; width: fit-content; min-height: 1.5rem; font-size: .75rem; color: var(--ai-usage-muted); }
    .ai-usage-popover { padding: 1rem; display: grid; gap: 1rem; }
    .ai-usage-requests { min-width: 0; border: 1px solid var(--ai-usage-line); border-radius: .625rem; background: var(--ai-usage-surface); overflow: hidden; }
    .ai-usage-chart .fi-section, .ai-usage-history .fi-ta-ctn { border: 0; border-radius: 0; box-shadow: none; background: transparent; }
    .ai-usage-chart .fi-section-header { padding: .75rem 1rem .25rem; border: 0; }
    .ai-usage-chart .fi-section-header-heading { font-size: .8125rem; }
    .ai-usage-chart .fi-section-content { padding: .5rem 1rem .5rem; }
    .ai-usage-chart .fi-section-content-ctn { border: 0; }
    .ai-usage-chart-data { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .ai-usage-history { border-top: 1px solid var(--ai-usage-line); }
    .ai-usage-history .fi-ta-header { padding: .75rem 1rem; }
    .ai-usage-history .fi-ta-header-heading { font-size: .875rem; }
    .ai-usage-history .fi-ta-text { padding-block: .625rem; }
    .ai-usage-history .fi-ta-text-item, .ai-usage-history .fi-ta-header-cell { font-size: .8125rem; }
    .ai-usage-history .fi-ta-text-description { font-size: .6875rem; }
    .ai-usage-detail { display: grid; gap: 1.5rem; }
    .ai-usage-detail h3 { font-weight: 600; font-size: .8125rem; margin-bottom: .75rem; }
    .ai-usage-detail-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .5rem; }
    .ai-usage-detail-summary > div { padding: .75rem; border: 1px solid var(--ai-usage-line); border-radius: .5rem; min-width: 0; }
    .ai-usage-detail-summary dd { margin-top: .25rem; font-weight: 600; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
    .ai-usage-detail-meta { display: grid; grid-template-columns: minmax(7rem, 1fr) minmax(0, 2fr); gap: .5rem 1rem; }
    .ai-usage-detail-meta dt { color: var(--ai-usage-muted); }
    .ai-usage-detail-meta dd { overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }
    @media (max-width: 900px) { .ai-usage-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 480px) { .ai-usage-metrics { grid-template-columns: minmax(0, 1fr); } .ai-usage-detail-summary { grid-template-columns: minmax(0, 1fr); } .ai-usage-detail-meta { grid-template-columns: minmax(0, 1fr); gap: .25rem; } .ai-usage-detail-meta dd { margin-bottom: .5rem; } }
</style>
