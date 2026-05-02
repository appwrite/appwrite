import { clearConsoleImpersonateUser } from '@/lib/appwrite/sdk'
import {
  clearConsoleImpersonationSession,
  hardNavigateToAccountAfterImpersonation,
  readConsoleImpersonationOperatorSnapshot,
} from '@/lib/console-impersonation'
import { flushRecentImpersonationUsersToAccountPrefs } from '@/lib/react-query/hooks/auth'

export type ExitConsoleImpersonationOptions = {
  /**
   * Skip merging recent impersonation targets into operator prefs (calls `account.get`).
   * Use when console account APIs are blocked or to avoid duplicate account traffic on exit.
   */
  skipRecentImpersonationFlush?: boolean
}

/** Clears impersonation headers and session, optionally syncs recents, then hard-navigates to `/account`. */
export async function performExitConsoleImpersonation(
  options?: ExitConsoleImpersonationOptions,
): Promise<void> {
  const opId = readConsoleImpersonationOperatorSnapshot()?.$id
  clearConsoleImpersonateUser()
  clearConsoleImpersonationSession({ skipNotify: true })
  if (opId && !options?.skipRecentImpersonationFlush) {
    void flushRecentImpersonationUsersToAccountPrefs(opId).catch((e) => {
      console.error(e)
    })
  }
  hardNavigateToAccountAfterImpersonation()
}
