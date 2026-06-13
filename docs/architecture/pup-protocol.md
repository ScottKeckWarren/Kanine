# Kanine Pup Protocol — Architecture

> How the Kanine supervisor (TUI) and pups (Claude Code agents) communicate over HTTPS/JSON.

---

## 1. Roles

| Role | What it is | Runs where |
|---|---|---|
| **Supervisor** | Kanine TUI process; embeds an HTTP server | Developer's machine |
| **Pup** | Headless Claude Code agent working one GitHub Issue | Same machine, separate machine, or Docker container |

The supervisor is the source of truth for:
- The task queue (GitHub Issues ready for dispatch)
- Pup registry (who is alive and what they are doing)
- Pending questions and their answers
- Card/column state shown on the board

Each pup is the source of truth for:
- Its own current task progress
- Questions it has asked but not yet received answers for

---

## 2. Communication Model

**Direction:** Pups always initiate. The supervisor never pushes to a pup.

**Mechanism:** Pups poll the supervisor on a configurable interval (default 5 s). Every poll carries the pup's current state and returns any pending answers plus a new task assignment if one is available.

```
Pup                          Supervisor
 |                                |
 |--- POST /pups/register ------->|  (on startup)
 |<-- { token, poll_interval } ---|
 |                                |
 |--- POST /pups/{id}/poll ------>|  (every poll_interval seconds)
 |<-- { answers[], new_task? } ---|
 |                                |
 |--- POST /tasks/{id}/status --->|  (whenever progress changes)
 |--- POST /tasks/{id}/questions->|  (when Claude needs user input)
 |--- POST /tasks/{id}/complete ->|  (when task finishes)
```

All requests are JSON over HTTP. TLS is optional on a trusted local network; required when pups run on remote machines or in Docker containers exposed to non-local networks.

---

## 3. Authentication

| Deployment | Auth mechanism |
|---|---|
| Same machine, local network | Bearer token issued at registration (low-friction) |
| Remote machine / Docker / public network | Bearer token over TLS (HTTPS required) |

The supervisor generates a unique token per pup at registration. Pups include it in every subsequent request:

```
Authorization: Bearer <token>
```

The supervisor rejects any request without a valid token with `401 Unauthorized`.

Token lifetime: until the pup deregisters or the supervisor restarts. Token rotation is out of scope for v1.

---

## 4. API Reference

All endpoints are under the supervisor's base URL, e.g. `http://localhost:7777`.

### 4.1 Register

```
POST /pups/register
```

Called once when a pup starts. Announces the pup to the supervisor and receives a bearer token.

