import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle, Check, ExternalLink, Loader2 } from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { accountIdentitiesQueryOptions } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import {
  buildCredentialName,
  createMintedCredentialTracker,
  defaultSenderEmail,
  emailBelongsToDomain,
  pickDefaultQuickSetupDomain,
  sortQuickSetupDomains,
  type QuickSetupDomain,
} from '@/lib/smtp/quick-setup'
import {
  getAvailableSmtpQuickSetupProvider,
  type SmtpQuickSetupCredential,
} from '@/lib/smtp/providers'
import {
  QuickSetupReauthorizeRequiredError,
  startProviderAuthorization,
} from '@/lib/smtp/quick-setup-oauth'
import { useProviderTokens } from '@/lib/smtp/use-provider-tokens'

type Phase =
  | 'idle'
  | 'loading'
  | 'ready'
  | 'no-domains'
  | 'minting'
  | 'done'
  | 'reauthorize'
  | 'error'

interface ResendOneClickSetupProps {
  projectName: string
  /** Current wizard values, so filling does not clobber what the user typed. */
  values: Record<string, unknown>
  onFill: (fields: {
    apiKey: string
    /** Provider name; set only when the user left it empty. */
    name?: string
    fromName?: string
    fromEmail?: string
  }) => void
  /** Run the flow on mount, used when returning from authorization. */
  autoStart?: boolean
}

export interface ResendOneClickSetupHandle {
  /**
   * The wizard is creating the provider with `apiKey`, and `request` is that
   * call. From here on a key minted here is decided by the request alone:
   * kept once the provider exists, revoked when creation fails, and never
   * touched by the unmount cleanup in between, even if the wizard is closed
   * while the request is still in flight. A minted key the user replaced by
   * hand is still unused and is revoked as usual.
   */
  settleCredential(apiKey: unknown, request: Promise<unknown>): void
}

/**
 * One-click setup for the messaging Resend provider: authorize Resend once,
 * then let Appwrite mint a sending-only API key for a verified domain and fill
 * the wizard's API key and sender fields.
 *
 * Same credentials and reconnect rules as the project SMTP one-click setup;
 * the difference is what gets filled, since this provider sends through
 * Resend's API rather than the SMTP relay.
 *
 * The key exists at Resend from the moment it is minted, but nothing depends
 * on it until the provider is created. Until the wizard hands that request
 * over through {@link ResendOneClickSetupHandle.settleCredential}, this panel
 * owns the key and revokes it when the user leaves without creating the
 * provider.
 */
export const ResendOneClickSetup = forwardRef<
  ResendOneClickSetupHandle,
  ResendOneClickSetupProps
