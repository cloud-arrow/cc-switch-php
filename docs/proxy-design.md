# Proxy Design Document

CC Switch Proxy 是一个基于 Swoole 的透明 HTTP 代理，拦截 CLI 工具（Claude Code、Codex CLI、Gemini CLI）的 API 请求，注入真实 Provider 凭证后转发至上游，同时记录 Token 使用量与费用。

---

## 1. 架构概览

```
┌─────────────┐         ┌─────────────────────────────┐         ┌──────────────┐
│  Claude Code │────┐    │     CC Switch Proxy          │    ┌───▶│ Anthropic    │
│  Codex CLI   │────┼───▶│     (Swoole :15721)          │────┤    │ OpenAI       │
│  Gemini CLI  │────┘    │                             │    └───▶│ ByteCatCode  │
└─────────────┘         │  ┌─────────────────────────┐ │         │ OpenRouter   │
                        │  │ 1. detectAppType()      │ │         └──────────────┘
                        │  │ 2. loadConfig()         │ │
                        │  │ 3. failoverManager()    │ │         ┌──────────────┐
                        │  │ 4. buildUpstreamUrl()   │ │         │   SQLite DB  │
                        │  │ 5. buildHeaders()       │ │────────▶│  usage_logs  │
                        │  │ 6. forward + log        │ │         │  proxy_config│
                        │  └─────────────────────────┘ │         └──────────────┘
                        └─────────────────────────────┘
```

### 核心组件

| 文件 | 职责 |
|------|------|
| `ProxyServer.php` | Swoole HTTP Server 生命周期管理（启动/停止/子监听器模式） |
| `RequestHandler.php` | 请求处理核心：识别应用、查找 Provider、转发、记录使用量 |
| `StreamHandler.php` | SSE 流式响应转发，逐 chunk 写回客户端 |
| `FailoverManager.php` | 从 DB 获取当前 Provider，故障时自动切换至 failover 队列 |
| `CircuitBreaker.php` | 熔断器：连续失败达阈值后自动熔断，避免反复请求故障上游 |
| `ModelMapper.php` | 模型名映射（如 `claude-3-sonnet` → Provider 实际支持的模型名） |
| `FormatConverter.php` | Claude ↔ OpenAI 请求/响应格式互转 |
| `UsageLogger.php` | 解析响应中的 Token 数，按定价计算费用，写入 `usage_logs` 表 |

---

## 2. 三家 API 格式对比

### 2.1 请求格式

#### Claude (Anthropic Messages API)

```http
POST /v1/messages HTTP/1.1
Host: api.anthropic.com
x-api-key: sk-ant-api03-xxxxx
anthropic-version: 2023-06-01
Content-Type: application/json

{
  "model": "claude-sonnet-4-20250514",
  "max_tokens": 1024,
  "system": "You are a helpful assistant",
  "messages": [
    {"role": "user", "content": "Hello"},
    {"role": "assistant", "content": "Hi there!"},
    {"role": "user", "content": "How are you?"}
  ],
  "stream": true
}
```

#### Codex / OpenAI (Chat Completions API)

```http
POST /v1/chat/completions HTTP/1.1
Host: api.openai.com
Authorization: Bearer sk-xxxxx
Content-Type: application/json

{
  "model": "gpt-4o",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant"},
    {"role": "user", "content": "Hello"},
    {"role": "assistant", "content": "Hi there!"},
    {"role": "user", "content": "How are you?"}
  ],
  "stream": true
}
```

#### Gemini (Google Generative AI API)

```http
POST /v1beta/models/gemini-2.5-flash:streamGenerateContent?alt=sse HTTP/1.1
Host: generativelanguage.googleapis.com
x-goog-api-key: AIza-xxxxx
Content-Type: application/json

{
  "contents": [
    {"role": "user", "parts": [{"text": "Hello"}]},
    {"role": "model", "parts": [{"text": "Hi there!"}]},
    {"role": "user", "parts": [{"text": "How are you?"}]}
  ],
  "systemInstruction": {
    "parts": [{"text": "You are a helpful assistant"}]
  },
  "generationConfig": {
    "temperature": 0.7,
    "maxOutputTokens": 1024
  }
}
```

