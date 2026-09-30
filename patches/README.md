# Dependency patches

Bun applies these patches during `bun install` (`patchedDependencies` in
`package.json`). `bun.lock` records each patch file's hash, so a
`--frozen-lockfile` install fails if a patch is edited without regenerating the
lockfile. pnpm does not read this key; local pnpm installs stay unpatched.

## TanStack Start server-function hardening (September 2026)

Backport of the unreleased upstream fix for the reflected XSS in TanStack Start
server-function response handling (private TanStack advisory of 2026-09-25, CVE
pending, provisional CVSS 9.3). Affected: `@tanstack/start-server-core`
1.143.12 - 1.169.37 and `@tanstack/react-start` 1.143.12 - 1.168.58. A crafted
`/_serverFn/<id>?payload=...` URL could seed internal middleware state
(`result`, `error`, `headers`, `sendContext`) from the wire payload, and a plain
browser navigation to it was answered with attacker-controlled HTML from the
app's own origin.

| Package                       | Installed | Patch                                          |
| ----------------------------- | --------- | ---------------------------------------------- |
| `@tanstack/start-server-core` | 1.169.32  | `@tanstack%2Fstart-server-core@1.169.32.patch` |
| `@tanstack/start-client-core` | 1.170.28  | `@tanstack%2Fstart-client-core@1.170.28.patch` |
| `@tanstack/router-core`       | 1.171.28  | `@tanstack%2Frouter-core@1.171.28.patch`       |

What they change (same as the upstream source diff shared with the advisory):

- `start-client-core`: `__executeServer` forwards only `data`, `context`, and
  `method` into server middleware instead of spreading the whole payload.
- `start-server-core`: `handleServerAction` builds the action input from those
  public fields only, prefers `error` over a stale `result`, and no longer hands
  non-`Response` objects to callers without the RPC header; `requestHandler`
  turns any non-`Response` handler result into a plain 500; nested
  server-function calls get the same input filtering and propagate errors;
  `notFound()` headers can no longer relabel the JSON body.
- `router-core`: `isSsrResponse` requires a real `Response` and
  `normalizeSsrResponse` throws instead of wrapping arbitrary values.

The `start-server-core` and `start-client-core` patches are TanStack's own bun
patches for React Start 1.168.32 regenerated against our versions (they apply
verbatim). `router-core` is a hand-port of the same two functions because the
surrounding code moved between 1.171.15 and 1.171.28; its source maps were not
regenerated.

`src/server/middleware/server-fn-guard.ts` is the independent second layer (RPC
header required, navigations refused, responses sandboxed) and stays in place
after the upgrade.

## Removing

Once TanStack publishes versions containing the fix (the CVE advisory will name
them), bump `@tanstack/react-start` and `@tanstack/react-router`, delete the
three patch files and their `patchedDependencies` entries, and regenerate
`bun.lock` with the CI bun version (`npx -y bun@1.4 install` in a scratch copy of
`package.json` + `bun.lock`). Bun reports a patch mismatch if the entries are
left in place after a version bump.
