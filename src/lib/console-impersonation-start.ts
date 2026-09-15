import { applyConsoleImpersonateUserId } from '@/lib/appwrite/sdk'
import {
  hardNavigateAfterConsoleImpersonation,
  persistConsoleImpersonationSession,
  type ConsoleImpersonationOperatorSnapshot,
} from '@/lib/console-impersonation'
import {
  recordRecentImpersonationTarget,
  waitForRecentImpersonationSync,
} from '@/lib/react-query/hooks/auth'

export type BeginConsoleImpersonationOptions = {
  /** Console path to open after impersonation starts (sanitized; defaults to `/account`). */
  redirect?: string | null
}

export type ConsoleImpersonationTarget = {
  $id: string
  name?: string | null
  email?: string | null
}

/**
 * Record the target in the operator's recents, apply impersonation headers, persist
 * the session, and hard-navigate to `/account` or a shared console path. Shared by
 * the impersonation dialog and `/impersonate` deep links.
 */
export async function beginConsoleImpersonation(
  target: ConsoleImpersonationTarget,
  operator: ConsoleImpersonationOperatorSnapshot,
  options?: BeginConsoleImpersonationOptions,
): Promise<void> {
  // Operator prefs are only writable before the impersonation headers go on.
  await waitForRecentImpersonationSync(
    recordRecentImpersonationTarget(operator.$id, target),
  )
  applyConsoleImpersonateUserId(target.$id)
  persistConsoleImpersonationSession(target.$id, operator, {
    skipNotify: true,
  })
  hardNavigateAfterConsoleImpersonation(options?.redirect)
}
