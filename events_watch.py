#!/usr/bin/env python3
"""Flag FRP events starting soon or already past, and list site pages that still mention them.

Env: RESEND_API_KEY, MAIL_TO (required unless --dry-run); MAIL_FROM, SOON_HOURS (optional);
QUIET_EMAIL=0 to skip the email on days with nothing to report.
Usage: python events_watch.py [--dry-run] [--html out.html]
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


def build_report(soon, past, pages, errors, now) -> dict:
    """Sections for render_text/render_html. Event items: title, url, when, pages {path: url}."""
    where = lambda e: {p: SITE + p for p, h in pages.items() if mentions(e, h)}
    sections = []

    items = [{"title": e["title"], "url": e["url"], "when": f"{e['start']:%a %b %d %H:%M} UTC",
              "pages": where(e)} for e in soon]
    if items:
        sections.append({"key": "soon", "title": f"Starting within {SOON_HOURS} hours", "items": items})
    n_soon = len(items)

    items = [{"title": e["title"], "url": e["url"], "when": f"ended {e['end']:%a %b %d} UTC", "pages": hits}
             for e in past if (hits := where(e))]
    if items:
        sections.append({"key": "stale", "title": "Past but still on site", "items": items})
    n_stale = len(items)

    quiet = not (n_soon or n_stale)
    if errors:
        sections.append({"key": "errors", "title": "Pages that failed to load", "items": errors})
    if quiet:
        return {"subject": "FRP events: nothing to report", "quiet": True,
                "summary": f"Nothing to report. No events start in the next {SOON_HOURS} hours, "
                           "and no past events are on the tracked pages.",
                "date": f"{now:%A, %B %d, %Y}", "sections": sections}
    return {"subject": f"FRP events: {n_soon} soon, {n_stale} past still live", "quiet": False,
            "summary": f"{n_soon} starting soon · {n_stale} past {'event' if n_stale == 1 else 'events'} still on the site",
            "date": f"{now:%A, %B %d, %Y}", "sections": sections}


def render_text(report: dict) -> str:
    lines = [report["summary"], ""] if report["quiet"] else []
    for s in report["sections"]:
        lines.append(s["title"].upper())
        for item in s["items"]:
            if s["key"] == "errors":
                lines.append(f"- {item}")
                continue
            lines += [f"- {item['title']} ({item['when']})", f"  {item['url']}",
                      f"  On: {', '.join(item['pages']) or 'not found on scanned pages'}"]
        lines.append("")
    return "\n".join(lines).rstrip()


def render_html(report: dict, footer_url: str | None = None) -> str:
    """Email-safe HTML: tables and inline styles only. Keep in step with frp_watch_render_html() in the plugin."""
    accent = {"soon": "#2563eb", "stale": "#d97706", "errors": "#6b7280"}
    font = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif"
    h = lambda s: html.escape(s, quote=True)

    body = ""
    for s in report["sections"]:
        c = accent[s["key"]]
        body += ('<tr><td style="padding:12px 24px 4px;">'
                 f'<div style="font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:{c};margin-bottom:8px;">'
                 f'{h(s["title"])}</div>')
        for item in s["items"]:
            if s["key"] == "errors":
                body += f'<div style="font-size:13px;color:#6b7280;margin-bottom:4px;">{h(item)}</div>'
                continue
            chips = "".join(
                f'<a href="{h(url)}" style="display:inline-block;font-size:12px;color:#1f2a44;background:#eef2ff;border-radius:10px;padding:2px 9px;margin:2px 4px 2px 0;text-decoration:none;">{h(path)}</a>'
                for path, url in item["pages"].items()) or '<span style="color:#9ca3af;">Not on any tracked page</span>'
            when = item["when"][:1].upper() + item["when"][1:]
            body += (f'<div style="border-left:4px solid {c};background:#f9fafb;border-radius:0 6px 6px 0;padding:10px 14px;margin-bottom:10px;">'
                     f'<a href="{h(item["url"])}" style="font-size:15px;font-weight:600;color:#111827;text-decoration:none;">{h(item["title"])}</a>'
                     f'<div style="font-size:13px;color:#374151;margin-top:3px;font-weight:600;">{h(when)}</div>'
                     f'<div style="font-size:13px;color:#374151;margin-top:6px;">On: {chips}</div>'
                     '</div>')
        body += "</td></tr>"

    footer = "Sent by FRP Events Watch" + (f' · <a href="{h(footer_url)}" style="color:#6b7280;">Workflow run</a>' if footer_url else "")
    return ('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            f'<title>{h(report["subject"])}</title></head>'
            '<body style="margin:0;padding:0;background:#f4f5f7;">'
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;"><tr><td align="center" style="padding:24px 12px;">'
            f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;font-family:{font};color:#1f2937;">'
            '<tr><td style="background:#1f2a44;color:#ffffff;padding:20px 24px;border-radius:8px 8px 0 0;">'
            '<div style="font-size:18px;font-weight:600;">FRP Events Watch</div>'
            f'<div style="font-size:13px;color:#c7cdd9;margin-top:2px;">{h(report["date"])}</div></td></tr>'
            f'<tr><td style="padding:20px 24px 4px;font-size:15px;">{h(report["summary"])}</td></tr>'
            f'{body}'
            f'<tr><td style="padding:16px 24px 20px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">{footer}</td></tr>'
            '</table></td></tr></table></body></html>')


def run_url() -> str | None:
    """Link to this GitHub Actions run, when running there."""
    if os.getenv("GITHUB_RUN_ID"):
        return f"{os.environ['GITHUB_SERVER_URL']}/{os.environ['GITHUB_REPOSITORY']}/actions/runs/{os.environ['GITHUB_RUN_ID']}"
    return None


def send_email(subject: str, text: str, html_body: str) -> None:
    r = requests.post(
        "https://api.resend.com/emails",
        headers={**UA, "Authorization": f"Bearer {os.environ['RESEND_API_KEY']}"},
        json={"from": os.getenv("MAIL_FROM", "onboarding@resend.dev"),
              "to": [os.environ["MAIL_TO"]], "subject": subject, "text": text, "html": html_body},
        timeout=30,
    )
    r.raise_for_status()


def main() -> int:
    dry = "--dry-run" in sys.argv
    html_out = sys.argv[sys.argv.index("--html") + 1] if "--html" in sys.argv else None
    now = datetime.now(timezone.utc)
    soon, past = classify(fetch_events(now), now)
    pages, errors = fetch_pages()
    report = build_report(soon, past, pages, errors, now)
    if report["quiet"] and os.getenv("QUIET_EMAIL") == "0":
        print("Nothing to report.")
        return 0
    text, html_body = render_text(report), render_html(report, run_url())
    print(report["subject"], text, sep="\n\n")
    if html_out:
        with open(html_out, "w", encoding="utf-8") as f:
            f.write(html_body)
    if not dry:
        send_email(report["subject"], text, html_body)
    return 0


if __name__ == "__main__":
    sys.exit(main())
