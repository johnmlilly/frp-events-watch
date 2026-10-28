# FRP Events Watch

![WordPress plugin](https://img.shields.io/badge/WordPress-plugin-21759B?logo=wordpress&logoColor=white)
![PHP 7.4+](https://img.shields.io/badge/php-7.4%2B-777BB4?logo=php&logoColor=white)
![The Events Calendar](https://img.shields.io/badge/requires-The%20Events%20Calendar-21759B?logo=wordpress&logoColor=white)
![Resend](https://img.shields.io/badge/email-Resend-000000?logo=resend&logoColor=white)

WordPress plugin that runs a daily check of the [Family Renewal Project](https://www.familyrenewalproject.com/events/) events calendar. It emails a report when:

- an event **starts within 24 hours**,
- an event **has already ended** but is still mentioned on one of the tracked site pages, or
- an upcoming event **has no graphic** yet (the flyer is made after the event page goes up, since it carries the page's tinyURL and QR code, so it's easy to forget); it's marked **urgent** once the event is a week away,
- an event in the next 30 days **isn't on any tracked page**, menu or widget, or
- two events **look like duplicates** (same title, same day).

Any item except an event starting soon can be **dismissed** from the email.

It only reports. It never edits WordPress. It runs inside the site, so Cloudflare bot protection doesn't get in the way.

Source: [`wordpress/frp-events-watch.php`](wordpress/frp-events-watch.php).

## How it works

| Step | What happens |
|---|---|
| 1. Get events | Reads events straight from The Events Calendar (no REST API calls). |
| 2. Scan pages | Reads each page in `FRP_WATCH_PAGES` from the database (saved content), plus every nav menu and active widget (they show on those pages too), and finds every link to an event: full or relative URLs, plain permalinks, and Avia's internal links (which store the event's ID, not its URL). Each linked event is checked however long ago it ended, and a link to an unpublished or deleted event is reported too. Titles, as a whole phrase, are searched for events starting soon and events that ended in the last 30 days; a past event whose title an upcoming event shares (a recurring event) is matched by link only. |
| 3. Check graphics | Every event that hasn't ended needs an image in its saved description (an `<img>`, a block-editor image, or an Avia image element); a featured image counts too. Images whose address contains an entry of `FRP_WATCH_PLACEHOLDER_IMAGES` (such as the Life-Giving Wounds logo) don't count. An image on another site (a Facebook link, say) is flagged too, since it can stop loading. A recurring event is listed once, at its next date. A missing graphic is urgent once the event starts within `FRP_WATCH_URGENT_DAYS` (7): the item gets an Urgent tag, the section moves to the top, and the subject starts with "Urgent:". |
| 4. Other checks | **Not on any tracked page:** an event starting within `FRP_WATCH_PROMOTE_DAYS` (30) that no tracked page, menu or widget links to or names (by title). A link to a recurring event's series, or to any of its dates, counts for every date. Events starting within 24 hours aren't listed here, since their own item already says where they appear. **Possible duplicates:** two published events that haven't ended, with the same title on the same day, that aren't dates of one recurring event. |
| 5. Dismiss | Every item except "Starting within 24 hours" has a Dismiss link. The link opens a page that asks first, so the mail scanners that open links in emails can't dismiss anything. Anyone with the email can dismiss, without logging in; the link is signed, so it only works for that item. A dismissed item stays hidden until what's reported about it changes (a different graphic problem, a stale link on another page), and is forgotten once it's no longer reported. Dismissed items are listed on the Tools page, with a Restore button. |
| 6. Email | Emails a summary through Resend if an API key is set, otherwise through `wp_mail`. On days with nothing to report it sends a short all-clear, unless that's turned off in settings. |
| Format | HTML email (event links, color-coded sections, page links) with a plain-text fallback. The Tools page previews both. |
| Schedule | WP-Cron, daily at 12:00 UTC. Fires on the first site visit after that time. |
| Soon window | Picks up where the last delivered report's 24h window ended, so a late WP-Cron run doesn't skip or repeat events. Events that started in the gap and are still running are listed as "started". Previews don't move the window; a failed send is retried next run. |

Limitation: it reads saved content (including Avia shortcodes), menus and widgets, not Avia's separate stored builder copy. It doesn't read theme options, SEO fields, or content built when a page renders (post sliders, event lists). A past event mentioned by title only, with no link, is caught only for 30 days after it ends. Leftovers in Avia element settings that the page never shows are ignored: an empty image element (no image source) and a `*_dynamic` setting such as `link_dynamic` whose plain setting (`link`) is empty. For each place, the email says where the event appears (for example "Image link", "Button link" or "Text block (title)"), which helps track down a stale mention. The graphic check can't tell a flyer from other images: an event with only a speaker photo counts as having a graphic.

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

- **Send reports to:** one or more addresses, separated by commas. Everyone gets the same email and sees the full list. Entries that aren't email addresses are left out (the save notice names them). Blank uses the site admin email. With Resend, sending to anyone other than your Resend account's own address needs a From address on a verified domain.
- **Resend API key:** optional. When set, only this plugin's mail goes through Resend; the rest of the site's mail is untouched. Use a dedicated key, since other admins can read it from the database.
- **From address:** must be on a domain verified in Resend. Blank uses `onboarding@resend.dev`, which only delivers to your Resend account's email.
- **Email me on days with nothing to report:** on by default. Sends a short all-clear so you know the job ran.
- **Dismissed:** items hidden from reports, each with **Restore**. Shown only when something is dismissed.
- **Send test email** always sends. **Send report now** sends the current report; refreshing the page afterwards doesn't send it again. **Preview report** shows the email without sending.
- **Next / Last scheduled run:** when the job runs next, and when it last ran and what happened (sent, failed and why, or skipped). A warning appears if the next run is more than an hour overdue, which means WP-Cron isn't firing.

## Customize

Edit the constants at the top of the plugin file: `FRP_WATCH_PAGES`, `FRP_WATCH_SOON_HOURS`, `FRP_WATCH_PAST_LOOKBACK_DAYS`, `FRP_WATCH_PLACEHOLDER_IMAGES`, `FRP_WATCH_URGENT_DAYS`, `FRP_WATCH_PROMOTE_DAYS`. A placeholder entry is any part of the image's file name or address, so `LGW_Logo_R_Horizontal` matches every size of that logo. An event that has a placeholder and its own flyer counts as having a graphic.

## Reuse on another site

Change `FRP_WATCH_PAGES` and the plugin name in the header comment. If you install both copies on one site, also rename `FRP_WATCH_HOOK`, the `frp_watch_` function prefix and option names so they don't collide. The site needs The Events Calendar.

## Changelog

- **1.8.0:** new sections: upcoming events in the next 30 days that aren't on any tracked page, and possible duplicate events (same title, same day). A missing graphic is urgent within 7 days of the event. Items can be dismissed from the email (restore them on the Tools page).
- **1.7.0:** new "Missing a graphic" section lists upcoming events with no image in their description, only a placeholder image, or an image hosted on another site. Reports can go to several addresses (comma-separated).
- **1.6.1:** ignores Avia leftovers the page never shows: matches inside an image element with no image, and in `link_dynamic` (or any `*_dynamic` setting) when `link` is empty. These were reported as "Image link" on pages with nothing visible to remove.
- **1.6.0:** unit tests (PHPUnit + Brain Monkey) run in CI on PHP 7.4 and 8.3 before the zip is built; the build workflow uses Node 24 actions and uploads the plugin zip directly. No change to what the plugin reports.
- **1.5.1:** no longer reads Avia's stored builder copy (`_aviaLayoutBuilderCleanData`), which can keep links the page no longer shows. Pages are read from their saved content only.
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
