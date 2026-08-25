/**
 * Where the browser build of the Appwrite CLI lives once it is running.
 *
 * The URLs it is fetched from are in asset-urls.ts, which is Vite-only.
 */

/**
 * The CLI release this console ships.
 *
 * Shown in progress and cache messages; the artifact itself is pinned by the
 * `appwrite-cli-wasm` version in package.json. Keep the two in step — this
 * string names what the user is running.
 *
 * Pinned rather than resolved from a dist-tag, deliberately. npm's `latest`
 * moved from a JavaScript bundle to a native binary at CLI 26, which would have
 * broken this terminal in production with no change on our side.
 */
export const CLI_WASM_VERSION = '27.1.0'

/**
 * HOME for the CLI process.
 *
 * Matches the fallback compiled into the browser build
 * (`internal/config/home_js.go`), but is set explicitly rather than relied upon:
 * the fallback is there for embedders that forget, and we are not one.
 */
export const CLI_WASM_HOME = '/home/appwrite'

/** Preferences directory, rewritten from the console session on every boot. */
export const CLI_WASM_PREFS_DIR = `${CLI_WASM_HOME}/.appwrite`
