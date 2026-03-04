# API 参考文档

CC Switch PHP 后端提供 RESTful API，共 **88 个端点**，用于管理 Provider、MCP、代理、技能、Prompt 等资源。

## 基础信息

| 项目 | 值 |
|------|------|
| Base URL | `http://127.0.0.1:8080` |
| Content-Type | `application/json` |
| 认证 | 无（本地服务） |
| CORS | 允许所有来源 |

### 通用错误响应

```json
{
  "error": "错误描述信息"
}
```

常见 HTTP 状态码：

| 状态码 | 含义 |
|--------|------|
| 200 | 成功 |
| 201 | 创建成功 |
| 400 | 请求参数错误 |
| 404 | 资源不存在 |
| 405 | 方法不允许 |
| 409 | 资源冲突 |
| 500 | 服务器内部错误 |

### 通用成功响应

```json
{"ok": true}
```

---

## Provider（供应商管理）

### 获取 Provider 列表

```
GET /api/providers/{app}
```

**路径参数：**
- `app` — 应用类型（`claude` / `codex` / `gemini` / `opencode` / `openclaw`）

**响应：** Provider 数组

```json
[
  {
    "id": "uuid",
    "app_type": "claude",
    "name": "Anthropic Official",
    "settings_config": "{\"env\":{\"ANTHROPIC_API_KEY\":\"...\"}}",
    "category": "official",
    "is_current": 1,
    "sort_index": 0,
    "notes": null,
    "icon": null,
    "icon_color": null,
    "meta": "{}"
  }
]
```

### 获取单个 Provider

```
GET /api/providers/{app}/{id}
```

**路径参数：**
- `app` — 应用类型
- `id` — Provider UUID

**响应：** Provider 对象，或 `404`

### 新增 Provider

```
POST /api/providers/{app}
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | Provider 名称 |
| `settings_config` | string (JSON) | 否 | 配置 JSON |
| `id` | string | 否 | 自定义 UUID（默认自动生成） |
| `website_url` | string | 否 | 网站 URL |
| `category` | string | 否 | 分类 |
| `sort_index` | int | 否 | 排序索引 |
| `notes` | string | 否 | 备注 |
| `icon` | string | 否 | 图标 |
| `icon_color` | string | 否 | 图标颜色 |
| `meta` | string (JSON) | 否 | 元数据 |

**响应：** `201` + 创建的 Provider 对象

### 更新 Provider

```
PUT /api/providers/{app}/{id}
```

**请求体：** 可更新字段：`name`, `settings_config`, `website_url`, `category`, `sort_index`, `notes`, `icon`, `icon_color`, `meta`

**响应：** `{"ok": true}`

### 删除 Provider

```
DELETE /api/providers/{app}/{id}
```

**响应：** `{"ok": true}`

### 切换当前 Provider

```
POST /api/providers/{app}/{id}/switch
```

将指定 Provider 设置为当前激活的 Provider，并写入对应应用的配置文件。

**响应：** `{"ok": true}`

### 重新排序

```
POST /api/providers/{app}/reorder
```

**请求体：**

```json
{
  "items": [
    {"id": "uuid-1"},
    {"id": "uuid-2"}
  ]
}
```

**响应：** `{"ok": true}`

### 导入 Provider

```
POST /api/providers/import
```

**请求体：**

```json
{
  "providers": [
    {
      "app_type": "claude",
      "name": "My Provider",
      "settings_config": "{}"
    }
  ]
}
```

**响应：** `{"imported": 3}`

### 导出 Provider

```
GET /api/providers/export
```

**查询参数：**
- `app`（可选）— 仅导出指定应用的 Provider；省略则导出全部

**响应：**

```json
{
  "providers": [...]
}
```

### 获取 Provider 预设

```
GET /api/providers/presets/{app}
```

返回指定应用类型的内置 Provider 模板。

**响应：** 预设数组

### 获取端点列表

```
GET /api/providers/{app}/{id}/endpoints
```

获取 Provider 的 API 端点列表。

### 添加端点

```
POST /api/providers/{app}/{id}/endpoints
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `url` | string | 是 | 端点 URL |

### 删除端点

```
DELETE /api/providers/{app}/{id}/endpoints
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `url` | string | 是 | 要删除的端点 URL |

---

## Universal Provider（通用供应商）

跨应用共享的供应商配置。

### 获取列表

```
GET /api/universal-providers
```

**响应：** Universal Provider 数组

### 新增

```
POST /api/universal-providers
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | string | 否 | UUID（默认自动生成） |
| `name` | string | 是 | 名称 |
| `provider_type` | string | 否 | 提供商类型（默认 `openai`） |
| `apps` | string (JSON) | 否 | 启用的应用列表（默认 `{}`） |
| `base_url` | string | 否 | API 基础 URL（默认空） |
| `api_key` | string | 否 | API 密钥（默认空） |
| `models` | string (JSON) | 否 | 支持的模型列表（默认 `{}`） |
| `website_url` | string | 否 | 网站 URL |
| `notes` | string | 否 | 备注 |
| `settings_config` | string (JSON) | 否 | 配置（默认 `{}`） |
| `category` | string | 否 | 分类 |
| `meta` | string (JSON) | 否 | 元数据（默认 `{}`） |

