import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canUsePasskeys } from '@/lib/passkeys'

/** Whether the signed-in console user sees project passkey settings (see `canUsePasskeys`). */
export function usePasskeysAllowed(): boolean {
  const { features } = useConsoleProfile()
  const { account } = useAuth()
  return canUsePasskeys(features, (account as Models.User | undefined)?.prefs)
}
