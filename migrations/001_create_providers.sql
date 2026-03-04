PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

CREATE TABLE IF NOT EXISTS providers (
    id TEXT NOT NULL,
    app_type TEXT NOT NULL,
    name TEXT NOT NULL,
    settings_config TEXT NOT NULL,
    website_url TEXT,
    category TEXT,
    created_at INTEGER,
    sort_index INTEGER,
    notes TEXT,
    icon TEXT,
    icon_color TEXT,
    meta TEXT NOT NULL DEFAULT '{}',
    is_current INTEGER NOT NULL DEFAULT 0,
    in_failover_queue INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (id, app_type)
);
