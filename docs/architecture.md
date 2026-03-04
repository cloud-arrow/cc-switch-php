# 系统架构

CC Switch PHP 版本是一个基于 Swoole HTTP 服务器的后端应用，为多种 AI 编程助手（Claude Code、Codex CLI、Gemini CLI、OpenCode、OpenClaw）提供统一的 Provider 管理、配置切换和 API 代理功能。

## 系统架构概览

```
┌─────────────────────────────────────────────────────────────┐
│                    前端 (浏览器)                                │
│                  Alpine.js + Pico CSS SPA                     │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTP REST API
┌──────────────────────────▼──────────────────────────────────┐
│                  HTTP 层 (Swoole HTTP Server)                │
│  ┌──────────┐  ┌────────────┐  ┌──────────────────────┐     │
│  │  Router   │  │ Controller │  │ Static File Server   │     │
│  │(FastRoute)│→ │  (19 个)   │  │ (SPA fallback)       │     │
│  └──────────┘  └─────┬──────┘  └──────────────────────┘     │
└──────────────────────┼──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                     Service 层                               │
│  ProviderService, McpService, SkillService,                  │
│  ProxyConfigService, SessionService, SettingsService, ...    │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                   Repository 层                              │
│  ProviderRepository, McpRepository, SettingsRepository,      │
│  ProxyConfigRepository, RequestLogRepository, ...            │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│               数据库 (SQLite + Medoo ORM)                     │
│  ~/.cc-switch/cc-switch.db  (WAL 模式, foreign_keys=ON)      │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                   代理服务器 (独立端口)                         │
│  ProxyServer (127.0.0.1:15721)                              │
│  ┌─────────────┐  ┌──────────────┐  ┌───────────────┐      │
│  │RequestHandler│→ │CircuitBreaker│→ │FailoverManager│      │
│  └──────┬──────┘  └──────────────┘  └───────────────┘      │
│         │                                                    │
│  ┌──────▼──────┐  ┌──────────────┐  ┌───────────────┐      │
│  │ ModelMapper  │  │FormatConverter│  │ StreamHandler │      │
│  └─────────────┘  └──────────────┘  └───────────────┘      │
│         │                                                    │
│  ┌──────▼──────┐  ┌──────────────────────────────────┐      │
│  │ UsageLogger  │  │ Rectifier (Signature / Budget)   │      │
│  └─────────────┘  └──────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────┘
```

## 目录结构