**Request:**
```json
{
  "pup_id": "550e8400-e29b-41d4-a716-446655440000",
  "hostname": "worker-1",
  "version": "1.0.0"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `pup_id` | UUID string | Yes | Pup generates this on startup; stable for pup's lifetime |
| `hostname` | string | Yes | For display in the TUI |
| `version` | string | No | Protocol version for forward-compat |

**Response `200`:**
```json
{
  "token": "t_abc123xyz",
  "poll_interval_ms": 5000
}
```

`poll_interval_ms` is a hint from the supervisor. Pups should respect it; supervisor may adjust it dynamically based on load.

---

### 4.2 Poll

```
POST /pups/{pup_id}/poll
```

The heartbeat. Pup sends its current state; supervisor returns pending answers and optionally a new task.

**Request:**
```json
{
  "current_task_id": "task-uuid-or-null",
  "status": "idle",
  "pending_question_ids": ["q-uuid-1", "q-uuid-2"]
}
```

| Field | Type | Notes |
|---|---|---|
| `current_task_id` | UUID \| null | null if pup has no active task |
| `status` | enum | `idle` \| `working` \| `blocked` |
| `pending_question_ids` | UUID[] | IDs of questions pup has asked but not yet received answers for |

**`status` values:**

| Value | Meaning |
|---|---|
| `idle` | No active task; ready for work |
| `working` | Processing a task normally |
| `blocked` | Has an unanswered question; can still take new task |

**Response `200`:**
```json
{
  "answers": [
    {
      "question_id": "q-uuid-1",
      "answer": "Use PostgreSQL — the issue mentions multi-user support."
    }
  ],
  "new_task": {
    "id": "task-uuid",
    "issue_number": 42,
    "repo": "owner/repo",
    "title": "Add user authentication",
    "body": "Implement JWT-based auth for the API...",
    "labels": ["status: in progress", "feature"]
  }
}
```

`new_task` is `null` when the queue is empty or pup is already working a task that has no blocking question. Supervisor assigns at most one new task per poll response. Pup may be working an original task and receive a new task only when original task is blocked by an unanswered question.

---

### 4.3 Task Status

```
POST /tasks/{task_id}/status
```

Pup reports progress. Supervisor displays this on the card in the TUI.

**Request:**
```json
{
  "pup_id": "pup-uuid",
  "status": "in_progress",
  "message": "Running PHPUnit suite — 47/200 tests passing"
}
```

| Field | Type | Notes |
|---|---|---|
| `status` | enum | `in_progress` \| `blocked` \| `error` |
| `message` | string | Short human-readable status; shown on card |

**Response `204`** — no body.

---

### 4.4 Post Question

```
POST /tasks/{task_id}/questions
```

Pup (via Claude Code) needs user input. Question is queued in supervisor and surfaced in the TUI. Pup does not block waiting for an answer — it picks up the answer on a future poll.

**Request:**
```json
{
  "pup_id": "pup-uuid",
  "question": "Should this feature use PostgreSQL or SQLite? The issue doesn't specify a database engine.",
  "context": "Issue body mentions 'persistent storage' but no engine. Current schema has no migrations."
}
```

| Field | Type | Notes |
|---|---|---|
| `question` | string | The question Claude generated; shown verbatim to user |
| `context` | string | Supporting context; shown in detail pane |

**Response `201`:**
```json
{
  "question_id": "q-uuid-1"
}
```

Pup stores `question_id` and includes it in future `pending_question_ids` until an answer arrives via poll.

---

### 4.5 Complete Task

```
POST /tasks/{task_id}/complete
```

Pup signals it has finished the task (success or failure).

**Request:**
```json
{
  "pup_id": "pup-uuid",
  "outcome": "success",
  "summary": "Implemented JWT auth. Added 3 endpoints, 42 tests, all passing. PR opened at #87."
}
```

| Field | Type | Notes |
|---|---|---|
| `outcome` | enum | `success` \| `failure` |
| `summary` | string | Human-readable summary; stored on card and shown in detail view |

**Response `200`:**
```json
{
  "label_actions": [
    { "remove": "status: in progress" },
    { "add": "status: review" }
  ]
}
```

Supervisor tells the pup which GitHub label actions to apply as part of the handoff. Pup applies them via the GitHub API (it has repo access). This drives the card's column transition on the board.

---

### 4.6 Deregister (optional)

```
DELETE /pups/{pup_id}
```

Called on clean shutdown. Supervisor marks pup offline and returns its task to the queue if incomplete.

**Response `204`** — no body.

---

## 5. Data Models

### Task

```json
{
  "id": "uuid",
  "issue_number": 42,
  "repo": "owner/repo",
  "title": "string",
  "body": "string (markdown)",
  "labels": ["string"],
  "assigned_pup_id": "uuid | null",
  "state": "queued | assigned | complete | failed"
}
```

### Question

```json
{
  "id": "uuid",
  "task_id": "uuid",
  "pup_id": "uuid",
  "question": "string",
  "context": "string",
  "answer": "string | null",
  "asked_at": "ISO 8601",
  "answered_at": "ISO 8601 | null"
}
```

### Pup

```json
{
  "id": "uuid",
  "hostname": "string",
  "token": "string",
  "status": "idle | working | blocked | offline",
  "current_task_id": "uuid | null",
  "last_seen_at": "ISO 8601"
}
```

---

## 6. Lifecycle Flows

### 6.1 Normal task flow

```
1. Pup starts → POST /pups/register → receives token
2. Pup polls → POST /pups/{id}/poll (status: idle)
3. Supervisor responds with new_task
4. Pup begins work, polls with status: working
5. Pup finishes → POST /tasks/{id}/complete
6. Supervisor returns label_actions; pup applies labels via GitHub API
7. Pup polls again with status: idle; may receive next task
```

### 6.2 Question flow

```
1. Claude Code hits ambiguous input mid-task
2. Pup → POST /tasks/{id}/questions → receives question_id
3. Pup continues polling with status: blocked, question_id in pending_question_ids
4. If another task is in the queue, supervisor assigns it in the next poll response
5. User sees question in TUI; types answer; supervisor stores it
6. On next poll: response includes answers[{ question_id, answer }]
7. Pup resumes original task with the answer; drops question_id from pending list
```

### 6.3 Pup working two tasks

```
Task A blocked (pending question q-1)
↓
Poll: { current_task_id: A, status: blocked, pending_question_ids: [q-1] }
↓
Supervisor response: { answers: [], new_task: { id: B, ... } }
↓
Pup works Task B while Task A waits
↓
Poll: { current_task_id: B, status: working, pending_question_ids: [q-1] }
↓
Supervisor response: { answers: [{ question_id: q-1, answer: "..." }], new_task: null }
↓
Pup notes answer; completes B; resumes A with answer
```

---

## 7. Supervisor HTTP Server

The supervisor embeds a minimal HTTP server (e.g. `react/http` or a lightweight socket loop). It binds on a configurable port (default `7777`). The TUI renders on the same process; the HTTP handler runs in the same event loop (or a forked process if PHP's concurrency model requires it).

Configuration (in `kanine.yaml`):

```yaml
supervisor:
  host: 127.0.0.1       # 0.0.0.0 for remote pups
  port: 7777
  tls: false            # true for remote/Docker pups
  tls_cert: /path/to/cert.pem
  tls_key:  /path/to/key.pem
