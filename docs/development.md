# 开发指南

## 开发环境搭建

### 系统要求

| 依赖 | 最低版本 | 说明 |
|------|----------|------|
| PHP | 8.1+ | 需启用 `strict_types` |
| ext-swoole | 5.0+ | 异步 HTTP 服务器 |
| ext-pdo_sqlite | * | SQLite 数据库支持 |
| Composer | 2.x | PHP 包管理器 |

### 安装步骤

```bash
# 克隆仓库
git clone https://github.com/nicepkg/cc-switch-php.git
cd cc-switch-php

# 安装依赖
composer install

# 启动开发服务器
php bin/cc-switch serve --port=8080
```

### 主要依赖

| 包 | 用途 |
|----|------|
| `symfony/console` ^7.0 | CLI 命令框架 |
| `symfony/yaml` ^7.0 | YAML 解析 |
| `symfony/validator` ^7.0 | 数据验证 |
| `yosymfony/toml` ^1.0 | TOML 文件读写 |
| `nikic/fast-route` ^1.3 | HTTP 路由 |
| `guzzlehttp/guzzle` ^7.0 | HTTP 客户端 |
| `ramsey/uuid` ^4.0 | UUID 生成 |
| `catfan/medoo` ^2.1 | 数据库 ORM |

### 开发依赖

| 包 | 用途 |
|----|------|
| `phpunit/phpunit` ^10.0 | 单元/集成测试 |
| `phpstan/phpstan` ^2.1 | 静态分析 |

---

## 项目结构概览