```
src/
├── App.php                    # 应用引导：数据目录、SQLite 初始化、迁移
├── Command/                   # CLI 命令（Symfony Console）
│   ├── BackupCommand.php      # 备份管理
│   ├── CompactCommand.php     # 数据库压缩
│   ├── DbExportCommand.php    # 数据库导出
│   ├── DbImportCommand.php    # 数据库导入
│   ├── EnvCheckCommand.php    # 环境检查
│   ├── ExportCommand.php      # 数据导出
│   ├── ImportCommand.php      # 数据导入
│   ├── MigrateCommand.php     # 数据库迁移
│   ├── ServeCommand.php       # 启动 HTTP 服务器
│   ├── SpeedTestCommand.php   # Provider 速度测试
│   ├── Mcp/                   # MCP Server 管理命令
│   ├── Provider/              # Provider 管理命令
│   ├── Proxy/                 # 代理服务器启停命令
│   └── Sync/                  # WebDAV 同步命令
├── ConfigWriter/              # 配置写入器（策略模式）
│   ├── WriterInterface.php    # 写入器接口
│   ├── WriterFactory.php      # 工厂类
│   ├── ClaudeWriter.php       # Claude Code 配置写入
│   ├── CodexWriter.php        # Codex CLI 配置写入
│   ├── GeminiWriter.php       # Gemini CLI 配置写入
│   ├── OpenCodeWriter.php     # OpenCode 配置写入
│   └── OpenClawWriter.php     # OpenClaw 配置写入
├── Database/
│   ├── Database.php           # PDO + Medoo 封装
│   ├── Migrator.php           # SQL 迁移执行器
│   └── Repository/            # 数据访问层（14 个 Repository）
├── DeepLink/                  # Deep Link 导入
│   ├── DeepLinkParser.php     # ccswitch:// 协议解析
│   ├── McpImporter.php        # MCP 导入
│   ├── PromptImporter.php     # Prompt 导入
│   ├── ProviderImporter.php   # Provider 导入
│   └── SkillImporter.php      # Skill 导入
├── Http/
│   ├── Router.php             # FastRoute 路由定义（88 路由）
│   ├── Server.php             # Swoole HTTP Server 主入口
│   └── Controller/            # API 控制器（19 个）
├── Model/                     # 数据模型 / DTO（13 个）
├── Proxy/                     # API 代理模块
│   ├── ProxyServer.php        # 代理服务器（Swoole 子监听器）
│   ├── RequestHandler.php     # 请求处理管线
│   ├── CircuitBreaker.php     # 熔断器状态机
│   ├── FailoverManager.php    # 故障转移管理器
│   ├── ModelMapper.php        # 模型名称映射
│   ├── FormatConverter.php    # Anthropic ↔ OpenAI 格式转换
│   ├── StreamHandler.php      # SSE 流式响应处理
│   ├── UsageLogger.php        # 用量日志记录与费用计算
│   ├── ThinkingSignatureRectifier.php  # Thinking 签名纠错器
│   └── ThinkingBudgetRectifier.php     # Thinking 预算纠错器
├── Service/                   # 业务逻辑层（21 个服务）
└── Util/
    └── AtomicFile.php         # 原子文件写入工具
```

## HTTP 层

### 服务器架构

应用使用 **Swoole HTTP Server** 作为运行时，监听 `127.0.0.1:8080`（可配置）：

- **路由**: 使用 `nikic/fast-route` 库，在 `Router` 类中定义所有 API 路由
- **静态文件**: Swoole 内置静态文件服务，路径 `/assets` 映射到 `public/assets/`
- **SPA Fallback**: 非 API、非静态文件的请求返回 `public/index.html`
- **CORS**: 所有 API 请求自动添加 CORS 头
- **定时任务**: Worker 0 上运行健康检查（60s）、自动备份（30min）、自动同步（5min）

### 控制器列表

| 控制器 | 职责 |
|--------|------|
| `ProviderController` | Provider CRUD、切换、导入导出、预设、端点管理、排序 |
| `UniversalProviderController` | 通用 Provider CRUD（跨应用共享） |
| `McpController` | MCP Server CRUD、同步到应用配置 |
| `ProxyController` | 代理启停、配置、健康状态、Takeover、Failover、熔断器 |
| `SkillController` | Skill/Skill Repo CRUD、发现、安装、同步 |
| `PromptController` | 自定义 Prompt CRUD（按应用类型） |
| `SettingsController` | 全局设置、全局代理、模型定价、Rectifier 设置 |
| `UsageController` | 用量统计：摘要、趋势、Provider/模型维度、日志 |
| `SyncController` | WebDAV 云同步：推送、拉取、测试 |
| `SpeedTestController` | Provider API 速度测试 |
| `SessionController` | Claude Code 会话列表、恢复命令 |
| `ImportController` | Deep Link 导入（ccswitch:// 协议） |
| `WorkspaceController` | 工作区文件管理、Memory 日志管理 |
| `OmoController` | OMO 配置导入导出 |
| `OpenClawController` | OpenClaw 专用：默认模型、模型目录、Agent 默认值 |
| `ClaudePluginController` | Claude 插件状态管理 |
| `StreamCheckController` | Provider 流式连通性检测 |
| `BackupController` | 备份创建、恢复、清理、列表 |
| `EnvController` | 环境变量检查、删除、恢复 |

## Service 层

