# CC Switch PHP 用户指南

## 项目简介

CC Switch PHP 是 [CC Switch](https://github.com/nicepkg/cc-switch)（原 Tauri 桌面应用）的 PHP 重新实现版本。它提供 CLI + Web UI 两种管理方式，用于统一管理以下 AI 编程助手的配置：

- **Claude Code** — Anthropic 的 CLI 编程助手
- **Codex** — OpenAI 的 CLI 编程助手
- **Gemini CLI** — Google 的 CLI 编程助手
- **OpenCode** — 开源 CLI 编程助手
- **OpenClaw** — 开源 CLI 编程助手

核心功能包括：Provider（API 提供商）管理与切换、MCP Server 管理、Skill 管理、Prompt 管理、API 代理与故障转移、数据备份与恢复、WebDAV 云同步等。

## 系统要求

- **PHP** >= 8.1
- **必需扩展**：
  - `ext-swoole` >= 5.0（HTTP 服务器 + 代理服务器）
  - `ext-pdo_sqlite`（数据库存储）
- **可选工具**：
  - Composer（源码安装时需要）
  - curl 或 wget（构建独立二进制时下载 micro.sfx 需要）

## 安装方式

### 方式一：Composer 安装（开发环境）

```bash
git clone <repo-url> cc-switch-php
cd cc-switch-php
composer install
```

安装完成后通过 `bin/cc-switch` 启动：

```bash
php bin/cc-switch serve
```

### 方式二：独立二进制（推荐生产环境）

项目提供 `build.sh` 脚本，利用 [static-php-cli](https://github.com/crazywhalecc/static-php-cli) 将 PHP 运行时和应用打包为单个可执行文件，无需系统安装 PHP。

```bash
./build.sh
```

构建流程：
1. 检测当前平台（支持 Linux/macOS，x86_64/aarch64）
2. 下载包含 swoole、pdo_sqlite 等扩展的 PHP 8.3 micro.sfx
3. 安装生产依赖（`composer install --no-dev`）
4. 使用 [box](https://github.com/box-project/box) 编译 `.phar`
5. 合并 micro.sfx + .phar 生成独立二进制文件

产物位于 `build/cc-switch`，可直接运行：

```bash
./build/cc-switch serve
```

## 快速开始

### 1. 启动服务

```bash
# 默认在 http://127.0.0.1:8080 启动 Web UI
cc-switch serve

# 指定端口
cc-switch serve --port 9090

# 同时启动代理服务器
cc-switch serve --with-proxy

# 自定义代理端口（默认 15721）
cc-switch serve --with-proxy --proxy-port 15800
```

### 2. 添加 Provider

```bash
# 查看 Claude 可用预设
cc-switch provider:list claude --presets

# 从预设添加（指定预设编号和 API Key）
cc-switch provider:add claude --preset 0 --api-key sk-xxx

# 自定义添加
cc-switch provider:add claude --name "My Provider" --config '{"env":{"ANTHROPIC_API_KEY":"sk-xxx"}}'
```

### 3. 切换 Provider

```bash
# 列出已有 Provider
cc-switch provider:list claude

# 切换到指定 Provider（使用 ID）
cc-switch provider:switch claude <provider-id>
```

### 4. 打开 Web UI

启动 `serve` 后在浏览器访问 `http://127.0.0.1:8080`，即可使用图形界面管理所有功能。

## CLI 命令参考

### 服务管理

| 命令 | 说明 |
|------|------|
| `serve` | 启动 Web UI 服务器 |
| | `--port, -p`：Web UI 端口（默认 `8080`）|
| | `--with-proxy`：同时启动代理服务器 |
| | `--proxy-port`：代理端口（默认 `15721`）|

### Provider 管理

| 命令 | 说明 |
|------|------|
| `provider:list <app>` | 列出指定应用的 Provider |
| | `--presets`：显示可用预设模板 |
| `provider:add <app>` | 添加 Provider |
| | `--preset, -p`：预设编号 |
| | `--name`：自定义名称 |
| | `--api-key, -k`：API Key |
| | `--base-url`：自定义 Base URL |
| | `--config, -c`：JSON 格式配置 |
| `provider:switch <app> <id>` | 切换到指定 Provider（仅 switch 模式的应用：claude, codex, gemini）|
| `provider:delete <app> <id>` | 删除指定 Provider |

> `<app>` 可选值：`claude`、`codex`、`gemini`、`opencode`、`openclaw`

### MCP Server 管理

| 命令 | 说明 |
|------|------|
| `mcp:list` | 列出所有 MCP Server |
| `mcp:add` | 添加或更新 MCP Server |
| | `--id`：Server ID（省略则使用 name）|
| | `--name`：显示名称（必需）|
| | `--command`：命令（stdio 类型必需）|
| | `--args`：逗号分隔的参数列表 |
| | `--url`：URL（http/sse 类型必需）|
| | `--type`：类型 `stdio`/`http`/`sse`（默认 `stdio`）|
| | `--apps`：逗号分隔的应用列表（默认 `claude`）|
| `mcp:delete <id>` | 删除 MCP Server |
| `mcp:sync [app]` | 将 MCP Server 配置同步到应用配置文件（省略 app 则同步所有）|

### 代理管理

| 命令 | 说明 |
|------|------|
| `proxy:start` | 启动独立代理服务器 |
| | `--host`：监听地址（默认 `127.0.0.1`）|
| | `--port, -p`：监听端口（默认 `15721`）|
| `proxy:stop` | 停止代理服务器 |

### WebDAV 同步

| 命令 | 说明 |
|------|------|
| `sync:push` | 推送数据库到 WebDAV 远端 |
| `sync:pull` | 从 WebDAV 远端拉取数据库 |
| | `--url`：WebDAV 地址 |
| | `--username`：用户名 |
| | `--password`：密码 |
| | `--profile`：同步配置名（默认 `default`）|

> 若已在 Web UI 设置中配置 WebDAV 信息，CLI 会自动读取，无需重复指定。

### 导入导出

| 命令 | 说明 |
|------|------|
| `import <app> <file>` | 从 JSON 文件导入 Provider |
| `export <app>` | 导出 Provider 为 JSON |
| | `--output, -o`：输出文件路径（默认输出到 stdout）|
| `db:import <file>` | 从 SQL 转储文件导入数据库 |
| `db:export [file]` | 导出数据库为 SQL 转储文件（默认 `cc-switch-export.sql`）|

### 备份管理

| 命令 | 说明 |
|------|------|
| `backup` | 创建数据库备份 |
| `backup --list` | 列出已有备份 |
| `backup --restore <file>` | 从备份恢复（恢复前自动创建安全备份）|
| `backup --cleanup [N]` | 清理旧备份，保留最近 N 个（默认 10）|

### 数据库维护

| 命令 | 说明 |
|------|------|
| `migrate` | 运行数据库迁移 |
| `migrate --status` | 查看迁移状态 |
| `db:compact` | 压缩 SQLite 数据库（VACUUM）以回收空间 |

### 诊断工具

| 命令 | 说明 |
|------|------|
| `speedtest <app> [providerId]` | 测试 Provider API 端点延迟 |
| | `--timeout, -t`：超时秒数（默认 `8`）|
| `env:check` | 检查可能与代理冲突的环境变量 |

## Web UI 使用说明

启动 `serve` 命令后，访问 `http://127.0.0.1:8080` 即可进入 Web UI。Web UI 为单页应用（SPA），提供以下功能模块的图形化管理：

- **Provider 管理** — 添加/编辑/删除/切换各应用的 API 提供商，支持从预设模板快速添加
- **Universal Provider** — 管理通用提供商配置
- **MCP Server 管理** — 添加/编辑/删除 MCP Server，选择启用的应用，一键同步配置
- **Skill 管理** — 浏览 Skill 仓库、发现/安装/删除 Skill，扫描未管理的 Skill
- **Prompt 管理** — 管理各应用的自定义 Prompt
- **代理控制** — 启停代理服务器、查看状态、配置故障转移链、Live Takeover 模式
- **速度测试** — 可视化测试各 Provider 端点的延迟
- **Stream 检测** — 检测 Provider 的流式响应可用性
- **备份管理** — 创建/恢复/清理备份
- **WebDAV 同步** — 配置 WebDAV 连接信息，手动推送/拉取
- **设置** — 全局代理、自动备份间隔、自动同步、模型定价、环境变量检查
- **使用统计** — 查看请求日志、Provider/模型使用趋势和汇总
- **Workspace** — 管理工作区文件和 Memory 记忆文件
- **会话管理** — 查看和恢复 Claude Code 会话
- **Claude Plugin** — 管理 Claude 插件的应用/清除
- **OpenClaw 配置** — 管理默认模型、模型目录、Agent 默认值
- **OMO 导入导出** — OpenCode/OpenClaw 配置的导入导出

## 功能详解

### Provider 管理

Provider 是 API 提供商的配置集合。CC Switch 支持两种 Provider 模式：

- **Switch 模式**（Claude、Codex、Gemini）：同一时刻只有一个 Provider 处于激活状态，切换时会修改对应应用的配置文件
- **Additive 模式**（OpenCode、OpenClaw）：所有 Provider 同时生效，配置会追加到应用配置中

每个应用都提供丰富的预设模板（`config/presets/` 目录），涵盖各大 API 中转服务和官方接口。

### MCP Server 管理

[MCP (Model Context Protocol)](https://modelcontextprotocol.io/) Server 可为 AI 助手提供额外的工具和上下文。CC Switch 允许你集中管理 MCP Server，并选择性地将其同步到各个应用的配置文件中。

支持的 MCP Server 类型：
- `stdio` — 通过标准输入/输出通信
- `http` — 通过 HTTP 通信
- `sse` — 通过 Server-Sent Events 通信

### Skill 管理

Skill 是可安装到 Claude Code 等应用中的扩展脚本（如自定义斜杠命令）。CC Switch 支持：

- 管理 Skill 仓库（GitHub 仓库）
- 发现仓库中的可用 Skill
- 安装/卸载 Skill 到指定应用
- 扫描系统中未被管理的 Skill
- 从应用配置中导入已有 Skill

### Prompt 管理

管理各应用的自定义系统提示词（System Prompt）。可为每个应用创建多个 Prompt 配置。

### 代理服务器

CC Switch 内置 API 代理服务器（默认端口 `15721`），提供以下高级功能：

- **请求转发** — 透明代理 AI API 请求
- **格式转换** — 在不同 API 格式之间转换（如 OpenAI ↔ Anthropic）
- **故障转移（Failover）** — 当主 Provider 不可用时自动切换到备用 Provider
- **熔断器（Circuit Breaker）** — 自动检测故障 Provider 并临时停止转发
- **Live Takeover** — 动态接管应用的 API 请求到代理
- **使用统计** — 记录请求日志、统计用量和费用
- **模型映射** — 在不同 Provider 之间映射模型名称
- **Thinking Budget 矫正器** — 矫正 thinking budget 参数
- **Thinking Signature 矫正器** — 矫正 thinking signature 参数

### 全局代理

CC Switch 支持配置全局 HTTP/HTTPS/SOCKS5 代理，用于访问需要代理的 API 端点。可通过 Web UI 配置，支持自动扫描系统代理和连通性测试。

### 备份与恢复

- 手动或定时自动备份数据库（默认间隔 24 小时，可自定义）
- 备份文件存储在数据目录下
- 恢复前自动创建安全备份，防止数据丢失
- 支持清理旧备份

### WebDAV 同步

支持通过 WebDAV 协议将数据库同步到云端（如坚果云、NextCloud 等）：

- 手动推送/拉取
- 自动定时同步（每 5 分钟检查一次，需在设置中启用）
- 支持多配置文件（profile）

### Portable 模式

在可执行文件同目录下创建 `portable.ini` 文件即可启用便携模式：

- 数据目录从 `~/.cc-switch/` 变更为可执行文件同目录下的 `data/`
- 适合 U 盘随身携带使用

## 数据存储

- **默认数据目录**：`~/.cc-switch/`
- **便携模式数据目录**：`<可执行文件目录>/data/`
- **数据库文件**：`cc-switch.db`（SQLite，WAL 模式）
- **备份目录**：数据目录下的备份文件

## FAQ

### Q: 启动时提示 ext-swoole 未安装？

安装 Swoole 扩展：
```bash
pecl install swoole
```
或使用独立二进制版本，已内置所有必需扩展。

### Q: Claude/Codex/Gemini 切换和 OpenCode/OpenClaw 有什么区别？

Claude、Codex、Gemini 使用 **Switch 模式**，同一时刻只能激活一个 Provider。OpenCode 和 OpenClaw 使用 **Additive 模式**，所有 Provider 同时生效。

### Q: 代理服务器有什么用？

代理服务器可以在不修改应用配置的情况下，实现故障转移、格式转换、使用统计等高级功能。启动代理后，将应用的 API Base URL 指向 `http://127.0.0.1:15721` 即可。

### Q: 如何在多台机器之间同步配置？

使用 WebDAV 同步功能。在 Web UI 设置中配置 WebDAV 连接信息，然后手动推送/拉取或启用自动同步。

### Q: 如何检查环境变量是否冲突？

运行 `cc-switch env:check` 检查系统环境变量和 Shell 配置文件中可能与 CC Switch 代理设置冲突的变量（如 `ANTHROPIC_API_KEY`、`ANTHROPIC_BASE_URL` 等）。

### Q: 支持哪些平台？

源码方式支持任何可运行 PHP 8.1+ 和 Swoole 的平台。独立二进制支持 Linux（x86_64/aarch64）和 macOS（x86_64/aarch64）。
