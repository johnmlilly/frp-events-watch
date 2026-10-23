# FRP Events Watch

[![FRP events watch](https://github.com/johnmlilly/frp-events-watch/actions/workflows/events-watch.yml/badge.svg)](https://github.com/johnmlilly/frp-events-watch/actions/workflows/events-watch.yml)
![Python 3.12](https://img.shields.io/badge/python-3.12-3776AB?logo=python&logoColor=white)
![GitHub Actions](https://img.shields.io/badge/GitHub%20Actions-scheduled-2088FF?logo=githubactions&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-The%20Events%20Calendar-21759B?logo=wordpress&logoColor=white)
![Resend](https://img.shields.io/badge/email-Resend-000000?logo=resend&logoColor=white)

Daily check of the [Family Renewal Project](https://www.familyrenewalproject.com/events/) events calendar. It emails a report when:

- an event **starts within 24 hours**, or
- an event **has already ended** but is still mentioned on one of the tracked site pages.

It only reports. It never edits WordPress.

## How it works

1. Pulls events from The Events Calendar REST API (`/wp-json/tribe/events/v1/events`).
2. Fetches the pages listed in `PAGES` and looks for each event by its URL slug or title.
3. Emails a summary through [Resend](https://resend.com). If there's nothing to report, it sends nothing.

A GitHub Actions workflow runs it daily at 12:00 UTC, and you can also trigger it manually from the Actions tab.

## Run locally

```
python3 -m venv .venv && .venv/bin/pip install requests
.venv/bin/python events_watch.py --dry-run
```

`--dry-run` prints the report without sending email. Local runs only get through Cloudflare from IPs it doesn't challenge, or with `FRP_WATCH_TOKEN` set in your environment.

## Customize

- **Pages to scan:** edit `PAGES` in `events_watch.py`.
- **Schedule:** edit the `cron` line in `.github/workflows/events-watch.yml`.
- **Lookback for past events:** `PAST_LOOKBACK_DAYS` in `events_watch.py`.