| 服务类 | 职责 |
|--------|------|
| `ProviderService` | Provider CRUD、Switch/Additive 模式切换、预设加载、导入导出 |
| `ProxyConfigService` | 代理配置 CRUD |
| `McpService` | MCP Server 管理、同步到各应用配置文件 |
| `SkillService` | Skill 安装/卸载、同步到 Claude `~/.claude/commands/` |
| `SkillRepoService` | Skill 仓库管理、从 GitHub 发现 Skills |
| `PromptService` | 自定义 Prompt 管理 |
| `SettingsService` | 全局设置读写 |
| `SessionService` | Claude Code 会话管理（读取 `~/.claude/projects/` 目录） |
| `BackupService` | 数据备份与恢复（ZIP 压缩） |
| `SpeedTestService` | API 连通性和速度测试 |
| `StreamCheckService` | 流式 API 连通性检测 |
| `UsageStatsService` | 用量统计聚合查询 |
| `WebDavSyncService` | WebDAV 云同步 |
| `GlobalProxyService` | 全局出站代理管理（HTTP/SOCKS5） |
| `LiveTakeoverService` | 代理 Takeover：备份原始配置，将应用指向本地代理 |
| `EnvCheckerService` | 环境变量冲突检查 |
| `ClaudePluginService` | Claude 插件管理 |
| `OmoService` | OMO 格式导入导出 |
| `OpenClawConfigService` | OpenClaw 特定配置管理 |
| `UniversalProviderService` | 通用 Provider 管理（一次配置，多应用共享） |
| `WorkspaceService` | 工作区文件和 Memory 日志管理 |

## Repository 层

| Repository | 数据表 | 职责 |
|------------|--------|------|
| `ProviderRepository` | `providers` | Provider CRUD，切换当前 Provider |
| `UniversalProviderRepository` | `universal_providers` | 通用 Provider CRUD |
| `McpRepository` | `mcp_servers` | MCP Server CRUD |
| `PromptRepository` | `prompts` | 自定义 Prompt CRUD |
| `SkillRepository` | `skills` | Skill CRUD |
| `SkillRepoRepository` | `skill_repos` | Skill 仓库 CRUD |
| `SettingsRepository` | `settings` | 键值对设置读写 |
| `ProxyConfigRepository` | `proxy_config` | 代理配置 CRUD |
| `HealthRepository` | `provider_health` | Provider 健康状态 |
| `RequestLogRepository` | `proxy_request_logs` | 请求日志记录与查询 |
| `FailoverQueueRepository` | `providers` (in_failover_queue) | 故障转移队列 |
| `ModelPricingRepository` | `model_pricing` | 模型定价查询 |
| `StreamCheckRepository` | `stream_check_logs` | 流式检测日志 |

## ConfigWriter 模块（策略模式）

ConfigWriter 负责将 Provider 的 `settings_config` 写入到对应 CLI 工具的配置文件中。通过 `WriterFactory` 根据 `AppType` 创建对应的 Writer 实例。

### 两种写入模式

#### Switch 模式（互斥切换）

同一时刻只有一个 Provider 生效。切换时覆盖目标应用的配置文件。

**适用应用**: Claude Code、Codex CLI、Gemini CLI

| Writer | 目标配置文件 | 写入方式 |
|--------|------------|---------|
| `ClaudeWriter` | `~/.claude/settings.json` | 合并 `env` 字段到现有 settings.json，保留用户自定义设置 |
| `CodexWriter` | `~/.codex/auth.json` + `~/.codex/config.toml` | 两阶段写入：先写 auth.json，再写 config.toml（失败时回滚）。保留现有 `[mcp_servers]` 段 |
| `GeminiWriter` | `~/.gemini/.env` + `~/.gemini/settings.json` | 将 env 映射写入 `.env` 文件，将 config 合并到 settings.json |

#### Additive 模式（累加共存）

所有 Provider 共存于同一配置文件中，各自占据独立的配置节点。

**适用应用**: OpenCode、OpenClaw

