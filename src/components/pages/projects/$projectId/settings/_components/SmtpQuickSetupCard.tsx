import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
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
import { cn } from '@/lib/utils'
import {
  isProviderTokenExpired,
  parseQuickSetupReturn,
  stripQuickSetupReturn,
} from '@/lib/smtp/quick-setup'
import {
  SMTP_QUICK_SETUP_PROVIDERS,
  getAvailableSmtpQuickSetupProvider,
  isProviderAvailable,
  type AvailableSmtpQuickSetupProvider,
  type SmtpQuickSetupProvider,
} from '@/lib/smtp/providers'
import {
  QuickSetupAccountMismatchError,
  QuickSetupReauthorizeRequiredError,
  claimProviderIdentity,
  refreshProviderAccessToken,
  resolveProviderAccessToken,
  startProviderAuthorization,
  tokenFromSession,
  type ProviderAccessToken,
} from '@/lib/smtp/quick-setup-oauth'
import {
  SmtpQuickSetupDialog,
  type ProviderApiCall,
} from './SmtpQuickSetupDialog'

interface SmtpQuickSetupCardProps {
  projectId: string
  project: Models.Project | undefined
  /** Custom SMTP is a paid feature; the tiles are disabled (with a tooltip) below that plan. */
  supportsCustomSmtp: boolean
  /** Open the "Send test email" dialog after a successful setup. */
  onSendTestEmail?: () => void
}

/**
 * One-click SMTP setup for any provider in `lib/smtp/providers.ts`.
 *
 * Owns the whole round trip: starts `createOAuth2Token`, claims the identity
 * with `createSession` when the tab is reopened with `userId` + `secret`,
 * keeps provider access tokens in memory (never persisted), refreshes them
 * through `updateSession`, and falls back to a fresh authorization when the
 * refresh is impossible.
 */