```
cc-switch-php/
├── bin/
│   └── cc-switch              # CLI 入口文件
├── config/
│   ├── defaults.php            # 默认配置值
│   └── presets/                # Provider 预设模板
├── migrations/                 # SQLite 数据库迁移文件（001-015）
│   ├── 001_create_providers.sql
│   ├── 002_create_provider_endpoints.sql
│   ├── 003_create_universal_providers.sql
│   ├── 004_create_mcp_servers.sql
│   ├── 005_create_prompts.sql
│   ├── 006_create_skills.sql
│   ├── 007_create_skill_repos.sql
│   ├── 008_create_settings.sql
│   ├── 009_create_proxy_config.sql
│   ├── 010_create_provider_health.sql
│   ├── 011_create_request_logs.sql
│   ├── 012_create_failover_queue.sql
│   ├── 013_create_model_pricing.sql
│   ├── 014_create_stream_check_logs.sql
│   └── 015_alter_universal_providers.sql
├── public/                     # 前端静态资源（SPA）
├── src/
│   ├── App.php                 # 应用引导类（数据库初始化、迁移）
│   ├── Command/                # CLI 命令（Symfony Console）
│   │   ├── ServeCommand.php            # serve — 启动 Web 服务器
│   │   ├── MigrateCommand.php          # migrate — 数据库迁移
│   │   ├── BackupCommand.php           # backup — 数据库备份
│   │   ├── CompactCommand.php          # compact — 数据库压缩
│   │   ├── ImportCommand.php           # import — DeepLink 导入
│   │   ├── ExportCommand.php           # export — 数据导出
│   │   ├── DbImportCommand.php         # db:import — 导入数据库
│   │   ├── DbExportCommand.php         # db:export — 导出数据库
│   │   ├── EnvCheckCommand.php         # env:check — 环境检查
│   │   ├── SpeedTestCommand.php        # speedtest — 测速
│   │   ├── Provider/                   # provider:* 命令
│   │   ├── Mcp/                        # mcp:* 命令
│   │   ├── Proxy/                      # proxy:* 命令
│   │   └── Sync/                       # sync:* 命令
│   ├── ConfigWriter/           # 应用配置文件写入器
│   │   ├── WriterInterface.php         # 写入器接口
│   │   ├── WriterFactory.php           # 工厂类
│   │   ├── ClaudeWriter.php            # Claude 配置写入
│   │   ├── CodexWriter.php             # Codex 配置写入
│   │   ├── GeminiWriter.php            # Gemini 配置写入
│   │   ├── OpenClawWriter.php          # OpenClaw 配置写入
│   │   └── OpenCodeWriter.php          # OpenCode 配置写入
│   ├── Database/
│   │   ├── Database.php                # PDO + Medoo 容器
│   │   ├── Migrator.php                # SQL 迁移执行器
│   │   └── Repository/                 # 数据访问层（13 个 Repository）
│   │       ├── ProviderRepository.php
│   │       ├── UniversalProviderRepository.php
│   │       ├── McpRepository.php
│   │       ├── PromptRepository.php
│   │       ├── SkillRepository.php
│   │       ├── SkillRepoRepository.php
│   │       ├── SettingsRepository.php
│   │       ├── ProxyConfigRepository.php
│   │       ├── HealthRepository.php
│   │       ├── RequestLogRepository.php
│   │       ├── FailoverQueueRepository.php
│   │       ├── ModelPricingRepository.php
│   │       └── StreamCheckRepository.php
│   ├── DeepLink/               # DeepLink 解析与导入
│   │   ├── DeepLinkParser.php
│   │   ├── ProviderImporter.php
│   │   ├── McpImporter.php
│   │   ├── PromptImporter.php
│   │   └── SkillImporter.php
│   ├── Http/
│   │   ├── Server.php                  # Swoole HTTP 服务器
│   │   ├── Router.php                  # FastRoute 路由定义（88 个端点）
│   │   └── Controller/                 # API 控制器（19 个）
│   ├── Model/                  # 领域模型（值对象）
│   │   ├── AppType.php                 # 应用类型枚举
│   │   ├── Provider.php
│   │   ├── UniversalProvider.php
│   │   ├── McpServer.php
│   │   ├── Prompt.php
│   │   ├── Skill.php
│   │   ├── ProxyConfig.php
│   │   ├── ProviderHealth.php
│   │   ├── ProviderMeta.php
│   │   ├── RequestLog.php
│   │   ├── ModelPricing.php
│   │   ├── Settings.php
│   │   ├── StreamCheckConfig.php
│   │   └── StreamCheckResult.php
│   ├── Proxy/                  # 反向代理核心
│   │   ├── ProxyServer.php             # 代理服务器入口
│   │   ├── RequestHandler.php          # 请求处理器
│   │   ├── StreamHandler.php           # SSE 流式转发
│   │   ├── CircuitBreaker.php          # 熔断器
│   │   ├── FailoverManager.php         # 故障转移管理
│   │   ├── FormatConverter.php         # 请求格式转换
│   │   ├── ModelMapper.php             # 模型映射
│   │   ├── UsageLogger.php             # 用量日志记录
│   │   ├── ThinkingBudgetRectifier.php # thinking budget 纠正
│   │   └── ThinkingSignatureRectifier.php # thinking 签名纠正
│   ├── Service/                # 业务逻辑层（21 个 Service）
│   └── Util/
│       └── AtomicFile.php              # 原子文件写入
├── tests/
│   ├── Unit/                   # 单元测试（约 52 个测试类）
│   ├── Integration/            # 集成测试（约 12 个测试类）
│   └── E2E/                    # 端到端测试（1 个测试类）
├── composer.json
├── phpunit.xml                 # PHPUnit 配置
├── phpstan.neon                # PHPStan 配置
├── build.sh                    # 构建脚本
└── box.json                    # Box PHAR 打包配置
```

---

## 编码规范

### PHP 标准

- **PSR-4** 自动加载：`CcSwitch\` → `src/`
- 所有文件必须声明 `declare(strict_types=1)`
- 命名空间遵循目录结构

### PHPStan 静态分析

- 分析级别：**Level 5**
- 分析范围：`src/` 目录
- 配置文件：`phpstan.neon`

```bash
# 运行静态分析
php vendor/bin/phpstan analyse
```

### 架构约定

- **Controller** — 接收请求参数，调用 Service，返回响应数组
- **Service** — 核心业务逻辑
- **Repository** — 数据访问层，封装 Medoo 查询
- **Model** — 领域模型（值对象 / 枚举），不可变
- **ConfigWriter** — 将配置写入各应用的原生配置文件

---

## 测试体系

### 测试结构

| 目录 | 类型 | 测试类/文件数 | 说明 |
|------|------|----------|------|
| `tests/Unit/` | 单元测试 | ~52 | 隔离测试 Service、Model、工具类 |
| `tests/Integration/` | 集成测试 | ~12 | 含数据库操作的跨层测试 |
| `tests/E2E/` | 端到端测试 (PHP) | 1 | 完整请求流程测试 |
| `tests/E2E/ui/` | 浏览器 E2E 测试 | 8 | Playwright 驱动的 UI 自动化测试 |

- PHPUnit 测试：约 **791 个测试用例**
- Playwright UI 测试：**31 个测试用例**

### 运行 PHPUnit 测试

```bash
# 运行全部 PHPUnit 测试
php vendor/bin/phpunit