### 2.2 差异总结

| 维度 | Claude (Anthropic) | Codex (OpenAI) | Gemini (Google) |
|------|-------------------|----------------|-----------------|
| **端点路径** | `/v1/messages` | `/v1/chat/completions` | `/v1beta/models/{model}:{method}` |
| **模型指定位置** | body `model` 字段 | body `model` 字段 | URL 路径 `/models/{model}` |
| **认证方式** | `x-api-key` Header | `Authorization: Bearer` | `x-goog-api-key` Header |
| **版本控制** | `anthropic-version` Header | 无 | 无 |
| **System Prompt** | body 顶层 `system` 字段 | messages 数组 `role: system` | body 顶层 `systemInstruction` |
| **消息结构** | `messages[].content` | `messages[].content` | `contents[].parts[].text` |
| **助手角色名** | `assistant` | `assistant` | `model` |
| **流式开关** | body `stream: true` | body `stream: true` | URL 方法名 `streamGenerateContent` + `?alt=sse` |
| **max_tokens** | `max_tokens`（必填） | `max_tokens`（可选） | `generationConfig.maxOutputTokens` |
| **多模态图片** | `{"type":"image","source":{"type":"base64","data":"..."}}` | `{"type":"image_url","image_url":{"url":"data:..."}}` | `{"inlineData":{"mimeType":"image/png","data":"..."}}` |

### 2.3 响应格式

#### Claude 非流式

```json
{
  "id": "msg_01XFDUDYJgAACzvnptvVoYEL",
  "type": "message",
  "role": "assistant",
  "content": [
    {"type": "text", "text": "I'm doing well, thanks!"}
  ],
  "model": "claude-sonnet-4-20250514",
  "stop_reason": "end_turn",
  "usage": {
    "input_tokens": 25,
    "output_tokens": 12
  }
}
```

#### OpenAI 非流式

```json
{
  "id": "chatcmpl-abc123",
  "object": "chat.completion",
  "choices": [
    {
      "index": 0,
      "message": {"role": "assistant", "content": "I'm doing well, thanks!"},
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 25,
    "completion_tokens": 12,
    "total_tokens": 37
  }
}
```

#### Gemini 非流式

```json
{
  "candidates": [
    {
      "content": {
        "role": "model",
        "parts": [{"text": "I'm doing well, thanks!"}]
      },
      "finishReason": "STOP"
    }
  ],
  "usageMetadata": {
    "promptTokenCount": 25,
    "candidatesTokenCount": 12,
    "thoughtsTokenCount": 30,
    "totalTokenCount": 67
  }
}
```

### 2.4 流式 SSE 格式

#### Claude SSE — 分阶段多事件类型

```
event: message_start
data: {"type":"message_start","message":{"id":"msg_xxx","role":"assistant","model":"claude-sonnet-4-20250514"}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"I'm"}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" doing well"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":12}}

event: message_stop
data: {"type":"message_stop"}
```

#### OpenAI SSE — 统一 `data:` 前缀

```
data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{"role":"assistant"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{"content":"I'm"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{"content":" doing well"},"finish_reason":null}]}

data: {"id":"chatcmpl-abc","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":25,"completion_tokens":12}}

data: [DONE]
```

#### Gemini SSE — 完整 JSON 对象逐个推送

```
data: {"candidates":[{"content":{"role":"model","parts":[{"text":"I'm"}]},"index":0}],"modelVersion":"gemini-2.5-flash"}

data: {"candidates":[{"content":{"role":"model","parts":[{"text":" doing well!"}]},"index":0}],"modelVersion":"gemini-2.5-flash"}

data: {"candidates":[{"content":{"role":"model","parts":[{"text":""}]},"finishReason":"STOP","index":0}],"usageMetadata":{"promptTokenCount":25,"candidatesTokenCount":12,"thoughtsTokenCount":30,"totalTokenCount":67},"modelVersion":"gemini-2.5-flash"}
```

