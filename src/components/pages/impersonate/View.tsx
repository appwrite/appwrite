import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Loader2, UserRound } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ACCOUNT_PATH_AFTER_IMPERSONATION,
  resolveConsoleImpersonationOperator,
} from '@/lib/console-impersonation'
import { beginConsoleImpersonation } from '@/lib/console-impersonation-start'
import { useT } from '@/lib/i18n/translate'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type ViewProps = {
  userId: string
}

/**
 * Confirmation screen for the `/impersonate/$userId` deep link (e.g. from HelpScout
 * triage notes). Operators see who they are about to impersonate and confirm before
 * the Console switches identity; everyone else is sent back to `/account`.
 */
export function View({ userId }: ViewProps) {
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

  const {
    data: target,
    error: targetError,
    isPending: isTargetPending,
  } = useQuery({
    queryKey: ['console', 'users', 'impersonation-target', userId],
    queryFn: () => sdk.forConsole.users.get(userId),
    enabled: isOperator,
    retry: false,
    staleTime: 30 * 1000,
  })

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
        : targetError
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
    return (
      <div className="flex h-[100dvh] items-center justify-center bg-background">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const targetLabel = target?.name || target?.email || target?.$id || userId

  return (
    <div className="relative flex h-[100dvh] max-h-[100dvh] flex-col items-center justify-center overflow-hidden bg-background p-6 md:p-10">
      <div className="w-full max-w-md">
        <Card className="overflow-hidden p-6 md:p-8">
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              <Badge variant="pending" className="shrink-0 text-[10px]">
                <UserRound className="size-3" aria-hidden />
                {t('Operator')}
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
              {isTargetPending && !targetError ? (
                <div className="flex h-10 w-full items-center justify-center">
                  <Loader2
                    className="h-5 w-5 animate-spin text-muted-foreground"
                    aria-hidden
                  />
                  <span className="sr-only">{t('Loading user')}</span>
                </div>
              ) : (
                <>
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
                </>
              )}
            </div>

            {operatorLabel ? (
              <p className="text-center text-[12px] text-muted-foreground">
                {t('Operator')}{' '}
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
                className="w-full"
                onClick={handleConfirm}
                disabled={
                  !target || !!blockingMessage || isStarting || isTargetPending
                }
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
