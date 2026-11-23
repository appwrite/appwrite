# Console static assets

Staging and production share `https://cdn.appwrite.io` and the existing bucket name
`appwrite-console`. Both workflows pass the same build-time `CDN_ORIGIN`
to Vite; TanStack Start uses its native CDN base while the router basepath stays `/`.
Prerendered pages and SSR use the same generated URLs. Local builds omit the origin.
There is no CDN runtime configuration, URL wrapper, or custom transform.

Only content-hashed Vite output is published. Fonts live in `src/assets/fonts` and
are referenced by CSS and preload imports, so Vite generates matching hashed URLs.
Existing root-relative public images, icons and downloads remain on the app origin.
For new CDN images, import them from source (for example
`import illustration from './illustration.svg'`) so Vite owns the URL and hash.

After building, each workflow extracts `dist/client` from both exact image digests.
Rclone combines the outputs locally with `copy --checksum --immutable`, rejecting
conflicting paths, then copies only the hashed assets to the shared bucket with
one-year immutable caching. Upload failure blocks deployment. No upload rebuilds
the image, deletes old chunks, or overwrites unversioned public files. Different
staging and production builds safely coexist; rollback retains the old URLs.
The existing build workflows remain independent, with identical CDN settings.

## Self-hosted releases

Production publishes both `appwrite/new` variants to Docker Hub and GHCR for
Linux amd64 and arm64:

| Tag | Asset origin |
| --- | --- |
| `<version>` | `https://cdn.appwrite.io` |
| `<version>-self-hosted` | Installation's own origin (empty `CDN_ORIGIN`) |

Staging keeps using the CDN. Manual runs use the commit SHA instead of the version.

CDN URLs are baked in at build time; changing the runtime profile cannot remove
them. Use the self-hosted tag with `VITE_CONSOLE_PROFILE=self-hosted`. Update
installer and Compose image pins only after that tag exists, and manually verify
asset loading with CDN access blocked before adopting the first release.

## Setup

1. Apply the infrastructure through CI and wait for `cdn.appwrite.io` to become Active.
2. Add bucket-scoped Object Read & Write repository secrets
   `CONSOLE_STAGING_R2_ACCESS_KEY_ID` / `CONSOLE_STAGING_R2_SECRET_ACCESS_KEY` and
   `CONSOLE_PRODUCTION_R2_ACCESS_KEY_ID` / `CONSOLE_PRODUCTION_R2_SECRET_ACCESS_KEY`;
   use a separate bucket-scoped credential for each. Staging uploads run in the
   `console-staging` environment; every production job waits on the
   `console-production` environment, where approval rules for releases belong.
3. Deploy staging, verify scripts/styles/fonts and client navigation, then release
   production. No application-configuration change is required.

R2 CORS permits anonymous GET/HEAD requests for browser modules and fonts.
Credentials are used only by the uploader. There is no Worker or staging CDN domain.
The shared CDN makes staging assets public, just like production assets.

References: [Vite public base](https://vite.dev/guide/build#public-base-path),
[Vite asset imports](https://vite.dev/guide/assets),
[TanStack CDN URLs](https://tanstack.com/start/latest/docs/framework/react/guide/cdn-asset-urls),
[rclone copy](https://rclone.org/commands/rclone_copy/).
