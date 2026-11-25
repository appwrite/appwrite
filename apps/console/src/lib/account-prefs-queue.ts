import {
  getConsoleAccountSession,
  getConsoleAccountSync,
} from '@/lib/console-account-cache'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'

/**
 * Account prefs writes from this tab, one at a time.
 *
 * `account.updatePrefs` replaces every key at once, so a write that read the
 * prefs first must land before another write from this tab starts, or one of
 * them loses the other's change. A failed write does not stop the next.
 */
let writes: Promise<unknown> = Promise.resolve()

/** The signed-in console account and its sign-in, as the Console has them cached. */
function signedIn(): string {
  const account = getConsoleAccountSync(getConsoleAccountQueryRevision())
  return `${getConsoleAccountSession()}:${account?.$id ?? ''}`
}

/**
 * Queue a write for the sign-in active now. A write runs only if that sign-in
 * is still active when its turn comes: one its account signed out of, even to
 * sign back in, or that another account or an impersonated user took over, is
 * dropped and resolves to `undefined`, like a skipped write. So is a write
 * queued with no account known once one signs in, as it was built from no
 * account's prefs. `write` gets `isCurrent` to check again after it awaits.
 */
export function queueAccountPrefsWrite<T>(
  write: (isCurrent: () => boolean) => Promise<T | undefined>,
): Promise<T | undefined> {
  const session = signedIn()
  const isCurrent = () => signedIn() === session
  const forAccount = () =>
    isCurrent() ? write(isCurrent) : Promise.resolve(undefined)
  const run = writes.then(forAccount, forAccount)
  writes = run.catch(() => undefined)
  return run
}
