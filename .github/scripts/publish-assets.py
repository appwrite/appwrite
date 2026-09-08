#!/usr/bin/env python3
"""Publish the static files from BOTH deployed image digests, never a rebuild.

No delete operation is used: old tabs and rollback images still need old chunks.
Run `python3 publish-assets.py --help` for the CI and local dry-run interfaces.
"""

import argparse
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
import hashlib
import mimetypes
import os
from pathlib import Path
import re
import time
from urllib.parse import quote, urlsplit
from urllib.request import Request, urlopen


# Keep aligned with infrastructure/cloudflare/files/assets-worker.mjs.
STATIC_PATH = re.compile(
    r"^(assets|images|fonts|icons)/.+\.(?:js|mjs|css|wasm|json|avif|webp|png|jpe?g|gif|svg|ico|woff2?|ttf|otf|mp4|webm|mov)$",
    re.IGNORECASE,
)
HASHED_NAME = re.compile(r"-[A-Za-z0-9_-]{8,}\.[^.]+$")
IMMUTABLE = "public, max-age=31536000, immutable"
MUTABLE = "public, max-age=300, must-revalidate"
TYPES = {
    ".js": "text/javascript",
    ".mjs": "text/javascript",
    ".css": "text/css",
    ".wasm": "application/wasm",
    ".json": "application/json",
    ".avif": "image/avif",
    ".svg": "image/svg+xml",
    ".woff": "font/woff",
    ".woff2": "font/woff2",
    ".ttf": "font/ttf",
    ".otf": "font/otf",
}


@dataclass(frozen=True)
class Asset:
    key: str
    path: Path
    sha256: str
    size: int
    content_type: str
    cache_control: str


def collect_assets(clients, public):
    """Validate the complete union before making any remote writes."""
    assets = {}
    for client in clients:
        if not client.is_dir():
            raise ValueError(f"Missing client build: {client}")
        count = 0
        for path in sorted(client.rglob("*")):
            key = path.relative_to(client).as_posix()
            if not path.is_file() or not STATIC_PATH.fullmatch(key):
                continue
            if path.is_symlink():
                raise ValueError(f"Refusing symlink: {path}")
            data = path.read_bytes()
            if not data:
                raise ValueError(f"Empty asset: {path}")
            asset = Asset(
                key=key,
                path=path,
                sha256=hashlib.sha256(data).hexdigest(),
                size=len(data),
                content_type=TYPES.get(path.suffix.lower())
                or mimetypes.guess_type(key)[0]
                or "application/octet-stream",
                cache_control=(
                    IMMUTABLE
                    if key.startswith("assets/")
                    and HASHED_NAME.search(path.name)
                    and not (public / key).exists()
                    else MUTABLE
                ),
            )
            if key in assets and assets[key].sha256 != asset.sha256:
                raise ValueError(
                    f"Architecture builds disagree on {key}; refusing to publish either version"
                )
            assets[key] = asset
            count += 1
        if not count or not any(
            a.key.startswith("assets/") and a.path.is_relative_to(client)
            for a in assets.values()
        ):
            raise ValueError(f"Client build has no bundled assets: {client}")
    return sorted(assets.values(), key=lambda asset: asset.key)


def head(s3, bucket, key):
    try:
        return s3.head_object(Bucket=bucket, Key=key)
    except Exception as error:
        # Treat ONLY a missing object as absent. Authentication/network failures
        # must fail the release instead of turning into unconditional uploads.
        response = getattr(error, "response", {})
        if str(response.get("Error", {}).get("Code")) in {
            "404",
            "NoSuchKey",
            "NotFound",
        }:
            return None
        raise


def matches(remote, asset):
    return remote is not None and (
        remote.get("Metadata", {}).get("sha256") == asset.sha256
        and remote.get("ContentLength") == asset.size
        and remote.get("ContentType") == asset.content_type
        and remote.get("CacheControl") == asset.cache_control
    )


