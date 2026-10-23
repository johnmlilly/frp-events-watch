#!/usr/bin/env python3
"""Flag FRP events starting soon or already past, and list site pages that still mention them.

Env: RESEND_API_KEY, MAIL_TO (required unless --dry-run); MAIL_FROM, SOON_HOURS (optional).
Usage: python events_watch.py [--dry-run]
"""
from __future__ import annotations

import html
import os
import re
import sys
import time
from datetime import datetime, timedelta, timezone

import requests

SITE = "https://www.familyrenewalproject.com"
API = f"{SITE}/wp-json/tribe/events/v1/events"
# Pages that carry time-sensitive event info. Edit freely.
PAGES = ["/", "/community/", "/families/", "/lcm/", "/lcw/", "/lgw/",
         "/thewayoftrust/", "/prayer/", "/courses/", "/gsc/"]
SOON_HOURS = int(os.getenv("SOON_HOURS", "24"))
PAST_LOOKBACK_DAYS = 14
UA = {"User-Agent": "frp-events-watch/1.0"}
# Shared secret that a Cloudflare WAF skip rule on the site matches on.
if os.getenv("FRP_WATCH_TOKEN"):
    UA["X-FRP-Watch"] = os.environ["FRP_WATCH_TOKEN"]


def norm(s: str) -> str:
    s = html.unescape(s)
    s = s.replace("\u2019", "'").replace("\u2018", "'")
    s = s.replace("\u201c", '"').replace("\u201d", '"')
    s = s.replace("\u2013", "-").replace("\u2014", "-")
    return re.sub(r"\s+", " ", s).strip().lower()


def parse_utc(s: str | None) -> datetime | None:
    if not s:
        return None
    return datetime.strptime(s, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)


def get(url, **kw):
    for attempt in range(3):
        r = requests.get(url, headers=UA, timeout=30, **kw)
        if r.status_code < 500 or attempt == 2:
            return r
        time.sleep(2 ** attempt)


def fetch_events(now: datetime) -> list[dict]:
    params = {
        "per_page": 50,
        "start_date": (now - timedelta(days=PAST_LOOKBACK_DAYS)).strftime("%Y-%m-%d"),
        "end_date": (now + timedelta(hours=SOON_HOURS + 24)).strftime("%Y-%m-%d"),
    }
    url, events = API, []
    while url:
        r = get(url, params=params)
        if not r.ok:
            print(r.status_code, r.headers.get("Cf-Mitigated"), file=sys.stderr)
        r.raise_for_status()
        data = r.json()
        for e in data.get("events", []):
            start = parse_utc(e.get("utc_start_date"))
            end = parse_utc(e.get("utc_end_date")) or start
            if start:
                events.append({"title": html.unescape(e["title"]), "url": e["url"],
                               "start": start, "end": end})
        url, params = data.get("next_rest_url"), None
    return events


def fetch_pages() -> tuple[dict[str, str], list[str]]:
    pages, errors = {}, []
    for path in PAGES:
        try:
            r = get(SITE + path)
            r.raise_for_status()
            pages[path] = r.text
        except requests.RequestException as ex:
            errors.append(f"{path}: {ex}")
    return pages, errors


def mentions(event: dict, page_html: str) -> bool:
    slug = event["url"].rstrip("/").rsplit("/", 1)[-1].lower()
    return f"/event/{slug}" in page_html.lower() or norm(event["title"]) in norm(page_html)


def classify(events: list[dict], now: datetime):
    soon = [e for e in events if now <= e["start"] <= now + timedelta(hours=SOON_HOURS)]
    past = [e for e in events if e["end"] < now]
    return sorted(soon, key=lambda e: e["start"]), sorted(past, key=lambda e: e["end"])


def build_report(soon, past, pages, errors) -> tuple[str, str] | None:
    where = lambda e: [p for p, h in pages.items() if mentions(e, h)]
    lines, n_soon, n_stale = [], 0, 0

    if soon:
        lines.append(f"STARTING WITHIN {SOON_HOURS}h")
        for e in soon:
            n_soon += 1
            hits = where(e)
            lines += [f"- {e['title']} ({e['start']:%a %b %d %H:%M} UTC)", f"  {e['url']}",
                      f"  On: {', '.join(hits) if hits else 'not found on scanned pages'}"]
        lines.append("")

    stale = [(e, where(e)) for e in past]
    stale = [(e, h) for e, h in stale if h]
    if stale:
        lines.append("PAST BUT STILL ON SITE")
        for e, hits in stale:
            n_stale += 1
            lines += [f"- {e['title']} (ended {e['end']:%a %b %d} UTC)", f"  {e['url']}",
                      f"  On: {', '.join(hits)}"]
        lines.append("")

    if errors:
        lines += ["PAGES THAT FAILED TO LOAD"] + [f"- {x}" for x in errors]

    if not (n_soon or n_stale):
        return None
    return f"FRP events: {n_soon} soon, {n_stale} past still live", "\n".join(lines)


def send_email(subject: str, body: str) -> None:
    r = requests.post(
        "https://api.resend.com/emails",
        headers={**UA, "Authorization": f"Bearer {os.environ['RESEND_API_KEY']}"},
        json={"from": os.getenv("MAIL_FROM", "onboarding@resend.dev"),
              "to": [os.environ["MAIL_TO"]], "subject": subject, "text": body},
        timeout=30,
    )
    r.raise_for_status()


def main() -> int:
    dry = "--dry-run" in sys.argv
    now = datetime.now(timezone.utc)
    soon, past = classify(fetch_events(now), now)
    pages, errors = fetch_pages()
    report = build_report(soon, past, pages, errors)
    if not report:
        print("Nothing to report.")
        return 0
    subject, body = report
    print(subject, body, sep="\n\n")
    if not dry:
        send_email(subject, body)
    return 0


if __name__ == "__main__":
    sys.exit(main())
