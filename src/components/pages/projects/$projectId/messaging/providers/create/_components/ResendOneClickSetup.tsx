import { useCallback, useEffect, useRef, useState } from 'react'
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
  defaultSenderEmail,
  emailBelongsToDomain,
  pickDefaultQuickSetupDomain,
  sortQuickSetupDomains,
  type QuickSetupDomain,
} from '@/lib/smtp/quick-setup'
import { getAvailableSmtpQuickSetupProvider } from '@/lib/smtp/providers'
import {
  QuickSetupReauthorizeRequiredError,
  startProviderAuthorization,
} from '@/lib/smtp/quick-setup-oauth'
import { useProviderTokens } from '@/lib/smtp/use-provider-tokens'

/** Wizard fields that survive the authorization round trip. No secrets. */
export interface ResendDraft {
  name?: string
  fromName?: string
  fromEmail?: string
}

const DRAFT_STORAGE_KEY = 'messaging.createProvider.resend'

/**
 * Authorization navigates away from the wizard, so the few fields the user may
 * have typed are parked here first. Only names and the sender address: never
 * the API key, which does not exist yet at this point anyway.
 */
export function rememberResendDraft(draft: ResendDraft): void {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify(draft))
  } catch {
    // Private mode: the user retypes the name, nothing else breaks.
  }
}

export function readResendDraft(): ResendDraft | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.sessionStorage.getItem(DRAFT_STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as ResendDraft
    return parsed && typeof parsed === 'object' ? parsed : null
  } catch {
    return null
  }
}

export function clearResendDraft(): void {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.removeItem(DRAFT_STORAGE_KEY)
  } catch {
    // Nothing to clean up.
  }
}

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
  projectId: string
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
  /** Path to come back to after authorizing. */
  returnPath: string
  /** Run the flow on mount, used when returning from authorization. */
  autoStart?: boolean
}

/**
 * One-click setup for the messaging Resend provider: authorize Resend once,
 * then let Appwrite mint a sending-only API key for a verified domain and fill
 * the wizard's API key and sender fields.
 *
 * Same credentials and refresh rules as the project SMTP one-click setup; the
 * difference is what gets filled, since this provider sends through Resend's
 * API rather than the SMTP relay.
 */
export function ResendOneClickSetup({
  projectId,
  projectName,
  values,
  onFill,
  returnPath,
  autoStart = false,
}: ResendOneClickSetupProps) {
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

  if (!provider) return null

  const authorize = () => {
    if (!accountId) return
    rememberResendDraft({
      name: typeof values.name === 'string' ? values.name : undefined,
      fromName:
        typeof values.fromName === 'string' ? values.fromName : undefined,
      fromEmail:
        typeof values.fromEmail === 'string' ? values.fromEmail : undefined,
    })
    try {
      startProviderAuthorization(provider, projectId, accountId, returnPath)
    } catch (error) {
      clearResendDraft()
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
}
