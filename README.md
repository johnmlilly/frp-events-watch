# FRP Events Watch

![WordPress plugin](https://img.shields.io/badge/WordPress-plugin-21759B?logo=wordpress&logoColor=white)
![PHP 7.4+](https://img.shields.io/badge/php-7.4%2B-777BB4?logo=php&logoColor=white)
![The Events Calendar](https://img.shields.io/badge/requires-The%20Events%20Calendar-21759B?logo=wordpress&logoColor=white)
![Resend](https://img.shields.io/badge/email-Resend-000000?logo=resend&logoColor=white)

WordPress plugin that runs a daily check of the [Family Renewal Project](https://www.familyrenewalproject.com/events/) events calendar. It emails a report when:

- an event **starts within 24 hours**, or
- an event **has already ended** but is still mentioned on one of the tracked site pages.

It only reports. It never edits WordPress. It runs inside the site, so Cloudflare bot protection doesn't get in the way.

Source: [`wordpress/frp-events-watch.php`](wordpress/frp-events-watch.php).

## How it works

| Step | What happens |
|---|---|
| 1. Get events | Reads events straight from The Events Calendar (no REST API calls). |
| 2. Scan pages | Reads each page in `FRP_WATCH_PAGES` from the database (content plus Avia builder data), plus every nav menu and active widget (they show on those pages too), and finds every link to an event: full or relative URLs, plain permalinks, and Avia's internal links (which store the event's ID, not its URL). Each linked event is checked however long ago it ended, and a link to an unpublished or deleted event is reported too. Titles, as a whole phrase, are searched for events starting soon and events that ended in the last 30 days; a past event whose title an upcoming event shares (a recurring event) is matched by link only. |
| 3. Email | Emails a summary through Resend if an API key is set, otherwise through `wp_mail`. On days with nothing to report it sends a short all-clear, unless that's turned off in settings. |
| Format | HTML email (event links, color-coded sections, page links) with a plain-text fallback. The Tools page previews both. |
| Schedule | WP-Cron, daily at 12:00 UTC. Fires on the first site visit after that time. |
| Soon window | Picks up where the last delivered report's 24h window ended, so a late WP-Cron run doesn't skip or repeat events. Events that started in the gap and are still running are listed as "started". Previews don't move the window; a failed send is retried next run. |

Limitation: it reads saved content, Avia (Enfold) builder data (while Avia is active on the page), menus and widgets. It doesn't read theme options, SEO fields, or content built when a page renders (post sliders, event lists). A past event mentioned by title only, with no link, is caught only for 30 days after it ends. For each place, the email says where the event appears (for example "Image link", "Button link" or "Text block (title)"), which helps track down a stale mention.

## Install

1. Download `frp-events-watch-<version>.zip` from the latest [release](https://github.com/johnmlilly/frp-events-watch/releases).
2. In WP admin: **Plugins → Add New → Upload Plugin**, upload the zip, and activate it. To update later, upload again and choose **Replace current with uploaded**.

## Development

Unit tests use PHPUnit and [Brain Monkey](https://github.com/Brain-WP/BrainMonkey), which stubs WordPress functions, so no WordPress install is needed:

```
composer install
composer test
```

Tests live in `tests/`. `FrpTestCase` stubs WordPress and The Events Calendar for a site at `https://www.familyrenewalproject.com` in UTC; its `EVENTS` list holds the event posts the tests link to. The Build workflow runs the tests on PHP 7.4 and 8.3 before building the zip. The zip contains only the plugin file.

## Release

The Build workflow lints and tests the plugin and builds the zip on every push and pull request (download it from the run's artifacts; it's the plugin zip itself, ready to upload). To publish a release:

1. Bump `Version:` in the plugin header and merge to `main`.
2. Tag it: `git tag v1.4.1 && git push origin v1.4.1`. The tag must match `Version:` or the build fails.
3. The workflow creates a GitHub release with the zip attached.

To build locally instead:

```
mkdir -p dist/frp-events-watch && cp wordpress/frp-events-watch.php dist/frp-events-watch/
(cd dist && zip -r frp-events-watch.zip frp-events-watch)
```

## Settings (Tools → FRP Events Watch)

- **Send reports to:** recipient. Falls back to the site admin email.
- **Resend API key:** optional. When set, only this plugin's mail goes through Resend; the rest of the site's mail is untouched. Use a dedicated key, since other admins can read it from the database.
- **From address:** must be on a domain verified in Resend. Blank uses `onboarding@resend.dev`, which only delivers to your Resend account's email.
- **Email me on days with nothing to report:** on by default. Sends a short all-clear so you know the job ran.
- **Send test email** always sends. **Send report now** sends the current report; refreshing the page afterwards doesn't send it again. **Preview report** shows the email without sending.
- **Next / Last scheduled run:** when the job runs next, and when it last ran and what happened (sent, failed and why, or skipped). A warning appears if the next run is more than an hour overdue, which means WP-Cron isn't firing.

## Customize

Edit the constants at the top of the plugin file: `FRP_WATCH_PAGES`, `FRP_WATCH_SOON_HOURS`, `FRP_WATCH_PAST_LOOKBACK_DAYS`.

## Reuse on another site

Change `FRP_WATCH_PAGES` and the plugin name in the header comment. If you install both copies on one site, also rename `FRP_WATCH_HOOK`, the `frp_watch_` function prefix and option names so they don't collide. The site needs The Events Calendar.

## Changelog

- **1.5.0:** scans only the pages in `FRP_WATCH_PAGES` again (plus menus and widgets), instead of every post and page; pages that can't be found are listed in the email. Link detection from 1.4.0 is unchanged.
- **1.4.1:** titles of past events are searched for 30 days after they end (was 14).
- **1.4.0:** finds links to past events however long ago they ended (before, only events that ended in the last 14 days were checked), plus Avia internal links that store an event ID instead of a URL, and links to unpublished or deleted events; element names ignore buttons and images that come before a link but don't contain it.
- **1.3.0:** scans the whole site (all published posts, pages and synced patterns, menus and active widgets) instead of a fixed page list; stops reading rendered page content; past recurring-event instances match by link only; titles match across HTML tags; links must start at a path boundary; the daily job can't send twice; refreshing after a send doesn't resend; the preview runs only when asked for.
- **1.2.0:** the email names where each event appears on a page (Avia image, button, text block, etc.); the "Why each page matched" list on the Tools page is removed.
- **1.1.4:** "Why each page matched" shows the surrounding text of each match; reads the front page from Enfold's theme options when WordPress has none set.
- **1.1.3:** stricter matching: event links must match the full event address (no prefix matches, works with any events URL), titles must match as whole words, and empty titles never match.
- **1.1.2:** scans Avia only; drops Elementor, Beaver Builder, Bricks, Oxygen and Themify.
- **1.1.1:** reads Avia Layout Builder (Enfold) page data, only while Avia is active on the page.
- **1.1.0:** recipient and Resend settings, HTML email, soon window carried across runs, quiet-day email, last-run status, page-builder-only scan with match reasons.
- **1.0.0:** initial release.
