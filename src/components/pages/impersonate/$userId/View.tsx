import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Eye, Loader2, UserRound } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { AuthFlowAccountSwitcher } from '@/components/global/auth/AuthFlowAccountSwitcher'
import { AuthFlowAccountSwitcherStatic } from '@/components/global/auth/AuthFlowAccountSwitcherStatic'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import {
  ACCOUNT_PATH_AFTER_IMPERSONATION,
  resolveConsoleImpersonationOperator,
  resolveConsoleImpersonationRedirect,
} from '@/lib/console-impersonation'
import { beginConsoleImpersonation } from '@/lib/console-impersonation-start'
import { useT } from '@/lib/i18n/translate'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import { consoleImpersonationTargetQueryOptions } from '@/lib/react-query/hooks/console-user-search'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  DEBUG_DEMO_MOCK_EMAIL,
  DEBUG_DEMO_MOCK_OPERATOR_EMAIL,
} from '@/lib/debug-demos/constants'

export type ImpersonateInitialData = {
  /** `null` when the loader could not resolve the user (unknown id, not an operator, signed out). */
  target: Models.User | null
}

type ViewProps = {
  /** Console user id (`/impersonate/$userId`). */
  userId?: string
  /** Requester email (`/impersonate?email=`), resolved to a console user. */
  email?: string
  /** Console path to open after confirm (`/impersonate?redirect=`). */
  redirect?: string
  initialData?: ImpersonateInitialData
  preview?: boolean
  previewOperatorLabel?: string
}

/**
 * Confirmation screen for `/impersonate` deep links (header Share, HelpScout
 * notes). Operators see who they are about to impersonate and confirm before
 * the Console switches identity, then open the shared page when `redirect` is set.
 */
const PREVIEW_IMPERSONATION_TARGET = {
  $id: 'preview-user-id',
  name: 'Demo User',
  email: DEBUG_DEMO_MOCK_EMAIL,
} as Models.User

export function View({
  userId,
  email,
  redirect,
  initialData,
  preview = false,
  previewOperatorLabel = DEBUG_DEMO_MOCK_OPERATOR_EMAIL,
}: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const { account: rawAccount } = useAuth()
  const account = rawAccount as OperatorAccount | undefined
  const [isStarting, setIsStarting] = useState(false)
  const [startError, setStartError] = useState<string | null>(null)

  const isOperator = preview || isOperatorAccount(account)

  useEffect(() => {
    if (preview) return
    if (!account) return
    if (!isOperator) {
      navigate({ to: ACCOUNT_PATH_AFTER_IMPERSONATION, replace: true })
    }
  }, [preview, account, isOperator, navigate])

  const hasLookup = !!userId || !!email
  const {
    data: targetFromHook,
    error: targetError,
    isFetched: targetFetched,
  } = useQuery({
    ...consoleImpersonationTargetQueryOptions(
      userId ? { userId } : { email: email ?? '' },
    ),
    enabled: !preview && isOperator && hasLookup,
  })
  const target = preview
    ? PREVIEW_IMPERSONATION_TARGET
    : (targetFromHook ?? initialData?.target ?? undefined)
  const targetId = target?.$id ?? userId
  const targetMissing =
    !target &&
    (!hasLookup || !!targetError || (targetFetched && !targetFromHook))

  const operator = preview
    ? undefined
    : resolveConsoleImpersonationOperator(account)
  const operatorLabel = preview
    ? previewOperatorLabel
    : operator?.name?.trim() || operator?.email?.trim() || operator?.$id || ''

  const alreadyActive = !!account && !!targetId && account.$id === targetId
  const isOwnOperatorAccount =
    !!operator && !!targetId && operator.$id === targetId
  const afterPath = resolveConsoleImpersonationRedirect(redirect)
  const continueExistingSession = alreadyActive && !!redirect?.trim()
  const showRedirectHint = !!redirect?.trim()

  const blockingMessage = preview
    ? null
    : !operator
      ? t('Operator context was lost. Stop impersonating, then start again.')
      : alreadyActive && !continueExistingSession
        ? t('That user is already the active Console session.')
        : isOwnOperatorAccount
          ? t('You cannot impersonate your own operator account.')
          : targetMissing
            ? targetError
              ? getErrorMessage(targetError, t('Could not load this user.'))
              : t('Could not load this user.')
            : null

  const handleConfirm = async () => {
    if (preview) return
    if (!operator || !target || blockingMessage || isStarting) return
    setStartError(null)
    setIsStarting(true)
    try {
      if (continueExistingSession) {
        window.location.replace(afterPath)
        return
      }
      await beginConsoleImpersonation(target, operator, { redirect: afterPath })
    } catch (error) {
      console.error(error)
      setIsStarting(false)
      setStartError(getErrorMessage(error, t('Could not start impersonation.')))
    }
  }

  const handleCancel = () => {
    if (preview) return
    navigate({ to: ACCOUNT_PATH_AFTER_IMPERSONATION, replace: true })
  }

  if (!preview && (!account || !isOperator)) {
    return null
  }

  const targetLabel = target?.name || target?.email || email || userId || ''

  const accountSwitcher = operatorLabel ? (
    preview ? (
      <AuthFlowAccountSwitcherStatic
        accountLabel={operatorLabel}
        preview
        disabled={isStarting}
      />
    ) : (
      <AuthFlowAccountSwitcher
        accountLabel={operatorLabel}
        disabled={isStarting}
      />
    )
  ) : null

  return (
    <main id="main-content" className="h-[100dvh] max-h-[100dvh]">
      <AuthFlowShell
        width="narrow"
        showLegal={false}
        accountSwitcher={accountSwitcher}
      >
        <AuthFlowNarrowCard>
          <div className="space-y-6">
            <div className="flex flex-col items-center gap-4 text-center">
              <AuthFlowHeaderIcon icon={Eye} />
              <Badge variant="info" className="shrink-0 text-[10px]">
                <UserRound className="size-3" aria-hidden />
                {t('Operator access')}
              </Badge>
              <div className="space-y-1">
                <AuthFlowTitle>{t('Impersonate user')}</AuthFlowTitle>
                <AuthFlowDescription>
                  {t(
                    "The Console will run with this user's access until you exit impersonation. Actions stay attributed to your operator account.",
                  )}
                </AuthFlowDescription>
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
                  {target?.email || email || '-'}
                </p>
                {targetId ? (
                  <div className="mt-1">
                    <CopyableId
                      id={targetId}
                      size="xs"
                      maxWidth={260}
                      className="max-w-full"
                    />
                  </div>
                ) : null}
              </div>
            </div>

            {showRedirectHint ? (
              <div className="rounded-lg border border-border bg-muted/30 p-3 text-start">
                <p className="text-[12px] text-muted-foreground">
                  {t('After you confirm, the Console will open this page.')}
                </p>
                <p
                  className="mt-1 break-all font-mono text-[12px] font-medium text-foreground"
                  dir="ltr"
                >
                  {afterPath}
                </p>
              </div>
            ) : null}

            {blockingMessage || startError ? (
              <p className="text-center text-[13px] leading-relaxed text-destructive">
                {blockingMessage ?? startError}
              </p>
            ) : null}

            <div className="flex flex-col gap-2">
              <Button
                type="button"
                variant="brandCta"
                className="w-full gap-1.5"
                onClick={handleConfirm}
                disabled={preview || !target || !!blockingMessage || isStarting}
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
        </AuthFlowNarrowCard>
      </AuthFlowShell>
    </main>
  )
}
