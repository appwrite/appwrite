import type { Models } from '@appwrite.io/console'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import {
  clearConsoleAccountCache,
  clearConsoleAccountInflight,
  getConsoleAccountInflight,
  getConsoleAccountSync,
  setConsoleAccountCache,
  setConsoleAccountInflight,
} from '@/lib/console-account-cache'

type RawConsoleAccountGet = () => Promise<Models.User>

let rawConsoleAccountGet: RawConsoleAccountGet | null = null

/** Called once from `sdk.ts` so every `account.get` shares the same singleton. */
export function registerConsoleAccountGet(fn: RawConsoleAccountGet): void {
  rawConsoleAccountGet = fn
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
  } else {
    clearConsoleAccountCache(revision)
  }

  const existing = getConsoleAccountInflight(revision)
  if (existing) return existing

  const promise = rawConsoleAccountGet()
    .then((account) => {
      setConsoleAccountCache(account, revision)
      return account
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
