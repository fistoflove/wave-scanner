<?php

return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WCAG / WAVE Scanner Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0b0b0b;
            --charcoal: #12100f;
            --panel: #151311;
            --panel-2: #1d1a16;
            --stroke: #2a2521;
            --muted: #8e8780;
            --text: #f4efe9;
            --accent: #f2b33d;
            --accent-2: #8fe6d2;
            --good: #7ddf7b;
            --warn: #f2b33d;
            --bad: #f06b5d;
            --highlight: rgba(242,179,61,0.15);
        }
        * { box-sizing: border-box; }
        body {
            font-family: "IBM Plex Sans", "Segoe UI", Arial, sans-serif;
            margin: 0;
            background: radial-gradient(circle at top left, #1b1714 0%, #0c0a08 45%, #050504 100%);
            color: var(--text);
        }
        header {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(12,10,8,0.9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--stroke);
        }
        h1, h2, h3, h4 { font-family: "Space Grotesk", "IBM Plex Sans", sans-serif; }
        h1 { margin: 0 0 6px 0; letter-spacing: 0.4px; font-size: 26px; }
        .sub { margin: 0; color: var(--muted); font-size: 13px; }
        main { padding: 18px 24px 28px; display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 16px; }
        section { background: var(--panel); border: 1px solid var(--stroke); border-radius: 16px; padding: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
        .panel-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0 0 10px; }
        .panel-title h3 { margin: 0; font-size: 16px; letter-spacing: 0.3px; }
        .flex { display: flex; gap: 10px; align-items: center; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 11px 8px; text-align: left; border-bottom: 1px solid var(--stroke); }
        .table td:not(:first-child), .table th:not(:first-child) { text-align: center; }
        #url-table th:nth-child(2), #url-table td:nth-child(2) { text-align: left; }
        #url-table input.url-select, #url-select-all {
            appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 4px;
            border: 1px solid rgba(90,88,86,0.7);
            background: rgba(10,16,14,0.6);
            display: inline-grid;
            place-items: center;
            cursor: pointer;
            padding: 0;
            margin: 0;
            box-sizing: border-box;
        }
        #url-table input.url-select:checked, #url-select-all:checked {
            background: var(--accent);
            border-color: var(--accent);
        }
        #url-table input.url-select:checked::after, #url-select-all:checked::after {
            content: '';
            width: 8px;
            height: 4px;
            border-left: 2px solid #111;
            border-bottom: 2px solid #111;
            transform: rotate(-45deg);
            margin-top: -1px;
        }
        #url-table input.url-select:focus-visible, #url-select-all:focus-visible {
            outline: 2px solid rgba(242,179,61,0.8);
            outline-offset: 2px;
        }
        #url-table th:first-child, #url-table td:first-child {
            padding-left: 4px;
            padding-right: 4px;
        }
        .table th { font-weight: 600; cursor: pointer; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 1.2px; }
        .table tbody tr:hover { background: rgba(242,179,61,0.08); }
        input, textarea, button, select { font: inherit; border-radius: 12px; border: 1px solid var(--stroke); padding: 10px 12px; background: var(--panel-2); color: var(--text); transition: border 0.15s ease, box-shadow 0.15s ease; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(242,179,61,0.15); }
        button { cursor: pointer; border: none; background: var(--accent); color: var(--ink); font-weight: 700; }
        button.secondary { background: transparent; color: var(--text); border: 1px solid var(--stroke); }
        button.ghost { background: transparent; border: 1px dashed var(--stroke); color: var(--muted); }
        button.icon { width: 36px; height: 36px; padding: 0; display: grid; place-items: center; border-radius: 10px; }
        .icon-btn { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; border: 1px solid transparent; }
        .icon-btn.with-label { width: auto; min-width: 70px; padding: 0 10px; }
        .icon-btn[disabled] { opacity: 0.35; cursor: not-allowed; pointer-events: none; }
        .icon-btn i { pointer-events: none; font-size: 14px; }
        .icon-btn:hover { background: rgba(242,179,61,0.1); border-color: rgba(242,179,61,0.3); }
        .badge { padding: 4px 10px; border-radius: 999px; font-size: 12px; background: rgba(242,179,61,0.14); color: var(--text); border: 1px solid rgba(242,179,61,0.2); }
        .heartbeat { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; background: rgba(143,230,210,0.18); border: 1px solid rgba(143,230,210,0.45); color: var(--text); font-size: 12px; font-weight: 700; position: relative; overflow: visible; }
        .heartbeat::after { content: ""; position: absolute; inset: -8px; border-radius: 50%; border: 1px solid rgba(143,230,210,0.35); opacity: 0; transform: scale(0.9); }
        .heartbeat.beat::after { animation: beatPulse 0.7s ease-out; }
        @keyframes beatPulse {
            0% { opacity: 0.9; transform: scale(0.8); }
            100% { opacity: 0; transform: scale(1.6); }
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .muted { color: var(--muted); font-size: 12px; }
        #loader { min-height: 16px; }
        #history-chart { width: 100%; height: 190px; max-width: 100%; display: block; }
        #trend-chart { width: 100%; height: 220px; max-width: 100%; display: block; }
        .toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); margin-right: 10px; }
        .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .controls { gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
        .pill { padding: 6px 10px; border-radius: 999px; background: var(--panel-2); border: 1px solid var(--stroke); font-size: 13px; color: var(--muted); }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 10px; margin: 8px 0 14px; }
        .stat { padding: 14px; border-radius: 16px; border: 1px solid var(--stroke); background: var(--panel-2); }
        .stat .label { color: var(--muted); font-size: 12px; }
        .stat .value { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .stat.good .value { color: var(--good); }
        .stat.warn .value { color: var(--warn); }
        .stat.bad .value { color: var(--bad); }
        a { color: var(--accent); text-decoration: none; }
        .loader { color: var(--muted); font-size: 12px; }
        #overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; z-index: 20; }
        #overlay .panel { background: var(--panel); border: 1px solid var(--stroke); padding: 16px; border-radius: 12px; width: min(360px, 90vw); text-align: center; }
        #progress-bar { height: 10px; border-radius: 6px; background: var(--stroke); overflow: hidden; margin-top: 8px; }
        #progress-bar div { height: 100%; width: 0%; background: var(--accent); transition: width 0.2s ease; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none; align-items: center; justify-content: center; z-index: 10; }
        .modal { background: var(--panel); border: 1px solid var(--stroke); border-radius: 16px; padding: 18px; width: 100%; max-width: 90vw; box-shadow: 0 12px 50px rgba(0,0,0,0.4); }
        .modal header { padding: 0 0 8px; border: none; display: flex; align-items: center; justify-content: space-between; background: transparent; }
        .modal h3 { margin: 0; font-size: 16px; }
        .modal-close { background: none; border: none; color: var(--text); cursor: pointer; font-size: 18px; }
        .topbar { padding: 22px 24px 18px; display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 16px; align-items: center; }
        .brand-block { display: grid; gap: 6px; }
        .toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; justify-content: flex-end; }
        .toolbar-group { display: flex; gap: 8px; padding: 8px; border-radius: 14px; background: var(--panel-2); border: 1px solid var(--stroke); flex-wrap: wrap; align-items: center; }
        .toolbar-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.2px; color: var(--muted); }
        .stack { display: flex; flex-direction: column; gap: 6px; }
        .section-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .section-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .soft-card { background: linear-gradient(140deg, rgba(242,179,61,0.12), rgba(143,230,210,0.08)); border: 1px solid rgba(242,179,61,0.2); padding: 10px 12px; border-radius: 14px; }
        .drawer-backdrop { position: fixed; inset: 0; background: rgba(4,4,4,0.55); display: none; z-index: 30; }
        .drawer-backdrop.open { display: block; }
        .drawer { position: fixed; top: 0; left: 0; height: 100vh; width: 50vw; background: var(--panel); border-right: 1px solid var(--stroke); transform: translateX(-100%); transition: transform 0.25s ease; z-index: 40; display: flex; flex-direction: column; }
        .drawer.open { transform: translateX(0); }
        .drawer-right { left: auto; right: 0; border-right: none; border-left: 1px solid var(--stroke); transform: translateX(100%); }
        .drawer-right.open { transform: translateX(0); }
        .drawer header { position: static; background: transparent; border-bottom: 1px solid var(--stroke); padding: 18px; display: flex; align-items: center; justify-content: space-between; }
        .drawer .content { padding: 18px; overflow: auto; display: flex; flex-direction: column; gap: 16px; }
        .drawer-section { display: flex; flex-direction: column; gap: 12px; }
        .scroll-panel { max-height: 70vh; overflow: auto; padding-right: 6px; }
        .modal-scroll { max-height: 80vh; overflow: hidden; display: flex; flex-direction: column; }
        .modal-scroll .modal-body { overflow: auto; padding-right: 6px; }
        @media (max-width: 1100px) {
            .topbar { grid-template-columns: 1fr; }
            main { grid-template-columns: 1fr; }
            header { position: static; }
        }
        @media (max-width: 720px) {
            .toolbar { justify-content: flex-start; }
            .section-actions { width: 100%; }
        }
    </style>
