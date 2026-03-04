# 配置参考

本文档描述 CC Switch PHP 版本的所有配置项、数据目录结构和相关文件格式。

## 数据目录

CC Switch 的所有持久化数据存储在 `~/.cc-switch/` 目录中（便携模式下为可执行文件旁的 `./data/` 目录）。

```
~/.cc-switch/
├── cc-switch.db          # SQLite 数据库（WAL 模式）
├── cc-switch.db-wal      # WAL 日志文件
├── cc-switch.db-shm      # WAL 共享内存
├── proxy.pid             # 代理服务器 PID 文件
└── backups/              # 自动/手动备份（ZIP 格式）
    ├── cc-switch-20260304-120000.zip
    └── ...
```

### 便携模式

在可执行文件同目录下创建 `portable.ini` 文件即可启用便携模式：

```bash
touch portable.ini
```

便携模式下数据目录变为可执行文件旁的 `./data/`，方便整体复制迁移。

## 各应用配置路径

CC Switch 管理以下 AI 编程助手的配置文件：

### Claude Code

| 路径 | 说明 |
|------|------|
| `~/.claude/settings.json` | 主配置文件（优先） |
| `~/.claude/claude.json` | 旧版配置文件（兼容回退） |

**写入方式**: Switch 模式。将 Provider 的 `settings_config` 中的 `env` 字段合并到 settings.json，保留用户自定义设置。

**settings_config 格式**:
```json
{
  "env": {
    "ANTHROPIC_AUTH_TOKEN": "sk-ant-xxx",
    "ANTHROPIC_BASE_URL": "https://api.anthropic.com",
    "ANTHROPIC_MODEL": "claude-sonnet-4-5-20250929",
    "ANTHROPIC_DEFAULT_HAIKU_MODEL": "claude-haiku-4-5-20251001",
    "ANTHROPIC_DEFAULT_SONNET_MODEL": "claude-sonnet-4-5-20250929",
    "ANTHROPIC_DEFAULT_OPUS_MODEL": "claude-opus-4-5-20251101",
    "ANTHROPIC_REASONING_MODEL": "claude-opus-4-5-20251101"
  }
}
```

**注意**: 以下内部字段会在写入时自动过滤，不会写入 settings.json：
- `api_format` / `apiFormat`
- `openrouter_compat_mode` / `openrouterCompatMode`

### Codex CLI

| 路径 | 说明 |
|------|------|
| `~/.codex/auth.json` | 认证信息 |
| `~/.codex/config.toml` | 配置文件（TOML 格式） |

**写入方式**: Switch 模式。两阶段写入 — 先写 auth.json，再写 config.toml。如果 config.toml 写入失败，auth.json 会回滚到原始内容。写入 config.toml 时会保留现有的 `[mcp_servers]` 段。

**settings_config 格式**:
```json
{
  "auth": {
    "OPENAI_API_KEY": "sk-xxx"
  },
  "config": "model = \"gpt-5.2\"\napproval_mode = \"suggest\"\n"
}
```

### Gemini CLI

| 路径 | 说明 |
|------|------|
| `~/.gemini/.env` | 环境变量（KEY=VALUE 格式） |
| `~/.gemini/settings.json` | 配置文件 |

**写入方式**: Switch 模式。env 字段写入 `.env` 文件（权限 0600），config 字段合并到 settings.json。会根据是否配置了 `GEMINI_API_KEY` 自动设置 `security.auth.selectedType`。

**settings_config 格式**:
```json
{
  "env": {
    "GEMINI_API_KEY": "AIzaSy...",
    "GEMINI_MODEL": "gemini-2.5-pro"
  },
  "config": {
    "theme": "dark"
  }
}
```

### OpenCode

| 路径 | 说明 |
|------|------|
| `~/.config/opencode/opencode.json` | 主配置文件 |

**写入方式**: Additive 模式。所有 Provider 共存于 `provider` 对象下，按 Provider ID 存储。

