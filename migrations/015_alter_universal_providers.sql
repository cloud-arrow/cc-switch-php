ALTER TABLE universal_providers ADD COLUMN settings_config TEXT NOT NULL DEFAULT '{}';
ALTER TABLE universal_providers ADD COLUMN category TEXT;
ALTER TABLE universal_providers ADD COLUMN meta TEXT NOT NULL DEFAULT '{}';
