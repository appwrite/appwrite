import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  isProviderTokenExpired,
  parseResendReturnSearch,
  stripResendReturnSearch,
} from '@/lib/smtp/resend'
import { isResendUnauthorizedError } from '@/lib/smtp/resend-api'
import {
  ResendAccountMismatchError,
  ResendReauthorizeRequiredError,
  claimResendIdentity,
  refreshResendAccessToken,
  resolveResendAccessToken,
  startResendAuthorization,
  tokenFromSession,
  type ResendAccessToken,
} from '@/lib/smtp/resend-oauth'
import { SetupResendSMTP, type ResendApiCall } from './SetupResendSMTP'

interface ResendQuickSetupCardProps {
  projectId: string
  project: Models.Project | undefined
  /** Custom SMTP is a paid feature; the CTA is disabled (with a tooltip) below that plan. */
  supportsCustomSmtp: boolean
  /** Open the "Send test email" dialog after a successful setup. */
  onSendTestEmail?: () => void
}

/**
 * One-click SMTP setup through the console's Resend OAuth2 provider.
 *
 * Owns the whole round trip: starts `createOAuth2Token`, claims the identity
 * with `createSession` when the tab is reopened with `userId` + `secret`,
 * keeps the provider access token in memory (never persisted), refreshes it
 * through `updateSession`, and falls back to a fresh authorization when the
 * refresh is impossible.
 */
export function ResendQuickSetupCard({
  projectId,
  project,
  supportsCustomSmtp,
  onSendTestEmail,
}: ResendQuickSetupCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const search = useSearch({ strict: false })
  const { account } = useAuth()
  const accountId =
    (account as Models.User<Models.Preferences> | null | undefined)?.$id ?? null

  const tokenRef = useRef<ResendAccessToken | null>(null)
  const handledReturnRef = useRef(false)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [isConnecting, setIsConnecting] = useState(false)

  const returnState = useMemo(() => parseResendReturnSearch(search), [search])

  const stripReturnParams = useCallback(() => {
    navigate({
      search: ((prev: unknown) => stripResendReturnSearch(prev)) as never,
      replace: true,
    })
  }, [navigate])

  const reauthorize = useCallback(() => {
    setIsConnecting(true)
    try {
      startResendAuthorization(projectId)
    } catch (error) {
      setIsConnecting(false)
      toast.error(getErrorMessage(error, t('Failed to connect Resend')))
    }
  }, [projectId, t])

  /**
   * Runs a Resend API call with the cached token. Expired tokens (15 minute
   * lifetime) and 401 responses trigger one `updateSession` refresh; when the
   * current session cannot refresh Resend tokens the dialog asks to reconnect.
   */
  const callResend = useCallback<ResendApiCall>(async (run) => {
    let current = tokenRef.current
    if (!current || isProviderTokenExpired(current.expiry)) {
      current = await refreshResendAccessToken()
      tokenRef.current = current
    }
    try {
      return await run(current.token)
    } catch (error) {
      if (!isResendUnauthorizedError(error)) throw error
      const refreshed = await refreshResendAccessToken()
      tokenRef.current = refreshed
      return await run(refreshed.token)
    }
  }, [])

  // Finish the OAuth2 round trip when Appwrite sends the user back here.
  useEffect(() => {
    if (!returnState) {
      handledReturnRef.current = false
      return
    }
    if (handledReturnRef.current) return
    // Wait for the signed-in account so the claim can be checked against it.
    if (returnState.status === 'connected' && !accountId) return
    handledReturnRef.current = true

    // The secret is single-use; drop it from the address bar right away.
    stripReturnParams()

    if (returnState.status === 'failed') {
      toast.error(returnState.message ?? t('Resend authorization failed'))
      return
    }

    const { userId, secret } = returnState
    setIsConnecting(true)
    void (async () => {
      try {
        const session = await claimResendIdentity({
          userId,
          secret,
          expectedUserId: accountId,
        })
        void queryClient.invalidateQueries({
          queryKey: ['sessions', 'account'],
        })
        void queryClient.invalidateQueries({
          queryKey: ['identities', 'account'],
        })

        const token =
          tokenFromSession(session) ?? (await resolveResendAccessToken())
        if (!token) throw new ResendReauthorizeRequiredError()
        tokenRef.current = token
        setDialogOpen(true)
      } catch (error) {
        if (error instanceof ResendAccountMismatchError) {
          toast.error(
            t(
              'Resend was authorized for a different Appwrite account. Try again while signed in to this account.',
            ),
          )
        } else if (error instanceof ResendReauthorizeRequiredError) {
          toast.error(t('Resend authorization failed'))
        } else {
          toast.error(getErrorMessage(error, t('Failed to connect Resend')))
        }
      } finally {
        setIsConnecting(false)
      }
    })()
  }, [returnState, accountId, queryClient, stripReturnParams, t])

  const handleSetup = async () => {
    setIsConnecting(true)
    try {
      const token = await resolveResendAccessToken()
      if (token) {
        tokenRef.current = token
        setDialogOpen(true)
        setIsConnecting(false)
        return
      }
      // No usable token: leave for Resend. The page unloads, so the button
      // intentionally stays disabled until then.
      startResendAuthorization(projectId)
    } catch (error) {
      setIsConnecting(false)
      toast.error(getErrorMessage(error, t('Failed to connect Resend')))
    }
  }

  const disabledReason = !supportsCustomSmtp
    ? t('Custom SMTP is available on Appwrite Cloud Pro and higher plans.') // pragma: allowlist secret
    : undefined

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex min-w-0 items-start gap-3">
            <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <img
                src="/icons/resend.svg"
                alt=""
                aria-hidden="true"
                className={`h-4 w-4 ${PUBLIC_ICON_MUTED_CLASSES}`}
              />
            </div>
            <div className="min-w-0">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Quick setup with Resend')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-1">
                {t(
                  'Connect your Resend account and Appwrite generates a sending-only API key, then fills in the SMTP settings for you. You need a verified domain in Resend.',
                )}
              </p>
            </div>
          </div>
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="inline-flex shrink-0">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleSetup}
                  disabled={!supportsCustomSmtp || isConnecting}
                  {...analyticsAttrs('smtp-resend-setup')}
                >
                  {t('Set up with Resend')}
                </Button>
              </span>
            </TooltipTrigger>
            {disabledReason ? (
              <TooltipContent className="max-w-xs text-[13px]">
                {disabledReason}
              </TooltipContent>
            ) : null}
          </Tooltip>
        </div>
      </div>

      <SetupResendSMTP
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        projectId={projectId}
        project={project}
        callResend={callResend}
        onReauthorize={reauthorize}
        onSendTestEmail={onSendTestEmail}
      />
    </>
  )
}
