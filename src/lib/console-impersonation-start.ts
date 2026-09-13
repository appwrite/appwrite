import { applyConsoleImpersonateUserId } from '@/lib/appwrite/sdk'
import {
  hardNavigateAfterConsoleImpersonation,
  persistConsoleImpersonationSession,
  type ConsoleImpersonationOperatorSnapshot,
} from '@/lib/console-impersonation'

export type BeginConsoleImpersonationOptions = {
  /** Console path to open after impersonation starts (sanitized; defaults to `/account`). */
  redirect?: string | null
}

/**
 * Apply impersonation headers, persist the session, and hard-navigate to `/account`
 * or a shared console path. Shared by the impersonation dialog and `/impersonate` deep links.
 */
export function beginConsoleImpersonation(
  targetUserId: string,
  operator: ConsoleImpersonationOperatorSnapshot,
  options?: BeginConsoleImpersonationOptions,
): void {
  applyConsoleImpersonateUserId(targetUserId)
  persistConsoleImpersonationSession(targetUserId, operator, {
    skipNotify: true,
  })
  hardNavigateAfterConsoleImpersonation(options?.redirect)
}
