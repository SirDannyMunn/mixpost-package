# Shared social connections

The calendar's **Connect accounts** picker uses the integrations package's Nango
Connect flow. Choose an existing workspace connection or authorize a new one,
then select its verified profile/Page. Linking the same provider identity updates
the existing scheduler account and clears its locally stored token.

`WorkspaceConnection` in `packages/integrations` owns workspace checks and fresh
credential retrieval. `SharedSocialConnection` in Mixpost adapts those credentials
to the existing publishing/import drivers. Credentials stay server-side and are
held in memory for the operation; only the integration key and connection ID are
stored in `mixpost_accounts.data.integration`. No client secrets need copying into
Mixpost's service settings. Nango owns OAuth callbacks and refresh tokens.

This is an authentication consolidation, not a separate Mixpost deployment.
Existing Laravel hosting, scheduler and queue workers continue to run publishing.
The integrations package's configured Nango API host is used; no callback or host
is hardcoded here. No additional database migration is needed for this bridge.

Current shared adapters: LinkedIn personal profiles, Facebook Pages, Instagram
Login, Threads, TikTok, YouTube and Pinterest; X OAuth 2 supports text posts.
The current X media uploader uses OAuth 1, so shared X media posts are rejected
with an actionable error. Existing OAuth 1 X and Mastodon accounts retain their
legacy setup. Provider permissions, approvals and existing publishing-driver
limitations still apply. YouTube uses the channel returned by its existing driver.

Connections must carry organization metadata from TWL's Connect flow. Unscoped
legacy connections are not silently adopted: reconnect them through Integrations.
Profile identity is checked again before using a shared connection, including
after reconnect. Facebook Page access tokens are derived in memory from the
selected Page; they are never sent to the picker or persisted.

Removing a shared account from Mixpost only unlinks it. To revoke its access for
all TWL features, disconnect it in Integrations. Existing Mixpost-only accounts
continue to use their original credentials until explicitly relinked. Nothing
automatically migrates or revokes existing live connections.

API (authenticated organization member, writes require a writable role):

* `GET /api/v1/social/accounts/integrations`: safe configured-provider/connection list.
* `POST .../integrations/targets`: `{key, connection_id}` returns safe profile choices.
* `POST .../integrations/link`: `{key, connection_id, target_id}` verifies and links.

Run `php vendor/bin/phpunit packages/mixpost/tests/Feature/SharedSocialConnectionTest.php`
and the frontend `SharedAccountsPanel.test.tsx` suite. Tests fake external APIs;
they do not post to live accounts.
