import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { isZeroAccount } from '@/lib/zero/session'

/** True while the console is signed in as the anonymous owner of an unclaimed Zero project. */
export function useIsZeroAccount(): boolean {
  const { account } = useAuth()
  return isZeroAccount(account as Models.User | undefined)
}
