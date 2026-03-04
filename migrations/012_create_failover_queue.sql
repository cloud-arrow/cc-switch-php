CREATE INDEX IF NOT EXISTS idx_providers_failover ON providers(app_type, in_failover_queue, sort_index);