**settings_config 格式** (每个 Provider):
```json
{
  "name": "my-anthropic",
  "type": "anthropic",
  "options": {
    "apiKey": "sk-ant-xxx",
    "baseURL": "https://api.anthropic.com"
  }
}
```

**生成的 opencode.json 结构**:
```json
{
  "$schema": "https://opencode.ai/config.json",
  "provider": {
    "uuid-1": { "name": "my-anthropic", "type": "anthropic", ... },
    "uuid-2": { "name": "my-openai", "type": "openai", ... }
  }
}
```

### OpenClaw

| 路径 | 说明 |
|------|------|
| `~/.openclaw/openclaw.json` | 主配置文件 |

**写入方式**: Additive 模式。所有 Provider 共存于 `models.providers` 对象下。

**settings_config 格式** (每个 Provider):
```json
{
  "baseUrl": "https://api.anthropic.com",
  "apiKey": "sk-ant-xxx",
  "models": ["claude-sonnet-4-5-20250929"]
}
```

**生成的 openclaw.json 结构**:
```json
{
  "models": {
    "mode": "merge",
    "providers": {
      "uuid-1": { "baseUrl": "...", "apiKey": "...", "models": [...] },
      "uuid-2": { ... }
    }
  }
}
```

## 代理配置

代理配置存储在 `proxy_config` 数据表中，每个应用类型一行（`claude`、`codex`、`gemini`）。

### 配置字段

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `app_type` | TEXT | — | 应用类型 (主键)：`claude` / `codex` / `gemini` |
| `enabled` | INTEGER | 0 | 是否启用代理 |
| `proxy_enabled` | INTEGER | 0 | 是否启用代理 (兼容字段) |
| `listen_address` | TEXT | `127.0.0.1` | 代理监听地址 |
| `listen_port` | INTEGER | `15721` | 代理监听端口 |
| `enable_logging` | INTEGER | 1 | 是否记录请求日志 |
| `auto_failover_enabled` | INTEGER | 0 | 是否启用自动故障转移 |
| `max_retries` | INTEGER | 3 | 最大重试次数 |
| `streaming_first_byte_timeout` | INTEGER | 60 | 流式请求首字节超时（秒） |
| `streaming_idle_timeout` | INTEGER | 120 | 流式请求空闲超时（秒） |
| `non_streaming_timeout` | INTEGER | 600 | 非流式请求超时（秒） |
| `circuit_failure_threshold` | INTEGER | 4 | 熔断器连续失败阈值 |
| `circuit_success_threshold` | INTEGER | 2 | 熔断器恢复所需连续成功数 |
| `circuit_timeout_seconds` | INTEGER | 60 | 熔断器 Open → Half-Open 超时 |
| `circuit_error_rate_threshold` | REAL | 0.6 | 熔断器错误率阈值 |
| `circuit_min_requests` | INTEGER | 10 | 计算错误率所需最小请求数 |
| `default_cost_multiplier` | TEXT | `1` | 默认费用乘数 |
| `pricing_model_source` | TEXT | `response` | 定价模型来源 (`response` / `request`) |

### 各应用默认参数

初始化时为三种应用预设不同的参数：

| 参数 | Claude | Codex | Gemini |
|------|--------|-------|--------|
| `max_retries` | 6 | 3 | 5 |
| `streaming_first_byte_timeout` | 90s | 60s | 60s |
| `streaming_idle_timeout` | 180s | 120s | 120s |
| `non_streaming_timeout` | 600s | 600s | 600s |
| `circuit_failure_threshold` | 8 | 4 | 4 |
| `circuit_success_threshold` | 3 | 2 | 2 |
| `circuit_timeout_seconds` | 90s | 60s | 60s |
| `circuit_error_rate_threshold` | 0.7 | 0.6 | 0.6 |
| `circuit_min_requests` | 15 | 10 | 10 |

## 全局设置

全局设置存储在 `settings` 表中（键值对），通过 `SettingsRepository` 读写。

### 基础设置