def publish(s3, bucket, assets, workers=16):
    # Check ALL immutable keys before writing anything, including collisions
    # with a previous release (public files may intentionally be updated).
    def inspect(asset):
        remote = head(s3, bucket, asset.key)
        if remote is not None and asset.cache_control == IMMUTABLE:
            if remote.get("Metadata", {}).get("sha256") != asset.sha256:
                raise ValueError(
                    f"Immutable key already exists with different/unknown bytes: {asset.key}"
                )
        return asset, remote

    with ThreadPoolExecutor(max_workers=workers) as pool:
        inspected = list(pool.map(inspect, assets))

        def upload(item):
            asset, remote = item
            if matches(remote, asset):
                return False
            with asset.path.open("rb") as body:
                s3.put_object(
                    Bucket=bucket,
                    Key=asset.key,
                    Body=body,
                    ContentType=asset.content_type,
                    CacheControl=asset.cache_control,
                    Metadata={"sha256": asset.sha256},
                )
            if not matches(head(s3, bucket, asset.key), asset):
                raise ValueError(f"R2 metadata verification failed: {asset.key}")
            return True

        uploaded = sum(pool.map(upload, inspected))
    return uploaded


def verify_http(origin, assets):
    # Check one small object per MIME/cache class via the real CDN hostname.
    # A cache-busting query avoids validating an older cached public file.
    samples = {}
    for asset in sorted(assets, key=lambda item: item.size):
        samples.setdefault((asset.content_type, asset.cache_control), asset)
    for asset in samples.values():
        url = f"{origin.rstrip('/')}/{quote(asset.key)}?verify={asset.sha256}"
        for attempt in range(5):
            try:
                with urlopen(Request(url), timeout=30) as response:
                    data = response.read()
                    if (
                        response.status != 200
                        or hashlib.sha256(data).hexdigest() != asset.sha256
                    ):
                        raise ValueError(f"CDN bytes differ: {asset.key}")
                    if response.headers.get_content_type() != asset.content_type:
                        raise ValueError(f"CDN MIME type differs: {asset.key}")
                    if response.headers.get("Cache-Control") != asset.cache_control:
                        raise ValueError(f"CDN cache policy differs: {asset.key}")
                break
            except Exception:
                if attempt == 4:
                    raise
                time.sleep(2**attempt)
    return len(samples)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--client", type=Path, action="append", required=True)
    parser.add_argument("--public", type=Path, default=Path("public"))
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    if not args.public.is_dir():
        parser.error("--public must point to the source checkout's public directory")
    assets = collect_assets(args.client, args.public)
    print(
        f"Validated {len(assets)} assets, {sum(asset.size for asset in assets)} bytes"
    )
    if args.dry_run:
        return

    required = (
        "R2_ENDPOINT",
        "R2_BUCKET",
        "R2_ORIGIN",
        "R2_ACCESS_KEY_ID",
        "R2_SECRET_ACCESS_KEY",
    )
    missing = [name for name in required if not os.environ.get(name)]
    if missing:
        parser.error(f"Missing environment variables: {', '.join(missing)}")

    endpoint, bucket, origin = (
        os.environ[name] for name in ("R2_ENDPOINT", "R2_BUCKET", "R2_ORIGIN")
    )
    if urlsplit(endpoint).scheme != "https" or urlsplit(origin).scheme != "https":
        parser.error("R2 endpoint and origin must use HTTPS")

    import boto3
    from botocore.config import Config

    s3 = boto3.client(
        "s3",
        endpoint_url=endpoint,
        region_name="auto",
        aws_access_key_id=os.environ["R2_ACCESS_KEY_ID"],
        aws_secret_access_key=os.environ["R2_SECRET_ACCESS_KEY"],
        config=Config(
            max_pool_connections=16,
            retries={"mode": "standard", "max_attempts": 5},
            request_checksum_calculation="when_required",
            response_checksum_validation="when_required",
        ),
    )
    uploaded = publish(s3, bucket, assets)
    checked = verify_http(origin, assets)
    print(
        f"Uploaded {uploaded}; reused {len(assets) - uploaded}; verified {checked} CDN samples. Ready to deploy."
    )


if __name__ == "__main__":
    main()
