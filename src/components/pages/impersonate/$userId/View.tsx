import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Loader2, UserRound } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  ACCOUNT_PATH_AFTER_IMPERSONATION,
  resolveConsoleImpersonationOperator,
} from '@/lib/console-impersonation'
import { beginConsoleImpersonation } from '@/lib/console-impersonation-start'
import { useT } from '@/lib/i18n/translate'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import { consoleUserQueryOptions } from '@/lib/react-query/hooks/console-user-search'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export type ImpersonateInitialData = {
  /** `null` when the loader could not resolve the user (unknown id, not an operator, signed out). */
  target: Models.User | null
}

type ViewProps = {
  userId: string
  initialData?: ImpersonateInitialData
}

/**
 * Confirmation screen for the `/impersonate/$userId` deep link (e.g. from HelpScout
 * triage notes). Operators see who they are about to impersonate and confirm before
 * the Console switches identity; everyone else is sent back to `/account`.
 */
export function View({ userId, initialData }: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const { account: rawAccount } = useAuth()
  const account = rawAccount as OperatorAccount | undefined
  const [isStarting, setIsStarting] = useState(false)
  const [startError, setStartError] = useState<string | null>(null)

  const isOperator = isOperatorAccount(account)

  useEffect(() => {
    if (!account) return
    if (!isOperator) {
      navigate({ to: ACCOUNT_PATH_AFTER_IMPERSONATION, replace: true })
    }
  }, [account, isOperator, navigate])

  const { data: targetFromHook, error: targetError } = useQuery({
    ...consoleUserQueryOptions(userId),
    enabled: isOperator,
  })
  const target = targetFromHook ?? initialData?.target ?? undefined

  const operator = resolveConsoleImpersonationOperator(account)
  const operatorLabel =
    operator?.name?.trim() || operator?.email?.trim() || operator?.$id || ''

  const alreadyActive = !!account && account.$id === userId
  const isOwnOperatorAccount = !!operator && operator.$id === userId

  const blockingMessage = !operator
    ? t('Operator context was lost. Stop impersonating, then start again.')
    : alreadyActive
      ? t('That user is already the active Console session.')
      : isOwnOperatorAccount
        ? t('You cannot impersonate your own operator account.')
        : targetError && !target
          ? getErrorMessage(targetError, t('Could not load this user.'))
          : null

  const handleConfirm = () => {
    if (!operator || !target || blockingMessage || isStarting) return
    setStartError(null)
    setIsStarting(true)
    try {
      beginConsoleImpersonation(target.$id, operator)
    } catch (error) {
      console.error(error)
      setIsStarting(false)
      setStartError(getErrorMessage(error, t('Could not start impersonation.')))
    }
  }

  const handleCancel = () => {
    navigate({ to: ACCOUNT_PATH_AFTER_IMPERSONATION, replace: true })
  }

  if (!account || !isOperator) {
    return null
  }

  const targetLabel = target?.name || target?.email || userId

  return (
    <div className="relative flex h-[100dvh] max-h-[100dvh] flex-col items-center justify-center overflow-hidden bg-background p-6 md:p-10">
      <div className="w-full max-w-md">
        <Card className="overflow-hidden p-6 md:p-8">
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              <Badge variant="info" className="shrink-0 text-[10px]">
                <UserRound className="size-3" aria-hidden />
                {t('Operator access')}
              </Badge>
              <div className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {t('Impersonate user')}
                </h1>
                <p className="text-[13px] leading-relaxed text-muted-foreground">
                  {t(
                    "The Console will run with this user's access until you exit impersonation. Actions stay attributed to your operator account.",
                  )}
                </p>
              </div>
            </div>

            <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 p-3">
              <InitialsAvatar
                name={targetLabel}
                size="sm"
                className="shrink-0"
              />
              <div className="min-w-0 flex-1 text-start">
                <p className="truncate text-[13px] font-medium text-foreground">
                  {target?.name || '-'}
                </p>
                <p className="truncate text-[12px] text-muted-foreground">
                  {target?.email || '-'}
                </p>
                <div className="mt-1">
                  <CopyableId
                    id={userId}
                    size="xs"
                    maxWidth={260}
                    className="max-w-full"
                  />
                </div>
              </div>
            </div>

            {operatorLabel ? (
              <p className="text-center text-[12px] text-muted-foreground">
                {t('Operator account')}{' '}
                <span className="text-foreground">{operatorLabel}</span>
              </p>
            ) : null}

            {blockingMessage || startError ? (
              <p className="text-center text-[13px] leading-relaxed text-destructive">
                {blockingMessage ?? startError}
              </p>
            ) : null}

            <div className="flex flex-col gap-2">
              <Button
                type="button"
                className="w-full gap-1.5"
                onClick={handleConfirm}
                disabled={!target || !!blockingMessage || isStarting}
              >
                {isStarting ? (
                  <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                ) : null}
                {t('Start impersonation')}
              </Button>
              <Button
                type="button"
                variant="outline"
                className="w-full"
                onClick={handleCancel}
                disabled={isStarting}
              >
                {t('Cancel')}
              </Button>
            </div>
          </div>
        </Card>
      </div>
    </div>
  )
}