| 键 | 说明 | 示例值 |
|----|------|--------|
| `theme` | 主题 | `dark` / `light` |
| `language` | 界面语言 | `zh-CN` / `en` / `ja` |
| `auto_backup` | 是否启用自动备份 | `1` / `0` |
| `backup_dir` | 自定义备份目录 | `/path/to/backups` |
| `backup_interval_hours` | 自动备份间隔（小时） | `24` |
| `proxy_port` | 代理端口 | `15721` |
| `web_port` | Web UI 端口 | `8080` |

### 全局出站代理

| 键 | 说明 | 示例值 |
|----|------|--------|
| `global_proxy_url` | 全局出站代理 URL | `http://127.0.0.1:7890` / `socks5://127.0.0.1:1080` |

支持的代理协议: `http`、`https`、`socks5`、`socks5h`

常见扫描端口: 1080, 7890, 7891, 8080, 8118, 10808, 10809, 20171

### WebDAV 同步设置

| 键 | 说明 |
|----|------|
| `auto_sync` | 是否启用自动同步 (`1`/`0`) |
| `webdav_url` | WebDAV 服务器地址 |
| `webdav_username` | WebDAV 用户名 |
| `webdav_password` | WebDAV 密码 |
| `webdav_profile` | 同步配置文件名 (`default`) |

### Rectifier 设置

| 键 | 说明 | 默认值 |
|----|------|--------|
| `rectifier_signature_enabled` | 是否启用 Thinking 签名纠错器 | `1` |
| `rectifier_budget_enabled` | 是否启用 Thinking 预算纠错器 | `1` |

### Live Takeover 设置

| 键 | 说明 |
|----|------|
| `live_takeover_{appType}` | Takeover 是否活跃 (`1`/空) |
| `live_backup_{appType}` | 原始配置文件备份内容 |
| `live_backup_{appType}_at` | 备份时间戳 |

## 模型定价配置

模型定价存储在 `model_pricing` 数据表中，用于计算 API 请求的费用。

### 表结构

| 字段 | 说明 |
|------|------|
| `model_id` | 模型标识符（主键），如 `claude-opus-4-6-20260206` |
| `display_name` | 显示名称，如 `Claude Opus 4.6` |
| `input_cost_per_million` | 输入 Token 费用（每百万 Token，USD） |
| `output_cost_per_million` | 输出 Token 费用（每百万 Token，USD） |
| `cache_read_cost_per_million` | 缓存读取费用（每百万 Token，USD） |
| `cache_creation_cost_per_million` | 缓存创建费用（每百万 Token，USD） |

### 内置定价数据

系统预置以下模型的定价：

**Anthropic Claude 系列**:
- Claude Opus 4.6: $5 / $25 / $0.50 / $6.25
- Claude Opus 4.5: $5 / $25 / $0.50 / $6.25
- Claude Sonnet 4.5: $3 / $15 / $0.30 / $3.75
- Claude Haiku 4.5: $1 / $5 / $0.10 / $1.25
- Claude Opus 4/4.1: $15 / $75 / $1.50 / $18.75
- Claude Sonnet 4: $3 / $15 / $0.30 / $3.75
- Claude 3.5 Sonnet: $3 / $15 / $0.30 / $3.75
- Claude 3.5 Haiku: $0.80 / $4 / $0.08 / $1

**OpenAI GPT 系列**:
- GPT-5.2 / 5.2 Codex: $1.75 / $14 / $0.175 / $0
- GPT-5.3 Codex: $1.75 / $14 / $0.175 / $0
- GPT-5.1 / 5.1 Codex: $1.25 / $10 / $0.125 / $0
- GPT-5 / 5 Codex: $1.25 / $10 / $0.125 / $0

**Google Gemini 系列**:
- Gemini 3 Pro Preview: $2 / $12 / $0.2 / $0
- Gemini 3 Flash Preview: $0.5 / $3 / $0.05 / $0
- Gemini 2.5 Pro: $1.25 / $10 / $0.125 / $0
- Gemini 2.5 Flash: $0.3 / $2.5 / $0.03 / $0

**中国厂商** (价格单位可能为 CNY):
- Doubao Seed Code, DeepSeek V3/V3.1/V3.2
- Kimi K2/K2 Thinking/K2 Turbo
- MiniMax M2/M2.1, GLM-4.6/4.7, Mimo V2 Flash

