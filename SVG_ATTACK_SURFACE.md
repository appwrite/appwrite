# SVG Attack Surface — `/view` and `/preview`

Review notes for the `feat-svg-preview` branch, which adds `svg => image/svg+xml`
to `app/config/storage/inputs.php` and rasterizes SVG previews through Imagick
(base image bumped to `appwrite/base:2.1.0`; enabled by docker-base PR #95).

Status: **app-side mitigations implemented on this branch** (see "Implemented"
below). Remaining rows depend on the 2.1.0 base image's SVG coder / `policy.xml`
(docker-base #95) or on infra (isolated origin), which are not in this repo.

## Implemented on `feat-svg-preview`

- **`/view` serves `image/svg+xml` as `Content-Disposition: attachment`**
  (`View/Get.php`). The browser never renders it as a top-level document, so
  scripts never execute; `<img src>` embedding still works (restricted mode).
  Closes V1–V7 for direct navigation without relying on a sanitizer.
- **`/view` CSP fixed** from `script-src none;` to `script-src 'none';`
  (backstop for any inline-served type).
- **`/preview` sanitizes SVG before rasterization** via
  `Appwrite\Storage\Svg::sanitize()` (`Preview/Get.php`), stripping scripts,
  entities, DOCTYPE, and external references before Imagick parses the XML.
  Neutralizes P1–P5 and P9 at the app layer, independent of the base-image
  coder. Guard is keyed on the server-detected mime (`image/svg+xml`), not the
  filename extension, and is disabled in the placeholder-logo fallback.

  > **Watch out:** `enshrined/svg-sanitize` alone does NOT stop SSRF. By design
  > its `isHrefSafeValue` allows `http://`/`https://` hrefs, and
  > `removeRemoteReferences(true)` only strips `url(...)`-wrapped refs — so a raw
  > `<image xlink:href="http://169.254.169.254/…">` survives it (P4/P5). That is
  > why we wrap it in `Appwrite\Storage\Svg`, which overrides the href policy to
  > allow only in-document `#fragments` and inline `data:image` URIs and drop
  > everything else. Do not replace `Svg::sanitize()` with the raw library.
- **`/preview` never re-emits SVG**: `svg` is absent from
  `app/config/storage/outputs.php` and output falls back to `jpg` (closes P15).
- **Tests**:
  - Unit (`tests/unit/Storage/SvgTest.php`, 7 cases, deterministic proof):
    strips scripts/handlers, `javascript:` hrefs, DOCTYPE/XXE entities, remote
    `<image>` refs, and `file://` refs; keeps benign shapes, `#fragments`, and
    `data:image`.
  - E2E (`tests/e2e/Services/Storage/StorageBase.php`):
    `testFileViewContentType` and `testFileViewSvgIsNotExecutable` assert the
    attachment disposition + CSP + nosniff on a hostile SVG;
    `testFilePreviewSvg` and `testFilePreviewSvgNeutralizesAttacks` push
    `script.svg`, `xxe.svg`, and `external.svg` through `/preview` and assert a
    clean rasterized JPEG with no payload markup, references, or leaked file
    content.

## Still pending (not in this repo)

- **Isolated cookieless origin for `/view`** — the strongest control; makes any
  residual XSS harmless even on sanitizer/CSP bypass. Infra/routing change.
- **Explicit SVG dimension/DoS cap in `/preview`** — the `getimagesizefromstring`
  resolution guard is skipped for SVG (finding 2 / P8); only Imagick
  `RESOURCETYPE_*` limits apply today.
- **`policy.xml` hardening in docker-base #95** — coder denylist + resource
  limits, as defense-in-depth behind the app-side sanitization.

## Why SVG is special

SVG is not really an image — it is an XML document with scripting, external
references, and styling. That splits the threat model cleanly by endpoint:

- **`/view`** serves the file to the browser, which **executes the SVG as a live
  document** (`Content-Type: image/svg+xml`, `Content-Disposition: inline`,
  `View/Get.php:162-166`). Threat = browser-side execution (stored XSS family).
- **`/preview`** hands the raw bytes to ImageMagick — `Image::__construct` does
  `new Imagick(); $image->readImageBlob($data)`
  (`vendor/utopia-php/image/src/Image/Image.php:47-52`). Threat = server-side
  XML/raster engine (XXE, SSRF, delegate abuse, DoS).

## Key code-path findings

1. **The `/view` CSP is misquoted.** Header is `script-src none;`
   (`View/Get.php:164`). The CSP keyword is `'none'` *with quotes*; unquoted,
   `none` is parsed as a hostname. It still blocks inline scripts today only
   because `'unsafe-inline'` is absent, and it does nothing about non-script HTML
   (`<foreignObject>`, `<iframe>`), external resource loads, or redirects.
2. **The `/preview` resolution guard is skipped for SVG.**
   `getimagesizefromstring()` returns `false` for SVG, so the max width/height/area
   check at `Preview/Get.php:246-259` never runs for SVG input. The only remaining
   backstop is Imagick's own `RESOURCETYPE_*` limits.
3. **Server-side SVG safety lives entirely in the base image.** Nothing in this
   repo restricts XXE, external references, or dangerous coders — that is all
   `policy.xml` + which SVG delegate (librsvg vs native MSVG/MVG) ships in 2.1.0.
4. **`/preview` responses are cached** (`label('cache', true)`,
   `Preview/Get.php:58`), keyed on transform params — amplifies anything below.
5. **Confirm SVG is not a valid output format** (see P15). Output is inferred from
   the source extension (`Preview/Get.php:202-208`); if `svg` is in
   `storage-outputs`, an SVG preview with no `output` param round-trips as
   `image/svg+xml` and inherits the entire `/view` XSS family.

## `/view` — browser-side execution (stored XSS family)

| # | Attack | Payload sketch | Impact | Status in this code |
|---|--------|----------------|--------|---------------------|
| V1 | `<script>` execution | `<svg><script>fetch('/v1/account',{credentials:'include'})…</script></svg>` | Stored XSS on API origin: session/cookie theft, authed API calls | CSP `script-src none` blocks inline (by accident — see finding 1); sole defense |
| V2 | Event-handler XSS | `<svg onload="…">`, `onclick`, `onmouseover` on any element | Same as V1 | Blocked only by CSP (no `'unsafe-inline'`) |
| V3 | `javascript:` URI | `<a xlink:href="javascript:alert(document.cookie)">` | XSS on click | Blocked by CSP `script-src` |
| V4 | SMIL/animate XSS | `<animate attributeName="href" values="javascript:…"/>`, `<set>` | XSS without a `<script>` tag; bypasses naive sanitizers | Blocked by CSP; NOT by tag-blocklists |
| V5 | `<foreignObject>` HTML injection | `<foreignObject><iframe src=…>`, embedded `<form>` | Phishing/clickjacking/HTML on a trusted origin, credential capture | No sanitization; CSP doesn't stop non-script HTML/iframes |
| V6 | External resource load | `<image href="http://attacker/x">`, `<use href="//…">`, CSS `@import` | Victim-browser SSRF-lite, IP/referrer leak, tracking beacon | Not restricted (no `img-src`/`connect-src` in CSP) |
| V7 | Open redirect / meta refresh | `<foreignObject>` with `<meta http-equiv=refresh>` | Phishing hosted on your domain | Not restricted |
| V8 | Content-type / polyglot sniffing | SVG that is also valid HTML/JS; served then sniffed | XSS if a browser ignores the mime | `X-Content-Type-Options: nosniff` mitigates |
| V9 | Cookie-scope pivot | Any of the above when `/view` shares a cookie domain with console/app | Full account takeover | Depends on deployment topology |
| V10 | Client-side bomb | Billion-laughs / deeply nested SVG served inline | Victim tab CPU/memory DoS | No size/complexity cap beyond upload limit |

## `/preview` — server-side rasterization (parser/engine family)

| # | Attack | Payload sketch | Impact | Status in this code |
|---|--------|----------------|--------|---------------------|
| P1 | XXE local file read | `<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>…<text>&x;</text>` | Secret disclosure baked into returned image | Depends entirely on base coder/policy |
| P2 | XXE → SSRF | `<!ENTITY x SYSTEM "http://169.254.169.254/…">` | Cloud metadata / internal service read | Depends on coder |
| P3 | Blind/OOB XXE | Parameter entities + external DTD exfil | Exfil of non-renderable secrets | Depends on coder |
| P4 | SSRF via `<image>` href | `<image xlink:href="http://169.254.169.254/latest/meta-data/…"/>` | Metadata/IMDS creds embedded in preview | librsvg blocks remote by default; MSVG coder does not |
| P5 | Local file via `<image>`/`<use>` | `xlink:href="file:///etc/passwd"` | Local file rendered into output | Depends on coder |
| P6 | Billion laughs | Nested entity expansion | Memory/CPU DoS | No entity guard in app; relies on parser limits |
| P7 | Decompression bomb (.svgz) | gzip'd SVG expanding to GBs | Memory DoS | App also runs its own GZIP/ZSTD decompress before Imagick |
| P8 | Huge canvas / dimensions | `width="1000000" height="1000000"` or vast `viewBox` | Pixel-buffer memory exhaustion | **Resolution guard skipped** (finding 2); only Imagick `RESOURCETYPE_*` limits remain |
| P9 | ImageMagick delegate/coder abuse | SVG pulling MVG/MSL/EPS→Ghostscript (ImageTragick, CVE-2016-3714 lineage) | Potential RCE via delegate chain | Depends on `policy.xml` disabling coders/delegates |
| P10 | Filter complexity bomb | Many `<feGaussianBlur>` / huge filter regions | CPU/time DoS | Only Imagick time/area limits |
| P11 | Recursion bomb | Mutually recursive `<use>` references | CPU/stack DoS | Depends on parser |
| P12 | Font/text delegate | Embedded font or text triggering fontconfig/freetype paths | DoS or delegate exploit | Depends on base build |
| P13 | Cache poisoning | Cached endpoint (finding 4); malicious/oversized output or error cached | Amplifies any above; serves attacker-shaped bytes repeatedly | Cache keyed on transform params, not content safety |
| P14 | Type confusion | `.svg` mime but bytes are another coder's format | Mishandling / coder-specific bugs | Mime trusted from upload; extension drives `$type` |
| P15 | Output-format XSS carry-over | Request with no `output` → SVG round-trips as `image/svg+xml` | Preview becomes an XSS vector, inherits V1–V10 | **Verify** `svg` absent from `storage-outputs`; force raster output for SVG inputs |

## Recommended verification before merge

Cross-check against docker-base PR #95 and confirm:

1. **SVG is rendered by librsvg**, not ImageMagick's native MSVG/MVG coder.
2. **`policy.xml` disables** the `URL`, `HTTPS`, `HTTP`, `MVG`, `MSL`, and
   `EPHEMERAL` coders, and sets width/height/area/memory/time/disk limits.
3. **`svg` is absent from `storage-outputs`** so previews always rasterize to
   jpg/png/webp (closes P15).

## Recommended hardening in this repo

- `/view`: fix the CSP to something like
  `default-src 'none'; style-src 'unsafe-inline'; sandbox` and prefer
  `Content-Disposition: attachment` for `image/svg+xml` so browsers never render
  uploaded SVG inline. (Applies to V1–V7.)
- `/preview`: add an explicit dimension/complexity cap for SVG since the
  `getimagesizefromstring` guard does not fire (finding 2, P8).
- Consider sanitizing SVG on upload (strip scripts/external refs/DOCTYPE) as
  defense-in-depth for both endpoints, independent of the base-image coder.
- Add e2e coverage for the failure cases above, not just the success/rasterize
  path currently in `tests/e2e/Services/Storage/StorageBase.php::testFilePreviewSvg`.

## References in code

- `src/Appwrite/Platform/Modules/Storage/Http/Buckets/Files/View/Get.php`
- `src/Appwrite/Platform/Modules/Storage/Http/Buckets/Files/Preview/Get.php`
- `vendor/utopia-php/image/src/Image/Image.php`
- `app/config/storage/inputs.php`, `app/config/storage/outputs.php`
- `tests/e2e/Services/Storage/StorageBase.php` (`testFilePreviewSvg`)
- `tests/resources/script.svg` (XSS fixture)