| Writer | 目标配置文件 | 写入方式 |
|--------|------------|---------|
| `OpenCodeWriter` | `~/.config/opencode/opencode.json` | 在 `provider` 对象下按 Provider ID 添加/更新/删除条目 |
| `OpenClawWriter` | `~/.openclaw/openclaw.json` | 在 `models.providers` 对象下按 Provider ID 添加/更新/删除条目 |

### 写入流程

```
ProviderService.switchTo(id, app)
    ├── ProviderRepository.switchTo(id, appType)   # 更新数据库 is_current 标记
    ├── WriterFactory.create(appType)               # 创建对应 Writer
    └── Writer.write(provider)                      # 写入配置文件
        └── AtomicFile.writeJson(path, data)        # 原子写入防止文件损坏
```

## Proxy 模块

代理服务器监听 `127.0.0.1:15721`，拦截 AI CLI 工具的 API 请求，实现 Provider 自动切换、故障转移、格式转换和用量统计。

### 请求处理管线

```
客户端请求 (Claude Code / Codex CLI)
    │
    ▼
RequestHandler.handle()
    │
    ├── detectAppType()          # 从 URL 路径和 Header 识别应用类型
    │     /v1/messages → claude
    │     /v1/chat/completions → codex
    │     /v1beta/ → gemini
    │
    ├── loadConfig()             # 加载 proxy_config 表中的配置
    │
    ├── FailoverManager.resolve()  # 解析目标 Provider
    │     ├── 获取当前 Provider
    │     ├── CircuitBreaker.canPass() 检查
    │     └── 不可用时尝试 failover 队列
    │
    ├── ModelMapper.apply()      # 模型名称映射
    │     ├── haiku/sonnet/opus 族映射
    │     ├── thinking 模式下使用 reasoning model
    │     └── 默认模型回退
    │
    ├── FormatConverter          # 格式转换（如需要）
    │     ├── anthropicToOpenAI()
    │     └── openAIToAnthropic()
    │
    ├── 构建上游 URL 和 Headers
    │
    ├── 转发请求（流式 / 非流式）
    │     ├── StreamHandler.forward()    # SSE 流式转发
    │     └── Guzzle HTTP 非流式请求
    │
    ├── Rectifier 纠错（仅 400 错误）
    │     ├── ThinkingSignatureRectifier # 清除 thinking 签名
    │     └── ThinkingBudgetRectifier    # 修正 budget_tokens
    │
    ├── CircuitBreaker.record*()  # 记录成功/失败
    │
    └── UsageLogger.log()        # 记录用量和费用
```

### 熔断器状态机 (CircuitBreaker)

```
                    连续失败 >= threshold
                    或错误率 > threshold
    ┌──────────┐ ─────────────────────────→ ┌──────────┐
    │  Closed   │                            │   Open   │
    │ (健康)    │ ←─────────────────────────  │ (熔断)   │
    └──────────┘   连续成功 >= threshold      └────┬─────┘
         ▲                                        │
         │         超时时间到期                     │
         │         (circuit_timeout_seconds)       │
         │                                        ▼
         │                                  ┌──────────┐
         └──────── 连续成功 ≥ threshold ──── │ Half-Open│
                                            │ (恢复中) │
                   任何失败 ──────────────→  └──────────┘
                                     回到 Open
```

**状态说明**:
- **Closed** (关闭/健康): 请求正常通过
- **Open** (打开/熔断): 拒绝所有请求，等待超时后进入 Half-Open
- **Half-Open** (半开/恢复中): 允许少量请求通过，成功则关闭熔断器，失败则重新打开

**配置参数** (proxy_config 表):
- `circuit_failure_threshold`: 连续失败阈值（默认 4）
- `circuit_error_rate_threshold`: 错误率阈值（默认 0.6）
- `circuit_min_requests`: 最小请求数（用于计算错误率，默认 10）
- `circuit_success_threshold`: 恢复所需连续成功数（默认 2）
- `circuit_timeout_seconds`: Open → Half-Open 超时秒数（默认 60）

