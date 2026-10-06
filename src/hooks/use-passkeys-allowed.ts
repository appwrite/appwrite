import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canUseAccountPasskeys, canUseProjectPasskeys } from '@/lib/passkeys'

function useAccountPrefs(): Models.Preferences | undefined {
  const { account } = useAuth()
  return (account as Models.User | undefined)?.prefs
}

/** Whether the signed-in console user sees project passkey settings (see `canUseProjectPasskeys`). */
export function useProjectPasskeysAllowed(): boolean {
  const { features } = useConsoleProfile()
  return canUseProjectPasskeys(features, useAccountPrefs())
}

/** Whether the signed-in console user may manage their own passkeys (see `canUseAccountPasskeys`). */
export function useAccountPasskeysAllowed(): boolean {
  const { features } = useConsoleProfile()
  return canUseAccountPasskeys(features, useAccountPrefs())
}
