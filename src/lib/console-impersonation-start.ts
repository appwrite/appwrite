import { applyConsoleImpersonateUserId } from '@/lib/appwrite/sdk'
import {
  hardNavigateToAccountAfterImpersonation,
  persistConsoleImpersonationSession,
  type ConsoleImpersonationOperatorSnapshot,
} from '@/lib/console-impersonation'

/**
 * Apply impersonation headers, persist the session, and hard-navigate to `/account`.
 * Shared by the impersonation dialog and the `/impersonate/$userId` deep link.
 */
export function beginConsoleImpersonation(
  targetUserId: string,
  operator: ConsoleImpersonationOperatorSnapshot,
): void {
  applyConsoleImpersonateUserId(targetUserId)
  persistConsoleImpersonationSession(targetUserId, operator, {
    skipNotify: true,
  })
  hardNavigateToAccountAfterImpersonation()
}
