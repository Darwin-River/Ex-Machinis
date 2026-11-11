#!/usr/bin/env python3
"""Announce strategy blog posts on Bluesky and Mastodon (#520).

Usage: announce.py documents/blog/11-some-post.md [...]

For each post it builds a short announcement from the post's front matter
(title, summary) and its address on the site, then:

- posts it to Bluesky when BLUESKY_HANDLE and BLUESKY_APP_PASSWORD are set;
- posts it to Mastodon when MASTODON_INSTANCE and MASTODON_TOKEN are set;
- always writes it to $GITHUB_STEP_SUMMARY (or prints it), ready to paste
  into Ko-fi, Discord, Reddit or anywhere else.

SITE_URL is the game's public address, e.g. https://exmachinis.example.
DRY_RUN=1 prints what would be posted and posts nothing.

Only the Python standard library is used, so the Action needs no installs.
"""

import datetime
import json
import os
import re
import sys
import urllib.request

BLUESKY_LIMIT = 300   # graphemes; counting characters is close enough here
MASTODON_LIMIT = 500


def front_matter(path):
    text = open(path, encoding="utf-8").read()
    meta = {"title": "", "summary": "", "date": ""}
    m = re.match(r"---\r?\n(.*?)\r?\n---\r?\n", text, re.S)
    if m:
        for line in m.group(1).splitlines():
            kv = re.match(r"^(\w+):\s*(.*)$", line)
            if kv:
                meta[kv.group(1)] = kv.group(2).strip()
    return meta


def shorten(text, limit):
    return text if len(text) <= limit else text[: limit - 1].rstrip() + "…"


def announcement(meta, link, limit):
    """Title, summary and link, trimming the summary to fit"""
    head = f"New on the Ex Machinis blog: {meta['title']}"
    tail = f"\n\n{link}\n\n#gamedev #programming #lua"
    room = limit - len(head) - len(tail) - 2
    body = shorten(meta["summary"], room) if room > 20 else ""
    return head + ("\n\n" + body if body else "") + tail


def request(url, data=None, token=None):
    headers = {"Content-Type": "application/json", "User-Agent": "exmachinis-announce"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read() or b"{}")


def post_bluesky(text, link, meta):
    session = request("https://bsky.social/xrpc/com.atproto.server.createSession",
                      {"identifier": os.environ["BLUESKY_HANDLE"],
                       "password": os.environ["BLUESKY_APP_PASSWORD"]})
    # Links and hashtags are only clickable with facets, which count in bytes
    raw = text.encode("utf-8")
    facets = []
    start = raw.find(link.encode())
    if start >= 0:
        facets.append({"index": {"byteStart": start, "byteEnd": start + len(link.encode())},
                       "features": [{"$type": "app.bsky.richtext.facet#link", "uri": link}]})
    for m in re.finditer(rb"#(\w+)", raw):
        facets.append({"index": {"byteStart": m.start(), "byteEnd": m.end()},
                       "features": [{"$type": "app.bsky.richtext.facet#tag",
                                     "tag": m.group(1).decode()}]})
    record = {
        "$type": "app.bsky.feed.post",
        "text": text,
        "createdAt": datetime.datetime.now(datetime.timezone.utc).isoformat().replace("+00:00", "Z"),
        "langs": ["en"],
        "facets": facets,
        "embed": {"$type": "app.bsky.embed.external",
                  "external": {"uri": link, "title": meta["title"],
                               "description": meta["summary"]}},
    }
    request("https://bsky.social/xrpc/com.atproto.repo.createRecord",
            {"repo": session["did"], "collection": "app.bsky.feed.post", "record": record},
            token=session["accessJwt"])


def post_mastodon(text):
    instance = os.environ["MASTODON_INSTANCE"].rstrip("/")
    if not instance.startswith("http"):
        instance = "https://" + instance
    request(f"{instance}/api/v1/statuses", {"status": text, "visibility": "public"},
            token=os.environ["MASTODON_TOKEN"])


def summary(lines):
    out = os.environ.get("GITHUB_STEP_SUMMARY")
    text = "\n".join(lines) + "\n"
    if out:
        with open(out, "a", encoding="utf-8") as f:
            f.write(text)
    print(text)


def main(paths):
    site = os.environ.get("SITE_URL", "").rstrip("/")
    dry = os.environ.get("DRY_RUN") == "1"
    failed = False
    if not site:
        summary(["**SITE_URL is not set** (Settings → Secrets and variables → Actions → "
                 "Variables), so nothing was posted."])
        site = "https://YOUR-SITE"
        dry = True
    for path in paths:
        slug = os.path.basename(path)[:-3]
        meta = front_matter(path)
        link = f"{site}/blog/{slug}"
        lines = [f"## {meta['title']}", ""]
        targets = []
        if os.environ.get("BLUESKY_HANDLE") and os.environ.get("BLUESKY_APP_PASSWORD"):
            targets.append(("Bluesky", lambda: post_bluesky(
                announcement(meta, link, BLUESKY_LIMIT), link, meta)))
        if os.environ.get("MASTODON_INSTANCE") and os.environ.get("MASTODON_TOKEN"):
            targets.append(("Mastodon", lambda: post_mastodon(
                announcement(meta, link, MASTODON_LIMIT))))
        for name, send in targets:
            if dry:
                lines.append(f"- {name}: dry run, not posted")
                continue
            try:
                send()
                lines.append(f"- {name}: posted")
            except Exception as e:   # one network failing shouldn't stop the other
                failed = True
                lines.append(f"- {name}: **failed**: {e}")
        if not targets:
            lines.append("- No network is set up; paste the text below by hand.")
        lines += ["", "Ready to paste (Ko-fi, Discord, Reddit…):", "",
                  "```", announcement(meta, link, MASTODON_LIMIT), "```", ""]
        summary(lines)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main([p for p in sys.argv[1:] if p.endswith(".md")]))
