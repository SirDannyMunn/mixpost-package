# Scheduler and content automations

Mixpost owns the scheduler service, automation models, validation, feed ingestion,
engagement processing, HTTP API, migrations and scheduled command. The MCP package
contains adapters only. The existing frontend calendar exposes an **Automations**
panel under `workflowy/src/modules/content-scheduler`.

## Deployment and operation

Run the normal application migrations before enabling the worker. The Mixpost
provider loads `2026_10_07_160000_create_mixpost_automations.php` directly; no manual
migration publishing is needed. The existing Laravel scheduler registers
`mixpost:run-automations` every minute with overlap protection. Set
`mixpost.schedule.run_automations_enabled=false` to disable automatic evaluation.
The existing `mixpost:run-scheduled-posts` command and queue workers perform real
publication. No integration credentials or live publishing are configured by this
feature's installation.

Rules start disabled unless explicitly enabled. Choose `draft` for human review,
or `schedule` to authorize ongoing publication after a configurable delay. Pausing
a rule stops future generated posts; it does not cancel posts already scheduled.
Cancel those separately in the scheduler. Generated posts appear in the existing
calendar and draft list.

## Rule behavior

* **Evergreen:** rotates `post_ids` in stored order; clones the current content,
  selected accounts and media. `interval_minutes` must be at least 1440. Set
  `next_run_at`, `max_runs` and optional `expires_at`. Missed intervals do not cause
  a burst of catch-up posts. Intervals are elapsed minutes in UTC, not local-time
  calendar recurrences; a fixed 24-hour interval can shift local hour across DST.
* **RSS/Atom:** polls a public feed, deduplicates by GUID/Atom ID (link fallback),
  and renders `{title}`, `{url}`, `{summary}` into a plain-text template. Existing
  items are baselined on the first successful poll unless `include_existing=true`.
  Changing the feed URL establishes a new baseline. Only the latest 100 entries
  per response are processed. Fetches reject private addresses, embedded
  credentials, redirects, XML entities and oversized payloads. Feeds must be
  served uncompressed or honor `Accept-Encoding: identity`. No remote article
  HTML, media or scripts are fetched. Media-only networks require manual media in
  draft mode; auto-scheduling rejects invalid posts. Multiple articles are spaced
  into distinct slots on their target accounts.
* **Engagement:** watches a published post and account. A metric threshold fires
  once per rule; a matching comment phrase fires once per unique event, subject
  to a cooldown and output limit. Follow-up content is snapshotted when the rule
  is saved. Output is a standalone draft/post, **not a comment, reply, or DM**.
  Imported per-post metrics are available through existing Twitter/Mastodon
  import jobs (normally every two hours, not real time). Other providers and
  comment triggers require an authenticated collector posting the normalized
  events below. This package does not scrape comments or install provider
  webhooks automatically.

All posts remain subject to connected-account state, provider validation and
provider API approvals. Credential storage in Nango alone does not establish a
connected Mixpost account.

## HTTP API

All endpoints use `/api/v1/social/automations`, Sanctum authentication and an
authorized `X-Organization-Id`. Read-only organization roles cannot write.

| Method | Path | Action |
| --- | --- | --- |
| GET | `/` | List up to 200 rules |
| POST | `/` | Create a rule |
| PUT | `/{id}` | Replace configuration; requires current `revision` |
| POST | `/{id}/enabled` | Pause/resume with `{ "enabled": false }` |
| GET | `/{id}/runs` | Last 100 generated/baselined results |
| POST | `/engagement-events` | Submit authenticated normalized engagement |

Example evergreen configuration:

```json
{
  "name": "Weekly product tutorials",
  "type": "evergreen",
  "enabled": false,
  "mode": "draft",
  "next_run_at": "2026-11-01T09:00:00Z",
  "max_runs": 12,
  "config": {"post_ids": ["POST_UUID"], "interval_minutes": 10080, "delay_minutes": 5}
}
```

RSS config: `feed_url`, `accounts` (account UUIDs), `template`,
`include_existing=false`, `interval_minutes>=15`, `delay_minutes>=1`.

Engagement config: `source_post_id` (post UUID), `account_id`, `trigger` (`metric`
or `comment_keyword`), `metric` and `threshold` or `keyword`, `followup` (scheduler
draft payload), `interval_minutes` (poll interval/cooldown), `delay_minutes`.
Supported normalized metrics: likes, replies, retweets, impressions, favourites,
reblogs. The upstream collector must have legitimate access to that provider.

```json
{
  "post_id": "PUBLISHED_POST_UUID",
  "account_id": "ACCOUNT_UUID",
  "event_id": "provider-comment-123",
  "text": "Please share the guide",
  "metrics": {"likes": 100}
}
```

The API validates the post/account relationship and organization. Event bodies
are data, not instructions. Failed evaluations store a user-visible `last_error`
and retry after 15 minutes. Creation and event deduplication are transactional;
the rule row is locked and rechecked before any output. Limits count generated
posts, not successful provider publications. Provider failures are inspected on
the generated post rather than silently retried as new posts.

## Validation

From the application root:

```sh
php vendor/bin/phpunit packages/mixpost/tests/Feature/SchedulerAutomationsTest.php packages/mixpost/tests/Unit/FeedReaderTest.php packages/mcp/tests/Unit/SchedulerToolsTest.php
```

Frontend: run `vitest` for `AutomationsPanel.test.tsx` from `workflowy`.
