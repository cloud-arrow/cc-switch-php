CREATE TABLE IF NOT EXISTS skills (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    directory TEXT NOT NULL,
    repo_owner TEXT,
    repo_name TEXT,
    repo_branch TEXT DEFAULT 'main',
    readme_url TEXT,
    enabled_claude INTEGER NOT NULL DEFAULT 0,
    enabled_codex INTEGER NOT NULL DEFAULT 0,
    enabled_gemini INTEGER NOT NULL DEFAULT 0,
    enabled_opencode INTEGER NOT NULL DEFAULT 0,
    installed_at INTEGER NOT NULL DEFAULT 0
);
