# Console static assets

TanStack Start uses Vite's CDN `base` while its router `basepath` stays `/`.
The staging and production workflows build with `CDN_ORIGIN` set to their respective
R2 custom domains. Generated scripts, styles, lazy chunks and CSS asset URLs use
the CDN; font preloads use the same base. Omit `CDN_ORIGIN` for local asset URLs.
Changing environments requires a rebuild; other application config remains runtime config.

After building, CI extracts `dist/client` from both exact image digests and uses
rclone to publish the static files before updating `application-configuration`.
`rclone copy --checksum --immutable` combines both outputs locally and rejects
conflicting paths before upload. The images are not rebuilt for upload.

Only `.github/scripts/static-assets.filter` paths and extensions are published.
HTML, source maps, server output and discovery exports stay on Bun. Hardcoded
public URLs in JSX/content (for example `/images/example.avif`) still resolve
against Bun; neither Vite nor TanStack rewrites arbitrary strings. Public files
are also uploaded so URLs can be migrated using `import.meta.env.BASE_URL`.

`rclone copy` retains previous chunks for open tabs and rollback. Hashed JS/CSS/WASM
files are immutable and cached for one year; other public assets use a five-minute
TTL. Rclone handles checksums, retries and MIME/metadata. Upload failure blocks
deployment. Image rollback does not restore overwritten unversioned public files.

## Setup

Apply infrastructure first and wait for both custom domains to become Active.
R2 CORS allows anonymous GET/HEAD requests for browser modules and fonts.
Add bucket-scoped R2 Object Read & Write repository secrets:

| Environment | Bucket                        | Secrets                                                          |
| ----------- | ----------------------------- | ---------------------------------------------------------------- |
| Staging     | `appwrite-console-staging`    | `R2_STAGING_ACCESS_KEY_ID`, `R2_STAGING_SECRET_ACCESS_KEY`       |
| Production  | `appwrite-console-production` | `R2_PRODUCTION_ACCESS_KEY_ID`, `R2_PRODUCTION_SECRET_ACCESS_KEY` |

Then merge the Console change, verify staging scripts/styles/fonts and client-side
navigation, and release production. There is no Worker or routing activation step.
Credentials are used only by the uploader, never by the build or running app.

References: [Vite public base](https://vite.dev/guide/build#public-base-path),
[TanStack CDN URLs](https://tanstack.com/start/latest/docs/framework/react/guide/cdn-asset-urls),
[rclone copy](https://rclone.org/commands/rclone_copy/).
