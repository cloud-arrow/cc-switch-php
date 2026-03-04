CREATE TABLE IF NOT EXISTS universal_providers (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    provider_type TEXT NOT NULL,
    apps TEXT NOT NULL DEFAULT '{}',
    base_url TEXT NOT NULL,
    api_key TEXT NOT NULL,
    models TEXT NOT NULL DEFAULT '{}',
    website_url TEXT,
    notes TEXT,
    created_at INTEGER
);