### 2.5 Token 用量字段映射

| 语义 | Claude | OpenAI | Gemini |
|------|--------|--------|--------|
| 输入 Token | `usage.input_tokens` | `usage.prompt_tokens` | `usageMetadata.promptTokenCount` |
| 输出 Token | `usage.output_tokens` | `usage.completion_tokens` | `usageMetadata.candidatesTokenCount` |
| 缓存读取 | `usage.cache_read_input_tokens` | — | — |
| 缓存创建 | `usage.cache_creation_input_tokens` | — | — |
| 思考 Token | — | `usage.completion_tokens_details.reasoning_tokens` | `usageMetadata.thoughtsTokenCount` |
| 总计 | 手动求和 | `usage.total_tokens` | `usageMetadata.totalTokenCount` |

---

## 3. 请求识别机制

代理通过 URL 路径和 Header 识别请求来自哪个应用：

```php
public function detectAppType(string $path, array $headers): ?string
{
    // 1. Claude: Anthropic Messages API
    if (str_contains($path, '/v1/messages') || str_contains($path, '/claude/')) {
        return 'claude';
    }

    // 2. Codex: OpenAI Chat Completions
    if (str_contains($path, '/v1/chat/completions') || str_contains($path, '/chat/completions')) {
        return 'codex';
    }

    // 3. Codex: OpenAI Responses API
    if (str_contains($path, '/v1/responses') || str_contains($path, '/responses')) {
        return 'codex';
    }

    // 4. Gemini: Google Generative AI
    if (str_contains($path, '/v1beta/') || str_contains($path, '/gemini/')) {
        return 'gemini';
    }

    // 5. 兜底: 检查 Header 推断
    if (isset($headers['x-api-key']) || isset($headers['anthropic-version'])) {
        return 'claude';
    }
    if (isset($headers['x-goog-api-key'])) {
        return 'gemini';
    }

    return null;  // 无法识别
}
```

**识别优先级**：URL 路径 > 特定 Header > 返回 null (404)

---

## 4. 转发策略

### 4.1 策略选择

```
请求到达
  │
  ├─ appType == "gemini" && path 含 "/v1beta/"
  │     └─ 策略二：原样透传
  │
  └─ appType == "claude" || "codex"
        └─ 策略一：解析-转换-重编码
```

### 4.2 策略一：解析-转换-重编码（Claude / Codex）

```
CLI 原始请求
  │
  ▼
json_decode($rawBody)          ← 解析为 PHP 数组
  │
  ▼
ModelMapper::apply($body)      ← 模型名映射（如 claude-3-sonnet → 实际名）
  │
  ▼
detectRequestFormat($path)     ← 判断请求格式（anthropic / openai）
inferProviderFormat($appType)  ← 判断上游期望格式
  │
  ├─ 请求=anthropic, 上游=openai  → FormatConverter::anthropicToOpenAI()
  ├─ 请求=openai, 上游=anthropic  → FormatConverter::openAIToAnthropic()
  └─ 格式相同                     → 不转换
  │
  ▼
json_encode($body)             ← 重编码为 JSON
  │
  ▼
Guzzle POST → 上游 API
```

**何时需要格式转换**：当 Claude Code 通过 Anthropic 格式发请求，但 Provider 配置的上游是 OpenAI 兼容接口（如 OpenRouter），代理需要自动将 Anthropic 格式转为 OpenAI 格式。反之亦然。

### 4.3 策略二：原样透传（Gemini）

```
CLI 原始请求
  │
  ▼
$rawBody (字符串，不做 json_decode)
  │
  ▼
buildUpstreamUrl()             ← 替换 Base URL
buildUpstreamHeaders()         ← 注入 x-goog-api-key
  │
  ▼
拼接 query string (?alt=sse)
  │
  ▼
Guzzle POST → 上游 API (body => $rawBody)
  │
  ▼
流式: 逐 chunk 写回 CLI
非流: 整包返回
  │
  ▼
parseGeminiUsage()             ← 正则提取 usageMetadata 中的 token 数
```

