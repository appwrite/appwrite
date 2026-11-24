import { getConsoleAccountSync } from '@/lib/console-account-cache'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'

/**
 * Account prefs writes from this tab, one at a time.
 *
 * `account.updatePrefs` replaces every key at once, so a write that read the
 * prefs first must land before another write from this tab starts, or one of
 * them loses the other's change. A failed write does not stop the next.
 */
let writes: Promise<unknown> = Promise.resolve()

/** The signed-in console account, as the Console has it cached. */
function signedInAccountId(): string | undefined {
  return getConsoleAccountSync(getConsoleAccountQueryRevision())?.$id
}

/**
 * Queue a write for the account signed in now. A write still waiting when
 * that account signs out, or another account or an impersonated user takes
 * its place, is dropped and resolves to `undefined`, like a skipped write,
 * so it never lands on the wrong account.
 */
export function queueAccountPrefsWrite<T>(
  write: () => Promise<T>,
): Promise<T | undefined> {
  const accountId = signedInAccountId()
  const forAccount = () =>
    accountId !== undefined && signedInAccountId() !== accountId
      ? Promise.resolve(undefined)
      : write()
  const run = writes.then(forAccount, forAccount)
  writes = run.catch(() => undefined)
  return run
}