>(function ResendOneClickSetup(
  { projectName, values, onFill, autoStart = false },
  ref,
) {
  const t = useT()
  const { account } = useAuth()
  const accountId =
    (account as { $id?: string } | null | undefined)?.$id ?? null
  const provider = getAvailableSmtpQuickSetupProvider('resend')

  const { data: identitiesData, isPending: identitiesPending } = useQuery(
    accountIdentitiesQueryOptions(),
  )
  const connected = (identitiesData?.identities ?? []).some(
    (identity) => identity.provider === 'resend',
  )

  const { callWith } = useProviderTokens()
  const [minted] = useState(() =>
    createMintedCredentialTracker<SmtpQuickSetupCredential>((credentialId) =>
      provider
        ? callWith(provider, (accessToken) =>
            provider.api.deleteCredential(accessToken, credentialId),
          )
        : Promise.resolve(),
    ),
  )
  const [phase, setPhase] = useState<Phase>('idle')
  const [domains, setDomains] = useState<QuickSetupDomain[]>([])
  const [domainId, setDomainId] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const startedRef = useRef(false)

  const selectedDomain = domains.find((domain) => domain.id === domainId)

  const failWith = useCallback((error: unknown, fallback: string) => {
    if (error instanceof QuickSetupReauthorizeRequiredError) {
      setPhase('reauthorize')
      return
    }
    setErrorMessage(getErrorMessage(error, fallback))
    setPhase('error')
  }, [])

  const loadDomains = useCallback(async () => {
    if (!provider) return
    setPhase('loading')
    setErrorMessage('')
    try {
      const list = sortQuickSetupDomains(
        await callWith(provider, provider.api.listDomains),
      )
      setDomains(list)
      const initial = pickDefaultQuickSetupDomain(
        list,
        typeof values.fromEmail === 'string' ? values.fromEmail : undefined,
      )
      if (!initial) {
        setPhase('no-domains')
        return
      }
      setDomainId(initial.id)
      setPhase('ready')
    } catch (error) {
      failWith(error, 'Failed to load domains from the email provider')
    }
    // `values` is read for a default only; re-running on each keystroke would
    // reload the domain list while the user types.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [callWith, failWith, provider])

  useEffect(() => {
    if (!autoStart || startedRef.current || !connected) return
    startedRef.current = true
    void loadDomains()
  }, [autoStart, connected, loadDomains])

  // Leaving the wizard without creating the provider (back, close, browser
  // navigation) abandons the minted key. Revoke it rather than leave a live,
  // unused sending key in the Resend account.
  useEffect(() => () => minted.release(), [minted])

  useImperativeHandle(
    ref,
    () => ({
      settleCredential: (apiKey, request) => minted.settle(apiKey, request),
    }),
    [minted],
  )

  if (!provider) return null

  const authorize = () => {
    if (!accountId) return
    try {
      startProviderAuthorization(provider)
    } catch (error) {
      failWith(error, 'Failed to connect the email provider')
    }
  }

  const generate = async () => {
    if (!selectedDomain) return
    setPhase('minting')
    setErrorMessage('')
    try {
      const credential = await callWith(provider, (accessToken) =>
        provider.api.createCredential(accessToken, {
          name: buildCredentialName(
            projectName,
            provider.credentialNameMaxLength,
          ),
          domainId: selectedDomain.id,
        }),
      )
      // Generating again replaces the key in the form, so the previous one
      // (if any) is revoked here as well.
      minted.track(credential)

      const currentEmail =
        typeof values.fromEmail === 'string' ? values.fromEmail.trim() : ''
      const currentName =
        typeof values.fromName === 'string' ? values.fromName.trim() : ''
      const currentProviderName =
        typeof values.name === 'string' ? values.name.trim() : ''
      onFill({
        apiKey: credential.secret,
        // The name is required to create the provider; give the one-click
        // path a default so the user is not blocked on it.
        name: currentProviderName ? undefined : t('One-click Resend sender'),
        // Keep what the user typed when it already fits the chosen domain.
        fromEmail: emailBelongsToDomain(currentEmail, selectedDomain.name)
          ? undefined
          : defaultSenderEmail(selectedDomain.name),
        fromName: currentName ? undefined : projectName,
      })
      setPhase('done')
    } catch (error) {
      failWith(error, 'Failed to create the API key with the email provider')
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="flex items-center gap-3 px-4 py-3">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <img
            src={provider.iconPath}
            alt=""
            aria-hidden="true"
            className={`h-4 w-4 ${PUBLIC_ICON_MUTED_CLASSES}`}
          />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-medium text-foreground">
            {t('One-click setup')}
          </p>
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Let Appwrite create the API key for a verified domain and fill these fields.',
            )}
          </p>
        </div>
        {phase === 'idle' ? (
          // The label depends on whether Resend is already connected. Both
          // labels are laid out invisibly in the same grid cell so the slot
          // is always as wide as the longer one; the skeleton and then the
          // button sit on top without ever changing the header's footprint.
          <div className="grid shrink-0">
            {[t(provider.connectLabel), t('One-click setup')].map((label) => (
              <Button
                key={label}
                type="button"
                variant="outline"
                size="sm"
                className="invisible col-start-1 row-start-1 h-8 text-[12px]"
                aria-hidden="true"
                tabIndex={-1}
              >
                {label}
              </Button>
            ))}
            {identitiesPending ? (
              <Skeleton className="col-start-1 row-start-1 h-8" />
            ) : (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="col-start-1 row-start-1 h-8 text-[12px]"
                disabled={!accountId}
                onClick={connected ? () => void loadDomains() : authorize}
                {...analyticsAttrs('messaging-quick-setup-resend')}
              >
                {connected ? t('One-click setup') : t(provider.connectLabel)}
              </Button>
            )}
          </div>
        ) : null}
      </div>

      {phase !== 'idle' ? (
        <>
          <div className="border-t border-border" />
          <div className="px-4 py-3">
            {phase === 'loading' || phase === 'minting' ? (
              <p className="flex items-center gap-2 text-[12px] text-muted-foreground">
                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                {phase === 'loading'
                  ? t('Loading domains…')
                  : t('Creating the sending credential…')}
              </p>
            ) : null}

            {phase === 'ready' ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div className="min-w-0 flex-1 space-y-2">
                  <Label
                    htmlFor="resend-one-click-domain"
                    className="text-[12px] font-medium"
                  >
                    {t('Domain')}
                  </Label>
                  <Select value={domainId} onValueChange={setDomainId}>
                    <SelectTrigger
                      id="resend-one-click-domain"
                      className="h-9 text-[13px]"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {domains.map((domain) => (
                        <SelectItem
                          key={domain.id}
                          value={domain.id}
                          disabled={!domain.verified}
                        >
                          {domain.name}
                          {!domain.verified ? (
                            <span className="text-muted-foreground">
                              {' '}
                              ({t('unverified')})
                            </span>
                          ) : null}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 shrink-0 text-[13px]"
                  disabled={!selectedDomain}
                  onClick={() => void generate()}
                >
                  {t('Generate API key')}
                </Button>
              </div>
            ) : null}

            {phase === 'no-domains' ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[12px] text-muted-foreground">
                  {t('Add and verify a sending domain, then check again.')}
                </p>
                <div className="flex shrink-0 gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-[12px]"
                    asChild
                  >
                    <a
                      href={provider.domainsUrl}
                      target="_blank"
                      rel="noreferrer"
                    >
                      {t('Manage domains')}
                      <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                    </a>
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    className="h-8 text-[12px]"
                    onClick={() => void loadDomains()}
                  >
                    {t('Check again')}
                  </Button>
                </div>
              </div>
            ) : null}

            {phase === 'done' ? (
              <p className="flex items-center gap-2 text-[12px] text-foreground">
                <Check className="h-3.5 w-3.5 text-green-600" />
                {t(
                  'API key created and filled in below. Review and create the provider.',
                )}
              </p>
            ) : null}

            {phase === 'reauthorize' ? (
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-[12px] text-muted-foreground">
                  {t('Reconnect your provider account to continue.')}
                </p>
                <Button
                  type="button"
                  size="sm"
                  className="h-8 shrink-0 text-[12px]"
                  disabled={!accountId}
                  onClick={authorize}
                >
                  {t('Reconnect')}
                </Button>
              </div>
            ) : null}

            {phase === 'error' ? (
              <div className="space-y-3">
                <Alert variant="destructive" className="border-destructive/30">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription className="text-[13px]">
                    {errorMessage}
                  </AlertDescription>
                </Alert>
                <Button
                  type="button"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() => void loadDomains()}
                >
                  {t('Try again')}
                </Button>
              </div>
            ) : null}
          </div>
        </>
      ) : null}
    </div>
  )
})
