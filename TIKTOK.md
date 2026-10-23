# TikTok publishing

TikTok and TikTok Sandbox connections are linked from the shared Integrations
catalog. Tokens remain in Nango and are resolved for each provider operation.
No new environment variables are required for photo posts or media upload.
Post options already live on `mixpost_post_versions.options`
(`2026_10_12_170000_add_post_version_options`). No new migration is required.

## Video and photo posts

| | Video | Photo carousel |
| --- | --- | --- |
| Init endpoint | `POST /v2/post/publish/video/init/` or `POST /v2/post/publish/inbox/video/init/` | `POST /v2/post/publish/content/init/` |
| `media_type` | `VIDEO` | `PHOTO` |
| Source | `PULL_FROM_URL` | `PULL_FROM_URL` only |
| Files | One uploaded video | 1–35 JPEG or WebP images |
| Text | Content body is the caption (about 2,200 characters) | `options.tiktok.title` up to 90 UTF-16 code units; description up to 4,000 UTF-16 code units. The description option overrides the body when set. |
| Cover | n/a | `photo_cover_index`, zero-based, required by TikTok |

`options.tiktok.mode` stays `direct` or `draft`. `post_mode` is the Content
Posting API value and is filled in when omitted:

- `draft` / `MEDIA_UPLOAD` uses scope `video.upload`. TikTok puts the upload in
  the creator inbox. It is not a public post. Status `SEND_TO_USER_INBOX` is
  stored as `DELIVERED`.
- `direct` / `DIRECT_POST` uses scope `video.publish`. It is the public post.
  Direct posts require `privacy_level` (one of the creator
  `privacy_level_options`), interaction flags, and commercial-content flags.
  Photo direct posts also send `auto_add_music` and may send `is_aigc`.

Creating a draft, validating, and uploading media never call TikTok. The init
request runs only inside the scheduler worker after `schedule_social_post` or
`publish_social_post`. A timeout after submit is not retried. An existing
TikTok `provider_post_id` is not submitted again.

The worker stores the `publish_id` and polls
`POST /v2/post/publish/status/fetch/` after 20 seconds, then every five minutes
for up to 24 hours (288 checks). Photo and video posts share that poll.
`PUBLISH_COMPLETE` becomes `PUBLISHED`. Anything else is not reported as a
public post.

PNG files can stay in the media library. TikTok photo posts reject PNG and GIF.
Each photo must be 20MB or smaller and 1080p or smaller: long side at most
1920 pixels and short side at most 1080. Images and video cannot be mixed.

## Pull URLs and domain verification

Video and photo bytes are not uploaded from the worker. TikTok downloads them
from a two-hour signed URL:

`https://api.usetwl.com/api/v1/social/tiktok-media/{media}?signature=...`

The route streams the file from `MIXPOST_DISK` (default `public`). It does not
proxy S3 or other external URLs, and it serves only video plus JPEG/WebP.
`APP_URL` must be the public `https://api.usetwl.com` origin with no redirect.
TikTok refuses non-HTTPS URLs, redirects, and URLs whose domain or prefix is
not verified (`url_ownership_unverified`).

Manual step in the TikTok Developer Portal, on the app URL properties for
Production:

1. Verify domain `api.usetwl.com` (DNS), or verify the parent domain
   `usetwl.com` so its subdomains are covered.
2. Verifying only `www.usetwl.com` does not cover `api.usetwl.com`.
3. Or verify the URL prefix `https://api.usetwl.com/api/v1/social/tiktok-media/`.
   Signed query strings are accepted when the path prefix matches.

This repository cannot complete that portal step. Until it is done, direct and
inbox init calls fail even though TWL validation succeeds.

FFprobe (`mixpost.ffprobe_path`) is required for video duration checks only.
Photo posts do not use it. Automations clear inherited TikTok approval;
generated drafts must be reviewed before scheduling. `consent` must be true
before validate, schedule, or publish.
