# TikTok publishing

TikTok and TikTok Sandbox connections are linked from the shared Integrations
catalog. Tokens remain in Nango and are resolved for each provider operation.

The composer requests current creator settings, requires an explicit privacy
choice for direct posts, defaults interactions off, and collects content
disclosure and approval. Changing content, media, account or TikTok controls
clears approval. One uploaded video is supported; photo publishing is not
advertised. The browser previews the video and checks duration, and the worker
checks duration again with FFprobe against current creator limits.

Videos are served through a two-hour signed, read-only media URL on the API
domain using TikTok's PULL_FROM_URL flow. Verify the API domain or its parent
domain in the TikTok app. The route streams stored video only and does not proxy
external URLs. Production requires FFprobe at `mixpost.ffprobe_path`.

The worker stores the accepted publish ID and polls status after 20 seconds,
then every five minutes for up to 24 hours. It never automatically resubmits an
accepted TikTok post. `PUBLISHED` requires TikTok's `PUBLISH_COMPLETE` response.
`DELIVERED` means sent to the TikTok inbox; the creator still needs to finish
posting in TikTok. Unknown or failed processing is not reported as publication.

Options are persisted by the `2026_10_12_170000_add_post_version_options`
migration. Deploy with queue workers restarted. Automations clear inherited
TikTok approval; TikTok-generated drafts must be reviewed in the composer.
MCP tools can create those drafts and inspect delivery status, but cannot
substitute for the creator's review.
