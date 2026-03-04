CREATE TABLE IF NOT EXISTS provider_health (
    provider_id TEXT NOT NULL,
    app_type TEXT NOT NULL,
    is_healthy INTEGER NOT NULL DEFAULT 1,
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    last_success_at TEXT,
    last_failure_at TEXT,
    last_error TEXT,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (provider_id, app_type),
    FOREIGN KEY (provider_id, app_type) REFERENCES providers(id, app_type) ON DELETE CASCADE
);
