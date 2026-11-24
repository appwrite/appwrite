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
 * Queue a write for the account signed in now. A write runs only if the same
 * account is still signed in when its turn comes: one that account signed out
 * of, or that another account or an impersonated user took over, is dropped
 * and resolves to `undefined`, like a skipped write. So is a write queued with
 * no account known once one signs in, as it was built from no account's prefs.
 */
export function queueAccountPrefsWrite<T>(
  write: () => Promise<T>,
): Promise<T | undefined> {
  const accountId = signedInAccountId()
  const forAccount = () =>
    signedInAccountId() === accountId ? write() : Promise.resolve(undefined)
  const run = writes.then(forAccount, forAccount)
  writes = run.catch(() => undefined)
  return run
}
