# Claude CLI Exit JSON Format — Spike Findings

**Claude Code version:** 2.1.179  
**Investigated:** `--print --output-format json` and `--print --verbose --output-format stream-json`

---

## Invocations tested

```bash
claude --print --output-format json "say only the word hello"
claude --print --verbose --output-format stream-json "say only the word hello"
```

---

## `--output-format json` (recommended for ClaudeRunner)

Emits a **single JSON object** to stdout on exit. No streaming. Example (formatted):

```json
{
  "type": "result",
  "subtype": "success",
  "is_error": false,
  "duration_ms": 1951,
  "num_turns": 1,
  "result": "hello",
  "stop_reason": "end_turn",
  "session_id": "3f9023b6-...",
  "total_cost_usd": 0.0636945,
  "usage": {
    "input_tokens": 3,
    "cache_creation_input_tokens": 9757,
    "cache_read_input_tokens": 16945,
    "output_tokens": 4,
    "server_tool_use": {
      "web_search_requests": 0,
      "web_fetch_requests": 0
    },
    "service_tier": "standard",
    "iterations": [
      {
        "input_tokens": 3,
        "output_tokens": 4,
        "cache_read_input_tokens": 16945,
        "cache_creation_input_tokens": 9757,
        "type": "message"
      }
    ]
  },
  "modelUsage": {
    "claude-sonnet-4-6": {
      "inputTokens": 3,
      "outputTokens": 4,
      "cacheReadInputTokens": 16945,
      "cacheCreationInputTokens": 9757,
      "webSearchRequests": 0,
      "costUSD": 0.0636945,
      "contextWindow": 200000,
      "maxOutputTokens": 32000
    }
  },
  "terminal_reason": "completed"
}
```

### Key fields

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | Always `"result"` for the final line |
| `subtype` | string | `"success"` or `"error_during_execution"` |
| `is_error` | bool | `true` if the run failed |
| `result` | string | The assistant's text output |
| `stop_reason` | string | `"end_turn"`, `"max_tokens"`, etc. |
| `total_cost_usd` | float | Cost of the invocation |
| `usage.input_tokens` | int | Uncached input tokens |
| `usage.cache_read_input_tokens` | int | Tokens read from cache |
| `usage.cache_creation_input_tokens` | int | Tokens written to cache |
| `usage.output_tokens` | int | Output tokens generated |
| `modelUsage.<model>.contextWindow` | int | Model's total context window size |
| `modelUsage.<model>.maxOutputTokens` | int | Model's max output token limit |

---

## `--output-format stream-json --verbose` (real-time events)

Emits **one JSON object per line** throughout the run. Event types observed:

| `type` | `subtype` | Description |
|--------|-----------|-------------|
| `system` | `hook_started` | Pre-tool hook firing |
| `system` | `hook_response` | Hook output |
| `system` | `init` | Session bootstrap (tools, model, version) |
| `assistant` | — | Each assistant message chunk |
| `rate_limit_event` | — | Rate limit status (see below) |
| `result` | `success` | Final result — same schema as `--output-format json` |

### `rate_limit_event` structure

```json
{
  "type": "rate_limit_event",
  "rate_limit_info": {
    "status": "allowed",
    "resetsAt": 1781655600,
    "rateLimitType": "five_hour",
    "overageStatus": "allowed",
    "overageResetsAt": 1781654400,
    "isUsingOverage": false
  }
}
```

This reflects **API-level rate limiting** (5-hour window), not context window consumption.  
It does not contain a usage percentage.

---

## Usage percentage — conclusion

**There is no `usage_pct` field in any Claude CLI output format.**

However, context window consumption can be computed from the `result` event:

```
total_tokens = input_tokens
             + cache_read_input_tokens
             + cache_creation_input_tokens
             + output_tokens

usage_pct = (total_tokens / contextWindow) * 100
```

Using fields from `--output-format json`:
- numerator: `usage.input_tokens + usage.cache_read_input_tokens + usage.cache_creation_input_tokens + usage.output_tokens`
- denominator: `modelUsage.<first key>.contextWindow` (e.g. 200000 for `claude-sonnet-4-6`)

**Recommendation for `getUsagePct()`:** parse `--output-format json` stdout on exit, extract the
`result` JSON object, and compute the formula above. Return `null` if parsing fails or the
`result` type is not `"result"`.

Note: this measures tokens consumed in a single `ClaudeRunner` invocation, not cumulative
session usage across multiple pup runs. If cross-run accumulation is needed, `UsageTracker`
must sum values across calls.
