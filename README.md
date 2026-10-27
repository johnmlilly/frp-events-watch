# FRP Events Watch

![WordPress plugin](https://img.shields.io/badge/WordPress-plugin-21759B?logo=wordpress&logoColor=white)
![PHP 7.4+](https://img.shields.io/badge/php-7.4%2B-777BB4?logo=php&logoColor=white)
![The Events Calendar](https://img.shields.io/badge/requires-The%20Events%20Calendar-21759B?logo=wordpress&logoColor=white)
![WP-Cron](https://img.shields.io/badge/schedule-WP--Cron%20daily-21759B?logo=wordpress&logoColor=white)
![HTML email](https://img.shields.io/badge/email-HTML%20%2B%20plain%20text-E34F26?logo=html5&logoColor=white)
![Resend](https://img.shields.io/badge/email-Resend-000000?logo=resend&logoColor=white)
![Read-only](https://img.shields.io/badge/site%20access-read--only-2EA44F)

WordPress plugin that runs a daily check of the [Family Renewal Project](https://www.familyrenewalproject.com/events/) events calendar. It emails a report when:

- an event **starts within 24 hours**, or
- an event **has already ended** but is still mentioned on one of the tracked site pages.

It only reports. It never edits WordPress. It runs inside the site, so Cloudflare bot protection doesn't get in the way.

Source: [`wordpress/frp-events-watch.php`](wordpress/frp-events-watch.php).

## How it works

| Step | What happens |
|---|---|
| 1. Get events | Reads events straight from The Events Calendar (no REST API calls). |
| 2. Scan pages | Loads each page in `FRP_WATCH_PAGES` from the database (content plus page-builder data) and looks for each event by its URL slug or title. |
| 3. Email | Emails a summary through Resend if an API key is set, otherwise through `wp_mail`. On days with nothing to report it sends a short all-clear, unless that's turned off in settings. |
| Format | HTML email (event links, color-coded sections, page links) with a plain-text fallback. The Tools page previews both. |
| Schedule | WP-Cron, daily at 12:00 UTC. Fires on the first site visit after that time. |
| Soon window | Picks up where the last delivered report's 24h window ended, so a late WP-Cron run doesn't skip or repeat events. Events that started in the gap and are still running are listed as "started". Previews don't move the window; a failed send is retried next run. |

Limitation: it checks page content and page-builder data only (`FRP_WATCH_BUILDER_META`, plus Avia/Enfold when active on the page), not headers, footers, sidebars, menus or SEO fields. The Tools page lists why each page matched (link or title, and where), which helps track down a stale mention.

## Install

1. Zip the plugin folder:
   ```
   mkdir -p dist/frp-events-watch && cp wordpress/frp-events-watch.php dist/frp-events-watch/
   (cd dist && zip -r frp-events-watch.zip frp-events-watch)
   ```
2. In WP admin: **Plugins → Add New → Upload Plugin**, upload the zip, and activate it. To update later, upload again and choose **Replace current with uploaded**.

## Settings (Tools → FRP Events Watch)

- **Send reports to:** recipient. Falls back to the site admin email.
- **Resend API key:** optional. When set, only this plugin's mail goes through Resend; the rest of the site's mail is untouched. Use a dedicated key, since other admins can read it from the database.
- **From address:** must be on a domain verified in Resend. Blank uses `onboarding@resend.dev`, which only delivers to your Resend account's email.
- **Email me on days with nothing to report:** on by default. Sends a short all-clear so you know the job ran.
- **Send test email** always sends. **Send report now** sends the current report. The page also shows a preview.
- **Next / Last scheduled run:** when the job runs next, and when it last ran and what happened (sent, failed and why, or skipped). A warning appears if the next run is more than an hour overdue, which means WP-Cron isn't firing.

## Customize

Edit the constants at the top of the plugin file: `FRP_WATCH_PAGES`, `FRP_WATCH_SOON_HOURS`, `FRP_WATCH_PAST_LOOKBACK_DAYS`, `FRP_WATCH_BUILDER_META`.

## Reuse on another site

Change `FRP_WATCH_PAGES` and the plugin name in the header comment. If you install both copies on one site, also rename `FRP_WATCH_HOOK`, the `frp_watch_` function prefix and option names so they don't collide. The site needs The Events Calendar.

## Changelog

- **1.1.1:** reads Avia Layout Builder (Enfold) page data, only while Avia is active on the page.
- **1.1.0:** recipient and Resend settings, HTML email, soon window carried across runs, quiet-day email, last-run status, page-builder-only scan with match reasons.
- **1.0.0:** initial release.
