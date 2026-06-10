/**
 * Patches for the prebuilt almostnode bundle used by the browser CLI shell.
 */

/** Worker URL rewrite (see vite-almostnode-plugin). */
const VITE_WORKER_URL =
  /new URL\(\s*\/\*\s*@vite-ignore\s*\*\/\s*"([^"]+)",\s*import\.meta\.url\s*\)/gs

/**
 * almostnode only throws from `process.exit()` during synchronous script startup.
 * CLIs like Appwrite use async entrypoints; commander calls `process.exit()` after
 * help then keeps parsing, which prints help a second time. Always throw so
 * execution stops the way real Node does.
 */
const PROCESS_EXIT_SYNC_GUARD =
  /if \(syncExecution\) \{\s*throw new Error\(`Process exited with code \$\{(\w+)\}`\);\s*\}/g

export function patchAlmostnodeBundle(code: string): string {
  return code
    .replace(VITE_WORKER_URL, '"$1"')
    .replace(
      PROCESS_EXIT_SYNC_GUARD,
      'throw new Error(`Process exited with code $1`);',
    )
}

/** Skip stderr when it duplicates stdout (commander + almostnode async exit quirk). */
export function shouldWriteCliStderr(
  stdout: string,
  stderr: string,
): boolean {
  if (!stderr) return false
  if (!stdout) return true
  return stderr !== stdout
}
