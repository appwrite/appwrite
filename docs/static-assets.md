# Console static assets

## Build once, configure at runtime

Staging builds both image architectures with one `ASSET_BUILD_ID` identifying the
workflow run and attempt. The ID is compiled into the app and written to
`dist/client/asset-build-id.txt`. No CDN hostname is baked into the image.

`application-configuration` supplies `CDN_ORIGIN`:

| Environment | Origin                            | Bucket                        |
| ----------- | --------------------------------- | ----------------------------- |
| Staging     | `https://cdn.staging.appwrite.io` | `appwrite-console-staging`    |
| Production  | `https://cdn.appwrite.io`         | `appwrite-console-production` |

Assets live under `<origin>/builds/<build-id>/`. Without a CDN origin, the same
image serves its bundled assets locally. Restart the app to change runtime config.

TanStack's native `transformAssets` selects SSR asset URLs. Vite `base: ''` makes
lazy chunks and stylesheet assets resolve relative to their CDN location. The
root stylesheet is a side-effect import so TanStack manages it. Public images,
fonts, icons, media and downloads use the shared `assetUrl` helper at render sites;
inline CSS and source sets use its companion helpers. Application routes, API
requests, user uploads and external URLs are left unchanged.

Prerendering remains enabled. Its asset URLs use a reserved
`https://console-cdn.invalid/builds/<build-id>` placeholder. Bun's existing HTML
runtime-config injection replaces it with the runtime origin and the same build
prefix, or removes the prefix for local serving. SSR and browser hydration use
the same runtime config. Server-side image generation retains local file paths.

## Publish and promote

CI extracts `dist/client` from both exact image digests. Rclone checks matching
build IDs and combines their static outputs with checksums and immutable-file
checks before uploading. Only `.github/scripts/static-assets.filter` files are
published; HTML, server exports, source maps and build metadata stay in the image.

Every uploaded file has a one-year immutable cache policy, including public
images/fonts. `rclone copy` retains previous build directories for open tabs and
rollback. Never replace a build directory with different bytes or delete older
builds as part of deployment. Retention cleanup is a separate operation.

Staging records a `build-<run-id>-<attempt>` image tag in both registries. Production
selects that immutable tag from the latest successful staging workflow for the
release commit, copies the same images' assets into production R2, and tags the
same image digests for release. It does not rebuild. A release commit without a
successful staging deployment fails; deploy it to staging first.

Both environments update the deployed image only after the asset upload succeeds.
An image rollback selects its original build prefix, including the original public
images and fonts.

## Rollout

1. Apply the R2 infrastructure and wait for both custom domains to become Active.
   Anonymous GET/HEAD CORS is required for modules, fonts and browser fetches.
2. Add bucket-scoped R2 Object Read & Write repository secrets:
   `R2_STAGING_ACCESS_KEY_ID`, `R2_STAGING_SECRET_ACCESS_KEY`,
   `R2_PRODUCTION_ACCESS_KEY_ID`, `R2_PRODUCTION_SECRET_ACCESS_KEY`.
3. Apply the runtime origins in `application-configuration`, then merge this app
   change and let its staging build/upload/deployment complete.
4. Verify staging, then release that commit to promote the same image to production.

No Worker or routing activation step is required. Upload
credentials are used only by CI, never by the build or running application.

References: [TanStack runtime CDN URLs](https://tanstack.com/start/latest/docs/framework/react/guide/cdn-asset-urls),
[rclone copy](https://rclone.org/commands/rclone_copy/).
