CREATE TABLE IF NOT EXISTS proxy_config (
    app_type TEXT PRIMARY KEY CHECK (app_type IN ('claude', 'codex', 'gemini')),
    proxy_enabled INTEGER NOT NULL DEFAULT 0,
    listen_address TEXT NOT NULL DEFAULT '127.0.0.1',
    listen_port INTEGER NOT NULL DEFAULT 15721,
    enable_logging INTEGER NOT NULL DEFAULT 1,
    enabled INTEGER NOT NULL DEFAULT 0,
    auto_failover_enabled INTEGER NOT NULL DEFAULT 0,
    max_retries INTEGER NOT NULL DEFAULT 3,
    streaming_first_byte_timeout INTEGER NOT NULL DEFAULT 60,
    streaming_idle_timeout INTEGER NOT NULL DEFAULT 120,
    non_streaming_timeout INTEGER NOT NULL DEFAULT 600,
    circuit_failure_threshold INTEGER NOT NULL DEFAULT 4,
    circuit_success_threshold INTEGER NOT NULL DEFAULT 2,
    circuit_timeout_seconds INTEGER NOT NULL DEFAULT 60,
    circuit_error_rate_threshold REAL NOT NULL DEFAULT 0.6,
    circuit_min_requests INTEGER NOT NULL DEFAULT 10,
    default_cost_multiplier TEXT NOT NULL DEFAULT '1',
    pricing_model_source TEXT NOT NULL DEFAULT 'response',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT OR IGNORE INTO proxy_config (
    app_type, max_retries, streaming_first_byte_timeout, streaming_idle_timeout,
    non_streaming_timeout, circuit_failure_threshold, circuit_success_threshold,
    circuit_timeout_seconds, circuit_error_rate_threshold, circuit_min_requests
) VALUES ('claude', 6, 90, 180, 600, 8, 3, 90, 0.7, 15);

INSERT OR IGNORE INTO proxy_config (
    app_type, max_retries, streaming_first_byte_timeout, streaming_idle_timeout,
    non_streaming_timeout, circuit_failure_threshold, circuit_success_threshold,
    circuit_timeout_seconds, circuit_error_rate_threshold, circuit_min_requests
) VALUES ('codex', 3, 60, 120, 600, 4, 2, 60, 0.6, 10);

INSERT OR IGNORE INTO proxy_config (
    app_type, max_retries, streaming_first_byte_timeout, streaming_idle_timeout,
    non_streaming_timeout, circuit_failure_threshold, circuit_success_threshold,
    circuit_timeout_seconds, circuit_error_rate_threshold, circuit_min_requests
) VALUES ('gemini', 5, 60, 120, 600, 4, 2, 60, 0.6, 10);
