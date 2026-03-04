CREATE TABLE IF NOT EXISTS proxy_request_logs (
    request_id TEXT PRIMARY KEY,
    provider_id TEXT NOT NULL,
    app_type TEXT NOT NULL,
    model TEXT NOT NULL,
    request_model TEXT,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    cache_read_tokens INTEGER NOT NULL DEFAULT 0,
    cache_creation_tokens INTEGER NOT NULL DEFAULT 0,
    input_cost_usd TEXT NOT NULL DEFAULT '0',
    output_cost_usd TEXT NOT NULL DEFAULT '0',
    cache_read_cost_usd TEXT NOT NULL DEFAULT '0',
    cache_creation_cost_usd TEXT NOT NULL DEFAULT '0',
    total_cost_usd TEXT NOT NULL DEFAULT '0',
    latency_ms INTEGER NOT NULL,
    first_token_ms INTEGER,
    duration_ms INTEGER,
    status_code INTEGER NOT NULL,
    error_message TEXT,
    session_id TEXT,
    provider_type TEXT,
    is_streaming INTEGER NOT NULL DEFAULT 0,
    cost_multiplier TEXT NOT NULL DEFAULT '1.0',
    created_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_request_logs_provider ON proxy_request_logs(provider_id, app_type);
CREATE INDEX IF NOT EXISTS idx_request_logs_created_at ON proxy_request_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_request_logs_model ON proxy_request_logs(model);
CREATE INDEX IF NOT EXISTS idx_request_logs_session ON proxy_request_logs(session_id);
CREATE INDEX IF NOT EXISTS idx_request_logs_status ON proxy_request_logs(status_code);