**透传原因**：
1. Gemini 格式与 Claude/OpenAI 差异过大（`contents` vs `messages`、模型在 URL 中、`parts` 嵌套结构）
2. ModelMapper 会在 body 中寻找 `model` 字段，Gemini body 中没有这个字段
3. FormatConverter 只支持 Claude ↔ OpenAI 互转，没有 Gemini 转换能力
4. 透传零成本、零风险，保留了所有 Gemini 特有参数（`generationConfig`、`safetySettings`、`tools` 等）

### 4.4 URL 构建

```php
private function buildUpstreamUrl(Provider $provider, array $providerConfig, string $requestPath): string
{
    $env = $providerConfig['env'] ?? [];

    // 优先从 Provider 环境变量读取 Base URL
    $baseUrl = $env['ANTHROPIC_BASE_URL']
        ?? $env['OPENAI_BASE_URL']
        ?? $env['GOOGLE_GEMINI_BASE_URL']
        ?? $env['API_BASE_URL']
        ?? null;

    if ($baseUrl !== null) {
        return rtrim($baseUrl, '/') . $requestPath;
    }

    // 无自定义 URL 时使用官方默认
    return match ($provider->app_type) {
        'claude'  => 'https://api.anthropic.com' . $requestPath,
        'codex'   => 'https://api.openai.com' . $requestPath,
        'gemini'  => 'https://generativelanguage.googleapis.com' . $requestPath,
        default   => 'https://api.anthropic.com' . $requestPath,
    };
}
```

### 4.5 Header 构建

```php
private function buildUpstreamHeaders(Provider $provider, array $providerConfig, array $requestHeaders): array
{
    $env = $providerConfig['env'] ?? [];
    $headers = ['Content-Type' => 'application/json'];

    // API Key — 适配三种格式
    $apiKey = $env['ANTHROPIC_API_KEY']
        ?? $env['OPENAI_API_KEY']
        ?? $env['GEMINI_API_KEY']
        ?? $env['API_KEY']
        ?? null;

    if ($apiKey !== null) {
        $headers['x-api-key'] = $apiKey;           // Claude
        $headers['Authorization'] = 'Bearer ' . $apiKey;  // OpenAI
        $headers['x-goog-api-key'] = $apiKey;      // Gemini
    }

    // Claude 专用版本头
    if ($provider->app_type === 'claude') {
        $headers['anthropic-version'] = $env['ANTHROPIC_API_VERSION'] ?? '2023-06-01';
    }

    return $headers;
}
```

> 注：三种认证头同时设置，上游只认识自己的那个，其余被忽略。

---

## 5. 使用量统计

### 5.1 Token 解析

| 应用 | 解析方式 |
|------|---------|
| Claude | 从 SSE 流的 `message_delta` 事件中提取 `usage.input_tokens` / `output_tokens` |
| OpenAI | 从响应 body 或最后一个 SSE chunk 的 `usage` 字段提取 |
| Gemini | 正则匹配响应中的 `usageMetadata.promptTokenCount` / `candidatesTokenCount` |

### 5.2 Gemini Token 解析实现

```php
private function parseGeminiUsage(string $responseBody): array
{
    $input = $output = 0;
    // 从 SSE 事件或 JSON 响应中提取 usageMetadata
    if (preg_match('/"promptTokenCount"\s*:\s*(\d+)/', $responseBody, $m1)) {
        $input = (int) $m1[1];
    }
    if (preg_match('/"candidatesTokenCount"\s*:\s*(\d+)/', $responseBody, $m2)) {
        $output = (int) $m2[1];
    }
    return ['input_tokens' => $input, 'output_tokens' => $output];
}
```

### 5.3 费用计算

Token 数写入 `usage_logs` 表后，由 `UsageLogger` 根据模型定价表计算费用（USD）：

