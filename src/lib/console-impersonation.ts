import { clearConsoleAccountCache } from '@/lib/console-account-cache'

/**
 * Console operator impersonation (Console Auth users via console SDK - not project Auth users).
 * Persists target user id in sessionStorage so impersonation headers can be restored after refresh.
 */

export const CONSOLE_IMPERSONATION_TARGET_KEY =
  'console.impersonation.targetUserId'
export const CONSOLE_IMPERSONATION_OPERATOR_KEY =
  'console.impersonation.operator'

export const CONSOLE_IMPERSONATION_CHANGED_EVENT =
  'console-impersonation-changed'

/**
 * Bumped on every impersonation session change so React Query keys for
 * `account.get` stay aligned between route loaders and `RequireAuth` / `useAuth`.
 */
let consoleAccountQueryRevision = 0

export function getConsoleAccountQueryRevision(): number {
  return consoleAccountQueryRevision
}

/** Safe landing after impersonation starts or ends (avoids staying on org/project routes the new session may not access). */
export const ACCOUNT_PATH_AFTER_IMPERSONATION = '/account'

export function hardNavigateToAccountAfterImpersonation() {
  if (typeof window === 'undefined') return
  window.location.replace(ACCOUNT_PATH_AFTER_IMPERSONATION)
}

export type ConsoleImpersonationOperatorSnapshot = {
  $id: string
  name: string
  email: string
}

export function readConsoleImpersonationTargetUserId(): string | undefined {
  if (typeof window === 'undefined') return undefined
  try {
    const id = sessionStorage.getItem(CONSOLE_IMPERSONATION_TARGET_KEY)
    return id?.trim() || undefined
  } catch {
    return undefined
  }
}

export function readConsoleImpersonationOperatorSnapshot():
  | ConsoleImpersonationOperatorSnapshot
  | undefined {
  if (typeof window === 'undefined') return undefined
  try {
    const raw = sessionStorage.getItem(CONSOLE_IMPERSONATION_OPERATOR_KEY)
    if (!raw) return undefined
    const parsed = JSON.parse(raw) as ConsoleImpersonationOperatorSnapshot
    if (parsed && typeof parsed.$id === 'string') return parsed
    return undefined
  } catch {
    return undefined
  }
}

export type ConsoleImpersonationSessionOptions = {
  /** Use before `location.replace` to `/account` so listeners don't refetch while still on another route. */
  skipNotify?: boolean
}

export function persistConsoleImpersonationSession(
  targetUserId: string,
  operator: ConsoleImpersonationOperatorSnapshot,
  options?: ConsoleImpersonationSessionOptions,
) {
  if (typeof window === 'undefined') return
  try {
    sessionStorage.setItem(CONSOLE_IMPERSONATION_TARGET_KEY, targetUserId)
    sessionStorage.setItem(
      CONSOLE_IMPERSONATION_OPERATOR_KEY,
      JSON.stringify(operator),
    )
  } catch {
    /* private mode */
  }
  if (!options?.skipNotify) {
    notifyConsoleImpersonationChanged()
  }
}

export function clearConsoleImpersonationSession(
  options?: ConsoleImpersonationSessionOptions,
) {
  if (typeof window === 'undefined') return
  try {
    sessionStorage.removeItem(CONSOLE_IMPERSONATION_TARGET_KEY)
    sessionStorage.removeItem(CONSOLE_IMPERSONATION_OPERATOR_KEY)
  } catch {
    /* ignore */
  }
  if (!options?.skipNotify) {
    notifyConsoleImpersonationChanged()
  }
}

export function notifyConsoleImpersonationChanged() {
  if (typeof window === 'undefined') return
  consoleAccountQueryRevision += 1
  clearConsoleAccountCache()
  window.dispatchEvent(new Event(CONSOLE_IMPERSONATION_CHANGED_EVENT))
}

/** True when the account payload indicates impersonation or a session target is stored (e.g. before first account fetch). */
export function isConsoleImpersonationActive(
  account?: { impersonatorUserId?: string } | null,
): boolean {
  return (
    !!account?.impersonatorUserId || !!readConsoleImpersonationTargetUserId()
  )
}

/** Session-only check - no `account.get` required (e.g. blocked-account UI, exit FAB). */
export function hasConsoleImpersonationSessionTarget(): boolean {
  return !!readConsoleImpersonationTargetUserId()
}