# 运行单元测试
php vendor/bin/phpunit --testsuite Unit

# 运行集成测试
php vendor/bin/phpunit --testsuite Integration

# 运行 E2E 测试
php vendor/bin/phpunit --testsuite E2E

# 运行特定测试文件
php vendor/bin/phpunit tests/Unit/CircuitBreakerTest.php

# 输出覆盖率（需要 Xdebug 或 PCOV）
php vendor/bin/phpunit --coverage-text
```

### 运行 Playwright UI 测试

项目使用 [Playwright](https://playwright.dev/) 进行浏览器 UI 自动化测试，覆盖 Web UI 全部 7 个标签页的交互功能。

**前置条件：** 需要先启动服务器。

```bash
# 安装依赖（首次）
npm install
npx playwright install chromium

# 启动服务器
php bin/cc-switch serve --port 8090 &

# 运行 UI 测试（无头模式）
npm run test:ui

# 运行 UI 测试（有头模式，可视化调试）
npm run test:ui:headed

# 指定其他端口
BASE_URL=http://127.0.0.1:9090 npx playwright test
```

**测试文件说明：**

| 文件 | 测试数 | 覆盖内容 |
|------|--------|----------|
| `page-load.spec.mjs` | 6 | 页面加载、Alpine.js 初始化、导航栏、资源加载 |
| `providers.spec.mjs` | 5 | Provider 列表、应用切换、添加/编辑/删除/切换 Provider |
| `mcp.spec.mjs` | 3 | MCP 页面渲染、添加 MCP Server、同步 |
| `proxy.spec.mjs` | 4 | 代理状态面板、配置复选框/数值输入、保存配置 |
| `prompts.spec.mjs` | 3 | Prompt 列表渲染、添加/编辑 Prompt |
| `settings.spec.mjs` | 3 | 设置表单渲染、主题选择器、保存设置 |
| `usage.spec.mjs` | 3 | 统计卡片渲染、标签内容、时间段过滤 |
| `navigation.spec.mjs` | 3 | 全部 7 个标签可导航、快速切换不崩溃、Skills 内容 |
| `helpers.mjs` | — | 共享工具函数（navTo、dialogFill、tableHasRow 等） |

**配置文件：** `playwright.config.mjs`（根目录），测试产物输出到 `tests/E2E/ui/results/`。

---

## 构建流程

项目使用 `build.sh` + `box.json` 构建独立可执行二进制文件。

### 构建步骤

```
build.sh 执行流程:

1. 检测平台（Linux/macOS × x86_64/aarch64）
2. 下载 static-php-cli 的 micro.sfx（PHP 8.3 静态运行时）
   内含扩展: swoole, pdo_sqlite, curl, zlib, openssl, mbstring, tokenizer, filter
3. 安装生产依赖: composer install --no-dev --optimize-autoloader
4. 使用 Box 编译 .phar: box compile
5. 拼接 micro.sfx + .phar → 独立二进制
```

### box.json 配置

```json
{
    "main": "bin/cc-switch",
    "output": "build/cc-switch.phar",
    "directories": ["src", "config", "migrations", "public"],
    "compression": "GZ",
    "compactors": ["KevinGH\\Box\\Compactor\\Php"]
}
```

### 执行构建

```bash
# 构建独立二进制
./build.sh

# 产物位于
./build/cc-switch
```

### 构建产物

| 文件 | 说明 |
|------|------|
| `build/cc-switch.phar` | PHAR 归档（中间产物） |
| `build/cc-switch` | 独立可执行文件（最终产物） |
| `build/micro.sfx` | PHP 静态运行时（缓存） |

---

## 扩展指南

### 添加新 API 端点

以添加 "Tag" 功能为例：

#### 1. 创建数据库迁移

```sql
-- migrations/015_create_tags.sql
CREATE TABLE IF NOT EXISTS tags (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    color TEXT DEFAULT '#666',
    created_at INTEGER NOT NULL
);
```

#### 2. 创建 Model

```php
// src/Model/Tag.php
<?php
declare(strict_types=1);
namespace CcSwitch\Model;

