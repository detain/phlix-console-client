---
name: syncplay-group-routes
description: The SyncPlay HTTP route contract (groups, not rooms), the SyncPlayGroup DTO, and the source-text guard test that pins it.
paths:
  - src/Api/ApiClient.php
  - src/Api/SyncPlay/**/*.php
  - src/Api/Dto/SyncPlayGroup.php
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

- `src/Api/Dto/SyncPlayGroup.php` replaces the old `SyncPlayRoom`: `fromArray()` reads
  each field through `Coerce` with an alias fallback (`id`/`room_id`, `name`/`room_name`,
  `is_public`/`isPublic`, `member_count`/`memberCount`) — see `api-dto-coercion`.
- `src/Api/SyncPlay/SyncPlayService.php`, `src/Ui/SyncPlayModal.php`, and
  `src/Screen/PlayerScreen.php` type on `SyncPlayGroup`; the list message class is
  `SyncPlayGroupsLoadedMsg` (still declared in `src/Msg/SyncPlayRoomsLoadedMsg.php`) and
  `src/Msg/SyncPlayJoinedMsg.php` carries a `SyncPlayGroup`.
- Local names such as `$rooms` / `getCurrentRoom()` are unchanged — only the wire routes,
  the DTO, and the message class were renamed.

## Tests

`tests/Unit/Api/SyncPlayRequestLineTest.php` asserts the request lines by scanning
`src/Api/ApiClient.php` as text, so a route regression fails without a live server.

```sh
vendor/bin/phpunit tests/Unit/Api/SyncPlayRequestLineTest.php
```