可通过 API 添加自定义模型定价：
```
POST /api/settings/pricing
PUT  /api/settings/pricing/{id}
```

## 预设文件格式

预设文件存储在 `config/presets/` 目录下，为各应用类型提供预配置的 Provider 模板。

### 文件列表

- `config/presets/claude.json` — Claude Code 预设
- `config/presets/codex.json` — Codex CLI 预设
- `config/presets/gemini.json` — Gemini CLI 预设
- `config/presets/opencode.json` — OpenCode 预设
- `config/presets/openclaw.json` — OpenClaw 预设
- `config/presets/universal.json` — 通用 Provider 预设

### 预设 JSON 格式

每个预设文件是一个 JSON 数组，每个元素包含：

```json
[
  {
    "name": "Anthropic (官方)",
    "category": "official",
    "websiteUrl": "https://console.anthropic.com",
    "icon": "anthropic",
    "iconColor": "#D97757",
    "settingsConfig": {
      "env": {
        "ANTHROPIC_AUTH_TOKEN": "",
        "ANTHROPIC_BASE_URL": "https://api.anthropic.com"
      }
    },
    "endpointCandidates": [
      { "url": "https://api.anthropic.com" }
    ],
    "apiFormat": "anthropic"
  }
]
```

### 字段说明

| 字段 | 说明 |
|------|------|
| `name` | 预设名称 |
| `category` | 分类：`official`（官方）、`third-party`（第三方）、`domestic`（国内） |
| `websiteUrl` | Provider 官网 URL |
| `icon` | 图标标识 |
| `iconColor` | 图标颜色（十六进制） |
| `settingsConfig` | Provider 的配置内容（写入目标应用的配置文件） |
| `endpointCandidates` | 可选的 API 端点列表（存入 Provider meta） |
| `apiFormat` | API 格式：`anthropic` 或 `openai`（存入 Provider meta） |

## 环境变量

### 应用环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `HOME` | 用户主目录，决定数据目录位置 | 系统默认 |

### Provider 支持的环境变量 (Claude)

这些变量存储在 Provider 的 `settings_config.env` 中，切换 Provider 时会写入 `~/.claude/settings.json`：

| 变量 | 说明 |
|------|------|
| `ANTHROPIC_AUTH_TOKEN` | Anthropic API 密钥 |
| `ANTHROPIC_API_KEY` | Anthropic API 密钥（别名） |
| `ANTHROPIC_BASE_URL` | API 基础 URL |
| `ANTHROPIC_MODEL` | 默认模型 |
| `ANTHROPIC_DEFAULT_HAIKU_MODEL` | Haiku 族默认模型 |
| `ANTHROPIC_DEFAULT_SONNET_MODEL` | Sonnet 族默认模型 |
| `ANTHROPIC_DEFAULT_OPUS_MODEL` | Opus 族默认模型 |
| `ANTHROPIC_REASONING_MODEL` | 推理模型（thinking 模式下优先使用） |
| `ANTHROPIC_API_VERSION` | API 版本号（默认 `2023-06-01`） |

### Provider 支持的环境变量 (Codex)

| 变量 | 说明 |
|------|------|
| `OPENAI_API_KEY` | OpenAI API 密钥 |
| `OPENAI_BASE_URL` | API 基础 URL |

### Provider 支持的环境变量 (Gemini)

| 变量 | 说明 |
|------|------|
| `GEMINI_API_KEY` | Google AI API 密钥 |
| `GEMINI_MODEL` | 默认模型 |
| `API_BASE_URL` | API 基础 URL |

### 默认常量

以下常量定义在 `config/defaults.php` 中：

| 常量 | 值 | 说明 |
|------|-----|------|
| `DEFAULT_PROXY_PORT` | `15721` | 默认代理端口 |
| `DEFAULT_WEB_PORT` | `8080` | 默认 Web UI 端口 |
| `SCHEMA_VERSION` | `1` | 数据库 Schema 版本 |
