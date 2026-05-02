import { UserRound } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { useEffect, useState } from 'react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import {
  CONSOLE_IMPERSONATION_CHANGED_EVENT,
  hasConsoleImpersonationSessionTarget,
  isConsoleImpersonationActive,
  readConsoleImpersonationOperatorSnapshot,
} from '@/lib/console-impersonation'
import { performExitConsoleImpersonation } from '@/lib/console-impersonation-exit'

/** No `account.get` — session target + operator snapshot only (e.g. account-access-blocked). */
function ConsoleImpersonationBannerSession({ className }: { className?: string }) {
  const [, bump] = useState(0)
  useEffect(() => {
    const onChange = () => bump((n) => n + 1)
    window.addEventListener(CONSOLE_IMPERSONATION_CHANGED_EVENT, onChange)
    return () =>
      window.removeEventListener(CONSOLE_IMPERSONATION_CHANGED_EVENT, onChange)
  }, [])

  if (!hasConsoleImpersonationSessionTarget()) return null

  const operatorSnapshot = readConsoleImpersonationOperatorSnapshot()
  const operatorLabel =
    operatorSnapshot?.name?.trim() ||
    operatorSnapshot?.email?.trim() ||
    'Operator'
  const summary =
    'Impersonation active. Operating as another console user. Exit to return to your operator session.'

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
          onClick={() =>
            void performExitConsoleImpersonation({
              skipRecentImpersonationFlush: true,
            })
          }
        >
          Exit
        </button>
      }
    >
      <>
        Impersonation active. Operating as{' '}
        <span className="text-foreground">another console user</span>. Operator{' '}
        <span className="text-foreground">{operatorLabel}</span>.
      </>
    </HeaderAlertBar>
  )
}

function ConsoleImpersonationBannerFull({ className }: { className?: string }) {
  const { account: accountRaw } = useAuth()
  const account = accountRaw as Models.User | undefined
  const operatorSnapshot = readConsoleImpersonationOperatorSnapshot()
  const active = isConsoleImpersonationActive(
    account as Models.User & { impersonatorUserId?: string },
  )

  if (!active) return null

  const impersonatorUserId = (
    account as Models.User & { impersonatorUserId?: string }
  )?.impersonatorUserId

  const operatorLabel =
    operatorSnapshot?.name?.trim() ||
    operatorSnapshot?.email?.trim() ||
    (impersonatorUserId ? `User ${impersonatorUserId}` : 'Operator')

  const targetLabel =
    account?.name?.trim() ||
    account?.email?.trim() ||
    (account?.$id ? `User ${account.$id}` : 'Console user')

  const summary = `Impersonation active. Operating as ${targetLabel}. Operator ${operatorLabel}.`

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
          onClick={() => void performExitConsoleImpersonation()}
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

export function ConsoleImpersonationBanner({
  className,
  sessionOnly = false,
}: {
  className?: string
  /**
   * No `useAuth` / `account.get` — uses session target + operator snapshot only.
   * Use on account-access-blocked and similar screens where console account APIs fail.
   */
  sessionOnly?: boolean
}) {
  if (sessionOnly) {
    return <ConsoleImpersonationBannerSession className={className} />
  }
  return <ConsoleImpersonationBannerFull className={className} />
}