**响应：** `201` + 创建的对象

### 更新

```
PUT /api/universal-providers/{id}
```

**可更新字段：** `name`, `provider_type`, `apps`, `base_url`, `api_key`, `models`, `website_url`, `notes`, `settings_config`, `category`, `meta`

### 删除

```
DELETE /api/universal-providers/{id}
```

---

## MCP（Model Context Protocol 服务器）

### 获取 MCP 服务器列表

```
GET /api/mcp
```

### 新增/更新 MCP 服务器

```
POST /api/mcp
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | string | 是 | 服务器标识 |
| `name` | string | 否 | 显示名称 |
| `server_config` | string (JSON) | 否 | 服务器配置 JSON（包含 `type`, `command`, `args`, `url`, `env` 等） |
| `enabled_claude` | int | 否 | 是否启用于 Claude（0/1） |
| `enabled_codex` | int | 否 | 是否启用于 Codex（0/1） |
| `enabled_gemini` | int | 否 | 是否启用于 Gemini（0/1） |
| `enabled_opencode` | int | 否 | 是否启用于 OpenCode（0/1） |

**server_config 格式示例（stdio 类型）：**

```json
{
  "type": "stdio",
  "command": "npx",
  "args": ["-y", "@modelcontextprotocol/server-filesystem"],
  "env": {"HOME": "/home/user"}
}
```

**响应：** 更新后的 MCP 服务器对象

### 删除 MCP 服务器

```
DELETE /api/mcp/{id}
```

### 同步 MCP 配置

```
POST /api/mcp/sync
```

将数据库中的 MCP 配置同步写入到各应用的配置文件。

---

## Proxy（代理服务）

### 获取代理状态

```
GET /api/proxy/status
```

**响应：**

```json
{"running": true}
```

### 启动代理

```
POST /api/proxy/start
```

> 代理需通过 CLI 启动：`cc-switch proxy:start`

### 停止代理

```
POST /api/proxy/stop
```

### 获取代理配置

```
GET /api/proxy/config/{app}
```

**路径参数：** `app` — 应用类型

**响应：** ProxyConfig 对象

### 更新代理配置

```
PUT /api/proxy/config/{app}
```

**请求体：** 代理配置字段

### 获取健康状态

```
GET /api/proxy/health/{app}
```

**响应：**

```json
{
  "config": { ... },
  "circuit_breaker": { ... }
}
```

---

## Proxy Takeover（接管模式）

### 获取接管状态

```
GET /api/proxy/takeover/status
```

返回各应用原始配置备份状态。

### 启用接管

```
POST /api/proxy/takeover/{app}/enable
```

**请求体：**

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `host` | string | `127.0.0.1` | 代理主机 |
| `port` | int | `15721` | 代理端口 |

将应用的 API 配置指向代理服务器。

### 禁用接管

```
POST /api/proxy/takeover/{app}/disable
```

恢复应用的原始 API 配置。

---

## Failover（故障转移队列）

### 获取故障转移列表

```
GET /api/failover/{app}
```

### 添加故障转移 Provider

```
POST /api/failover/{app}
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `provider_id` | string | 是 | Provider UUID |
| `position` | int | 否 | 队列位置（默认 0） |

### 移除故障转移 Provider

```
DELETE /api/failover/{app}/{providerId}
```

---

## Circuit Breaker（熔断器）

### 获取熔断器状态

```
GET /api/proxy/circuit-breaker/{app}
```

返回指定应用所有 Provider 的健康检查记录。

### 重置熔断器

```
POST /api/proxy/circuit-breaker/{app}/{providerId}/reset
```

重置指定 Provider 的健康检查记录。

---

## Skills（技能管理）

### 获取技能列表

```
GET /api/skills
```

### 安装技能

```
POST /api/skills/install
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `repo_owner` | string | 是 | 仓库所有者 |
| `repo_name` | string | 是 | 仓库名称 |
| `directory` | string | 是 | 技能目录 |

**响应：** `201` + 安装的技能对象

### 删除技能

```
DELETE /api/skills/{id}
```

### 同步技能到应用

```
POST /api/skills/sync
```

### 扫描非托管技能

```
POST /api/skills/scan-unmanaged
```

### 从应用导入技能

```
POST /api/skills/import-from-apps
```

---

## Skill Repos（技能仓库）

### 获取仓库列表

```
GET /api/skill-repos
```

### 添加仓库

```
POST /api/skill-repos
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `owner` | string | 是 | 仓库所有者 |
| `name` | string | 是 | 仓库名称 |
| `branch` | string | 否 | 分支（默认 `main`） |