### 模型映射 (ModelMapper)

根据 Provider 的 `settings_config.env` 配置进行模型名称替换：

| 环境变量 | 匹配规则 | 用途 |
|----------|---------|------|
| `ANTHROPIC_DEFAULT_HAIKU_MODEL` | 模型名包含 "haiku" | 替换 Haiku 系列模型 |
| `ANTHROPIC_DEFAULT_SONNET_MODEL` | 模型名包含 "sonnet" | 替换 Sonnet 系列模型 |
| `ANTHROPIC_DEFAULT_OPUS_MODEL` | 模型名包含 "opus" | 替换 Opus 系列模型 |
| `ANTHROPIC_MODEL` | 无匹配时的默认回退 | 默认模型 |
| `ANTHROPIC_REASONING_MODEL` | thinking 模式启用时优先 | 推理模型 |

映射优先级: thinking 推理模型 > 族名匹配 > 默认模型 > 原始模型名

### 格式转换 (FormatConverter)

在 Anthropic Messages API 和 OpenAI Chat Completions API 格式之间自动转换：

**Anthropic → OpenAI**:
- `system` (顶层) → `messages[0].role = "system"`
- `stop_sequences` → `stop`
- `tools[].input_schema` → `tools[].function.parameters`
- `tool_use` content block → `tool_calls`
- `tool_result` content block → `role: "tool"` message

**OpenAI → Anthropic**:
- `messages[].role = "system"` → 顶层 `system`
- `stop` → `stop_sequences`
- `tools[].function` → `tools[].name/description/input_schema`
- `tool_calls` → `tool_use` content block
- `role: "tool"` → `tool_result` content block

### Rectifier 纠错器

当上游 API 返回 400 错误时，Rectifier 尝试自动修正请求并重试一次。

**ThinkingSignatureRectifier** — 处理 7 种签名相关错误：
- 清除 `thinking` 和 `redacted_thinking` content block
- 移除 `signature` 字段
- 条件性移除顶层 `thinking` 对象（当最后一条 assistant 消息含 `tool_use` 且无 thinking block 时）

**ThinkingBudgetRectifier** — 处理 `budget_tokens` 约束错误：
- 设置 `thinking.type = "enabled"`，`thinking.budget_tokens = 32000`
- 确保 `max_tokens ≥ 32001`（不足时设为 64000）

### 故障转移 (FailoverManager)

当当前 Provider 的熔断器打开时，自动切换到 failover 队列中的下一个健康 Provider：

```
FailoverManager.resolve(appType)
    ├── 获取当前 Provider (is_current = 1)
    ├── CircuitBreaker.canPass(providerId, appType)
    │     ├── 可通过 → 返回当前 Provider
    │     └── 不可通过 → 调用 next()
    └── next(): 遍历 failover 队列
          ├── 找到健康 Provider → switchTo() 并返回
          └── 全部不可用 → 返回 null (503)
```

## 数据库设计

数据库使用 SQLite（WAL 模式），存储在 `~/.cc-switch/cc-switch.db`。

### 数据表总览

| 表名 | 主键 | 说明 |
|------|------|------|
| `providers` | `(id, app_type)` | Provider 配置，包含 settings_config JSON |
| `provider_endpoints` | `id` (自增) | Provider 自定义端点 |
| `universal_providers` | `id` | 通用 Provider（跨应用共享，含 settings_config/category/meta） |
| `mcp_servers` | `id` | MCP Server 配置 |
| `prompts` | `(id, app_type)` | 自定义 Prompt |
| `skills` | `id` | Skill 定义（已安装） |
| `skill_repos` | `(owner, name)` | Skill 仓库源 |
| `settings` | `key` | 全局键值对设置 |
| `proxy_config` | `app_type` | 代理配置（每应用一行） |
| `provider_health` | `(provider_id, app_type)` | Provider 健康状态 |
| `proxy_request_logs` | `request_id` | API 请求日志（用量、费用） |
| `model_pricing` | `model_id` | 模型定价表 |
| `stream_check_logs` | `id` (自增) | 流式连通性检测日志 |

