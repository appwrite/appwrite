import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearch } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { accountIdentitiesQueryOptions } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import {
  isProviderTokenExpired,
  parseQuickSetupReturn,
  stripQuickSetupReturn,
  type SmtpQuickSetupResult,
} from '@/lib/smtp/quick-setup'
import {
  SMTP_QUICK_SETUP_PROVIDERS,
  getAvailableSmtpQuickSetupProvider,
  isProviderAvailable,
  type AvailableSmtpQuickSetupProvider,
  type SmtpQuickSetupProvider,
} from '@/lib/smtp/providers'
import {
  QuickSetupReauthorizeRequiredError,
  refreshProviderAccessToken,
  resolveProviderAccessToken,
  startProviderAuthorization,
  type ProviderAccessToken,
} from '@/lib/smtp/quick-setup-oauth'
import {
  SmtpQuickSetupDialog,
  type ProviderApiCall,
} from './SmtpQuickSetupDialog'
import { DisconnectSmtpProvider } from './DisconnectSmtpProvider'

interface SmtpQuickSetupCardProps {
  projectId: string
  project: Models.Project | undefined
  /** Custom SMTP is a paid feature; the tiles are disabled (with a tooltip) below that plan. */
  supportsCustomSmtp: boolean
  /** Fills the SMTP form on this page. Quick setup never saves by itself. */
  onApply: (result: SmtpQuickSetupResult) => void
}

/**
 * One-click SMTP setup for any provider in `lib/smtp/providers.ts`.
 *
 * Authorization leaves for the provider and comes back through
 * `/auth/smtp/callback`, which restores the console session (Appwrite drops it
 * when the OAuth2 flow starts) and returns here with `smtpSetup=connected`.
 * From there this card resolves the provider access token from any session
 * this account holds for the provider, refreshing it through `updateSession`.
 * Clicking a connected provider never leaves the page: if no session can
 * produce a token, the dialog says the authorization expired and the user
 * decides whether to reconnect. Tokens are kept in memory only.
 */
export function SmtpQuickSetupCard({
  projectId,
  project,
  supportsCustomSmtp,
  onApply,
}: SmtpQuickSetupCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const search = useSearch({ strict: false })
  const { account } = useAuth()
  const accountId =
    (account as Models.User<Models.Preferences> | null | undefined)?.$id ?? null

  // Identities already linked to this console account, so a provider that was
  // connected earlier reads as connected here too (matches account settings).
  const { data: identitiesData } = useQuery(accountIdentitiesQueryOptions())
  const identityByProvider = useMemo(() => {
    const map = new Map<string, Models.Identity>()
    for (const identity of identitiesData?.identities ?? []) {
      if (!map.has(identity.provider)) map.set(identity.provider, identity)
    }
    return map
  }, [identitiesData])

  /** Access token per provider id; in memory only, dropped on reload. */
  const tokensRef = useRef(new Map<string, ProviderAccessToken>())
  const handledReturnRef = useRef(false)
  const [activeProvider, setActiveProvider] =
    useState<AvailableSmtpQuickSetupProvider | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [disconnecting, setDisconnecting] =
    useState<SmtpQuickSetupProvider | null>(null)
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
      if (!accountId) return
      setIsConnecting(true)
      try {
        startProviderAuthorization(provider, projectId, accountId)
      } catch (error) {
        setIsConnecting(false)
        toast.error(
          getErrorMessage(error, t('Failed to connect the email provider')),
        )
      }
    },
    [accountId, projectId, t],
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

  const openDialogFor = useCallback(
    async (provider: AvailableSmtpQuickSetupProvider) => {
      const token = await resolveProviderAccessToken(provider.id)
      if (!token) return false
      tokensRef.current.set(provider.id, token)
      setActiveProvider(provider)
      setDialogOpen(true)
      return true
    },
    [],
  )

  // Pick the flow back up after `/auth/smtp/callback` restored the session.
  useEffect(() => {
    if (!returnState) {
      handledReturnRef.current = false
      return
    }
    if (handledReturnRef.current) return
    handledReturnRef.current = true
    stripReturnParams()

    const provider = getAvailableSmtpQuickSetupProvider(returnState.providerId)
    if (!provider) return

    if (returnState.status === 'failed') {
      toast.error(returnState.message ?? t('Authorization failed'))
      return
    }

    // The identity is new; account settings and the tile badge should show it.
    void queryClient.invalidateQueries({ queryKey: ['identities', 'account'] })
    void queryClient.invalidateQueries({ queryKey: ['sessions', 'account'] })

    setIsConnecting(true)
    void (async () => {
      try {
        if (!(await openDialogFor(provider))) {
          toast.error(t('Authorization failed'))
        }
      } catch (error) {
        toast.error(
          getErrorMessage(error, t('Failed to connect the email provider')),
        )
      } finally {
        setIsConnecting(false)
      }
    })()
  }, [returnState, queryClient, stripReturnParams, openDialogFor, t])

  const handleSetup = async (
    provider: SmtpQuickSetupProvider,
    connected: boolean,
  ) => {
    if (!isProviderAvailable(provider) || !accountId) return

    setIsConnecting(true)
    try {
      // Reuses a live token, or refreshes one from any session this account
      // holds for the provider.
      if (await openDialogFor(provider)) {
        setIsConnecting(false)
        return
      }

      if (connected) {
        // An already connected provider never gets bounced to the consent
        // screen on a click. The dialog opens and explains that the
        // authorization expired, leaving reconnecting to the user.
        setActiveProvider(provider)
        setDialogOpen(true)
        setIsConnecting(false)
        return
      }

      // First connection: the page unloads on redirect, so the tile stays
      // disabled until then.
      startProviderAuthorization(provider, projectId, accountId)
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
                connected={identityByProvider.has(provider.id)}
                disabled={
                  !supportsCustomSmtp ||
                  !isProviderAvailable(provider) ||
                  !accountId ||
                  isConnecting
                }
                planTooltip={planTooltip}
                onSelect={() =>
                  void handleSetup(
                    provider,
                    identityByProvider.has(provider.id),
                  )
                }
                onDisconnect={() => setDisconnecting(provider)}
              />
            ))}
          </div>
        </div>
      </div>

      {activeProvider ? (
        <SmtpQuickSetupDialog
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          project={project}
          provider={activeProvider}
          callProvider={callProvider}
          onReauthorize={() => authorize(activeProvider)}
          onApply={onApply}
        />
      ) : null}

      {disconnecting && identityByProvider.get(disconnecting.id) ? (
        <DisconnectSmtpProvider
          open
          onOpenChange={(next) => {
            if (!next) setDisconnecting(null)
          }}
          provider={disconnecting}
          identityId={identityByProvider.get(disconnecting.id)!.$id}
          onDisconnected={() => {
            // The cached token belonged to the identity that just went away.
            tokensRef.current.delete(disconnecting.id)
            setDisconnecting(null)
          }}
        />
      ) : null}
    </>
  )
}

