/**
 * Account prefs writes from this tab, one at a time.
 *
 * `account.updatePrefs` replaces every key at once, so a write that read the
 * prefs first must land before another write from this tab starts, or one of
 * them loses the other's change. A failed write does not stop the next.
 */
let writes: Promise<unknown> = Promise.resolve()

export function queueAccountPrefsWrite<T>(write: () => Promise<T>): Promise<T> {
  const run = writes.then(write, write)
  writes = run.catch(() => undefined)
  return run
}