### 核心表结构

#### providers

```sql
CREATE TABLE providers (
    id TEXT NOT NULL,
    app_type TEXT NOT NULL,           -- 'claude'|'codex'|'gemini'|'opencode'|'openclaw'
    name TEXT NOT NULL,
    settings_config TEXT NOT NULL,    -- JSON: 各应用的配置内容
    website_url TEXT,
    category TEXT,
    created_at INTEGER,
    sort_index INTEGER,
    notes TEXT,
    icon TEXT,
    icon_color TEXT,
    meta TEXT NOT NULL DEFAULT '{}',  -- JSON: ProviderMeta 结构
    is_current INTEGER NOT NULL DEFAULT 0,     -- Switch 模式下是否为当前 Provider
    in_failover_queue INTEGER NOT NULL DEFAULT 0,  -- 是否在故障转移队列中
    PRIMARY KEY (id, app_type)
);
```

**meta JSON 结构** (`ProviderMeta`):
- `customEndpoints`: 自定义 API 端点列表
- `apiFormat`: API 格式 (`"anthropic"` / `"openai"`)
- `providerType`: Provider 类型 (`"claude"` / `"codex"` / `"openrouter"` 等)
- `costMultiplier`: 费用乘数
- `limitDailyUsd` / `limitMonthlyUsd`: 预算限制

#### proxy_config

```sql
CREATE TABLE proxy_config (
    app_type TEXT PRIMARY KEY,
    proxy_enabled INTEGER DEFAULT 0,
    listen_address TEXT DEFAULT '127.0.0.1',
    listen_port INTEGER DEFAULT 15721,
    enable_logging INTEGER DEFAULT 1,
    enabled INTEGER DEFAULT 0,
    auto_failover_enabled INTEGER DEFAULT 0,
    max_retries INTEGER DEFAULT 3,
    streaming_first_byte_timeout INTEGER DEFAULT 60,
    streaming_idle_timeout INTEGER DEFAULT 120,
    non_streaming_timeout INTEGER DEFAULT 600,
    circuit_failure_threshold INTEGER DEFAULT 4,
    circuit_success_threshold INTEGER DEFAULT 2,
    circuit_timeout_seconds INTEGER DEFAULT 60,
    circuit_error_rate_threshold REAL DEFAULT 0.6,
    circuit_min_requests INTEGER DEFAULT 10,
    default_cost_multiplier TEXT DEFAULT '1',
    pricing_model_source TEXT DEFAULT 'response',
    created_at TEXT,
    updated_at TEXT
);
```

#### proxy_request_logs

```sql
CREATE TABLE proxy_request_logs (
    request_id TEXT PRIMARY KEY,
    provider_id TEXT NOT NULL,
    app_type TEXT NOT NULL,
    model TEXT NOT NULL,
    request_model TEXT,              -- 映射前的原始模型名
    input_tokens INTEGER DEFAULT 0,
    output_tokens INTEGER DEFAULT 0,
    cache_read_tokens INTEGER DEFAULT 0,
    cache_creation_tokens INTEGER DEFAULT 0,
    input_cost_usd TEXT DEFAULT '0',
    output_cost_usd TEXT DEFAULT '0',
    cache_read_cost_usd TEXT DEFAULT '0',
    cache_creation_cost_usd TEXT DEFAULT '0',
    total_cost_usd TEXT DEFAULT '0',
    latency_ms INTEGER NOT NULL,
    first_token_ms INTEGER,          -- 首 Token 延迟（仅流式）
    duration_ms INTEGER,             -- 总耗时（仅流式）
    status_code INTEGER NOT NULL,
    error_message TEXT,
    session_id TEXT,
    provider_type TEXT,
    is_streaming INTEGER DEFAULT 0,
    cost_multiplier TEXT DEFAULT '1.0',
    created_at INTEGER NOT NULL
);
```

