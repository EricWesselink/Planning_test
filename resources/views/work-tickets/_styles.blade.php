<style>
    @page { margin: 12mm 12mm 14mm 12mm; }
    * { box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, sans-serif;
        color: #1a1a1a;
        font-size: 9.5pt;
        line-height: 1.35;
        margin: 0;
        background: #fff;
    }
    .ticket-page { max-width: 210mm; margin: 0 auto; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    a { color: #163a5f; }
    .brand td {
        border: none;
        border-bottom: 0.7pt solid #163a5f;
        padding: 0 0 10px;
        vertical-align: middle;
    }
    .logo { width: 118px; padding-right: 14px; }
    .logo img { width: 110px; height: 54px; display: block; object-fit: contain; }
    .brand-name {
        font-size: 10pt;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #163a5f;
    }
    .doc-title {
        font-size: 18pt;
        font-weight: 700;
        color: #163a5f;
        letter-spacing: 0.06em;
        line-height: 1.1;
        padding-top: 2px;
    }
    .doc-meta { font-size: 8.5pt; color: #5b6570; padding-top: 3px; }
    .brand-side { text-align: right; font-size: 8pt; color: #5b6570; line-height: 1.4; }
    .brand-side strong { color: #163a5f; font-size: 9pt; }
    .blocks { margin-top: 12px; }
    .blocks td {
        width: 50%;
        border: 0.4pt solid #d5dde5;
        padding: 8px 10px;
        background: #f7f9fb;
    }
    .blocks td + td { border-left: none; }
    .block-title {
        font-size: 7.5pt;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #163a5f;
        margin-bottom: 4px;
    }
    .block p { margin: 0 0 2px; }
    .section { margin-top: 14px; }
    .section-title {
        font-size: 8pt;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #163a5f;
        border-bottom: 0.6pt solid #163a5f;
        padding-bottom: 3px;
        margin-bottom: 6px;
    }
    .lines th {
        text-align: left;
        color: #5b6570;
        font-size: 8pt;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        padding: 4px 6px;
        border-bottom: 0.6pt solid #163a5f;
    }
    .lines td { padding: 6px; border-bottom: 0.4pt solid #e7e0d4; }
    .num { text-align: right; white-space: nowrap; }
    .total td {
        font-weight: 700;
        border-top: 1.2pt solid #163a5f;
        border-bottom: none;
        color: #163a5f;
        padding-top: 8px;
    }
    .notes { font-size: 9.5pt; }
    .drawings { margin-top: 8px; }
    .drawing {
        margin: 8px 0 12px;
        page-break-inside: avoid;
    }
    .drawing img {
        display: block;
        max-width: 100%;
        max-height: 170mm;
        border: 0.4pt solid #d5dde5;
    }
    .drawing-name { font-size: 8pt; color: #5b6570; margin-top: 4px; }
    .foot {
        margin-top: 22px;
        padding-top: 8px;
        border-top: 0.4pt solid #d5dde5;
        font-size: 8pt;
        color: #5b6570;
    }
    .toolbar { margin: 0 0 16px; font-size: 13px; }
    .toolbar a, .toolbar button {
        display: inline-block;
        margin: 0 8px 8px 0;
        padding: 8px 14px;
        border: 1px solid #163a5f;
        background: #fff;
        color: #163a5f;
        text-decoration: none;
        font: inherit;
        cursor: pointer;
    }
    .toolbar .primary { background: #e4572e; border-color: #e4572e; color: #fff; }
    .hours-form {
        margin-top: 18px;
        padding: 12px;
        border: 1px solid #d5dde5;
        font-size: 13px;
    }
    .hours-form label { display: block; font-size: 11px; color: #5b6570; margin-bottom: 4px; }
    .hours-form input { width: 7rem; padding: 6px 8px; border: 1px solid #d5dde5; }
    .status { color: #3f6212; font-size: 13px; margin: 0 0 12px; }
    .error { color: #b91c1c; font-size: 13px; margin: 0 0 12px; }
    @media screen {
        body { margin: 24px; font-size: 13px; }
        .ticket-page { margin: 0 auto; }
        .logo img { width: 132px; height: auto; }
        .doc-title { font-size: 26px; }
    }
    @media print {
        .no-print { display: none !important; }
        body { margin: 0; background: #fff; }
        a { text-decoration: none; color: inherit; }
    }
</style>
