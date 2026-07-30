# Contract: Supervisor Pup API

**Version**: 1.0
**Date**: 2026-06-28
**Transport**: HTTP/1.1 JSON (TLS required when supervisor not bound to localhost)

All request and response bodies are `application/json`. All timestamps are ISO 8601 UTC.
All error responses use HTTP 4xx/5xx with body `{"error": "<message>"}`.

---

## POST /pups/register

Pup registers with the supervisor at startup. Must be called before polling.

**Request**
```json
{
  "pupId": "550e8400-e29b-41d4-a716-446655440000"
}
```

| Field  | Type   | Required | Description               |
|--------|--------|----------|---------------------------|
| pupId  | string | yes      | UUID v4; stable per pup process lifetime |

**Response 200**
```json
{
  "ok": true,
  "pollIntervalSeconds": 5
}
```

| Field               | Type | Description                                    |
|---------------------|------|------------------------------------------------|
| ok                  | bool | Always true on success                         |
| pollIntervalSeconds | int  | Recommended poll interval from supervisor config |

**Errors**
- `400` — missing or malformed pupId
- `409` — pupId already registered and active

---

## GET /pups/{pupId}/poll

Pup polls for an assignment and pending answers. This request also serves as the pup's
heartbeat; each call updates `lastHeartbeatAt` in the supervisor.

**Path params**
| Param | Type   | Description          |
|-------|--------|----------------------|
| pupId | string | UUID of the pup      |

**Response 200**
```json
{
  "assignment": {
    "issueId": 42,
    "repo": "owner/repo",
    "title": "Add OAuth2 login",
    "body": "Full issue body markdown..."
  },
  "pendingAnswers": [
    {
      "questionId": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
      "body": "Use 15-minute token expiry"
    }
  ]
}
```

| Field                       | Type    | Description                                                        |
|-----------------------------|---------|--------------------------------------------------------------------|
| assignment                  | object? | The active assignment; null if no assignment pending for this pup  |
| assignment.issueId          | int     | GitHub issue number                                                |
| assignment.repo             | string  | `owner/repo` slug                                                  |
| assignment.title            | string  | Issue title                                                        |
| assignment.body             | string  | Issue body markdown                                                |
| pendingAnswers              | array   | Operator answers to questions posted by this pup; may be empty     |
| pendingAnswers[].questionId | string  | UUID matching the question the pup posted                          |
| pendingAnswers[].body       | string  | The operator's answer text                                         |

**Notes**:
- The supervisor only sends `assignment` when the pup's status is `registered` (idle).
  A pup that is already `working` receives `assignment: null` and only `pendingAnswers`.
- `pendingAnswers` are cleared from the store after being returned; the pup should not
  expect to retrieve the same answers twice.

**Errors**
- `404` — pupId not registered

---

## POST /pups/{pupId}/status

Pup reports a status change to the supervisor.

**Path params**
| Param | Type   | Description     |
|-------|--------|-----------------|
| pupId | string | UUID of the pup |

**Request**
```json
{
  "status": "working",
  "message": "Created worktree, beginning implementation"
}
```

| Field   | Type   | Required | Description                                            |
|---------|--------|----------|--------------------------------------------------------|
| status  | string | yes      | One of: `working`, `blocked`, `complete`, `failed`     |
| message | string | no       | Human-readable detail; shown in board's issue detail   |

**Response 200**
```json
{
  "ok": true
}
```

**Notes**:
- `complete` triggers the supervisor to release the assignment and mark the issue done.
- `failed` triggers release of the assignment; the issue re-enters the dispatch queue.
- `blocked` transitions the pup to blocked status; dispatch loop will not re-assign the
  pup until the pup subsequently reports `working`.

**Errors**
- `400` — invalid status value
- `404` — pupId not registered
- `409` — status transition not valid from current state

---

## POST /pups/{pupId}/questions

Pup posts a question requiring operator input.

**Path params**
| Param | Type   | Description     |
|-------|--------|-----------------|
| pupId | string | UUID of the pup |

**Request**
```json
{
  "questionId": "7c9e6679-7425-40de-944b-e07fc1f90ae7",
  "body": "Should the token expiry be 15 minutes or 24 hours? The issue says 'secure' but no duration."
}
```

| Field      | Type   | Required | Description                                |
|------------|--------|----------|--------------------------------------------|
| questionId | string | yes      | UUID v4; generated by the pup              |
| body       | string | yes      | Question text displayed to the operator    |

**Response 200**
```json
{
  "ok": true
}
```

**Notes**:
- Multiple questions may be open simultaneously on the same assignment.
- The pup SHOULD also report `status: blocked` to pause autonomous re-dispatch.

**Errors**
- `400` — missing or malformed fields
- `404` — pupId not registered
- `409` — questionId already exists

---

## DELETE /pups/{pupId}

Pup deregisters from the supervisor (graceful shutdown).

**Path params**
| Param | Type   | Description     |
|-------|--------|-----------------|
| pupId | string | UUID of the pup |

**Response 200**
```json
{
  "ok": true
}
```

**Notes**:
- Any active assignment is released and re-queued for dispatch.
- Open questions are cleared.

**Errors**
- `404` — pupId not registered

---

## Error Response Format

All 4xx and 5xx responses use:

```json
{
  "error": "Human-readable error message naming what failed and why"
}
```
