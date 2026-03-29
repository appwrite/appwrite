import { UserRound } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { clearConsoleImpersonateUser } from '@/lib/appwrite/sdk'
import {
  clearConsoleImpersonationSession,
  hardNavigateToAccountAfterImpersonation,
  readConsoleImpersonationOperatorSnapshot,
  readConsoleImpersonationTargetUserId,
} from '@/lib/console-impersonation'
import { flushRecentImpersonationUsersToAccountPrefs } from '@/lib/react-query/hooks/auth'

export function ConsoleImpersonationBanner({ className }: { className?: string }) {
  const { account: accountRaw } = useAuth()
  const account = accountRaw as Models.User | undefined
  const operatorSnapshot = readConsoleImpersonationOperatorSnapshot()
  const sessionTarget = readConsoleImpersonationTargetUserId()
  const impersonatorUserId = (
    account as Models.User & { impersonatorUserId?: string }
  )?.impersonatorUserId

  const active = !!impersonatorUserId || !!sessionTarget

  if (!active) return null

  const operatorLabel =
    operatorSnapshot?.name?.trim() ||
    operatorSnapshot?.email?.trim() ||
    (impersonatorUserId ? `User ${impersonatorUserId}` : 'Operator')

  const targetLabel =
    account?.name?.trim() ||
    account?.email?.trim() ||
    (account?.$id ? `User ${account.$id}` : 'Console user')

  const summary = `Impersonation active. Operating as ${targetLabel}. Operator ${operatorLabel}.`

  const handleExit = async () => {
    const opId = readConsoleImpersonationOperatorSnapshot()?.$id
    clearConsoleImpersonateUser()
    clearConsoleImpersonationSession()
    if (opId) {
      void flushRecentImpersonationUsersToAccountPrefs(opId).catch((e) => {
        console.error(e)
      })
    }
    hardNavigateToAccountAfterImpersonation()
  }

  return (
    <HeaderAlertBar
      variant="warning"
      icon={UserRound}
      role="status"
      aria-label={summary}
      className={className}
      action={
        <button
          type="button"
          className={headerAlertOutlineButtonClass('warning')}
          aria-label="Exit impersonation"
          onClick={() => void handleExit()}
        >
          Exit
        </button>
      }
    >
      <>
        Impersonation active. Operating as{' '}
        <span className="text-foreground">{targetLabel}</span>. Operator{' '}
        <span className="text-foreground">{operatorLabel}</span>.
      </>
    </HeaderAlertBar>
  )
}