### 关键关系

- `provider_endpoints` → `providers`: 外键 `(provider_id, app_type)` 级联删除
- `provider_health` → `providers`: 外键 `(provider_id, app_type)` 级联删除
- `proxy_request_logs` 通过 `provider_id` 和 `app_type` 关联到 `providers`（无外键约束，允许保留已删除 Provider 的日志）
- `skills` 通过 `(repo_owner, repo_name)` 关联到 `skill_repos`（逻辑关系，无外键约束）

## 关键流程

### Provider 切换流程 (Switch 模式)

```
用户在 UI 点击「切换」
    │
    ▼
POST /api/providers/{app}/{id}/switch
    │
    ▼
ProviderController.switch()
    │
    ▼
ProviderService.switchTo(id, app)
    ├── 1. 验证 AppType 是否为 Switch 模式
    ├── 2. 查找目标 Provider
    ├── 3. ProviderRepository.switchTo()
    │       ├── UPDATE providers SET is_current = 0 WHERE app_type = ?
    │       └── UPDATE providers SET is_current = 1 WHERE id = ? AND app_type = ?
    ├── 4. WriterFactory.create(app) → ClaudeWriter / CodexWriter / GeminiWriter
    └── 5. Writer.write(provider)
            └── 写入对应 CLI 工具的配置文件
```

### 代理请求处理流程

```
Claude Code 发送请求
    POST http://127.0.0.1:15721/v1/messages
    │
    ▼
ProxyServer (Swoole 子监听器)
    │
    ▼
RequestHandler.handle(request, response)
    ├── 识别 appType = "claude"
    ├── 加载 proxy_config (enabled, timeouts, circuit breaker 参数)
    ├── FailoverManager.resolve("claude")
    │     ├── 获取 is_current=1 的 Provider
    │     ├── CircuitBreaker.canPass() → true: 使用当前 Provider
    │     └── CircuitBreaker.canPass() → false:
    │           FailoverManager.next() → 遍历 failover 队列
    ├── ModelMapper.apply(body, providerConfig)
    │     └── 例: "claude-sonnet-4-5" → "deepseek-v3" (按 env 配置)
    ├── FormatConverter (如 Provider 是 OpenAI 格式)
    │     └── anthropicToOpenAI(body)
    ├── 构建上游 URL (从 Provider 的 env 或 meta)
    ├── 构建上游 Headers (API Key, anthropic-version 等)
    ├── 转发请求
    │     ├── 流式: StreamHandler.forward() → SSE chunk 转发
    │     └── 非流式: Guzzle POST → 返回响应体
    ├── 400 错误时尝试 Rectifier 纠错并重试
    ├── CircuitBreaker 记录结果
    └── UsageLogger 记录用量和费用
```

## 与 Tauri 版本的架构对比

| 方面 | Tauri (桌面版) | PHP (轻量版) |
|------|---------------|-------------|
| **运行时** | Rust + WebView2 | Swoole HTTP Server |
| **前端打包** | Tauri 内嵌 WebView | 独立 SPA (浏览器访问) |
| **数据库** | 相同 SQLite schema | 相同 SQLite schema |
| **API 兼容性** | 完全相同的 REST API | 完全相同的 REST API |
| **代理服务器** | Rust hyper 实现 | Swoole + Guzzle 实现 |
| **系统托盘** | Tauri 原生托盘 | 无（纯后台服务） |
| **部署方式** | .dmg / .exe / .AppImage | 单文件 PHP binary 或 composer |
| **平台支持** | Windows / macOS / Linux | 任何支持 PHP + Swoole 的平台 |
| **配置文件格式** | 相同 | 相同 |
| **数据目录** | `~/.cc-switch/` | `~/.cc-switch/`（或便携模式 `./data/`） |