</head>
<body>
    <header>
        <div class="topbar">
            <div class="brand-block">
                <div class="toolbar-label">LOCOMOTIVE</div>
                <h1>WCAG / WAVE Scanner</h1>
                <p class="sub">Track remediation at scale with a single source of truth.</p>
            </div>
            <div class="toolbar">
                <div class="badge" id="credits-badge" title="WAVE credits remaining">Credits: --</div>
                <div class="heartbeat" id="ws-status" title="Queue heartbeat">0</div>
                <button class="secondary icon-btn" id="workspace-btn" title="Workspace"><i class="fa-solid fa-bars"></i></button>
                <button class="secondary icon-btn" id="queue-btn" title="Queue"><i class="fa-solid fa-inbox"></i></button>
                <button class="secondary icon-btn" id="project-settings-btn" title="Project Settings"><i class="fa-solid fa-gear"></i></button>
                <form method="GET" action="/logout" style="margin:0;">
                    <button class="secondary">Logout</button>
                </form>
            </div>
        </div>
    </header>
    <div class="drawer-backdrop" id="drawer-backdrop"></div>
    <aside class="drawer" id="workspace-drawer">
        <header>
            <div>
                <div class="toolbar-label">Workspace</div>
                <h3 style="margin:4px 0 0 0;">Project</h3>
            </div>
            <button class="modal-close" id="drawer-close-btn">&times;</button>
        </header>
        <div class="content">
            <div class="drawer-section" id="drawer-project">
                <div class="stack">
                    <span class="toolbar-label">Project</span>
                    <select id="project-select" class="pill" style="min-width:200px;">
                        <option>Loading projects...</option>
                    </select>
                </div>
                <div class="flex">
                    <button class="secondary" id="new-project-btn">New Project</button>
                </div>
                <div class="stack">
                    <span class="toolbar-label">Report viewports</span>
                    <div class="muted" id="report-viewport-summary"></div>
                </div>
                <div>
                    <div class="panel-title">
                        <h3>Suppressions</h3>
                        <div class="flex">
                            <button class="secondary" id="suppression-refresh-btn">Refresh</button>
                            <button class="secondary" id="recount-btn">Recalculate</button>
                        </div>
                    </div>
                    <div class="muted">Project-level suppressions</div>
                    <div class="scroll-panel">
                        <table class="table" id="suppression-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Reason</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="muted" style="margin-top:10px;">Selector-level suppressions</div>
                    <div class="scroll-panel">
                        <table class="table" id="suppression-elements-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Selector</th>
                                    <th>Viewport</th>
                                    <th>Reason</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="muted" id="suppression-status" style="margin-top:6px;"></div>
                </div>
            </div>
        </div>
    </aside>
    <aside class="drawer drawer-right" id="queue-drawer">
        <header>
            <div>
                <div class="toolbar-label">Queue</div>
                <h3 style="margin:4px 0 0 0;">Jobs & Errors</h3>
            </div>
            <button class="modal-close" id="queue-drawer-close-btn">&times;</button>
        </header>
        <div class="content">
            <div class="drawer-section" id="drawer-queue">
                <div class="panel-title">
                    <h3>Queue</h3>
                    <div class="flex">
                        <button class="secondary" id="queue-refresh-btn">Refresh</button>
                        <button class="secondary" id="queue-clear-btn">Clear</button>
                    </div>
                </div>
                <div class="muted" id="queue-summary"></div>
                <div class="scroll-panel">
                    <table class="table" id="queue-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>URL</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Started</th>
                                <th>Finished</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="panel-title" style="margin-top:12px;">
                    <h3>Recent Errors</h3>
                    <div class="flex">
                        <button class="secondary" id="refresh-errors-btn">Refresh</button>
                        <button class="secondary" id="clear-errors-btn">Clear</button>
                    </div>
                </div>
                <div class="scroll-panel">
                    <table class="table" id="errors-table">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Viewport</th>
                                <th>Context</th>
                                <th>Error</th>
                                <th>Finished</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="muted" id="errors-status" style="margin-top:8px;"></div>
            </div>
        </div>
    </aside>
    <main>
        <section style="grid-column: span 2;">
            <div class="section-header">
                <div>
                    <h3>URLs</h3>
                    <div class="muted">Search, segment, and queue scans from a single command bar.</div>
                </div>
                <div class="section-actions">
                    <input id="search" placeholder="Search URLs..." style="min-width:220px;">
                    <select id="tag-filter" class="pill">
                        <option value="">All tags</option>
                    </select>
                    <button id="new-url-btn">Add URL</button>
                    <button id="audit-active-btn" class="secondary">Audit Active</button>
                    <button id="import-export-btn" class="secondary">Import / Export</button>
                </div>
            </div>
            <div class="stat-grid">
                <div class="stat">
                    <div class="label">Total URLs</div>
                    <div class="value" id="stat-total">--</div>
                </div>
                <div class="stat">
                    <div class="label">Avg AIM</div>
                    <div class="value good" id="stat-aim">--</div>
                </div>
                <div class="stat">
                    <div class="label">Unique Errors</div>
                    <div class="value" id="stat-unique">--</div>
                </div>
                <div class="stat">
                    <div class="label">Unique Contrast Errors</div>
                    <div class="value" id="stat-unique-contrast">--</div>
                </div>
                <div class="stat">
                    <div class="label">Unique Alerts</div>
                    <div class="value" id="stat-unique-alerts">--</div>
                </div>
            </div>
            <div class="panel-title" style="margin-top:8px;">
                <h3>Trends</h3>
                <div class="flex" style="flex-wrap:wrap;">
                    <button class="secondary" id="report-viewport-btn">Select viewports</button>
                    <label class="toggle"><span class="dot" style="background:#10b981"></span><input type="checkbox" id="toggle-aim" checked> Avg AIM</label>
                    <label class="toggle"><span class="dot" style="background:#5A5856"></span><input type="checkbox" id="toggle-unique" checked> Unique Errors</label>
                    <label class="toggle"><span class="dot" style="background:#C09C3A"></span><input type="checkbox" id="toggle-unique-contrast" checked> Unique Contrast</label>
                    <label class="toggle"><span class="dot" style="background:#A8E3D8"></span><input type="checkbox" id="toggle-unique-alerts"> Unique Alerts</label>
                    <label class="toggle"><span class="dot" style="background:#3C2484"></span><input type="checkbox" id="toggle-errors"> Errors</label>
                    <label class="toggle"><span class="dot" style="background:#C09C3A"></span><input type="checkbox" id="toggle-contrast"> Contrast</label>
                    <label class="toggle"><span class="dot" style="background:#A8E3D8"></span><input type="checkbox" id="toggle-alerts"> Alerts</label>
                </div>
            </div>
            <canvas id="trend-chart"></canvas>
            <div class="soft-card" id="bulk-actions" style="margin:10px 0 6px; display:none; align-items:center; justify-content:space-between; gap:12px;">
                <div class="muted" id="bulk-count">0 selected</div>
                <div class="flex" style="flex-wrap:wrap;">
                    <button class="secondary" id="bulk-activate-btn" disabled>Activate</button>
                    <button class="secondary" id="bulk-deactivate-btn" disabled>Deactivate</button>
                    <button class="secondary" id="bulk-tag-btn" disabled>Set label</button>
                    <button class="secondary" id="bulk-delete-btn" disabled>Delete</button>
                    <button class="secondary" id="bulk-clear-btn" disabled>Clear</button>
                </div>
            </div>
            <table class="table" id="url-table">
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;"><input type="checkbox" id="url-select-all" /></th>
                        <th data-sort="url">URL</th>
                        <th>Tag</th>
                        <th data-sort="last_aim_score">AIM</th>
                        <th data-sort="last_errors">Errors</th>
                        <th data-sort="last_contrast_errors">Contrast</th>
                        <th data-sort="last_alerts">Alerts</th>
                        <th data-sort="last_test_at">Last Test</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <div class="flex" style="justify-content:space-between;align-items:center;margin-top:8px;">
                <div class="flex" id="pagination"></div>
                <div class="flex" style="gap:6px; align-items:center;">
                    <span class="muted">Rows</span>
                    <select id="page-size-select" class="pill" style="padding:6px 10px;">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>
            <div class="loader" id="loader"></div>
        </section>

        <section id="issues-section" style="grid-column: span 2; margin-top: 16px;">
            <div class="section-header">
                <div>
                    <h3>Issues by Impact</h3>
                    <div class="muted">Aggregate WCAG issues by severity and type.</div>
                </div>
                <div class="section-actions">
                    <select id="issue-impact-category" class="pill">
                        <option value="">All categories</option>
                        <option value="error">Errors</option>
                        <option value="contrast">Contrast</option>
                        <option value="alert">Alerts</option>
                        <option value="feature">Features</option>
                        <option value="structure">Structure</option>
                        <option value="aria">ARIA</option>
                    </select>
                    <input id="issue-search" placeholder="Search issue description..." style="min-width:220px;">
                    <label class="toggle" style="margin-right:0;">
                        <input type="checkbox" id="show-suppressed"> Show suppressed
                    </label>
                    <button id="export-issues-btn" class="secondary">Export Issues</button>
                    <button id="export-plan-btn" class="secondary">Export Plan</button>
                </div>
            </div>
            <table class="table" id="issues-impact-table">
                <thead>
                    <tr>
                        <th>Issue</th>
                        <th>WCAG</th>
                        <th>Category</th>
                        <th>Occurrences</th>
                        <th>Pages</th>
                        <th>Unique Instances</th>
                        <th>Flag</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <div class="muted" id="issues-status" style="margin-top:8px;"></div>
        </section>

    </main>

    <!-- Import / Export Modal -->
    <div class="modal-backdrop" id="import-export-modal">
        <div class="modal" style="max-width:820px;">
            <header>
                <h3>Import / Export</h3>
                <button class="modal-close" data-close="#import-export-modal">&times;</button>
            </header>
            <div style="display:grid; gap:16px;">
                <div>
                    <h4 style="margin:0 0 6px 0;">Import URLs</h4>
                    <p class="muted" style="margin:0 0 8px 0;">Paste a single-column CSV of URLs. Invalid rows are ignored.</p>
                    <textarea id="csv-input" rows="10" style="width:100%; min-height:200px;" placeholder="https://example.com/page1"></textarea>
                    <div class="flex" style="margin-top:10px;">
                        <button id="csv-import-submit">Import URLs</button>
                        <span class="muted" id="import-status"></span>
                    </div>
                </div>
                <div class="soft-card">
                    <h4 style="margin:0 0 6px 0;">Export URLs</h4>
                    <p class="muted" style="margin:0 0 8px 0;">Download the current URL list with metrics.</p>
                    <button class="secondary" id="export-audit-btn">Export CSV</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Issues Modal -->
    <div class="modal-backdrop" id="export-issues-modal">
        <div class="modal" style="max-width:720px;">
            <header>
                <h3>Export Issues</h3>
                <button class="modal-close" data-close="#export-issues-modal">&times;</button>
            </header>
            <div style="display:grid; gap:14px;">
                <div class="muted">Exports issue counts per page (filterable by category and issue type).</div>
                <div>
                    <label class="muted" style="display:block; margin-bottom:6px;">Category Filter</label>
                    <select id="export-issues-category" class="pill" style="width:100%; padding:10px;">
                        <option value="all">All categories</option>
                        <option value="error">Errors</option>
                        <option value="contrast">Contrast</option>
                        <option value="alert">Alerts</option>
                        <option value="feature">Features</option>
                        <option value="structure">Structure</option>
                        <option value="aria">ARIA</option>
                    </select>
                </div>
                <div>
                    <label class="muted" style="display:block; margin-bottom:6px;">Type Filter</label>
                    <select id="export-issues-item" class="pill" style="width:100%; padding:10px;">
                        <option value="">All issue types</option>
                    </select>
                    <div class="muted" style="margin-top:6px;">Select from the issues detected in this project.</div>
                </div>
                <div>
                    <label class="muted" style="display:block; margin-bottom:6px;">URL filter (optional)</label>
                    <input id="export-issues-url" type="text" placeholder="e.g., /about-us" style="width:100%;" />
                    <div class="muted" style="margin-top:6px;">Matches any URL containing this text.</div>
                </div>
                <div style="display:flex; align-items:center;">
                    <label class="toggle" style="margin-right:0;">
                        <input type="checkbox" id="export-issues-suppressed"> Include suppressed
                    </label>
                </div>
                <div class="muted" id="export-issues-note">Export reflects latest results for selected viewports.</div>
                <div class="flex">
                    <button id="export-issues-confirm">Download</button>
                    <button class="secondary" data-close="#export-issues-modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="history-modal">
        <div class="modal" style="max-width:90vw; width:100%;">
            <header>
                <h3>History</h3>
                <button class="modal-close" data-close="#history-modal">&times;</button>
            </header>
            <div class="muted" id="history-summary">History</div>
            <canvas id="history-chart"></canvas>
            <table class="table" id="history-table">
                <thead>
                    <tr>
                        <th>Tested</th>
                        <th>Viewport</th>
                        <th>AIM</th>
                        <th>Errors</th>
                        <th>Contrast</th>
                        <th>Alerts</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="modal-backdrop" id="issue-modal">
        <div class="modal" style="max-width:90vw; width:100%; max-height:80vh; display:flex; flex-direction:column;">
            <header>
                <h3 id="issue-modal-title">Issue details</h3>
                <button class="modal-close" data-close="#issue-modal">&times;</button>
            </header>
            <div class="muted" id="issue-modal-summary"></div>
            <div style="margin-top:6px;" id="issue-doc">
                <button class="secondary" id="issue-doc-btn" style="padding:6px 10px;">Explain this to me</button>
                <div class="muted" id="issue-doc-content" style="margin-top:6px; display:none;"></div>
            </div>
            <div style="overflow:auto; flex:1; margin-top:8px;">
                <table class="table" id="issue-modal-table">
                    <thead>
                        <tr>
                            <th>URL</th>
                            <th>Selector</th>
                            <th>Contrast</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="issue-pages-modal">
        <div class="modal" style="max-width:90vw; width:100%; max-height:80vh; display:flex; flex-direction:column;">
            <header>
                <h3 id="issue-pages-title">Issue Pages</h3>
                <button class="modal-close" data-close="#issue-pages-modal">&times;</button>
            </header>
            <div class="muted" id="issue-pages-summary"></div>
            <div class="muted" id="issue-pages-doc" style="margin-top:6px;"></div>
            <div style="overflow:auto; flex:1; margin-top:8px;">
                <table class="table" id="issue-pages-table">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th>Occurrences</th>
                            <th>Report</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="page-issues-modal">
        <div class="modal" style="max-width:90vw; width:100%; max-height:80vh; display:flex; flex-direction:column;">
            <header>
                <h3 id="page-issues-title">Page Issues</h3>
                <button class="modal-close" data-close="#page-issues-modal">&times;</button>
            </header>
            <div class="muted" id="page-issues-summary"></div>
            <div style="display:grid; grid-template-columns: minmax(280px, 1.15fr) minmax(280px, 1fr); gap:16px; flex:1; overflow:hidden; margin-top:8px;">
                <div style="overflow:auto;">
                    <table class="table" id="page-issues-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Issue</th>
                                <th>Count</th>
                                <th>Unique</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div style="display:flex; flex-direction:column; gap:8px; overflow:hidden;">
                    <div>
                        <h4 id="page-issue-detail-title" style="margin:0;">Select an issue</h4>
                        <div class="muted" id="page-issue-detail-summary">Pick an issue to see selectors.</div>
                    </div>
                    <div style="overflow:auto; flex:1;">
                        <table class="table" id="page-issue-detail-table">
                            <thead>
                                <tr>
                                    <th>Selector</th>
                                    <th>Contrast</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="audit-modal">
        <div class="modal">
            <header>
                <h3>Audit Active URLs</h3>
                <button class="modal-close" data-close="#audit-modal">&times;</button>
            </header>
            <div class="muted" style="margin-bottom:8px;">Select which viewports to test. Credits are estimated based on selection.</div>
            <div id="audit-viewport-list" style="display:grid; gap:6px; margin-bottom:10px;"></div>
            <div class="muted" id="audit-credit-estimate" style="margin-bottom:10px;"></div>
            <div class="flex">
                <button id="audit-confirm-btn">Start Audit</button>
                <button class="secondary" data-close="#audit-modal">Cancel</button>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="report-viewport-modal">
        <div class="modal">
            <header>
                <h3>Report Viewports</h3>
                <button class="modal-close" data-close="#report-viewport-modal">&times;</button>
            </header>
            <div class="muted" style="margin-bottom:8px;">Select viewports to include in reporting.</div>
            <div id="report-viewport-list" style="display:grid; gap:6px; margin-bottom:10px;"></div>
            <div class="flex">
                <button id="report-viewport-save-btn">Apply</button>
                <button class="secondary" data-close="#report-viewport-modal">Cancel</button>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="project-settings-modal">
        <div class="modal modal-scroll" style="max-width:900px;">
            <header>
                <h3>Project Settings</h3>
                <button class="modal-close" data-close="#project-settings-modal">&times;</button>
            </header>
            <div class="modal-body" style="display:grid; gap:16px;">
                <div>
                    <h4 style="margin:0 0 6px 0;">Project identity</h4>
                    <div style="margin-top:4px;">
                        <label class="muted" style="display:block; margin-bottom:6px;">Project name</label>
                        <input id="project-name-input" type="text" placeholder="Project name" style="width:100%;" />
                    </div>
                    <div style="margin-top:10px;">
                        <label class="muted" style="display:block; margin-bottom:6px;">Project slug</label>
                        <input id="project-slug-input" type="text" placeholder="project-slug" style="width:100%;" />
                        <div class="muted" style="margin-top:4px;">Use a stable slug for API and export references.</div>
                    </div>
                    <div class="muted" id="project-settings-status" style="margin-top:8px;"></div>
                </div>
                <div style="border-top:1px solid var(--stroke); padding-top:12px;">
                    <h4 style="margin:0 0 6px 0;">WAVE configuration</h4>
                    <div class="muted" style="margin-bottom:10px;">Select the WAVE report type (affects detail depth and credits).</div>
                    <label class="muted" style="display:block; margin-bottom:6px;">Report type</label>
                    <select id="reporttype-select" class="pill" style="width:100%; padding:10px;">
                        <option value="1">1 - Summary only (1 credit)</option>
                        <option value="2">2 - Item list (2 credits)</option>
                        <option value="3">3 - XPath details (3 credits)</option>
                        <option value="4">4 - Selector details (3 credits)</option>
                    </select>
                    <div class="muted" id="reporttype-note" style="margin-top:8px;"></div>
                    <div style="margin-top:10px;">
                        <label class="muted" style="display:block; margin-bottom:6px;">WAVE API key</label>
                        <input id="api-key-input" type="password" placeholder="Leave blank to keep current key" style="width:100%;" />
                        <div class="muted" id="api-key-status" style="margin-top:4px;"></div>
                        <button class="secondary" id="clear-api-key-btn" style="margin-top:6px;">Clear API key</button>
                    </div>
                    <div style="margin-top:10px; display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div>
                            <label class="muted" style="display:block; margin-bottom:6px;">Retry attempts</label>
                            <input id="retry-attempts-input" type="number" min="0" placeholder="2" style="width:100%;" />
                        </div>
                        <div>
                            <label class="muted" style="display:block; margin-bottom:6px;">Retry delay (ms)</label>
                            <input id="retry-delay-input" type="number" min="0" placeholder="500" style="width:100%;" />
                        </div>
                    </div>
                </div>
                <div style="border-top:1px solid var(--stroke); padding-top:12px;">
                    <div class="panel-title" style="margin-bottom:6px;">
                        <h3>Viewports</h3>
                        <button class="secondary" id="add-viewport-btn">Add viewport</button>
                    </div>
                    <div class="muted" style="margin-bottom:8px;">
                        Viewports define the scan configurations you can include in reporting filters.
                    </div>
                    <div class="scroll-panel">
                        <table class="table" id="viewport-table">
                            <thead>
                                <tr>
                                    <th>Label</th>
                                    <th>Width</th>
                                    <th>Delay</th>
                                    <th>User agent</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="muted" id="viewport-status" style="margin-top:6px;"></div>
                </div>
                <div class="flex" style="margin-top:4px;">
                    <button id="save-project-all-btn">Save Changes</button>
                    <button class="secondary" id="delete-project-btn">Delete Project</button>
                    <button class="secondary" data-close="#project-settings-modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div id="overlay">
        <div class="panel">
            <div id="overlay-text" class="muted">Processing...</div>
            <div id="progress-bar"><div></div></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const storedPageSize = Number(localStorage.getItem('pageSize') || '');
        const api = {
            urls: [],
            sort: { by: 'created_at', direction: 'DESC' },
            selectedId: null,
            issues: [],
            viewports: [],
            projects: [],
            page: 1,
            pageSize: Number.isFinite(storedPageSize) && storedPageSize > 0 ? storedPageSize : 15,
            reportType: 4,
            queueUrlIds: new Set(),
            uniqueMetrics: null,
            uniqueMetricsAt: 0
        };

        const urlTableBody = document.querySelector('#url-table tbody');
        const urlSelectAll = document.getElementById('url-select-all');
        const bulkActions = document.getElementById('bulk-actions');
        const bulkCount = document.getElementById('bulk-count');
        const bulkActivateBtn = document.getElementById('bulk-activate-btn');
        const bulkDeactivateBtn = document.getElementById('bulk-deactivate-btn');
        const bulkTagBtn = document.getElementById('bulk-tag-btn');
        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
        const bulkClearBtn = document.getElementById('bulk-clear-btn');
        const pageSizeSelect = document.getElementById('page-size-select');
        const historyBody = document.querySelector('#history-table tbody');
        const historyChartCanvas = document.querySelector('#history-chart');
        let historyChartInstance = null;
        const selectedUrlIds = new Set();
        let currentPageIds = [];
        const trendChartCanvas = document.querySelector('#trend-chart');
        let trendChartInstance = null;
        const issuesStatus = document.getElementById('issues-status');
        const issueSearchInput = document.getElementById('issue-search');
        const issueModal = document.getElementById('issue-modal');
        const issueModalTitle = document.getElementById('issue-modal-title');
        const issueModalSummary = document.getElementById('issue-modal-summary');
        const issueModalTableBody = document.querySelector('#issue-modal-table tbody');
        const issueDocContainer = document.getElementById('issue-doc');
        const issueDocBtn = document.getElementById('issue-doc-btn');
        const issueDocContent = document.getElementById('issue-doc-content');
        const issuePagesDocContainer = document.getElementById('issue-pages-doc');
        const pageIssueDetailTitle = document.getElementById('page-issue-detail-title');
        const pageIssueDetailSummary = document.getElementById('page-issue-detail-summary');
        const pageIssueDetailTableBody = document.querySelector('#page-issue-detail-table tbody');
        const issueImpactCategory = document.getElementById('issue-impact-category');
        const showSuppressedInput = document.getElementById('show-suppressed');
        const issuesImpactTableBody = document.querySelector('#issues-impact-table tbody');
        const issuesSection = document.getElementById('issues-section');
        const issuePagesModal = document.getElementById('issue-pages-modal');
        const issuePagesTitle = document.getElementById('issue-pages-title');
        const issuePagesSummary = document.getElementById('issue-pages-summary');
        const issuePagesTableBody = document.querySelector('#issue-pages-table tbody');
        const errorsTableBody = document.querySelector('#errors-table tbody');
        const errorsStatus = document.getElementById('errors-status');
        const reporttypeSelect = document.getElementById('reporttype-select');
        const reporttypeNote = document.getElementById('reporttype-note');
        const saveConfigBtn = document.getElementById('save-config-btn');
        const apiKeyInput = document.getElementById('api-key-input');
        const apiKeyStatus = document.getElementById('api-key-status');
        const clearApiKeyBtn = document.getElementById('clear-api-key-btn');
        const retryAttemptsInput = document.getElementById('retry-attempts-input');
        const retryDelayInput = document.getElementById('retry-delay-input');
        const viewportTableBody = document.querySelector('#viewport-table tbody');
        const addViewportBtn = document.getElementById('add-viewport-btn');
        const viewportStatus = document.getElementById('viewport-status');
        const projectSelect = document.getElementById('project-select');
        const newProjectBtn = document.getElementById('new-project-btn');
        const projectSettingsBtn = document.getElementById('project-settings-btn');
        const projectNameInput = document.getElementById('project-name-input');
        const projectSlugInput = document.getElementById('project-slug-input');
        const projectSettingsStatus = document.getElementById('project-settings-status');
        const saveProjectBtn = document.getElementById('save-project-btn');
        const saveProjectAllBtn = document.getElementById('save-project-all-btn');
        const deleteProjectBtn = document.getElementById('delete-project-btn');
        const reportViewportBtn = document.getElementById('report-viewport-btn');
        const reportViewportSummary = document.getElementById('report-viewport-summary');
        const reportViewportList = document.getElementById('report-viewport-list');
        const reportViewportSaveBtn = document.getElementById('report-viewport-save-btn');
        const auditViewportList = document.getElementById('audit-viewport-list');
        const auditCreditEstimate = document.getElementById('audit-credit-estimate');
        const auditConfirmBtn = document.getElementById('audit-confirm-btn');
        const pageIssuesModal = document.getElementById('page-issues-modal');
        const pageIssuesTitle = document.getElementById('page-issues-title');
        const pageIssuesSummary = document.getElementById('page-issues-summary');
        const pageIssuesTableBody = document.querySelector('#page-issues-table tbody');
        const searchInput = document.getElementById('search');
        const tagFilter = document.getElementById('tag-filter');
        const paginationEl = document.getElementById('pagination');
        const queueBtn = document.getElementById('queue-btn');
        const queueTableBody = document.querySelector('#queue-table tbody');
        const queueSummary = document.getElementById('queue-summary');
        const queueRefreshBtn = document.getElementById('queue-refresh-btn');
        const queueClearBtn = document.getElementById('queue-clear-btn');
        const workspaceBtn = document.getElementById('workspace-btn');
        const drawer = document.getElementById('workspace-drawer');
        const drawerBackdrop = document.getElementById('drawer-backdrop');
        const drawerCloseBtn = document.getElementById('drawer-close-btn');
        const queueDrawer = document.getElementById('queue-drawer');
        const queueDrawerCloseBtn = document.getElementById('queue-drawer-close-btn');
        const drawerProject = document.getElementById('drawer-project');
        const drawerQueue = document.getElementById('drawer-queue');
        const suppressionTableBody = document.querySelector('#suppression-table tbody');
        const suppressionElementsTableBody = document.querySelector('#suppression-elements-table tbody');
        const suppressionStatus = document.getElementById('suppression-status');
        const suppressionRefreshBtn = document.getElementById('suppression-refresh-btn');
        const recountBtn = document.getElementById('recount-btn');
        const wsStatus = document.getElementById('ws-status');
        const buttons = Array.from(document.querySelectorAll('button'));
        const overlay = document.getElementById('overlay');
        const overlayText = document.getElementById('overlay-text');
        const overlayBar = document.querySelector('#progress-bar div');
        const exportIssuesModal = document.getElementById('export-issues-modal');
        const exportIssuesCategory = document.getElementById('export-issues-category');
        const exportIssuesUrl = document.getElementById('export-issues-url');
        const exportIssuesItem = document.getElementById('export-issues-item');
        const exportIssuesSuppressed = document.getElementById('export-issues-suppressed');
        const exportIssuesConfirm = document.getElementById('export-issues-confirm');
        const exportIssuesNote = document.getElementById('export-issues-note');
        const issueDocCache = {};
        let processing = false;
        let clearApiKey = false;
        let wsRefreshTimer = null;
        let activeIssueItem = null;
        let activePageIssue = null;

        function fmt(ts) {
            if (!ts) return '--';
            const d = new Date(ts);
            if (Number.isNaN(d.getTime())) return ts;
            return `${d.toLocaleDateString(undefined, { day:'2-digit', month:'2-digit', year:'2-digit' })} - ${d.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' })}`;
        }

        function escAttr(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function escHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function reportTypeCredits(reportType) {
            if (reportType === 1) return 1;
            if (reportType === 2) return 2;
            if (reportType === 3) return 3;
            if (reportType === 4) return 3;
            return 4;
        }

        function applyReportType() {
            if (issuesSection) {
                issuesSection.style.display = api.reportType >= 2 ? '' : 'none';
            }
            const exportBtn = document.getElementById('export-issues-btn');
            if (exportBtn) exportBtn.disabled = api.reportType < 2;
            const planBtn = document.getElementById('export-plan-btn');
            if (planBtn) planBtn.disabled = api.reportType < 2;
            if (issueImpactCategory) issueImpactCategory.disabled = api.reportType < 2;
            if (issueSearchInput) issueSearchInput.disabled = api.reportType < 2;
            if (reporttypeNote) {
                if (api.reportType < 2) {
                    reporttypeNote.textContent = 'Report type 1 only returns summary counts. Issue details are unavailable.';
                } else if (api.reportType < 3) {
                    reporttypeNote.textContent = 'Report type 2 returns item counts, but no element locations.';
                } else if (api.reportType < 4) {
                    reporttypeNote.textContent = 'Report type 3 returns XPath element locations (no CSS selectors).';
                } else {
                    reporttypeNote.textContent = 'Report type 4 returns CSS selectors with contrast data.';
                }
            }
        }

        function loadConfig() {
            return fetch('/api/config')
                .then(r => r.json())
                .then(data => {
                    const reportType = Number(data.data?.reporttype || 4);
                    api.reportType = Number.isFinite(reportType) ? reportType : 4;
                    if (reporttypeSelect) reporttypeSelect.value = String(api.reportType);
                    if (retryAttemptsInput) retryAttemptsInput.value = data.data?.retry_attempts ?? '';
                    if (retryDelayInput) retryDelayInput.value = data.data?.retry_delay_ms ?? '';
                    if (apiKeyInput) apiKeyInput.value = '';
                    if (apiKeyStatus) {
                        apiKeyStatus.textContent = data.data?.api_key_configured ? 'API key configured.' : 'API key not set.';
                    }
                    clearApiKey = false;
                    applyReportType();
                });
        }

        function loadProjects() {
            if (!projectSelect) return Promise.resolve();
            return fetch('/api/projects')
                .then(r => r.json())
                .then(data => {
                    const projects = data.data || [];
                    const currentId = data.current_project_id;
                    api.projects = projects;
                    projectSelect.innerHTML = '';
                    projects.forEach(project => {
                        const opt = document.createElement('option');
                        opt.value = project.id;
                        opt.textContent = project.name;
                        if (Number(currentId) === Number(project.id)) {
                            opt.selected = true;
                        }
                        projectSelect.appendChild(opt);
                    });
                });
        }

        function getStoredReportViewports() {
            try {
                const stored = JSON.parse(localStorage.getItem('reportViewports') || '[]');
                return Array.isArray(stored) ? stored : [];
            } catch (err) {
                return [];
            }
        }

        function selectedReportViewports() {
            const stored = getStoredReportViewports();
            if (!stored.length) {
                return (api.viewports || []).map(vp => vp.label);
            }
            return stored;
        }

        function reportViewportParam() {
            const selected = selectedReportViewports();
            if (!selected.length) return '';
            return selected.join(',');
        }

        function updateReportViewportSummary() {
            if (!reportViewportSummary) return;
            const selected = selectedReportViewports();
            reportViewportSummary.textContent = selected.length ? selected.join(', ') : 'All viewports';
        }

        function renderReportViewportList() {
            if (!reportViewportList) return;
            reportViewportList.innerHTML = '';
            const selected = new Set(selectedReportViewports());
            (api.viewports || []).forEach(vp => {
                const row = document.createElement('label');
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.gap = '8px';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = vp.label;
                checkbox.checked = selected.has(vp.label);
                row.appendChild(checkbox);
                const label = document.createElement('span');
                label.textContent = vp.label;
                row.appendChild(label);
                reportViewportList.appendChild(row);
            });
        }

        function currentProject() {
            const id = projectSelect?.value;
            if (!id || !api.projects) return null;
            return api.projects.find(p => String(p.id) === String(id)) || null;
        }

        function reloadProjectData() {
            return loadConfig()
                .catch(() => applyReportType())
                .finally(() => {
                    loadTags();
                    loadUrls();
                    loadQueueStats();
                    loadTrends();
                    loadIssues();
                    loadErrors();
                    loadUniqueErrorsMetric();
                    loadViewports();
                });
        }

        function renderViewports() {
            if (!viewportTableBody) return;
            viewportTableBody.innerHTML = '';
            const rows = api.viewports || [];
            if (!rows.length) {
                viewportTableBody.innerHTML = '<tr><td colspan="5" class="muted">No viewports configured.</td></tr>';
                return;
            }
            rows.forEach(vp => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><input data-field="label" value="${escAttr(vp.label || '')}" ${vp.label ? 'readonly' : ''} style="width:100%;" /></td>
                    <td><input data-field="viewport_width" type="number" min="0" value="${vp.viewport_width ?? ''}" style="width:100px;" /></td>
                    <td><input data-field="eval_delay" type="number" min="0" value="${vp.eval_delay ?? ''}" style="width:90px;" /></td>
                    <td><input data-field="user_agent" value="${escAttr(vp.user_agent ?? '')}" style="width:100%;" /></td>
                    <td>
                        <div style="display:flex; gap:6px; justify-content:flex-end;">
                            <button class="secondary icon-btn" data-action="save-viewport" title="Save"><i class="fa-solid fa-floppy-disk"></i></button>
                            <button class="secondary icon-btn" data-action="delete-viewport" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                `;
                tr.dataset.label = vp.label || '';
                viewportTableBody.appendChild(tr);
            });
            if (viewportStatus) {
                viewportStatus.textContent = `${rows.length} viewport(s) configured.`;
            }
        }

        function loadViewports() {
            return fetch('/api/viewports')
                .then(r => r.json())
                .then(data => {
                    api.viewports = data.data || [];
                    renderViewports();
                    const stored = getStoredReportViewports();
                    const validStored = stored.filter(label => api.viewports.some(vp => vp.label === label));
                    const selection = validStored.length ? validStored : api.viewports.map(vp => vp.label);
                    localStorage.setItem('reportViewports', JSON.stringify(selection));
                    renderReportViewportList();
                    updateReportViewportSummary();
                    renderAuditViewportList();
                });
        }

        function renderUrls(list) {
            if (!list) list = [];
            const totalPages = Math.max(1, Math.ceil(list.length / api.pageSize));
            if (api.page > totalPages) api.page = totalPages;
            const start = (api.page - 1) * api.pageSize;
            const pageRows = list.slice(start, start + api.pageSize);
            currentPageIds = pageRows.map(row => Number(row.id));
            urlTableBody.innerHTML = '';
            pageRows.forEach(row => {
                const tr = document.createElement('tr');
                const lastTest = fmt(row.last_test_at);
                const isStale = row.last_test_at ? ((Date.now() - new Date(row.last_test_at).getTime()) > 7 * 24 * 3600 * 1000) : true;
                const hasError = row.last_test_status === 'failed';
                const errorTitle = row.last_error_message ? escAttr(row.last_error_message) : 'Last test failed';
                const errorBadge = hasError ? `<span class="badge" style="margin-left:6px;background:rgba(192,60,60,0.2);" title="${errorTitle}">Error</span>` : '';
                const viewportBadge = row.last_viewport_label ? `<span class="badge" style="margin-left:6px;">${escHtml(row.last_viewport_label)}</span>` : '';
                const issuesButton = api.reportType >= 2
                    ? `<button class="secondary icon-btn with-label" title="Issues" data-action="page-issues" data-id="${row.id}" data-url="${row.url}">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span style="font-size:10px; letter-spacing:1px; margin-left:6px;">ISSUES</span>
                       </button>`
                    : '';
                const isSelected = selectedUrlIds.has(Number(row.id));
                const isQueued = api.queueUrlIds && api.queueUrlIds.has(Number(row.id));
                const reportIcon = row.last_report_url
                    ? `<a href="${row.last_report_url}" target="_blank" class="icon-btn" style="padding:4px 6px;" title="Open report"><i class="fa-solid fa-link"></i></a>`
                    : '';
                const tagCell = row.tags && row.tags.length ? row.tags.map(t => `<span class="badge">${escHtml(t)}</span>`).join('') : '--';
                tr.innerHTML = `
                    <td style="text-align:center;">
                        <input type="checkbox" class="url-select" data-id="${row.id}" ${isSelected ? 'checked' : ''} />
                    </td>
                    <td>
                        <span style="display:inline-flex; align-items:center; gap:6px; max-width:500px;">
                            <i class="fa-solid ${row.active ? 'fa-circle-check' : 'fa-circle-xmark'}" style="color:${row.active ? '#A6E04E' : '#C09C3A'};"></i>
                            <a href="${row.url}" target="_blank" style="display:inline-block; max-width:460px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${row.url}">${row.url}</a>
                            ${reportIcon}
                        </span>
                    </td>
                    <td>${tagCell}</td>
                    <td>${row.last_aim_score ?? '--'}</td>
                    <td>${row.last_errors ?? '--'}</td>
                    <td>${row.last_contrast_errors ?? '--'}</td>
                    <td>${row.last_alerts ?? '--'}</td>
                    <td>${lastTest} ${isStale ? '<span class="badge" style="margin-left:6px;">Stale</span>' : ''} ${viewportBadge} ${errorBadge}</td>
                    <td style="text-align:right;">
                        <div style="display:flex; gap:6px; justify-content:flex-end;">
                        <button class="secondary icon-btn with-label" title="${isQueued ? 'Queued' : 'Test'}" data-action="test" data-id="${row.id}" data-url="${row.url}" ${isQueued ? 'disabled' : ''}>
                            <i class="fa-solid fa-flask"></i>
                            <span style="font-size:10px; letter-spacing:1px; margin-left:6px;">TEST</span>
                        </button>
                        ${issuesButton}
                        <button class="secondary icon-btn" title="${row.active ? 'Deactivate' : 'Activate'}" data-action="toggle" data-id="${row.id}" data-active="${row.active ? 1 : 0}">
                            <i class="fa-solid ${row.active ? 'fa-toggle-on' : 'fa-toggle-off'}"></i>
                        </button>
                        <button class="secondary icon-btn" title="Delete" data-action="delete" data-id="${row.id}">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                        </div>
                    </td>
                `;
                tr.addEventListener('click', (e) => {
                    if (e.target.closest('button') || e.target.closest('input')) return;
                    api.selectedId = row.id;
                    showHistory(row.id);
                });
                urlTableBody.appendChild(tr);
            });
            syncSelectAllState();
            updateBulkActions();
            renderPagination(totalPages);
        }

        function filteredUrls() {
            const term = searchInput.value.toLowerCase();
            return api.urls.filter(u => u.url.toLowerCase().includes(term));
        }

        function drawTrends(data) {
            if (!trendChartCanvas) return;
            if (trendChartInstance) {
                trendChartInstance.destroy();
                trendChartInstance = null;
            }
            // Clamp canvas size to avoid oversizing issues
            const parentWidth = trendChartCanvas.parentElement ? trendChartCanvas.parentElement.clientWidth : 800;
            trendChartCanvas.width = parentWidth || 800;
            trendChartCanvas.height = 220;
            const enabled = {
                aim: document.getElementById('toggle-aim').checked,
                errors: document.getElementById('toggle-errors').checked,
                unique: document.getElementById('toggle-unique').checked,
                uniqueContrast: document.getElementById('toggle-unique-contrast').checked,
                uniqueAlerts: document.getElementById('toggle-unique-alerts').checked,
                contrast: document.getElementById('toggle-contrast').checked,
                alerts: document.getElementById('toggle-alerts').checked
            };
            const series = [
                { key: 'avg_aim', label: 'Avg AIM', color: '#A6E04E', enabled: enabled.aim },
                { key: 'unique_errors', label: 'Unique Errors', color: '#5A5856', enabled: enabled.unique },
                { key: 'unique_contrast_errors', label: 'Unique Contrast', color: '#C09C3A', enabled: enabled.uniqueContrast },
                { key: 'unique_alerts', label: 'Unique Alerts', color: '#A8E3D8', enabled: enabled.uniqueAlerts },
                { key: 'errors', label: 'Errors', color: '#3C2484', enabled: enabled.errors },
                { key: 'contrast_errors', label: 'Contrast', color: '#C09C3A', enabled: enabled.contrast },
                { key: 'alerts', label: 'Alerts', color: '#A8E3D8', enabled: enabled.alerts }
            ].filter(s => s.enabled);
            if (!data.length || !series.length) return;
            const safeVal = (v) => Number.isFinite(Number(v)) ? Number(v) : null;
            const rows = data; // already ASC from API
            const labels = [];
            const aimSeries = [];
            const uniqSeries = [];
            const uniqConSeries = [];
            const uniqAlertSeries = [];
            const errSeries = [];
            const conSeries = [];
            const alertSeries = [];

            rows.forEach((r) => {
                aimSeries.push(safeVal(r.aim_score));
                uniqSeries.push(safeVal(r.unique_errors) || 0);
                uniqConSeries.push(safeVal(r.unique_contrast_errors) || 0);
                uniqAlertSeries.push(safeVal(r.unique_alerts) || 0);
                errSeries.push(safeVal(r.errors) || 0);
                conSeries.push(safeVal(r.contrast_errors) || 0);
                alertSeries.push(safeVal(r.alerts) || 0);
                const labelValue = r.run_started || r.day || r.tested_at || r.created_at || '';
                const d = new Date(labelValue);
                if (Number.isNaN(d.getTime())) {
                    labels.push(String(labelValue));
                } else if (r.run_started) {
                    labels.push(d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }));
                } else {
                    labels.push(d.toLocaleDateString(undefined, { month: 'short', day: '2-digit' }));
                }
            });

            const datasets = [
                { key: 'aim', label: 'Avg AIM', color: '#A6E04E', data: aimSeries, enabled: enabled.aim },
                { key: 'unique', label: 'Unique Errors', color: '#5A5856', data: uniqSeries, enabled: enabled.unique },
                { key: 'unique_contrast', label: 'Unique Contrast', color: '#C09C3A', data: uniqConSeries, enabled: enabled.uniqueContrast },
                { key: 'unique_alerts', label: 'Unique Alerts', color: '#A8E3D8', data: uniqAlertSeries, enabled: enabled.uniqueAlerts },
                { key: 'errors', label: 'Errors', color: '#3C2484', data: errSeries, enabled: enabled.errors },
                { key: 'contrast', label: 'Contrast', color: '#C09C3A', data: conSeries, enabled: enabled.contrast },
                { key: 'alerts', label: 'Alerts', color: '#A8E3D8', data: alertSeries, enabled: enabled.alerts }
            ].filter(s => s.enabled);
            trendChartInstance = new Chart(trendChartCanvas.getContext('2d'), {
                type: 'line',
                data: { labels, datasets },
                options: {
                    plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                    scales: {
                        x: { grid: { color: 'rgba(168,174,172,0.2)' } },
                        y: { type: 'logarithmic', grid: { color: 'rgba(168,174,172,0.2)' }, beginAtZero: true }
                    },
                    responsive: false,
                    maintainAspectRatio: false
                }
            });
        }

        function renderPagination(totalPages) {
            if (!paginationEl) return;
            paginationEl.innerHTML = '';
            if (totalPages <= 1) return;
            const addBtn = (label, page, disabled = false, active = false) => {
                const btn = document.createElement('button');
                btn.className = 'secondary icon-btn';
                btn.style.padding = '6px 10px';
                btn.textContent = label;
                if (active) btn.style.background = 'rgba(168,174,172,0.15)';
                btn.disabled = disabled;
                btn.addEventListener('click', () => {
                    if (page < 1 || page > totalPages) return;
                    api.page = page;
                    renderUrls(filteredUrls());
                });
                paginationEl.appendChild(btn);
            };
            addBtn('<', api.page - 1, api.page === 1);
            for (let p = 1; p <= totalPages; p++) {
                if (p === 1 || p === totalPages || Math.abs(p - api.page) <= 1) {
                    addBtn(String(p), p, false, p === api.page);
                } else if (Math.abs(p - api.page) === 2) {
                    const span = document.createElement('span');
                    span.className = 'muted';
                    span.textContent = '...';
                    paginationEl.appendChild(span);
                }
            }
            addBtn('>', api.page + 1, api.page === totalPages);
        }

        function renderAuditViewportList() {
            if (!auditViewportList) return;
            auditViewportList.innerHTML = '';
            (api.viewports || []).forEach(vp => {
                const row = document.createElement('label');
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.gap = '8px';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = vp.label;
                checkbox.checked = selectedReportViewports().includes(vp.label);
                checkbox.addEventListener('change', updateAuditEstimate);
                row.appendChild(checkbox);
                const label = document.createElement('span');
                label.textContent = vp.label;
                row.appendChild(label);
                auditViewportList.appendChild(row);
            });
            updateAuditEstimate();
        }

        function getAuditSelectedViewports() {
            if (!auditViewportList) return selectedReportViewports();
            const selected = Array.from(auditViewportList.querySelectorAll('input[type="checkbox"]:checked')).map(i => i.value);
            return selected;
        }

        function updateAuditEstimate() {
            if (!auditCreditEstimate) return;
            const activeCount = api.urls.filter(u => u.active).length;
            const viewportCount = getAuditSelectedViewports().length || 0;
            const estimatedCredits = activeCount * viewportCount * reportTypeCredits(api.reportType);
            auditCreditEstimate.textContent = viewportCount
                ? `Estimated credits: ~${estimatedCredits} for ${activeCount} active URL(s) x ${viewportCount} viewport(s).`
                : 'Select at least one viewport to run the audit.';
        }

        function updateStats(list) {
            const total = list.length;
            const aimValues = list
                .map(u => u.last_aim_score)
                .filter(v => v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v)))
                .map(v => Number(v));
            const avgAim = aimValues.length ? (aimValues.reduce((a,b)=>a+b,0) / aimValues.length).toFixed(1) : '--';
            document.getElementById('stat-total').textContent = total || '0';
            document.getElementById('stat-aim').textContent = avgAim;
            const sum = (key) => list.map(u => Number(u[key]) || 0).reduce((a,b)=>a+b,0);

            // Credits badge based on most recent tested_at across URLs
            const badge = document.getElementById('credits-badge');
            if (badge) {
                let latest = null;
                let credits = null;
                list.forEach(u => {
                    if (!u.last_test_at) return;
                    const t = new Date(u.last_test_at).getTime();
                    if (Number.isNaN(t)) return;
                    if (latest === null || t > latest) {
                        latest = t;
                        credits = u.last_credits_remaining ?? null;
                    }
                });
                badge.textContent = 'Credits: ' + (credits !== null && credits !== undefined ? credits : '--');
            }
        }

        function loadQueueStats() {
            fetch('/api/queue')
                .then(r => r.json())
                .then(data => {
                    const jobs = data.data || [];
                    updateQueuedUrls(jobs);
                    const pending = jobs.filter(j => j.status === 'pending' || j.status === 'running').length;
                    const pendingEl = document.getElementById('stat-pending');
                    if (pendingEl) pendingEl.textContent = pending || '0';
                    if (wsStatus) wsStatus.textContent = String(pending);
                });
        }

        function loadUrls() {
            const params = new URLSearchParams({
                search: searchInput.value,
                sort: api.sort.by,
                direction: api.sort.direction
            });
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            if (tagFilter && tagFilter.value) {
                params.set('tag', tagFilter.value);
            }
            fetch('/api/urls?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    api.page = 1;
                    api.urls = data.data || [];
                    const filtered = filteredUrls();
                    renderUrls(filtered);
                    updateStats(filtered);
                });
        }

        function loadTags() {
            if (!tagFilter) return;
            fetch('/api/tags')
                .then(r => r.json())
                .then(data => {
                    const tags = data.data || [];
                    const current = tagFilter.value;
                    tagFilter.innerHTML = '<option value="">All tags</option>';
                    tags.forEach(tag => {
                        const opt = document.createElement('option');
                        opt.value = tag;
                        opt.textContent = tag;
                        tagFilter.appendChild(opt);
                    });
                    tagFilter.value = current;
                });
        }

        function loadTrends() {
            const params = new URLSearchParams();
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            fetch('/api/trends' + (params.toString() ? '?' + params.toString() : ''))
                .then(r => r.json())
                .then(data => {
                    drawTrends(data.data || []);
                    if (data.metrics) {
                        applyUniqueMetrics(data.metrics);
                    }
                });
        }

        function loadUniqueErrorsMetric() {
            if (api.reportType < 3) {
                const el = document.getElementById('stat-unique');
                if (el) el.textContent = '--';
                const conEl = document.getElementById('stat-unique-contrast');
                if (conEl) conEl.textContent = '--';
                const alertEl = document.getElementById('stat-unique-alerts');
                if (alertEl) alertEl.textContent = '--';
                return;
            }
            if (api.uniqueMetrics && Date.now() - api.uniqueMetricsAt < 15000) {
                applyUniqueMetrics(api.uniqueMetrics);
                return;
            }
            const params = new URLSearchParams();
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            fetch('/api/metrics/unique-errors' + (params.toString() ? '?' + params.toString() : ''))
                .then(r => r.json())
                .then(data => {
                    applyUniqueMetrics(data.data || {});
                });
        }

        function applyUniqueMetrics(metrics) {
            const errors = metrics?.errors ?? metrics ?? null;
            const contrast = metrics?.contrast ?? null;
            const alerts = metrics?.alerts ?? null;
            if (Number.isFinite(Number(errors)) && Number.isFinite(Number(contrast)) && Number.isFinite(Number(alerts))) {
                api.uniqueMetrics = { errors: Number(errors), contrast: Number(contrast), alerts: Number(alerts) };
                api.uniqueMetricsAt = Date.now();
            }
            const el = document.getElementById('stat-unique');
            if (el) el.textContent = Number.isFinite(Number(errors)) ? errors : '--';
            const conEl = document.getElementById('stat-unique-contrast');
            if (conEl) conEl.textContent = Number.isFinite(Number(contrast)) ? contrast : '--';
            const alertEl = document.getElementById('stat-unique-alerts');
            if (alertEl) alertEl.textContent = Number.isFinite(Number(alerts)) ? alerts : '--';
        }

        function loadIssues() {
            if (api.reportType < 2) {
                api.issues = [];
                if (issuesStatus) issuesStatus.textContent = 'Report type 1 does not include issue details.';
                renderImpactIssues();
                populateExportIssueItems();
                return;
            }
            if (issuesStatus) issuesStatus.textContent = 'Loading issues...';
            const params = new URLSearchParams({ include_guidelines: '1' });
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            if (showSuppressedInput && showSuppressedInput.checked) {
                params.set('include_suppressed', '1');
            }
            fetch('/api/issues/summary?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    api.issues = data.data || [];
                    renderImpactIssues();
                    populateExportIssueItems();
                })
                .catch(() => {
                    if (issuesStatus) issuesStatus.textContent = 'Failed to load issues.';
                });
        }

        function populateExportIssueItems() {
            if (!exportIssuesItem) return;
            const current = exportIssuesItem.value;
            const categoryFilter = exportIssuesCategory?.value || '';
            exportIssuesItem.innerHTML = '<option value="">All issue types</option>';
            (api.issues || []).forEach(issue => {
                const itemId = issue.item_id || '';
                if (!itemId) return;
                if (categoryFilter && issue.category !== categoryFilter) {
                    return;
                }
                const opt = document.createElement('option');
                const desc = issue.description || itemId;
                const category = issue.category ? ` (${issue.category})` : '';
                opt.value = itemId;
                opt.textContent = `${desc}${category} • ${itemId}`;
                exportIssuesItem.appendChild(opt);
            });
            if (current && exportIssuesItem.querySelector(`option[value="${current}"]`)) {
                exportIssuesItem.value = current;
            }
        }

        function renderImpactIssues() {
            if (!issuesImpactTableBody) return;
            if (api.reportType < 2) {
                issuesImpactTableBody.innerHTML = '<tr><td colspan="6" class="muted">Issue detail requires report type 2 or higher.</td></tr>';
                return;
            }
            const cat = issueImpactCategory ? issueImpactCategory.value : '';
            const term = (issueSearchInput?.value || '').toLowerCase();
            const filtered = api.issues.filter(i => {
                const matchesCat = cat ? i.category === cat : true;
                const matchesSearch = term ? ((i.description || '').toLowerCase().includes(term) || (i.item_id || '').toLowerCase().includes(term)) : true;
                return matchesCat && matchesSearch;
            });
            const list = filtered.slice().sort((a,b) => (b.total_count||0) - (a.total_count||0));
            issuesImpactTableBody.innerHTML = '';
            list.forEach(item => {
                const tr = document.createElement('tr');
                const wcag = Array.isArray(item.guidelines) && item.guidelines.length
                    ? item.guidelines.map(g => escHtml(g)).join(', ')
                    : '--';
                const suppressed = !!item.suppressed;
                const suppressLabel = suppressed ? 'Restore' : 'Suppress';
                const suppressClass = suppressed ? 'secondary' : '';
                tr.innerHTML = `
                    <td>${item.description || item.item_id}${suppressed ? ' <span class="badge">Suppressed</span>' : ''}</td>
                    <td>${wcag}</td>
                    <td>${item.category}</td>
                    <td>${item.total_count ?? '--'}</td>
                    <td>${item.url_count ?? '--'}</td>
                    <td>${item.unique_selectors ?? '--'}</td>
                    <td><button class="${suppressClass}" data-action="toggle-suppress" data-item="${escAttr(item.item_id)}" data-category="${escAttr(item.category)}">${suppressLabel}</button></td>
                `;
                tr.addEventListener('click', (e) => {
                    if (e.target.closest('button')) return;
                    openIssuePagesModal(item);
                });
                issuesImpactTableBody.appendChild(tr);
            });
            if (!list.length) {
                issuesImpactTableBody.innerHTML = '<tr><td colspan="7" class="muted">No issues found.</td></tr>';
            }
            if (issuesStatus) issuesStatus.textContent = `${list.length} issue type(s) found`;
        }

        function renderDocInfo(doc, target) {
            if (!target) return;
            if (!doc) {
                target.innerHTML = '';
                return;
            }
            const guidelines = (doc.guidelines || []).map(g => `<div><a target="_blank" href="${g.link}">${g.name}</a></div>`).join('') || '--';
            target.innerHTML = `
                <div style="margin-top:6px; display:grid; gap:6px;">
                    <div><strong>Purpose:</strong><br>${doc.purpose || '--'}</div>
                    <div><strong>Details:</strong><br>${doc.details || '--'}</div>
                    <div><strong>Recommended action:</strong><br>${doc.actions || '--'}</div>
                    <div><strong>Guidelines:</strong><br>${guidelines}</div>
                </div>
            `;
        }

        function loadIssueDoc(itemId, target) {
            if (!target) return;
            if (issueDocCache[itemId]) {
                renderDocInfo(issueDocCache[itemId], target);
                return;
            }
            target.innerHTML = 'Loading guidance...';
            fetch('/api/issues/doc?item_id=' + encodeURIComponent(itemId))
                .then(r => r.json())
                .then(data => {
                    const doc = data.data || null;
                    if (doc) issueDocCache[itemId] = doc;
                    renderDocInfo(doc, target);
                })
                .catch(() => {
                    target.innerHTML = '';
                });
        }

        function openIssueModal(item) {
            if (!item) return;
            activeIssueItem = item;
            if (api.reportType < 3) {
                issueModalTitle.textContent = item.description || item.item_id;
                issueModalSummary.textContent = 'Element-level details require report type 3 or 4.';
                issueModalTableBody.innerHTML = '<tr><td colspan="4" class="muted">No element details available.</td></tr>';
                showModal('#issue-modal');
                return;
            }
            issueModalTitle.textContent = item.description || item.item_id;
            const uniqueText = (item.unique_selectors ?? 0) + ' unique selector(s)';
            issueModalSummary.textContent = `${item.category.toUpperCase()} * ${item.url_count} page(s) * ${item.total_count} instance(s) * ${uniqueText}`;
            issueModalTableBody.innerHTML = '<tr><td colspan="3" class="muted">Loading...</td></tr>';
            if (issueDocContent) issueDocContent.style.display = 'none';
            if (issueDocBtn) issueDocBtn.style.display = 'inline-block';
            if (issueDocContent) issueDocContent.innerHTML = '';

            const params = new URLSearchParams({
                item_id: item.item_id,
                category: item.category
            });
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }

            fetch('/api/issues/details?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    issueModalTableBody.innerHTML = '';
                    rows.forEach(row => {
                        (row.elements || []).forEach(elem => {
                            const contrastText = elem.contrast_ratio != null
                                ? `${Number(elem.contrast_ratio).toFixed(2)} (${elem.foreground_color} on ${elem.background_color}${elem.large_text ? ', large text' : ''})`
                                : '--';
                            const viewportLabel = elem.viewport_label || 'default';
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td><a href="${row.url}" target="_blank">${row.url}</a></td>
                                <td><code>${elem.selector}</code></td>
                                <td>${contrastText}</td>
                                <td><button class="secondary" data-action="toggle-suppress-element" data-url-id="${row.url_id}" data-item="${escAttr(item.item_id)}" data-category="${escAttr(item.category)}" data-selector="${escAttr(elem.selector)}" data-viewport="${escAttr(viewportLabel)}">Suppress</button></td>
                            `;
                            issueModalTableBody.appendChild(tr);
                        });
                    });
                    if (!rows.length) {
                        issueModalTableBody.innerHTML = '<tr><td colspan="4" class="muted">No element details recorded.</td></tr>';
                    }
                });

            showModal('#issue-modal');
            if (issueDocBtn) {
                issueDocBtn.onclick = () => {
                    issueDocBtn.style.display = 'none';
                    if (issueDocContent) issueDocContent.style.display = 'block';
                    loadIssueDoc(item.item_id, issueDocContent || issueDocContainer);
                };
            }
        }

        function openIssuePagesModal(item) {
            if (!item || !issuePagesModal) return;
            activeIssueItem = item;
            issuePagesTitle.textContent = item.description || item.item_id;
            issuePagesSummary.textContent = `${item.category.toUpperCase()} * ${item.total_count} occurrences across ${item.url_count} page(s)`;
            issuePagesTableBody.innerHTML = '<tr><td colspan="3" class="muted">Loading...</td></tr>';
            if (issuePagesDocContainer) {
                issuePagesDocContainer.innerHTML = '<button class="secondary" id="issue-pages-doc-btn" style="padding:6px 10px;">Explain this to me</button><div class="muted" id="issue-pages-doc-content" style="margin-top:6px; display:none;"></div>';
            }
            const params = new URLSearchParams({ item_id: item.item_id, category: item.category });
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            fetch('/api/issues/pages?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    issuePagesTableBody.innerHTML = '';
                    rows.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><a href="${row.url}" target="_blank">${row.url}</a></td>
                            <td>${row.count ?? '--'}</td>
                            <td>${row.last_report_url ? `<a target="_blank" href="${row.last_report_url}">Report</a>` : '--'}</td>
                        `;
                        issuePagesTableBody.appendChild(tr);
                    });
                    if (!rows.length) {
                        issuePagesTableBody.innerHTML = '<tr><td colspan="3" class="muted">No pages found for this issue.</td></tr>';
                    }
                });
            showModal('#issue-pages-modal');
            const btn = document.getElementById('issue-pages-doc-btn');
            const content = document.getElementById('issue-pages-doc-content');
            if (btn) {
                btn.onclick = () => {
                    btn.style.display = 'none';
                    if (content) content.style.display = 'block';
                    loadIssueDoc(item.item_id, content || issuePagesDocContainer);
                };
            }
        }

        function loadHistory(id) {
            historyBody.innerHTML = '';
            const params = new URLSearchParams();
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            fetch(`/api/urls/${id}/history` + (params.toString() ? `?${params.toString()}` : ''))
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    document.getElementById('history-summary').textContent = rows.length ? `History for URL #${id}` : 'No history yet.';
                    rows.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${fmt(row.tested_at)}</td>
                            <td>${row.viewport_label || 'default'}</td>
                            <td>${row.aim_score ?? '--'}</td>
                            <td>${row.errors ?? '--'}</td>
                            <td>${row.contrast_errors ?? '--'}</td>
                            <td>${row.alerts ?? '--'}</td>
                        `;
                        historyBody.appendChild(tr);
                    });
                    drawChart(rows);
                });
        }

        function drawChart(rows) {
            if (!historyChartCanvas) return;
            if (historyChartInstance) {
                historyChartInstance.destroy();
                historyChartInstance = null;
            }
            if (!rows.length) return;
            historyChartCanvas.width = historyChartCanvas.parentElement ? historyChartCanvas.parentElement.clientWidth : 600;
            historyChartCanvas.height = 190;
            const labels = rows.slice().reverse().map(r => fmt(r.tested_at));
            const aimData = rows.slice().reverse().map(r => Number.isFinite(Number(r.aim_score)) ? Number(r.aim_score) : null);
            const errData = rows.slice().reverse().map(r => Number.isFinite(Number(r.errors)) ? Number(r.errors) : null);
            const conData = rows.slice().reverse().map(r => Number.isFinite(Number(r.contrast_errors)) ? Number(r.contrast_errors) : null);
            const alertData = rows.slice().reverse().map(r => Number.isFinite(Number(r.alerts)) ? Number(r.alerts) : null);
            historyChartInstance = new Chart(historyChartCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'AIM', borderColor: '#A6E04E', backgroundColor: '#A6E04E', data: aimData, spanGaps: true, tension: 0.25 },
                        { label: 'Errors', borderColor: '#3C2484', backgroundColor: '#3C2484', data: errData, spanGaps: true, tension: 0.25 },
                        { label: 'Contrast', borderColor: '#C09C3A', backgroundColor: '#C09C3A', data: conData, spanGaps: true, tension: 0.25 },
                        { label: 'Alerts', borderColor: '#A8E3D8', backgroundColor: '#A8E3D8', data: alertData, spanGaps: true, tension: 0.25 }
                    ]
                },
                options: {
                    plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                    scales: {
                        x: { grid: { color: 'rgba(168,174,172,0.2)' } },
                        y: { type: 'logarithmic', grid: { color: 'rgba(168,174,172,0.2)' }, beginAtZero: true }
                    },
                    responsive: false,
                    maintainAspectRatio: false
                }
            });
        }

        function showHistory(id) {
            historyBody.innerHTML = '';
            document.getElementById('history-summary').textContent = 'Loading...';
            document.querySelector('#history-modal').style.display = 'flex';
            const params = new URLSearchParams();
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            fetch(`/api/urls/${id}/history` + (params.toString() ? `?${params.toString()}` : ''))
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    document.getElementById('history-summary').textContent = rows.length ? `History for URL #${id}` : 'No history yet.';
                    rows.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${fmt(row.tested_at)}</td>
                            <td>${row.viewport_label || 'default'}</td>
                            <td>${row.aim_score ?? '--'}</td>
                            <td>${row.errors ?? '--'}</td>
                            <td>${row.contrast_errors ?? '--'}</td>
                            <td>${row.alerts ?? '--'}</td>
                        `;
                        historyBody.appendChild(tr);
                    });
                    drawChart(rows);
                });
        }

        function showPageIssues(id, url) {
            if (!pageIssuesModal) return;
            pageIssuesTitle.textContent = `Issues for URL #${id}`;
            pageIssuesSummary.textContent = url || '';
            pageIssuesTableBody.innerHTML = '<tr><td colspan="4" class="muted">Loading...</td></tr>';
            activePageIssue = null;
            pageIssueDetailTitle.textContent = 'Select an issue';
            pageIssueDetailSummary.textContent = 'Pick an issue to see selectors.';
            pageIssueDetailTableBody.innerHTML = '<tr><td colspan="3" class="muted">No issue selected.</td></tr>';
            showModal('#page-issues-modal');

            const params = new URLSearchParams();
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            fetch(`/api/urls/${id}/issues` + (params.toString() ? `?${params.toString()}` : ''))
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    pageIssuesTableBody.innerHTML = '';
                    rows.forEach(r => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${r.category}</td>
                            <td>${r.description || r.item_id}</td>
                            <td>${r.count}</td>
                            <td>${r.unique_selectors ?? '--'}</td>
                        `;
                        tr.addEventListener('click', () => showPageIssueDetail(id, url, r));
                        pageIssuesTableBody.appendChild(tr);
                    });
                    if (!rows.length) {
                        pageIssuesTableBody.innerHTML = '<tr><td colspan="4" class="muted">No issues recorded.</td></tr>';
                    }
                });
        }

        function showPageIssueDetail(urlId, url, issue) {
            activePageIssue = { urlId, url, issue };
            if (api.reportType < 3) {
                pageIssueDetailTitle.textContent = 'Select an issue';
                pageIssueDetailSummary.textContent = 'Element-level details require report type 3 or 4.';
                pageIssueDetailTableBody.innerHTML = '<tr><td colspan="3" class="muted">No element details available.</td></tr>';
                return;
            }
            pageIssueDetailTitle.textContent = issue.description || issue.item_id;
            pageIssueDetailSummary.textContent = `${url} * ${issue.category.toUpperCase()} * ${issue.count} instance(s)`;
            pageIssueDetailTableBody.innerHTML = '<tr><td colspan="3" class="muted">Loading...</td></tr>';
            const params = new URLSearchParams({ item_id: issue.item_id, category: issue.category });
            const viewports = reportViewportParam();
            if (viewports) {
                params.set('viewports', viewports);
            }
            fetch(`/api/urls/${urlId}/issues/details?` + params.toString())
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    pageIssueDetailTableBody.innerHTML = '';
                    rows.forEach(el => {
                        const contrastText = el.contrast_ratio != null
                            ? `${Number(el.contrast_ratio).toFixed(2)} (${el.foreground_color} on ${el.background_color}${el.large_text ? ', large' : ''})`
                            : '--';
                        const viewportLabel = el.viewport_label || 'default';
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><code>${el.selector}</code></td>
                            <td>${contrastText}</td>
                            <td><button class="secondary" data-action="toggle-suppress-element" data-url-id="${urlId}" data-item="${escAttr(issue.item_id)}" data-category="${escAttr(issue.category)}" data-selector="${escAttr(el.selector)}" data-viewport="${escAttr(viewportLabel)}">Suppress</button></td>
                        `;
                        pageIssueDetailTableBody.appendChild(tr);
                    });
                    if (!rows.length) {
                        pageIssueDetailTableBody.innerHTML = '<tr><td colspan="3" class="muted">No selectors recorded.</td></tr>';
                    }
                });
        }

        urlTableBody.addEventListener('click', (e) => {
            const btn = e.target.closest('button');
            if (!btn) return;
            const id = btn.dataset.id;
            const action = btn.dataset.action;
            const btnUrl = btn.dataset.url || '';
            if (action === 'test') {
                fetch('/api/tests/run', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mode: 'single', url_id: id, viewports: selectedReportViewports() })
                }).then(r => r.json()).then(res => {
                    loadQueueStats();
                    setTimeout(() => {
                        loadUrls();
                        loadIssues();
                        loadErrors();
                        loadUniqueErrorsMetric();
                        if (api.selectedId) loadHistory(api.selectedId);
                    }, 500);
                });
            }
            if (action === 'page-issues') {
                showPageIssues(id, btnUrl);
            }
            if (action === 'delete') {
                if (!confirm('Remove this URL?')) return;
                fetch(`/api/urls/${id}`, { method: 'DELETE' }).then(() => loadUrls());
            }
            if (action === 'toggle') {
                const active = btn.dataset.active === '1';
                fetch(`/api/urls/${id}/toggle`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ active: !active })
                }).then(() => loadUrls());
            }
        });

        urlTableBody.addEventListener('change', (e) => {
            const checkbox = e.target.closest('input.url-select');
            if (!checkbox) return;
            const id = Number(checkbox.dataset.id);
            if (checkbox.checked) {
                selectedUrlIds.add(id);
            } else {
                selectedUrlIds.delete(id);
            }
            syncSelectAllState();
            updateBulkActions();
        });

        function syncSelectAllState() {
            if (!urlSelectAll) return;
            if (!currentPageIds.length) {
                urlSelectAll.checked = false;
                urlSelectAll.indeterminate = false;
                return;
            }
            const selectedCount = currentPageIds.filter(id => selectedUrlIds.has(id)).length;
            urlSelectAll.checked = selectedCount === currentPageIds.length;
            urlSelectAll.indeterminate = selectedCount > 0 && selectedCount < currentPageIds.length;
        }

        function updateBulkActions() {
            if (!bulkActions) return;
            const count = selectedUrlIds.size;
            bulkCount.textContent = `${count} selected`;
            bulkActions.style.display = count ? 'flex' : 'none';
            const disabled = count === 0;
            [bulkActivateBtn, bulkDeactivateBtn, bulkTagBtn, bulkDeleteBtn, bulkClearBtn].forEach(btn => {
                if (btn) btn.disabled = disabled;
            });
        }

        if (urlSelectAll) {
            urlSelectAll.addEventListener('change', () => {
                if (!currentPageIds.length) return;
                if (urlSelectAll.checked) {
                    currentPageIds.forEach(id => selectedUrlIds.add(id));
                } else {
                    currentPageIds.forEach(id => selectedUrlIds.delete(id));
                }
                renderUrls(filteredUrls());
            });
        }

        function bulkRequest(action, extra = {}) {
            const ids = Array.from(selectedUrlIds);
            if (!ids.length) return Promise.resolve();
            return fetch('/api/urls/bulk', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ids, ...extra })
            }).then(r => r.json());
        }

        if (bulkActivateBtn) {
            bulkActivateBtn.addEventListener('click', () => {
                bulkRequest('activate').then(() => {
                    selectedUrlIds.clear();
                    loadUrls();
                });
            });
        }

        if (bulkDeactivateBtn) {
            bulkDeactivateBtn.addEventListener('click', () => {
                bulkRequest('deactivate').then(() => {
                    selectedUrlIds.clear();
                    loadUrls();
                });
            });
        }

        if (bulkTagBtn) {
            bulkTagBtn.addEventListener('click', () => {
                const input = prompt('Set label(s). Use a single value for one label, or comma-separated for multiple.', '');
                if (input === null) return;
                const tags = input
                    .split(',')
                    .map(tag => tag.trim())
                    .filter(Boolean);
                bulkRequest('set_tags', { tags }).then(() => {
                    selectedUrlIds.clear();
                    loadUrls();
                    loadTags();
                });
            });
        }

        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', () => {
                const count = selectedUrlIds.size;
                if (!count) return;
                if (!confirm(`Delete ${count} URL(s)? This cannot be undone.`)) return;
                bulkRequest('delete').then(() => {
                    selectedUrlIds.clear();
                    loadUrls();
                });
            });
        }

        if (bulkClearBtn) {
            bulkClearBtn.addEventListener('click', () => {
                selectedUrlIds.clear();
                renderUrls(filteredUrls());
            });
        }

        if (pageSizeSelect) {
            const preset = String(api.pageSize);
            if (pageSizeSelect.querySelector(`option[value="${preset}"]`)) {
                pageSizeSelect.value = preset;
            } else {
                const opt = document.createElement('option');
                opt.value = preset;
                opt.textContent = preset;
                pageSizeSelect.appendChild(opt);
                pageSizeSelect.value = preset;
            }
            pageSizeSelect.addEventListener('change', () => {
                const value = Number(pageSizeSelect.value);
                if (Number.isFinite(value) && value > 0) {
                    api.pageSize = value;
                    localStorage.setItem('pageSize', String(value));
                    api.page = 1;
                    renderUrls(filteredUrls());
                }
            });
        }

        document.querySelectorAll('#url-table th[data-sort]').forEach(th => {
            th.addEventListener('click', () => {
                const column = th.dataset.sort;
                api.sort.by = column;
                api.sort.direction = api.sort.direction === 'DESC' ? 'ASC' : 'DESC';
                loadUrls();
            });
        });

        searchInput.addEventListener('input', () => {
            api.page = 1;
            const list = filteredUrls();
            renderUrls(list);
            updateStats(list);
        });
        if (tagFilter) {
            tagFilter.addEventListener('change', () => {
                api.page = 1;
                loadUrls();
            });
        }

        document.getElementById('csv-import-submit').addEventListener('click', () => {
            const csv = document.getElementById('csv-input').value;
            fetch('/api/urls/import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csv })
            }).then(r => r.json()).then(res => {
                const stats = res.results.reduce((acc,r) => {
                    acc[r.status] = (acc[r.status] || 0) + 1;
                    return acc;
                }, {});
                document.getElementById('import-status').textContent =
                    `Imported: ${stats.imported||0}, Duplicates: ${stats.duplicate||0}, Invalid: ${stats.invalid||0}`;
                hideModal('#import-export-modal');
                loadUrls();
            });
        });

        document.getElementById('new-url-btn').addEventListener('click', () => {
            const url = prompt('Enter URL to add');
            if (!url) return;
            fetch('/api/urls', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url })
            }).then(() => loadUrls());
        });

        document.getElementById('import-export-btn').addEventListener('click', () => showModal('#import-export-modal'));
        if (queueBtn) {
            queueBtn.addEventListener('click', () => {
                loadQueueTable();
                loadErrors();
                showQueueDrawer();
            });
        }
        if (queueRefreshBtn) {
            queueRefreshBtn.addEventListener('click', loadQueueTable);
        }
        if (queueClearBtn) {
            queueClearBtn.addEventListener('click', () => {
                const proceed = confirm('Clear all queue entries?');
                if (!proceed) return;
                fetch('/api/queue/clear', { method: 'POST' })
                    .then(() => loadQueueTable());
            });
        }
        if (workspaceBtn) {
            workspaceBtn.addEventListener('click', () => {
                showDrawer('project');
                loadSuppressions();
            });
        }
        if (drawerBackdrop) {
            drawerBackdrop.addEventListener('click', hideDrawer);
        }
        if (drawerCloseBtn) {
            drawerCloseBtn.addEventListener('click', hideDrawer);
        }
        if (queueDrawerCloseBtn) {
            queueDrawerCloseBtn.addEventListener('click', hideDrawer);
        }
        if (suppressionRefreshBtn) {
            suppressionRefreshBtn.addEventListener('click', loadSuppressions);
        }
        if (recountBtn) {
            recountBtn.addEventListener('click', () => {
                fetch('/api/maintenance/recount', { method: 'POST' })
                    .then(() => {
                        loadUrls();
                        loadIssues();
                        loadUniqueErrorsMetric();
                        loadTrends();
                        if (api.selectedId) loadHistory(api.selectedId);
                    });
            });
        }
        document.getElementById('export-audit-btn').addEventListener('click', exportAuditCsv);
        document.getElementById('export-issues-btn')?.addEventListener('click', () => {
            if (exportIssuesModal) {
                if (exportIssuesNote) {
                    exportIssuesNote.textContent = 'Export reflects latest results for selected viewports.';
                }
                populateExportIssueItems();
                showModal('#export-issues-modal');
            }
        });
        document.getElementById('export-plan-btn')?.addEventListener('click', () => {
            const params = new URLSearchParams({ format: 'csv' });
            const viewports = reportViewportParam();
            if (viewports) params.set('viewports', viewports);
            window.location = '/api/remediation/export?' + params.toString();
        });
        if (exportIssuesConfirm) {
            exportIssuesConfirm.addEventListener('click', () => {
                const params = new URLSearchParams();
                const viewports = reportViewportParam();
                if (viewports) params.set('viewports', viewports);
                const scope = 'pages';
                const category = exportIssuesCategory?.value || 'all';
                const urlFilter = (exportIssuesUrl?.value || '').trim();
                const itemId = (exportIssuesItem?.value || '').trim();
                if (scope) params.set('scope', scope);
                if (category) params.set('category', category);
                if (urlFilter) params.set('url', urlFilter);
                if (itemId) params.set('item_id', itemId);
                if (exportIssuesSuppressed?.checked) params.set('include_suppressed', '1');
                hideModal('#export-issues-modal');
                window.location = '/api/issues/export?' + params.toString();
            });
        }
        if (exportIssuesConfirm && exportIssuesNote) {
            exportIssuesNote.textContent = 'Export reflects latest results for selected viewports.';
        }
        if (exportIssuesCategory) {
            exportIssuesCategory.addEventListener('change', populateExportIssueItems);
        }
        function saveConfig() {
            const reportType = parseInt(reporttypeSelect?.value || '4', 10);
            const retryAttempts = retryAttemptsInput?.value || '';
            const retryDelay = retryDelayInput?.value || '';
            const payload = {
                reporttype: reportType,
                retry_attempts: retryAttempts,
                retry_delay_ms: retryDelay
            };
            const apiKeyValue = apiKeyInput?.value?.trim() || '';
            if (apiKeyValue !== '' || clearApiKey) {
                payload.api_key = apiKeyValue;
            }
            return fetch('/api/config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(r => r.json())
                .then(() => {
                    api.reportType = reportType;
                    applyReportType();
                    renderUrls(filteredUrls());
                    loadIssues();
                    loadUniqueErrorsMetric();
                    if (apiKeyInput) apiKeyInput.value = '';
                    clearApiKey = false;
                    loadConfig();
                });
        }
        if (reporttypeSelect) {
            reporttypeSelect.addEventListener('change', () => {
                const reportType = parseInt(reporttypeSelect.value || '4', 10);
                if (!Number.isFinite(reportType)) return;
                api.reportType = reportType;
                applyReportType();
            });
        }
        if (reportViewportBtn) {
            reportViewportBtn.addEventListener('click', () => {
                renderReportViewportList();
                showModal('#report-viewport-modal');
            });
        }
        if (reportViewportSaveBtn) {
            reportViewportSaveBtn.addEventListener('click', () => {
                const selected = Array.from(reportViewportList.querySelectorAll('input[type="checkbox"]:checked'))
                    .map(input => input.value);
                if (!selected.length && api.viewports.length) {
                    selected.push(...api.viewports.map(vp => vp.label));
                }
                localStorage.setItem('reportViewports', JSON.stringify(selected));
                updateReportViewportSummary();
                hideModal('#report-viewport-modal');
                loadUrls();
                loadTrends();
                loadIssues();
                loadUniqueErrorsMetric();
                if (api.selectedId) loadHistory(api.selectedId);
            });
        }
        if (auditConfirmBtn) {
            auditConfirmBtn.addEventListener('click', () => {
                const activeCount = api.urls.filter(u => u.active).length;
                const selected = getAuditSelectedViewports();
                if (!selected.length) {
                    if (auditCreditEstimate) {
                        auditCreditEstimate.textContent = 'Select at least one viewport to run the audit.';
                    }
                    return;
                }
                const estimatedCredits = activeCount * selected.length * reportTypeCredits(api.reportType);
                const proceed = confirm(`Audit ${activeCount} active URL(s) across ${selected.length} viewport(s)? This may use up to ~${estimatedCredits} credits. Continue?`);
                if (!proceed) return;
                hideModal('#audit-modal');
                fetch('/api/tests/run', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mode: 'all', viewports: selected })
                }).then(r => r.json()).then(res => {
                    loadQueueStats();
                    setTimeout(() => {
                        loadUrls();
                        loadIssues();
                        loadErrors();
                        loadUniqueErrorsMetric();
                        if (api.selectedId) loadHistory(api.selectedId);
                    }, 800);
                });
            });
        }
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', () => hideModal(btn.dataset.close));
        });
        document.querySelectorAll('[data-close]').forEach(btn => {
            if (btn.classList.contains('modal-close')) return;
            btn.addEventListener('click', () => hideModal(btn.dataset.close));
        });
        function showModal(sel){
            const el = document.querySelector(sel);
            if (!el) return;
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                if (backdrop !== el) {
                    backdrop.style.display = 'none';
                }
            });
            el.style.display = 'flex';
        }
        function hideModal(sel){
            const el = document.querySelector(sel);
            if (!el) return;
            el.style.display = 'none';
        }
        function showDrawer(section) {
            if (drawer) drawer.classList.add('open');
            if (drawerBackdrop) drawerBackdrop.classList.add('open');
            if (section === 'project') {
                loadSuppressions();
            }
        }
        function showQueueDrawer() {
            if (queueDrawer) queueDrawer.classList.add('open');
            if (drawerBackdrop) drawerBackdrop.classList.add('open');
        }
        function hideDrawer() {
            if (drawer) drawer.classList.remove('open');
            if (queueDrawer) queueDrawer.classList.remove('open');
            if (drawerBackdrop) drawerBackdrop.classList.remove('open');
        }
        function setLoading(msg){ document.getElementById('loader').textContent = msg || ''; }
        function setDisabled(state) {
            processing = state;
            buttons.forEach(b => b.disabled = state);
        }
        function showOverlay(text, percent) {
            overlay.style.display = 'flex';
            overlayText.textContent = text;
            overlayBar.style.width = `${percent}%`;
        }
        function hideOverlay() {
            overlay.style.display = 'none';
            overlayBar.style.width = '0%';
        }

        document.getElementById('audit-active-btn')?.addEventListener('click', () => {
            renderAuditViewportList();
            showModal('#audit-modal');
        });
        ['toggle-aim','toggle-unique','toggle-unique-contrast','toggle-unique-alerts','toggle-errors','toggle-contrast','toggle-alerts'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', loadTrends);
        });
        if (issueSearchInput) issueSearchInput.addEventListener('input', renderImpactIssues);
        if (issueImpactCategory) issueImpactCategory.addEventListener('change', renderImpactIssues);
        if (showSuppressedInput) showSuppressedInput.addEventListener('change', loadIssues);
        if (issuesImpactTableBody) {
            issuesImpactTableBody.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-action="toggle-suppress"]');
                if (!btn) return;
                const itemId = btn.dataset.item || '';
                const category = btn.dataset.category || '';
                if (!itemId || !category) return;
                const isRestore = btn.textContent.trim().toLowerCase() === 'restore';
                const method = isRestore ? 'DELETE' : 'POST';
                fetch('/api/issues/suppressions', {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ item_id: itemId, category })
                })
                    .then(() => {
                        loadIssues();
                        loadUniqueErrorsMetric();
                        loadUrls();
                        loadTrends();
                        loadSuppressions();
                        if (api.selectedId) loadHistory(api.selectedId);
                    });
            });
        }
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-action="toggle-suppress-element"]');
            if (!btn) return;
            const itemId = btn.dataset.item || '';
            const category = btn.dataset.category || '';
            const selector = btn.dataset.selector || '';
                const viewportLabel = btn.dataset.viewport || '';
                if (!itemId || !category || !selector) return;
                const isRestore = btn.textContent.trim().toLowerCase() === 'restore';
                const method = isRestore ? 'DELETE' : 'POST';
                fetch('/api/issues/suppressions/element', {
                    method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        item_id: itemId,
                        category,
                        selector,
                        viewport_label: viewportLabel
                    })
                })
                .then(() => {
                    loadIssues();
                    loadUniqueErrorsMetric();
                    loadUrls();
                    loadTrends();
                    loadSuppressions();
                    if (api.selectedId) loadHistory(api.selectedId);
                    if (activeIssueItem && issueModal && issueModal.style.display === 'flex') {
                        openIssueModal(activeIssueItem);
                    }
                    if (activePageIssue) {
                        showPageIssueDetail(activePageIssue.urlId, activePageIssue.url, activePageIssue.issue);
                    }
                });
        });

        if (suppressionTableBody) {
            suppressionTableBody.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-action="restore-suppression"]');
                if (!btn) return;
                const itemId = btn.dataset.item || '';
                const category = btn.dataset.category || '';
                if (!itemId || !category) return;
                fetch('/api/issues/suppressions', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ item_id: itemId, category })
                })
                    .then(() => {
                        loadIssues();
                        loadUniqueErrorsMetric();
                        loadUrls();
                        loadTrends();
                        loadSuppressions();
                        if (api.selectedId) loadHistory(api.selectedId);
                    });
            });
        }
        if (suppressionElementsTableBody) {
            suppressionElementsTableBody.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-action="restore-suppression-element"]');
                if (!btn) return;
                const itemId = btn.dataset.item || '';
                const category = btn.dataset.category || '';
                const selector = btn.dataset.selector || '';
                const viewportLabel = btn.dataset.viewport || '';
                if (!itemId || !category || !selector) return;
                fetch('/api/issues/suppressions/element', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        item_id: itemId,
                        category,
                        selector,
                        viewport_label: viewportLabel
                    })
                })
                    .then(() => {
                        loadIssues();
                        loadUniqueErrorsMetric();
                        loadUrls();
                        loadTrends();
                        loadSuppressions();
                        if (api.selectedId) loadHistory(api.selectedId);
                    });
            });
        }

        function processQueue() {
            return fetch('/api/queue/process', { method: 'POST' })
                .then(r => r.json())
                .then(res => {
                    if (res.error || res.message) {
                        if (res.error || res.message) {
                            loadQueueStats();
                        }
                    } else {
                        loadQueueStats();
                    }
                    loadUrls();
                    loadQueueStats();
                    loadIssues();
                    loadErrors();
                    loadUniqueErrorsMetric();
                    if (api.selectedId) loadHistory(api.selectedId);
                    return res;
                });
        }

        function exportAuditCsv() {
            fetch('/api/urls')
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    const headers = ['id','url','created_at','last_test_at','last_aim_score','last_errors'];
                    const csv = [headers.join(',')].concat(
                        rows.map(r => headers.map(h => `"${String(r[h] ?? '').replace(/"/g,'""')}"`).join(','))
                    ).join('\n');
                    const blob = new Blob([csv], { type: 'text/csv' });
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'wcag-wave-audit.csv';
                    a.click();
                    URL.revokeObjectURL(a.href);
                });
        }

        function loadErrors() {
            fetch('/api/errors?limit=50')
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    errorsTableBody.innerHTML = '';
                    if (!rows.length) {
                        errorsStatus.textContent = 'No recent errors.';
                        return;
                    }
                    errorsStatus.textContent = `${rows.length} recent error(s).`;
                    rows.forEach(row => {
                        const tr = document.createElement('tr');
                        const url = row.url || '--';
                        const viewport = row.viewport_label || 'default';
                        const ctx = row.context || '--';
                        const msg = row.message || row.error_message || 'Unknown error';
                        const finished = fmt(row.created_at || row.finished_at);

                        const urlCell = document.createElement('td');
                        urlCell.textContent = url;
                        const viewportCell = document.createElement('td');
                        viewportCell.textContent = viewport;
                        const ctxCell = document.createElement('td');
                        ctxCell.textContent = ctx;
                        const msgCell = document.createElement('td');
                        msgCell.textContent = msg;
                        msgCell.title = msg;
                        const finishedCell = document.createElement('td');
                        finishedCell.textContent = finished;

                        tr.appendChild(urlCell);
                        tr.appendChild(viewportCell);
                        tr.appendChild(ctxCell);
                        tr.appendChild(msgCell);
                        tr.appendChild(finishedCell);
                        errorsTableBody.appendChild(tr);
                    });
                });
        }

        function loadQueueTable() {
            if (!queueTableBody) return;
            fetch('/api/queue')
                .then(r => r.json())
                .then(data => {
                    const rows = data.data || [];
                    updateQueuedUrls(rows);
                    queueTableBody.innerHTML = '';
                    if (wsStatus) {
                        wsStatus.textContent = String(rows.filter(r => r.status === 'pending' || r.status === 'running').length);
                    }
                    if (queueSummary) {
                        const pending = rows.filter(r => r.status === 'pending' || r.status === 'running').length;
                        const failed = rows.filter(r => r.status === 'failed').length;
                        queueSummary.textContent = `${rows.length} job(s). Pending/running: ${pending}. Failed: ${failed}.`;
                    }
                    rows.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${row.id ?? '--'}</td>
                            <td>${row.url ?? '--'}</td>
                            <td>${row.status ?? '--'}</td>
                            <td>${fmt(row.created_at)}</td>
                            <td>${fmt(row.started_at)}</td>
                            <td>${fmt(row.finished_at)}</td>
                            <td>${row.error_message ? escHtml(row.error_message) : '--'}</td>
                        `;
                        queueTableBody.appendChild(tr);
                    });
                    if (!rows.length) {
                        queueTableBody.innerHTML = '<tr><td colspan="7" class="muted">No jobs in queue.</td></tr>';
                    }
                });
        }

        function updateQueuedUrls(jobs) {
            const active = new Set();
            (jobs || []).forEach(job => {
                if (job && (job.status === 'pending' || job.status === 'running')) {
                    const id = Number(job.url_id ?? job.urlId ?? job.urlID ?? 0);
                    if (id) active.add(id);
                }
            });
            api.queueUrlIds = active;
            document.querySelectorAll('button[data-action="test"]').forEach(btn => {
                const id = Number(btn.dataset.id || 0);
                const disabled = id && active.has(id);
                btn.disabled = disabled;
                btn.title = disabled ? 'Queued' : 'Test';
            });
        }

        function loadSuppressions() {
            if (!suppressionTableBody || !suppressionElementsTableBody) return;
            suppressionTableBody.innerHTML = '';
            suppressionElementsTableBody.innerHTML = '';
            const reqs = [
                fetch('/api/issues/suppressions').then(r => r.json()),
                fetch('/api/issues/suppressions/element').then(r => r.json())
            ];
            Promise.all(reqs)
                .then(([projectRes, elementRes]) => {
                    const projectRows = projectRes.data || [];
                    const elementRows = elementRes.data || [];
                    projectRows.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${escHtml(row.item_id)}</td>
                            <td>${escHtml(row.category)}</td>
                            <td>${escHtml(row.reason || '--')}</td>
                            <td><button class="secondary" data-action="restore-suppression" data-item="${escAttr(row.item_id)}" data-category="${escAttr(row.category)}">Restore</button></td>
                        `;
                        suppressionTableBody.appendChild(tr);
                    });
                    if (!projectRows.length) {
                        suppressionTableBody.innerHTML = '<tr><td colspan="4" class="muted">No project-level suppressions.</td></tr>';
                    }

                    elementRows.forEach(row => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${escHtml(row.item_id)}</td>
                            <td><code>${escHtml(row.selector)}</code></td>
                            <td>${escHtml(row.viewport_label || 'default')}</td>
                            <td>${escHtml(row.reason || '--')}</td>
                            <td><button class="secondary" data-action="restore-suppression-element" data-item="${escAttr(row.item_id)}" data-category="${escAttr(row.category)}" data-selector="${escAttr(row.selector)}" data-viewport="${escAttr(row.viewport_label || '')}">Restore</button></td>
                        `;
                        suppressionElementsTableBody.appendChild(tr);
                    });
                    if (!elementRows.length) {
                        suppressionElementsTableBody.innerHTML = '<tr><td colspan="5" class="muted">No selector-level suppressions.</td></tr>';
                    }
                    if (suppressionStatus) {
                        suppressionStatus.textContent = `${projectRows.length} project-level, ${elementRows.length} selector-level suppressions.`;
                    }
                });
        }

        function scheduleRealtimeRefresh() {
            if (wsRefreshTimer) return;
            wsRefreshTimer = setTimeout(() => {
                wsRefreshTimer = null;
                loadQueueStats();
                loadUrls();
                loadIssues();
                loadErrors();
                loadUniqueErrorsMetric();
                if (api.selectedId) loadHistory(api.selectedId);
                if (queueDrawer && queueDrawer.classList.contains('open')) {
                    loadQueueTable();
                }
            }, 500);
        }

        function initRealtime() {
            if (!window.WebSocket) return;
            const protocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
            const wsUrl = protocol + window.location.host;
            const ws = new WebSocket(wsUrl);
            ws.addEventListener('open', () => {
                ws.send(JSON.stringify({ action: 'subscribe', channel: 'queue' }));
            });
            ws.addEventListener('message', (evt) => {
                let payload = null;
                try {
                    payload = JSON.parse(evt.data);
                } catch (err) {
                    return;
                }
                const message = payload && payload.message ? payload.message : payload;
                if (message && message.event === 'ws.heartbeat') {
                    if (wsStatus) {
                        wsStatus.classList.remove('beat');
                        void wsStatus.offsetWidth;
                        wsStatus.classList.add('beat');
                    }
                    return;
                }
                if (message && message.event === 'queue.job') {
                    scheduleRealtimeRefresh();
                }
                if (message && message.event === 'metrics.updated') {
                    loadUniqueErrorsMetric();
                }
            });
            ws.addEventListener('close', () => {
                if (wsStatus) wsStatus.textContent = '0';
                setTimeout(initRealtime, 2000);
            });
        }

        api.urls = __INITIAL_URLS__;
        renderUrls(api.urls);
        updateStats(api.urls);
        loadProjects()
            .catch(() => {})
            .finally(() => {
                reloadProjectData();
            });

        initRealtime();

        if (viewportTableBody) {
            viewportTableBody.addEventListener('click', (e) => {
                const btn = e.target.closest('button');
                if (!btn) return;
                const row = e.target.closest('tr');
                if (!row) return;
                const action = btn.dataset.action;
                const label = row.dataset.label || '';
                const getField = (name) => row.querySelector(`[data-field="${name}"]`);
                if (action === 'save-viewport') {
                    const payload = {
                        label: getField('label')?.value?.trim(),
                        viewport_width: getField('viewport_width')?.value ?? '',
                        eval_delay: getField('eval_delay')?.value ?? '',
                        user_agent: getField('user_agent')?.value ?? ''
                    };
                    if (!payload.label) {
                        alert('Viewport label is required.');
                        return;
                    }
                    fetch('/api/viewports', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    })
                        .then(r => r.json())
                        .then(res => {
                            if (res?.error) {
                                if (viewportStatus) viewportStatus.textContent = res.error;
                                return;
                            }
                            loadViewports();
                            loadUrls();
                            loadTrends();
                            loadIssues();
                            loadUniqueErrorsMetric();
                        });
                }
                if (action === 'delete-viewport') {
                    if (!label) return;
                    if (!confirm(`Delete viewport "${label}"?`)) return;
                    fetch(`/api/viewports/${encodeURIComponent(label)}`, { method: 'DELETE' })
                        .then(() => {
                            loadViewports();
                            loadUrls();
                            loadTrends();
                            loadIssues();
                            loadUniqueErrorsMetric();
                        });
                }
            });
        }
        if (addViewportBtn) {
            addViewportBtn.addEventListener('click', () => {
                api.viewports = api.viewports || [];
                api.viewports.push({
                    label: '',
                    viewport_width: '',
                    eval_delay: '',
                    user_agent: ''
                });
                renderViewports();
            });
        }
        if (projectSelect) {
            projectSelect.addEventListener('change', () => {
                const id = projectSelect.value;
                fetch('/api/projects/select', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ project_id: id })
                }).then(() => {
                    reloadProjectData();
                });
            });
        }
        if (newProjectBtn) {
            newProjectBtn.addEventListener('click', () => {
                const name = prompt('Project name');
                if (!name) return;
                fetch('/api/projects', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res?.id) {
                            return fetch('/api/projects/select', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ project_id: res.id })
                            });
                        }
                        return null;
                    })
                    .then(() => {
                        loadProjects().then(reloadProjectData);
                    });
            });
        }
        if (projectSettingsBtn) {
            projectSettingsBtn.addEventListener('click', () => {
                const project = currentProject();
                if (!project) {
                    alert('No project selected.');
                    return;
                }
                if (projectNameInput) projectNameInput.value = project.name || '';
                if (projectSlugInput) projectSlugInput.value = project.slug || '';
                if (projectSettingsStatus) projectSettingsStatus.textContent = '';
                showModal('#project-settings-modal');
            });
        }
        function saveProject() {
            const project = currentProject();
            if (!project) return Promise.resolve();
            const name = projectNameInput?.value?.trim() || '';
            const slug = projectSlugInput?.value?.trim() || '';
            if (!name) {
                if (projectSettingsStatus) projectSettingsStatus.textContent = 'Project name is required.';
                return Promise.reject(new Error('Project name is required.'));
            }
            return fetch(`/api/projects/${project.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, slug })
            })
                .then(r => r.json())
                .then(res => {
                    if (res.error) {
                        if (projectSettingsStatus) projectSettingsStatus.textContent = res.error;
                        throw new Error(res.error);
                    }
                    return res;
                });
        }

        if (saveProjectAllBtn) {
            saveProjectAllBtn.addEventListener('click', () => {
                if (projectSettingsStatus) projectSettingsStatus.textContent = '';
                Promise.resolve()
                    .then(() => saveProject())
                    .then(() => saveConfig())
                    .then(() => {
                        hideModal('#project-settings-modal');
                        loadProjects().then(reloadProjectData);
                    })
                    .catch(() => {});
            });
        }
        if (deleteProjectBtn) {
            deleteProjectBtn.addEventListener('click', () => {
                const project = currentProject();
                if (!project) return;
                if (api.projects && api.projects.length <= 1) {
                    if (projectSettingsStatus) {
                        projectSettingsStatus.textContent = 'Cannot delete the last project.';
                    }
                    return;
                }
                const proceed = confirm(`Delete project "${project.name}"? This will remove its database if it lives under data/projects.`);
                if (!proceed) return;
                fetch(`/api/projects/${project.id}`, { method: 'DELETE' })
                    .then(r => r.json())
                    .then(res => {
                        if (res.error) {
                            if (projectSettingsStatus) projectSettingsStatus.textContent = res.error;
                            return;
                        }
                        hideModal('#project-settings-modal');
                        loadProjects().then(reloadProjectData);
                    });
            });
        }
        if (clearApiKeyBtn) {
            clearApiKeyBtn.addEventListener('click', () => {
                clearApiKey = true;
                if (apiKeyInput) apiKeyInput.value = '';
                if (apiKeyStatus) apiKeyStatus.textContent = 'API key will be cleared on save.';
            });
        }

        document.getElementById('refresh-errors-btn')?.addEventListener('click', loadErrors);
        document.getElementById('clear-errors-btn')?.addEventListener('click', () => {
            const proceed = confirm('Clear all error entries?');
            if (!proceed) return;
            fetch('/api/errors/clear', { method: 'POST' })
                .then(() => loadErrors());
        });
    </script>
</body>
</html>
HTML;