interface ProviderTileProps {
  provider: SmtpQuickSetupProvider
  /** This console account already has an identity for the provider. */
  connected: boolean
  disabled: boolean
  /** Shown only for the plan gate; the badges already explain the other states. */
  planTooltip?: string
  onSelect: () => void
  onDisconnect: () => void
}

/**
 * Provider card. The tile itself is never clickable: every state names its
 * action on a button, so nothing happens by clicking near a label.
 */
function ProviderTile({
  provider,
  connected,
  disabled,
  planTooltip,
  onSelect,
  onDisconnect,
}: ProviderTileProps) {
  const t = useT()
  const comingSoon = !isProviderAvailable(provider)

  const action = connected ? (
    <>
      <ActionButton
        label={t('Quick setup')}
        onClick={onSelect}
        disabled={disabled}
        planTooltip={planTooltip}
        analyticsAction={provider.analyticsAction}
      />
      <Button
        type="button"
        variant="destructive"
        size="sm"
        className="h-8 text-[12px]"
        onClick={onDisconnect}
      >
        {t('Disconnect')}
      </Button>
    </>
  ) : (
    <ActionButton
      label={t(provider.connectLabel)}
      onClick={onSelect}
      disabled={disabled}
      planTooltip={comingSoon ? undefined : planTooltip}
      analyticsAction={provider.analyticsAction}
    />
  )

  return (
    <div className="flex h-full w-full flex-col gap-2 rounded-xl border border-border bg-card/50 p-4">
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
        ) : connected ? (
          <Badge variant="success" className="text-[10px] shrink-0">
            {t('Connected')}
          </Badge>
        ) : null}
      </div>
      <span className="text-[12px] text-muted-foreground">
        {t(provider.tagline)}
      </span>
      <div className="mt-auto flex flex-wrap items-center gap-2 pt-2">
        {action}
      </div>
    </div>
  )
}

function ActionButton({
  label,
  onClick,
  disabled,
  planTooltip,
  analyticsAction,
}: {
  label: string
  onClick: () => void
  disabled: boolean
  planTooltip?: string
  analyticsAction: SmtpQuickSetupProvider['analyticsAction']
}) {
  const button = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 text-[12px]"
      onClick={onClick}
      disabled={disabled}
      {...analyticsAttrs(analyticsAction)}
    >
      {label}
    </Button>
  )

  if (!planTooltip) return button

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{button}</span>
      </TooltipTrigger>
      <TooltipContent className="max-w-xs text-[13px]">
        {planTooltip}
      </TooltipContent>
    </Tooltip>
  )
}