### 移除仓库

```
DELETE /api/skill-repos/{owner}/{name}
```

### 发现仓库中的技能

```
POST /api/skill-repos/{owner}/{name}/discover
```

---

## Prompts（提示词管理）

### 获取提示词列表

```
GET /api/prompts/{app}
```

### 新增提示词

```
POST /api/prompts/{app}
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string | 是 | 提示词名称 |
| `content` | string | 是 | 提示词内容 |
| `description` | string | 否 | 描述 |
| `enabled` | bool | 否 | 是否启用 |

**响应：** `201` + 创建的提示词对象

### 更新提示词

```
PUT /api/prompts/{app}/{id}
```

**可更新字段：** `name`, `content`, `description`, `enabled`

### 删除提示词

```
DELETE /api/prompts/{app}/{id}
```

---

## Settings（全局设置）

### 获取所有设置

```
GET /api/settings
```

**响应：** 键值对对象

### 更新设置

```
PUT /api/settings
```

**请求体：** 键值对对象，值为 `null` 时删除该设置。

```json
{
  "theme": "dark",
  "language": "zh-CN",
  "deprecated_key": null
}
```

---

## Global Proxy（全局网络代理）

### 获取代理配置

```
GET /api/settings/proxy
```

**响应：**

```json
{
  "url": "http://127.0.0.1:7890",
  "enabled": true
}
```

### 设置代理

```
PUT /api/settings/proxy
```

**请求体：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `url` | string\|null | 代理 URL，`null` 为禁用 |

### 测试代理

```
POST /api/settings/proxy/test
```

**请求体：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `url` | string | 可选，默认使用已保存的代理 URL |

### 扫描本地代理

```
POST /api/settings/proxy/scan
```

自动检测本地运行的代理服务。

---

## Model Pricing（模型定价）

### 获取所有定价

```
GET /api/settings/pricing
```

### 更新定价

```
PUT /api/settings/pricing/{id}
```

**路径参数：** `id` — 模型 ID

**请求体：** 定价字段

### 新增定价

```
POST /api/settings/pricing
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `model_id` | string | 是 | 模型 ID |
| `display_name` | string | 是 | 显示名称 |
| `input_cost_per_million` | string | 否 | 输入 Token 单价 |
| `output_cost_per_million` | string | 否 | 输出 Token 单价 |
| `cache_read_cost_per_million` | string | 否 | 缓存读取单价 |
| `cache_creation_cost_per_million` | string | 否 | 缓存创建单价 |

---

## Rectifier Settings（纠正器设置）

### 获取纠正器配置

```
GET /api/settings/rectifier
```

**响应：**

```json
{
  "signature_enabled": true,
  "budget_enabled": true
}
```

### 更新纠正器配置

```
PUT /api/settings/rectifier
```

**请求体：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `signature_enabled` | bool | 是否启用签名纠正 |
| `budget_enabled` | bool | 是否启用预算纠正 |

---

## Usage（用量统计）

### 获取摘要

```
GET /api/usage/summary
```

**查询参数：**
- `start`（可选）— 起始时间戳
- `end`（可选）— 结束时间戳

### 获取趋势

```
GET /api/usage/trends
```

**查询参数：** 同上

### 按 Provider 统计

```
GET /api/usage/providers
```

**查询参数：** 同上

### 按模型统计

```
GET /api/usage/models
```

**查询参数：** 同上

### 获取详细统计

```
GET /api/usage/stats
```

**查询参数：**
- `app`（可选，默认 `claude`）— 应用类型
- `start`（可选）— 起始时间戳（默认 24 小时前）
- `end`（可选）— 结束时间戳

### 获取请求日志

```
GET /api/usage/logs
```

**查询参数：**

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `app` | string | — | 按应用类型过滤 |
| `provider_id` | string | — | 按 Provider 过滤 |
| `limit` | int | 100 | 返回条数（最大 500） |
| `offset` | int | 0 | 偏移量 |

### 获取单条日志详情

```
GET /api/usage/logs/{id}
```

---

## Backup（备份管理）

### 获取备份列表

```
GET /api/backup/list
```

### 创建备份

```
POST /api/backup/create
```

**响应：**

```json
{"ok": true, "path": "/home/user/.cc-switch/backups/backup-2024-01-01.db"}
```

### 恢复备份

```
POST /api/backup/restore
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `filename` | string | 是 | 备份文件名 |

### 清理旧备份

```
POST /api/backup/cleanup
```

**请求体：**

| 字段 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `retain_count` | int | 10 | 保留最新 N 个备份 |

---

## Sync（WebDAV 同步）

### 推送

```
POST /api/sync/push
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `baseUrl` | string | 否 | WebDAV 服务器地址（省略则使用已保存配置） |
| `username` | string | 否 | 用户名 |
| `password` | string | 否 | 密码 |
| `remoteRoot` | string | 否 | 远程根路径 |
| `profile` | string | 否 | 配置文件名（默认 `default`） |