```

---

## 8. Error Handling

| Scenario | Supervisor behavior | Pup behavior |
|---|---|---|
| Pup misses 3+ polls | Mark pup `offline`; return task to queue | — |
| Pup sends unknown `pup_id` | `404 Not Found` | Re-register |
| Invalid/expired token | `401 Unauthorized` | Re-register |
| Malformed JSON | `400 Bad Request` with `{ "error": "..." }` | Log, retry next poll |
| Supervisor unreachable | — | Exponential backoff; keep working current task |
| Task no longer exists (cancelled) | `404 Not Found` on status/complete | Drop task, poll for new one |

All error responses follow:

```json
{
  "error": "human-readable message",
  "code": "MACHINE_READABLE_CODE"
}
```

---

## 9. Deployment Considerations

| Deployment | Notes |
|---|---|
| Same machine | `host: 127.0.0.1`, no TLS, token still required |
| Docker (local) | Map supervisor port; pup uses host's Docker bridge IP |
| Remote machines | `host: 0.0.0.0`, TLS required, firewall to supervisor port |
| Multiple pups | No limit; supervisor queues tasks, pups consume at their own rate |

Pups discover the supervisor URL via an environment variable:

```bash
KANINE_SUPERVISOR_URL=https://kanine.internal:7777
KANINE_PUP_ID=550e8400-e29b-41d4-a716-446655440000
```

---

## 10. Open Questions (pre-Phase 3)

- **Concurrency model inside supervisor.** PHP TUI loop + HTTP server in one process requires non-blocking I/O (ReactPHP) or a forked HTTP worker. Decide before Phase 3.
- **Task queue persistence.** In-memory queue is lost if supervisor restarts. SQLite sidecar? Flat JSON file? Decide before dispatch is wired to GitHub labels.
- **Pup-generated PRs.** Should pups push branches and open PRs autonomously, or surface the branch and let the user open the PR from the TUI?
- **Question timeout.** If a question goes unanswered for N minutes, should the pup abandon the task or keep holding?
