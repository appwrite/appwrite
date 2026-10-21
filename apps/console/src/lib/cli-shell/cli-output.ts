/**
 * Whether a command's stderr is worth writing after its stdout.
 *
 * The CLI renders some errors to both streams, and printing the same text twice
 * reads like two failures.
 */
export function shouldWriteCliStderr(stdout: string, stderr: string): boolean {
  if (!stderr) return false
  if (!stdout) return true
  return stderr !== stdout
}