```
total_cost = input_tokens × input_price_per_token
           + output_tokens × output_price_per_token
           + cache_read_tokens × cache_read_price
           + cache_creation_tokens × cache_creation_price
```

费用乘以 `cost_multiplier`（第三方 Provider 可能有加价）。

---

## 6. 容错机制

### 6.1 熔断器 (Circuit Breaker)

```
状态机:  Closed ──失败达阈值──▶ Open ──超时后──▶ Half-Open ──成功──▶ Closed
                                 │                    │
                                 ◀────── 再次失败 ─────┘
```

| 参数 | 默认值 | 说明 |
|------|-------|------|
| `circuit_failure_threshold` | 4 | 连续失败多少次触发熔断 |
| `circuit_success_threshold` | 2 | Half-Open 状态下连续成功多少次恢复 |
| `circuit_timeout_seconds` | 60 | Open 状态持续多久后进入 Half-Open |
| `circuit_error_rate_threshold` | 0.6 | 错误率阈值 |
| `circuit_min_requests` | 10 | 最少多少请求后才计算错误率 |

### 6.2 自动故障切换 (Failover)

当当前 Provider 熔断后：

```
1. FailoverManager::resolve($appType)
2. 检查当前 Provider 的熔断器状态
3. 若熔断 → 从 failover 队列中按 sort_index 顺序查找可用 Provider
4. 找到可用 → 自动切换 + 写入配置文件
5. 全部不可用 → 返回 503 "All providers are unavailable"
```

---

## 7. 接管机制 (Takeover)

Takeover 是将 CLI 工具的配置文件从"直连上游"改为"走本地代理"的过程：

```
启用接管:
  1. 备份原始 ~/.claude.json (或 ~/.gemini/.env)
  2. 将 Base URL 改为 http://127.0.0.1:15721
  3. CLI 后续请求自动走代理

禁用接管:
  1. 从备份恢复原始配置文件
  2. CLI 恢复直连
```

| 应用 | 配置文件 | 修改的 Key |
|------|---------|-----------|
| Claude | `~/.claude.json` | `apiUrl` → `http://127.0.0.1:15721` |
| Codex | `~/.codex/config.toml` | `api_base_url` |
| Gemini | `~/.gemini/.env` | `GOOGLE_GEMINI_BASE_URL` + `API_BASE_URL` |

---

## 8. 数据流时序图

```
Gemini CLI                  CC Switch Proxy                    ByteCatCode
    │                            │                                  │
    │  POST /v1beta/models/      │                                  │
    │  gemini-2.5-flash:         │                                  │
    │  streamGenerateContent     │                                  │
    │  ?alt=sse                  │                                  │
    │  x-goog-api-key: dummy     │                                  │
    │  body: {contents:[...]}    │                                  │
    │───────────────────────────▶│                                  │
    │                            │  detectAppType() → "gemini"      │
    │                            │  loadConfig() → enabled=true     │
    │                            │  resolve() → ByteCatCode Provider│
    │                            │  buildUpstreamUrl()               │
    │                            │    → https://bytecat.lamclod.cn  │
    │                            │      /v1beta/models/...?alt=sse  │
    │                            │  buildHeaders()                   │
    │                            │    → x-goog-api-key: sk-real-key │
    │                            │                                  │
    │                            │  POST (rawBody 透传)              │
    │                            │─────────────────────────────────▶│
    │                            │                                  │
    │                            │  data: {"candidates":[...]}      │
    │    data: {"candidates":..} │◀─────────────────────────────────│
    │◀───────────────────────────│                                  │
    │                            │  data: {"usageMetadata":{...}}   │
    │    data: {"usageMetadata"} │◀─────────────────────────────────│
    │◀───────────────────────────│                                  │
    │                            │                                  │
    │                            │  parseGeminiUsage()               │
    │                            │  → input=8886, output=403        │
    │                            │  usageLogger.log()                │
    │                            │  → INSERT INTO usage_logs         │
    │                            │                                  │
```
