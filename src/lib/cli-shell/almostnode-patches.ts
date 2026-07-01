/**
 * Patches for the prebuilt almostnode bundle used by the browser CLI shell.
 */

/** Worker URL rewrite (see vite-almostnode-plugin). */
const VITE_WORKER_URL =
  /new URL\(\s*\/\*\s*@vite-ignore\s*\*\/\s*"([^"]+)",\s*import\.meta\.url\s*\)/gs

export function patchAlmostnodeBundle(code: string): string {
  return code.replace(VITE_WORKER_URL, '"$1"')
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
