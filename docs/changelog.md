# CC Switch PHP 更新日志

## v1.1.0

### 新增

- **Playwright 浏览器 E2E 测试** — 31 个自动化测试覆盖 Web UI 全部 7 个标签页（Providers、MCP、Proxy、Prompts、Settings、Usage、Navigation），使用 `@playwright/test` 框架
- **`--host` 选项** — `serve` 命令新增 `--host` 参数，设为 `0.0.0.0` 可远程访问 Web UI
- **GitHub Actions CI/CD** — 自动化测试（PHPStan + PHPUnit）和多平台 Release 构建
- **数据库迁移 015** — 为 `universal_providers` 表添加 `settings_config`、`category`、`meta` 列

### 修复

- **MCP 添加 UI Bug** — Web UI `addMcp()` 未生成 `id` 且未将 command/args 打包为 `server_config` JSON，导致添加 MCP Server 失败
- **Prompt 保存 UI Bug** — Web UI `savePrompt()` 发送 `title` 字段，但 API 期望 `name` 字段
- **Migrator phar 兼容性** — `glob()` 不支持 `phar://` 路径，改用 `scandir()` 确保独立二进制内的迁移文件可被正确发现
- **UniversalProviderController** — `add()` 方法未传递 `provider_type`、`base_url`、`api_key` 等 NOT NULL 列的默认值，导致插入失败
- **独立二进制静态资源** — 修复 phar 包内静态文件无法正确 serve 的问题
- **Gemini Provider API Key** — 修复添加 Gemini Provider 时 API Key 未写入 `.env` 的问题

### 技术栈更新

- 新增 `package.json`、`playwright.config.mjs` 用于 UI 测试
- 测试套件：791 PHPUnit + 31 Playwright = 822 个测试用例
- CLI 命令：22 个，Service：21 个，Controller：19 个，API 路由：88 个

---

## v1.0.0

CC Switch PHP 的首个正式版本。本项目是 [CC Switch](https://github.com/nicepkg/cc-switch)（原 Tauri 桌面应用）的 PHP 重新实现版本，使用 PHP + Swoole 替代 Tauri，提供完整的 CLI 和 Web UI 管理能力。

### 核心功能

- **多应用支持** — 统一管理 Claude Code、Codex、Gemini CLI、OpenCode、OpenClaw 五种 AI 编程助手的配置
- **双模式 Provider 管理**
  - Switch 模式（Claude、Codex、Gemini）：一键切换当前激活的 API 提供商
  - Additive 模式（OpenCode、OpenClaw）：多提供商同时生效
- **丰富的预设模板** — 内置多个主流 API 提供商的预设配置，支持一键添加
- **MCP Server 管理** — 集中管理 MCP Server（stdio/http/sse），按应用启用并同步到配置文件
- **Skill 管理** — GitHub 仓库浏览、Skill 发现/安装/卸载、未管理 Skill 扫描、从应用导入
- **Prompt 管理** — 为各应用管理自定义系统提示词

### Web UI

- 基于 Swoole HTTP Server 的单页应用（SPA）
- 静态资源由 Swoole 直接托管，默认端口 `8080`
- 完整覆盖所有功能模块的图形化管理界面

### CLI 工具

- 基于 Symfony Console 的完整命令行工具集
- 22 个 CLI 命令覆盖所有核心功能
- 命令分组：`serve`、`provider:*`、`mcp:*`、`proxy:*`、`sync:*`、`backup`、`import`/`export`、`db:*`、`speedtest`、`env:check`、`migrate`

### API 代理服务器

- 内置 API 代理（默认端口 `15721`），支持作为独立服务或 Web Server 子监听器运行
- 请求格式转换（OpenAI ↔ Anthropic 等）
- 故障转移（Failover）— 主 Provider 不可用时自动切换备用
- 熔断器（Circuit Breaker）— 自动检测并隔离故障 Provider
- Live Takeover — 动态接管应用 API 请求
- 模型映射（Model Mapper）
- Thinking Budget / Signature 矫正器
- 使用统计与请求日志记录

### 数据管理

- SQLite 数据库存储（WAL 模式），自动迁移
- 数据库备份/恢复/清理，支持定时自动备份
- 数据库压缩（VACUUM）
- SQL 转储导入/导出
- Provider JSON 导入/导出

### 云同步

- WebDAV 同步（推送/拉取）
- 自动定时同步（可配置，每 5 分钟检查一次）
- 多 Profile 支持

### 其他功能

- Portable 便携模式（`portable.ini`）
- 环境变量冲突检测
- API 端点延迟测速
- Stream 流式响应可用性检测
- 全局 HTTP/HTTPS/SOCKS5 代理支持（含自动扫描和连通性测试）
- 模型定价管理
- Workspace 文件和 Memory 管理
- 会话管理（查看/恢复 Claude Code 会话）
- Claude Plugin 管理
- OpenClaw 专用配置（默认模型、模型目录、Agent 默认值）
- OMO 配置导入导出

### 构建与部署

- 支持通过 `build.sh` 构建独立二进制（基于 static-php-cli micro.sfx）
- 支持 Linux（x86_64/aarch64）和 macOS（x86_64/aarch64）
- 无需系统安装 PHP 即可运行

### 技术栈

- PHP 8.1+、Swoole 5.0+
- Symfony Console（CLI）、FastRoute（路由）
- SQLite + Medoo（ORM）、Guzzle（HTTP 客户端）
- Symfony YAML / Yosymfony TOML（配置文件读写）
