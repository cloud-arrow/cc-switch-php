CREATE TABLE IF NOT EXISTS stream_check_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id TEXT NOT NULL,
    provider_name TEXT NOT NULL,
    app_type TEXT NOT NULL,
    status TEXT NOT NULL,
    success INTEGER NOT NULL,
    message TEXT NOT NULL,
    response_time_ms INTEGER,
    http_status INTEGER,
    model_used TEXT,
    retry_count INTEGER DEFAULT 0,
    tested_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_stream_check_logs_provider ON stream_check_logs(app_type, provider_id, tested_at DESC);
