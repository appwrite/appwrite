#!/usr/bin/env python3
"""Print the published stable version directly below a target version.

The upgrade gate needs a baseline to upgrade *from*. Docker Hub is the source of
truth rather than git tags, because the image has to exist for the gate to run at
all -- a tag with no published image is not an upgrade anyone can perform.

    ./previous-stable.py 2.0.0   ->  1.9.6

Release candidates and channel tags are ignored: only X.Y.Z counts as a stable a
self-hoster could be upgrading from.
"""

from __future__ import annotations

import json
import sys
import urllib.error
import urllib.request

REPOSITORY = "appwrite/appwrite"
TAGS_URL = f"https://hub.docker.com/v2/repositories/{REPOSITORY}/tags/"
PAGES = 3
PAGE_SIZE = 100


def parse(version: str) -> tuple[int, int, int] | None:
    parts = version.split(".")

    if len(parts) != 3 or not all(part.isdigit() for part in parts):
        return None

    major, minor, patch = (int(part) for part in parts)

    return major, minor, patch


def published() -> list[tuple[int, int, int]]:
    versions: list[tuple[int, int, int]] = []
    url: str | None = f"{TAGS_URL}?page_size={PAGE_SIZE}&ordering=last_updated"

    for _ in range(PAGES):
        if url is None:
            break

        request = urllib.request.Request(url, headers={"User-Agent": "appwrite-upgrade-gate"})

        with urllib.request.urlopen(request, timeout=60) as response:
            payload = json.load(response)

        versions.extend(
            version
            for version in (parse(tag["name"]) for tag in payload.get("results", []))
            if version is not None
        )

        url = payload.get("next")

    return versions


def main() -> int:
    if len(sys.argv) != 2:
        print(f"usage: {sys.argv[0]} <target version>", file=sys.stderr)
        return 2

    target = parse(sys.argv[1])

    if target is None:
        print(f"'{sys.argv[1]}' is not an X.Y.Z version.", file=sys.stderr)
        return 2

    try:
        candidates = sorted(version for version in published() if version < target)
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as error:
        print(f"Could not read tags from Docker Hub: {error}", file=sys.stderr)
        return 1

    if not candidates:
        print(f"No published stable below {sys.argv[1]}.", file=sys.stderr)
        return 1

    print(".".join(str(part) for part in candidates[-1]))

    return 0


if __name__ == "__main__":
    sys.exit(main())
