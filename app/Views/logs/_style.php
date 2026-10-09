<style>
    .log-grid {
        display: grid;
        grid-template-columns: 320px minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }

    .log-stack {
        display: grid;
        gap: 18px;
    }

    .log-chart-box {
        padding: 18px;
    }

    .log-chart-wrap {
        position: relative;
        min-height: 240px;
    }

    .log-chart-wrap.sm {
        min-height: 210px;
    }

    .log-count {
        color: #667085;
        font-size: 13px;
        font-weight: 800;
    }

    .log-mini-list {
        list-style: none;
        margin: 0;
        padding: 0 18px 18px;
    }

    .log-mini-list li {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid #eef2f7;
    }

    .log-mini-list li:last-child {
        border-bottom: 0;
    }

    .log-mini-list strong {
        display: block;
        color: #1f2937;
        font-size: 14px;
        font-weight: 800;
    }

    .log-mini-list small {
        display: block;
        color: #98a2b3;
        font-size: 12px;
        font-weight: 600;
    }

    .log-empty {
        color: #98a2b3;
        text-align: center;
        padding: 28px 18px;
        font-style: italic;
    }

    .log-subtext {
        color: #98a2b3;
        font-size: 12px;
        font-weight: 600;
    }

    .log-message {
        max-width: 360px;
        white-space: normal;
    }

    .log-path {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .log-path .arrow {
        color: #98a2b3;
        font-weight: 900;
    }

    .filter-foot {
        display: flex;
        justify-content: flex-end;
        margin-top: 10px;
    }

    .filter-foot .log-count {
        font-size: 12px;
    }

    .table-card .table tbody td .small-muted {
        color: #98a2b3;
        font-size: 12px;
        font-weight: 600;
    }

    .code-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 8px;
        border-radius: 8px;
        background: #eef2ff;
        color: #4f46e5;
        font-size: 12px;
        font-weight: 800;
    }

    .status-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 999px;
        margin-right: 6px;
    }

    .status-running {
        background: #10b981;
    }

    .status-idle {
        background: #f59e0b;
    }

    .status-alarm {
        background: #ef4444;
    }

    .status-offline {
        background: #94a3b8;
    }

    .status-setting {
        background: #8b5cf6;
    }

    @media (max-width: 1199.98px) {
        .log-grid {
            grid-template-columns: 1fr;
        }
    }
</style>