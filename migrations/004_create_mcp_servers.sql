CREATE TABLE IF NOT EXISTS mcp_servers (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    server_config TEXT NOT NULL,
    description TEXT,
    homepage TEXT,
    docs TEXT,
    tags TEXT NOT NULL DEFAULT '[]',
    enabled_claude INTEGER NOT NULL DEFAULT 0,
    enabled_codex INTEGER NOT NULL DEFAULT 0,
    enabled_gemini INTEGER NOT NULL DEFAULT 0,
    enabled_opencode INTEGER NOT NULL DEFAULT 0
);
