/**
 * Console access reporting: signals to the backend that the project is "recently used"
 * so it can avoid auto-pausing. Fire-and-forget; backend has a 6-day cooldown.
 *
 * Call when: project layout loads in the browser, cloud profile, and project is not paused.
 * Uses the same fingerprint header as resume; header is removed after the request.
 */

import { sdk } from '@/lib/appwrite/sdk'
import {
  ensureFingerprintServerTimeSynced,
  generateFingerprintToken,
} from '@/lib/fingerprint'

const CONSOLE_FINGERPRINT_HEADER = 'X-Appwrite-Console-Fingerprint'

type ProjectsWithConsoleAccess = {
  updateConsoleAccess(params: { projectId: string }): Promise<unknown>
}

/**
 * Report that the console is accessing this project (keep-active signal).
 * Fire-and-forget: sets fingerprint, calls updateConsoleAccess, clears header in finally.
 * Only call when: browser, cloud profile, and project is not paused.
 */
export function reportConsoleAccess(projectId: string): void {
  if (!projectId) return

  const client = sdk.forConsole.client as {
    headers?: Record<string, string>
    config?: { endpoint?: string; project?: string }
  }

  ensureFingerprintServerTimeSynced(
    client.config?.endpoint ?? '',
    client.config?.project ?? 'console',
  )
    .then(() => generateFingerprintToken())
    .then((fingerprint) => {
      if (client.headers)
        client.headers[CONSOLE_FINGERPRINT_HEADER] = fingerprint
      const projects = sdk.forConsole.projects as ProjectsWithConsoleAccess
      if (typeof projects.updateConsoleAccess === 'function') {
        return projects.updateConsoleAccess({ projectId })
      }
    })
    .catch((e) => console.error('Failed to update console access:', e))
    .finally(() => {
      const client = sdk.forConsole.client as {
        headers?: Record<string, string>
      }
      if (client.headers) delete client.headers[CONSOLE_FINGERPRINT_HEADER]
    })
}
