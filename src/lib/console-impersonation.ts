/**
 * Console operator impersonation (Console Auth users via console SDK — not project Auth users).
 * Persists target user id in sessionStorage so impersonation headers can be restored after refresh.
 */

export const CONSOLE_IMPERSONATION_TARGET_KEY =
  'console.impersonation.targetUserId'
export const CONSOLE_IMPERSONATION_OPERATOR_KEY =
  'console.impersonation.operator'

export const CONSOLE_IMPERSONATION_CHANGED_EVENT =
  'console-impersonation-changed'

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
  window.dispatchEvent(new Event(CONSOLE_IMPERSONATION_CHANGED_EVENT))
}
