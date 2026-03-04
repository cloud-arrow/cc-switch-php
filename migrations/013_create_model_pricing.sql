CREATE TABLE IF NOT EXISTS model_pricing (
    model_id TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    input_cost_per_million TEXT NOT NULL,
    output_cost_per_million TEXT NOT NULL,
    cache_read_cost_per_million TEXT NOT NULL DEFAULT '0',
    cache_creation_cost_per_million TEXT NOT NULL DEFAULT '0'
);

-- Claude 4.6
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-opus-4-6-20260206', 'Claude Opus 4.6', '5', '25', '0.50', '6.25');

-- Claude 4.5
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-opus-4-5-20251101', 'Claude Opus 4.5', '5', '25', '0.50', '6.25');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-sonnet-4-5-20250929', 'Claude Sonnet 4.5', '3', '15', '0.30', '3.75');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-haiku-4-5-20251001', 'Claude Haiku 4.5', '1', '5', '0.10', '1.25');

-- Claude 4 (Legacy)
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-opus-4-20250514', 'Claude Opus 4', '15', '75', '1.50', '18.75');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-opus-4-1-20250805', 'Claude Opus 4.1', '15', '75', '1.50', '18.75');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-sonnet-4-20250514', 'Claude Sonnet 4', '3', '15', '0.30', '3.75');

-- Claude 3.5
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-3-5-haiku-20241022', 'Claude 3.5 Haiku', '0.80', '4', '0.08', '1');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('claude-3-5-sonnet-20241022', 'Claude 3.5 Sonnet', '3', '15', '0.30', '3.75');

-- GPT-5.2
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2', 'GPT-5.2', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-low', 'GPT-5.2', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-medium', 'GPT-5.2', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-high', 'GPT-5.2', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-xhigh', 'GPT-5.2', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-codex', 'GPT-5.2 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-codex-low', 'GPT-5.2 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-codex-medium', 'GPT-5.2 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-codex-high', 'GPT-5.2 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.2-codex-xhigh', 'GPT-5.2 Codex', '1.75', '14', '0.175', '0');

-- GPT-5.3 Codex
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.3-codex', 'GPT-5.3 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.3-codex-low', 'GPT-5.3 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.3-codex-medium', 'GPT-5.3 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.3-codex-high', 'GPT-5.3 Codex', '1.75', '14', '0.175', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.3-codex-xhigh', 'GPT-5.3 Codex', '1.75', '14', '0.175', '0');

-- GPT-5.1
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1', 'GPT-5.1', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-low', 'GPT-5.1', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-medium', 'GPT-5.1', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-high', 'GPT-5.1', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-minimal', 'GPT-5.1', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-codex', 'GPT-5.1 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-codex-mini', 'GPT-5.1 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-codex-max', 'GPT-5.1 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-codex-max-high', 'GPT-5.1 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5.1-codex-max-xhigh', 'GPT-5.1 Codex', '1.25', '10', '0.125', '0');

-- GPT-5
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5', 'GPT-5', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-low', 'GPT-5', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-medium', 'GPT-5', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-high', 'GPT-5', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-minimal', 'GPT-5', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-codex', 'GPT-5 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-codex-low', 'GPT-5 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-codex-medium', 'GPT-5 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-codex-high', 'GPT-5 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-codex-mini', 'GPT-5 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-codex-mini-medium', 'GPT-5 Codex', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gpt-5-codex-mini-high', 'GPT-5 Codex', '1.25', '10', '0.125', '0');

-- Gemini 3
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gemini-3-pro-preview', 'Gemini 3 Pro Preview', '2', '12', '0.2', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gemini-3-flash-preview', 'Gemini 3 Flash Preview', '0.5', '3', '0.05', '0');

-- Gemini 2.5
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gemini-2.5-pro', 'Gemini 2.5 Pro', '1.25', '10', '0.125', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('gemini-2.5-flash', 'Gemini 2.5 Flash', '0.3', '2.5', '0.03', '0');

-- Doubao (CNY)
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('doubao-seed-code', 'Doubao Seed Code', '1.20', '8.00', '0.24', '0');

-- DeepSeek (CNY)
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('deepseek-v3.2', 'DeepSeek V3.2', '2.00', '3.00', '0.40', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('deepseek-v3.1', 'DeepSeek V3.1', '4.00', '12.00', '0.80', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('deepseek-v3', 'DeepSeek V3', '2.00', '8.00', '0.40', '0');

-- Kimi (CNY)
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('kimi-k2-thinking', 'Kimi K2 Thinking', '4.00', '16.00', '1.00', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('kimi-k2-0905', 'Kimi K2', '4.00', '16.00', '1.00', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('kimi-k2-turbo', 'Kimi K2 Turbo', '8.00', '58.00', '1.00', '0');

-- MiniMax
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('minimax-m2.1', 'MiniMax M2.1', '2.10', '8.40', '0.21', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('minimax-m2.1-lightning', 'MiniMax M2.1 Lightning', '2.10', '16.80', '0.21', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('minimax-m2', 'MiniMax M2', '2.10', '8.40', '0.21', '0');

-- GLM
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('glm-4.7', 'GLM-4.7', '2.00', '8.00', '0.40', '0');
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('glm-4.6', 'GLM-4.6', '2.00', '8.00', '0.40', '0');

-- Mimo
INSERT OR IGNORE INTO model_pricing (model_id, display_name, input_cost_per_million, output_cost_per_million, cache_read_cost_per_million, cache_creation_cost_per_million) VALUES ('mimo-v2-flash', 'Mimo V2 Flash', '0', '0', '0', '0');