### 拉取

```
POST /api/sync/pull
```

**请求体：** 同 push

### 测试连接

```
POST /api/sync/test
```

**请求体：** 同 push

**响应：**

```json
{"connected": true}
```

---

## Sessions（会话管理）

### 获取会话列表

```
GET /api/sessions
```

扫描本地应用会话。

### 获取恢复命令

```
GET /api/sessions/{id}/resume-command
```

**查询参数：**
- `app`（可选，默认 `claude`）— 应用类型

**响应：**

```json
{"command": "claude --resume abc123"}
```

---

## Import（DeepLink 导入）

```
POST /api/import
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `url` | string | 是 | cc-switch:// DeepLink URL |

支持导入类型：`provider`, `mcp`, `prompt`, `skill`

**响应：**

```json
{"type": "provider", "id": "uuid"}
```

---

## Workspace（工作区文件管理）

### 获取文件列表

```
GET /api/workspace/files
```

### 读取文件

```
GET /api/workspace/files/{name}
```

**响应：**

```json
{"filename": "CLAUDE.md", "content": "..."}
```

### 写入文件

```
PUT /api/workspace/files/{name}
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `content` | string | 是 | 文件内容 |

### 获取日记列表

```
GET /api/workspace/memory
```

### 搜索日记

```
GET /api/workspace/memory/search
```

**查询参数：**
- `q` — 搜索关键词

### 读取日记

```
GET /api/workspace/memory/{date}
```

### 写入日记

```
PUT /api/workspace/memory/{date}
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `content` | string | 是 | 日记内容 |

### 删除日记

```
DELETE /api/workspace/memory/{date}
```

---

## OMO（配置导入导出）

### 获取配置

```
GET /api/omo/{variant}
```

**路径参数：** `variant` — 配置变体名称

### 导入配置

```
POST /api/omo/import/{variant}
```

从文件导入指定变体的配置。

### 导出配置

```
POST /api/omo/export/{variant}
```

**请求体：** 要导出的配置数据

---

## OpenClaw

### 获取默认模型

```
GET /api/openclaw/default-model
```

### 设置默认模型

```
PUT /api/openclaw/default-model
```

**请求体：** 模型配置对象

### 获取模型目录

```
GET /api/openclaw/model-catalog
```

### 设置模型目录

```
PUT /api/openclaw/model-catalog
```

**请求体：** 模型目录配置

### 获取 Agents 默认配置

```
GET /api/openclaw/agents-defaults
```

### 设置 Agents 默认配置

```
PUT /api/openclaw/agents-defaults
```

**请求体：** Agents 默认配置对象

---

## Claude Plugin（Claude 插件）

### 获取插件状态

```
GET /api/claude-plugin/status
```

### 应用插件

```
POST /api/claude-plugin/apply
```

**响应：**

```json
{"ok": true, "changed": true}
```

### 清除插件

```
POST /api/claude-plugin/clear
```

**响应：**

```json
{"ok": true, "changed": true}
```

---

## Stream Check（流式检测）

### 检测单个 Provider

```
POST /api/stream-check/{appType}/{providerId}
```

对指定 Provider 发起流式连通性测试。

### 检测所有 Provider

```
POST /api/stream-check/{appType}
```

**请求体：**

| 字段 | 类型 | 说明 |
|------|------|------|
| `proxy_targets_only` | bool | 仅检测代理目标（默认 `false`） |

**响应：**

```json
{
  "results": {
    "provider-uuid": {
      "status": "ok",
      "latency_ms": 234
    }
  }
}
```

### 获取检测配置

```
GET /api/stream-check/config
```

### 保存检测配置

```
PUT /api/stream-check/config
```

**请求体：** StreamCheckConfig 配置对象

---

## SpeedTest（测速）

```
POST /api/speedtest
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `urls` | string[] | 是 | 要测试的 URL 列表 |
| `timeout` | int | 否 | 超时秒数（默认 10） |

**响应：**

```json
{
  "results": [
    {
      "url": "https://api.anthropic.com",
      "latency_ms": 150,
      "status": "ok"
    }
  ]
}
```

---

## Env（环境变量检查）

### 检查环境冲突

```
GET /api/env/check
```

检测 shell 配置文件中与 CC Switch 冲突的环境变量设置。

### 删除冲突

```
POST /api/env/delete
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `conflicts` | array | 是 | 要删除的冲突列表 |

### 恢复备份

```
POST /api/env/restore
```

**请求体：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `backup_file` | string | 是 | 备份文件路径 |
