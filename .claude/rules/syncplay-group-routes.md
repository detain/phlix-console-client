---
name: syncplay-group-routes
description: The SyncPlay HTTP route contract (groups, not rooms), the SyncPlayGroup/SyncPlaySession DTOs, and the guard tests that pin them.
paths:
  - src/Api/ApiClient.php
  - src/Api/SyncPlay/**/*.php
  - src/Api/Dto/SyncPlayGroup.php
  - src/Api/Dto/SyncPlaySession.php
  - src/Ui/SyncPlayModal.php
  - tests/Unit/Api/SyncPlayRequestLineTest.php
---

# SyncPlay group routes

The server exposes SyncPlay under `groups`, never `rooms`. `src/Api/ApiClient.php`:

- `createSyncPlayGroup()` — `POST /api/v1/syncplay/groups`
- `listSyncPlayGroups()` — `GET /api/v1/syncplay/groups`, response key `$data['groups']`
- `joinSyncPlayGroup()` — `POST /api/v1/syncplay/groups/{id}/join`
- `leaveSyncPlayGroup()` — `POST /api/v1/syncplay/groups/{id}/leave` (**POST**, not `DELETE`)

No `/api/v1/syncplay/rooms` path and no `'DELETE', '/api/v1/syncplay/` call may remain.

## DTO and messages

- `src/Api/Dto/SyncPlayGroup.php` replaces the old `SyncPlayRoom`: `fromArray()` reads one
  LIST row (`{id, name, member_count, has_password}`) through `Coerce` — see
  `api-dto-coercion`. `is_public` is **not** a wire key; `isPublic` is derived as
  `!has_password`, so there are no `room_id` / `isPublic` alias fallbacks.
- `src/Api/Dto/SyncPlaySession.php` parses the create/join envelope `{success, group:{…}}`
  and takes the id from `group['group_id']`; `serverUrl` is **not** a wire field —
  `src/Api/ApiClient.php` passes its own configured base in.
- `src/Api/SyncPlay/SyncPlayService.php`, `src/Ui/SyncPlayModal.php`, and
  `src/Screen/PlayerScreen.php` type on `SyncPlayGroup`; the list message class
  `SyncPlayGroupsLoadedMsg` lives in `src/Msg/SyncPlayGroupsLoadedMsg.php` and
  `src/Msg/SyncPlayJoinedMsg.php` carries a `SyncPlayGroup`.
- Local names such as `$rooms` / `getCurrentRoom()` are unchanged — only the wire routes,
  the DTOs, and the message class were renamed.

## Tests

`tests/Unit/Api/SyncPlayRequestLineTest.php` asserts the request lines by scanning
`src/Api/ApiClient.php` as text, so a route regression fails without a live server;
`tests/Unit/Api/SyncPlay/SyncPlayEnvelopeWireShapeTest.php` pins the envelope unwrap
against captured server bytes.

```sh
vendor/bin/phpunit tests/Unit/Api/SyncPlayRequestLineTest.php tests/Unit/Api/SyncPlay
```
