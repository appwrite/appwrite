import { AppwriteException, type Models } from '@appwrite.io/console'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import {
  clearConsoleAccountCache,
  clearConsoleAccountInflight,
  clearConsoleAccountUnauthenticatedError,
  getConsoleAccountInflight,
  getConsoleAccountSync,
  getConsoleAccountUnauthenticatedError,
  setConsoleAccountCache,
  setConsoleAccountInflight,
  setConsoleAccountUnauthenticatedError,
} from '@/lib/console-account-cache'
import { CONSOLE_SESSION_COOKIE_NAME } from '@/lib/console-session-cookie'
import { isHttpUnauthorizedError } from '@/lib/utils/error-formatting'

type RawConsoleAccountGet = () => Promise<Models.User>

let rawConsoleAccountGet: RawConsoleAccountGet | null = null

/**
 * Lightweight session probe (no SDK import) so we can bypass cached guest 401s
 * after `createEmailPasswordSession` / OAuth when a cookie already exists.
 */
export function hasLikelyConsoleSession(): boolean {
  if (typeof window === 'undefined') return false

  try {
    const cookieFallback = window.localStorage.getItem('cookieFallback')
    if (cookieFallback) {
      const parsed = JSON.parse(cookieFallback) as Record<string, string>
      const session = parsed[CONSOLE_SESSION_COOKIE_NAME]
      if (typeof session === 'string' && session.trim()) return true
    }
  } catch {
    /* private mode / invalid JSON */
  }

  return document.cookie.includes(`${CONSOLE_SESSION_COOKIE_NAME}=`)
}

/** Called once from `sdk.ts` so every `account.get` shares the same singleton. */
export function registerConsoleAccountGet(fn: RawConsoleAccountGet): void {
  rawConsoleAccountGet = fn
}

/** Partial auth (MFA pending) is not a guest session - do not cache or replay as 401. */
function isConsoleMfaRequiredError(error: unknown): boolean {
  return (
    error instanceof AppwriteException &&
    error.type === 'user_more_factors_required'
  )
}

function shouldCacheConsoleAccountUnauthenticatedError(error: unknown): boolean {
  if (!isHttpUnauthorizedError(error)) return false
  return !isConsoleMfaRequiredError(error)
}

export type FetchConsoleAccountOptions = {
  revision?: number
  /** Bypass cache (sign-in, impersonation exit, explicit debug refresh). */
  force?: boolean
}

/**
 * App-wide singleton for console `account.get`.
 * One network request per revision until `force` or session reset.
 */
export async function fetchConsoleAccount(
  revisionOrOptions?: number | FetchConsoleAccountOptions,
): Promise<Models.User> {
  if (!rawConsoleAccountGet) {
    throw new Error('Console account getter is not registered')
  }

  let revision = getConsoleAccountQueryRevision()
  let force = false
  if (typeof revisionOrOptions === 'number') {
    revision = revisionOrOptions
  } else if (revisionOrOptions) {
    revision = revisionOrOptions.revision ?? revision
    force = revisionOrOptions.force ?? false
  }

  if (!force) {
    const cached = getConsoleAccountSync(revision)
    if (cached) return cached

    const cachedUnauthenticated = getConsoleAccountUnauthenticatedError(revision)
    if (cachedUnauthenticated && !hasLikelyConsoleSession()) {
      throw cachedUnauthenticated
    }
  } else {
    clearConsoleAccountCache(revision)
  }

  const existing = getConsoleAccountInflight(revision)
  if (existing) return existing

  const promise = rawConsoleAccountGet()
    .then((account) => {
      setConsoleAccountCache(account, revision)
      clearConsoleAccountUnauthenticatedError(revision)
      return account
    })
    .catch((error) => {
      if (shouldCacheConsoleAccountUnauthenticatedError(error)) {
        setConsoleAccountUnauthenticatedError(revision, error)
      }
      throw error
    })
    .finally(() => {
      clearConsoleAccountInflight(revision, promise)
    })
  setConsoleAccountInflight(revision, promise)
  return promise
}

export function getConsoleAccountFromSingleton(
  revision: number = getConsoleAccountQueryRevision(),
): Models.User | undefined {
  return getConsoleAccountSync(revision)
}