export function SmtpQuickSetupCard({
  projectId,
  project,
  supportsCustomSmtp,
  onSendTestEmail,
}: SmtpQuickSetupCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const search = useSearch({ strict: false })
  const { account } = useAuth()
  const accountId =
    (account as Models.User<Models.Preferences> | null | undefined)?.$id ?? null

  /** Access token per provider id; in memory only, dropped on reload. */
  const tokensRef = useRef(new Map<string, ProviderAccessToken>())
  const handledReturnRef = useRef(false)
  const [activeProvider, setActiveProvider] =
    useState<AvailableSmtpQuickSetupProvider | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [isConnecting, setIsConnecting] = useState(false)

  const returnState = useMemo(() => parseQuickSetupReturn(search), [search])

  const stripReturnParams = useCallback(() => {
    navigate({
      search: ((prev: unknown) => stripQuickSetupReturn(prev)) as never,
      replace: true,
    })
  }, [navigate])

  const authorize = useCallback(
    (provider: AvailableSmtpQuickSetupProvider) => {
      setIsConnecting(true)
      try {
        startProviderAuthorization(provider, projectId)
      } catch (error) {
        setIsConnecting(false)
        toast.error(
          getErrorMessage(error, t('Failed to connect the email provider')),
        )
      }
    },
    [projectId, t],
  )

  /**
   * Runs a provider API call with the cached token. Expired tokens and 401
   * responses trigger one `updateSession` refresh; when the current session
   * cannot refresh the token, the dialog asks to reconnect.
   */
  const callProvider = useCallback<ProviderApiCall>(
    async (run) => {
      const provider = activeProvider
      if (!provider) throw new QuickSetupReauthorizeRequiredError()

      let current = tokensRef.current.get(provider.id)
      if (!current || isProviderTokenExpired(current.expiry)) {
        current = await refreshProviderAccessToken(provider.id)
        tokensRef.current.set(provider.id, current)
      }
      try {
        return await run(current.token)
      } catch (error) {
        if (!provider.api.isUnauthorizedError(error)) throw error
        const refreshed = await refreshProviderAccessToken(provider.id)
        tokensRef.current.set(provider.id, refreshed)
        return await run(refreshed.token)
      }
    },
    [activeProvider],
  )

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

    const provider = getAvailableSmtpQuickSetupProvider(returnState.providerId)
    if (!provider) return

    if (returnState.status === 'failed') {
      toast.error(returnState.message ?? t('Authorization failed'))
      return
    }

    const { userId, secret } = returnState
    setIsConnecting(true)
    void (async () => {
      try {
        const session = await claimProviderIdentity({
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
          tokenFromSession(session, provider.id) ??
          (await resolveProviderAccessToken(provider.id))
        if (!token) throw new QuickSetupReauthorizeRequiredError()
        tokensRef.current.set(provider.id, token)
        setActiveProvider(provider)
        setDialogOpen(true)
      } catch (error) {
        if (error instanceof QuickSetupAccountMismatchError) {
          toast.error(
            t(
              'The provider was authorized for a different Appwrite account. Try again while signed in to this account.',
            ),
          )
        } else if (error instanceof QuickSetupReauthorizeRequiredError) {
          toast.error(t('Authorization failed'))
        } else {
          toast.error(
            getErrorMessage(error, t('Failed to connect the email provider')),
          )
        }
      } finally {
        setIsConnecting(false)
      }
    })()
  }, [returnState, accountId, queryClient, stripReturnParams, t])

  const handleSetup = async (provider: SmtpQuickSetupProvider) => {
    if (!isProviderAvailable(provider)) return

    setIsConnecting(true)
    try {
      const token = await resolveProviderAccessToken(provider.id)
      if (token) {
        tokensRef.current.set(provider.id, token)
        setActiveProvider(provider)
        setDialogOpen(true)
        setIsConnecting(false)
        return
      }
      // No usable token: leave for the provider. The page unloads, so the
      // tile intentionally stays disabled until then.
      startProviderAuthorization(provider, projectId)
    } catch (error) {
      setIsConnecting(false)
      toast.error(
        getErrorMessage(error, t('Failed to connect the email provider')),
      )
    }
  }

  const planTooltip = !supportsCustomSmtp
    ? t('Custom SMTP is available on Appwrite Cloud Pro and higher plans.') // pragma: allowlist secret
    : undefined

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Quick setup')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Connect an email provider and Appwrite fills in your SMTP settings automatically.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />

        <div className="px-6 py-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {SMTP_QUICK_SETUP_PROVIDERS.map((provider) => (
              <ProviderTile
                key={provider.id}
                provider={provider}
                disabled={
                  !supportsCustomSmtp ||
                  !isProviderAvailable(provider) ||
                  isConnecting
                }
                planTooltip={planTooltip}
                onSelect={() => void handleSetup(provider)}
              />
            ))}
          </div>
        </div>
      </div>

      {activeProvider ? (
        <SmtpQuickSetupDialog
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          projectId={projectId}
          project={project}
          provider={activeProvider}
          callProvider={callProvider}
          onReauthorize={() => authorize(activeProvider)}
          onSendTestEmail={onSendTestEmail}
        />
      ) : null}
    </>
  )
}

interface ProviderTileProps {
  provider: SmtpQuickSetupProvider
  disabled: boolean
  /** Shown only for the plan gate; "Coming soon" is already visible as a badge. */
  planTooltip?: string
  onSelect: () => void
}

function ProviderTile({
  provider,
  disabled,
  planTooltip,
  onSelect,
}: ProviderTileProps) {
  const t = useT()
  const comingSoon = !isProviderAvailable(provider)

  const tile = (
    <button
      type="button"
      onClick={onSelect}
      disabled={disabled}
      className={cn(
        'group flex h-full w-full flex-col gap-2 rounded-xl border border-border bg-card/50 p-4 text-start transition-all',
        disabled
          ? 'cursor-not-allowed opacity-60'
          : 'cursor-pointer hover:border-border/80 hover:bg-card/60',
      )}
      {...analyticsAttrs(provider.analyticsAction)}
    >
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <img
            src={provider.iconPath}
            alt=""
            aria-hidden="true"
            className={cn('h-4 w-4', PUBLIC_ICON_MUTED_CLASSES)}
          />
        </div>
        <span className="text-[13px] font-medium text-foreground">
          {provider.name}
        </span>
        {comingSoon ? (
          <Badge variant="info" className="text-[10px] shrink-0">
            {t('Coming soon')}
          </Badge>
        ) : null}
      </div>
      <span className="text-[12px] text-muted-foreground">
        {t(provider.tagline)}
      </span>
    </button>
  )

  if (!planTooltip || comingSoon) return tile

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{tile}</span>
      </TooltipTrigger>
      <TooltipContent className="max-w-xs text-[13px]">
        {planTooltip}
      </TooltipContent>
    </Tooltip>
  )
}
