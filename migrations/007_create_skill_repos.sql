CREATE TABLE IF NOT EXISTS skill_repos (
    owner TEXT NOT NULL,
    name TEXT NOT NULL,
    branch TEXT NOT NULL DEFAULT 'main',
    enabled INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (owner, name)
);

INSERT OR IGNORE INTO skill_repos (owner, name, branch, enabled) VALUES ('anthropics', 'skills', 'main', 1);
INSERT OR IGNORE INTO skill_repos (owner, name, branch, enabled) VALUES ('ComposioHQ', 'awesome-claude-skills', 'master', 1);
