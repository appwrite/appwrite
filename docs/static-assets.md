# Static assets on R2

Console still runs Bun/SSR in the assets Kubernetes cluster. Cloudflare serves
selected same-origin static URLs from an R2 bucket. HTML, runtime configuration,
robots/discovery/docs exports, generated OG images, and server handlers continue
to use Bun. Keep the complete `dist` in the image for serving and fallback.

## Deployment

Both `staging.yml` and `production.yml` now follow this dependency graph:

```text
build amd64 + arm64 images
  ├─ publish multi-arch manifest ───────────────┐
  └─ extract dist/client from BOTH digests     │
       → validate union → upload R2 → verify ──┤
                                              → update application-configuration
                                                → ArgoCD rollout
```

Extraction uses `docker create` and `docker cp`; it does not execute the images
or rebuild the frontend. Identical object keys must contain identical bytes in
both architectures. Conflicts fail before any upload. Environment-level workflow
concurrency prevents overlapping releases writing to the same bucket.

The uploader accepts only the file types in its allowlist below `assets/`,
`images/`, `fonts/`, and `icons/`. HTML, source maps and server output are excluded.
The Worker in `appwrite-labs/infrastructure/cloudflare/files/assets-worker.mjs`
has the same allowlist. Update both together when adding asset types or paths.
Root files such as logos and the web manifest remain served by Bun.

## Provisioning order

1. Apply the infrastructure change through its Terraform CI workflow. It upgrades
   the existing Cloudflare provider and provisions both buckets, custom domains,
   and Workers with **no traffic routes enabled**. Check the plan for unintended
   Access changes before merging. Both custom domains must reach Active status.
2. Create an R2 Object Read & Write credential for each bucket and set the four
   GitHub Actions repository secrets below. Never use the account management API
   token as an S3 access key. Never put these credentials in image build arguments
   or Helm values. If credentials are managed centrally, store their values in
   the existing SSM secret system and provision the CI secrets out of band.
3. Merge this Console change and let staging deploy. Publishing is mandatory;
   missing credentials or a failed verification prevents the image-tag update.
4. Enable `assets_routing_environments = ["staging"]` in the infrastructure stack
   through CI. Test staging, then publish/deploy a Console production release before
   adding `"production"` to that set.

| Environment | Bucket | Verification origin | Repository secrets |
| --- | --- | --- | --- |
| Staging | `appwrite-console-staging` | `https://console-assets.staging.appwrite.io` | `R2_STAGING_ACCESS_KEY_ID`, `R2_STAGING_SECRET_ACCESS_KEY` |
| Production | `appwrite-console-production` | `https://console-assets.appwrite.io` | `R2_PRODUCTION_ACCESS_KEY_ID`, `R2_PRODUCTION_SECRET_ACCESS_KEY` |

The S3 endpoint is
`https://285d5ba7177bab8490e237b619b4b9f6.r2.cloudflarestorage.com`, region `auto`.
Infrastructure's `assets` output exposes the same destinations.

## Caching and rollback

- Hashed build files that do not come from `public/` get
  `public, max-age=31536000, immutable`. Existing keys with a different or unknown
  SHA-256 are rejected. The uploader records the SHA-256 in object metadata.
- Public/unversioned files get `public, max-age=300, must-revalidate`. These files
  can change before the app rollout and cached copies can remain for five minutes.
  Image rollback does **not** roll their bytes back. Version their URLs when a
  change requires exact old/new asset compatibility.
- No delete or expiry policy is applied. Keep old chunks for open tabs and image
  rollback. Garbage collection needs an explicit release-retention design.
- R2 metadata is checked for every object. Newly written objects are checked
  again, and a sample from every MIME/cache-policy class is downloaded through
  the custom domain and SHA-256 checked before deployment.
- The Worker forwards only conditional/range headers to the bucket, never user
  credentials. Errors and misses fall back to the original Bun origin and are
  not cached on the R2 subrequest. Successes carry `X-Asset-Source: r2`.

To disable the CDN, remove the environment from `assets_routing_environments`
through infrastructure CI; Bun still has its original static files. For an app
rollback, restore the previous image tag in `application-configuration` as usual.

## Checks

```sh
python3 -m unittest discover -s .github/scripts -p 'test_*.py' -v
bash -n .github/scripts/extract-assets.sh
python3 .github/scripts/publish-assets.py --client image-assets/amd64 --client image-assets/arm64 --public public --dry-run
```

In staging, check a hashed JS file, lazy navigation, an image, a font, and a range
request against a video. Verify `X-Asset-Source: r2`, correct MIME/cache headers,
and cache hits on repeated GETs. Check an SSR page and runtime endpoint values;
these must still come from Bun. Keep an old tab open across a release and check
lazy navigation again; also exercise an image-tag rollback. A deliberately
missing R2 asset should fall back to Bun, while a nonexistent `/assets/*.js`
must remain a 404 rather than return the HTML shell.
