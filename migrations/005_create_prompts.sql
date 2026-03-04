CREATE TABLE IF NOT EXISTS prompts (
    id TEXT NOT NULL,
    app_type TEXT NOT NULL,
    name TEXT NOT NULL,
    content TEXT NOT NULL,
    description TEXT,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER,
    updated_at INTEGER,
    PRIMARY KEY (id, app_type)
);