class Tag
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $color,
        public readonly int $createdAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            color: $row['color'] ?? '#666',
            createdAt: (int) $row['created_at'],
        );
    }
}
```

#### 3. 创建 Repository

```php
// src/Database/Repository/TagRepository.php
<?php
declare(strict_types=1);
namespace CcSwitch\Database\Repository;

use Medoo\Medoo;

class TagRepository
{
    public function __construct(private readonly Medoo $medoo) {}

    public function list(): array
    {
        return $this->medoo->select('tags', '*');
    }

    public function insert(array $data): void
    {
        $this->medoo->insert('tags', $data);
    }

    public function delete(string $id): void
    {
        $this->medoo->delete('tags', ['id' => $id]);
    }
}
```

#### 4. 创建 Service

```php
// src/Service/TagService.php
<?php
declare(strict_types=1);
namespace CcSwitch\Service;

use CcSwitch\Database\Repository\TagRepository;
use CcSwitch\Model\Tag;
use Ramsey\Uuid\Uuid;

class TagService
{
    public function __construct(private readonly TagRepository $repo) {}

    public function list(): array
    {
        $rows = $this->repo->list();
        return array_map(fn($r) => Tag::fromRow($r), $rows);
    }

    public function add(array $data): Tag
    {
        $data['id'] = Uuid::uuid4()->toString();
        $data['created_at'] = time();
        $this->repo->insert($data);
        return Tag::fromRow($data);
    }
}
```

#### 5. 创建 Controller

```php
// src/Http/Controller/TagController.php
<?php
declare(strict_types=1);
namespace CcSwitch\Http\Controller;

use CcSwitch\App;
use CcSwitch\Database\Repository\TagRepository;
use CcSwitch\Service\TagService;

class TagController
{
    private TagService $service;

    public function __construct(private readonly App $app)
    {
        $this->service = new TagService(
            new TagRepository($this->app->getMedoo()),
        );
    }

    public function list(): array
    {
        $tags = $this->service->list();
        return array_map(fn($t) => get_object_vars($t), $tags);
    }

    public function add(array $vars, array $body): array
    {
        if (empty($body['name'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing name']];
        }
        $tag = $this->service->add($body);
        return ['status' => 201, 'body' => get_object_vars($tag)];
    }

    public function delete(array $vars): array
    {
        $this->service->delete($vars['id']);
        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
```

#### 6. 注册路由

在 `src/Http/Router.php` 的路由定义闭包中添加：

```php
// Tags
$r->addRoute('GET', '/api/tags', ['TagController', 'list']);
$r->addRoute('POST', '/api/tags', ['TagController', 'add']);
$r->addRoute('DELETE', '/api/tags/{id}', ['TagController', 'delete']);
```

#### 7. 编写测试

```php
// tests/Unit/TagServiceTest.php
<?php
declare(strict_types=1);
namespace CcSwitch\Tests\Unit;

use PHPUnit\Framework\TestCase;
// ... 测试实现
```

#### 8. 验证

```bash
# 静态分析
php vendor/bin/phpstan analyse

# 运行测试
php vendor/bin/phpunit

# 启动服务器测试
php bin/cc-switch serve
curl http://127.0.0.1:8080/api/tags
```

---

## CI/CD

项目已配置 GitHub Actions，位于 `.github/workflows/`：

### CI 测试流程

每次 push 和 pull request 自动运行：

1. **PHPStan 静态分析** — Level 5
2. **PHPUnit 测试** — 全部测试套件（Unit + Integration + E2E）

### Release 构建流程

通过 `workflow_dispatch` 手动触发或 tag 推送自动触发：

1. 在 Linux（x86_64/aarch64）和 macOS（x86_64/aarch64）原生 runner 上构建
2. 使用 `static-php-cli` 下载含 swoole + pdo_sqlite 的 micro.sfx
3. 通过 `box compile` 编译 .phar 并合并为独立二进制
4. 上传构建产物到 GitHub Releases
