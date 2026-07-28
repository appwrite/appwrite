import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Check, ChevronDown, Copy, Info } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import {
  OAUTH2_SERVER_TIME_UNIT_OPTIONS,
  oauth2DurationFromSeconds,
  oauth2DurationToSeconds,
  type OAuth2ServerTimeUnit,
} from '@/lib/oauth2-server/duration'
import {
  getOAuth2ServerDiscoveryUrl,
  getOAuth2ServerEndpointUrl,
  OAUTH2_SERVER_COMMON_ENDPOINTS,
} from '@/lib/oauth2-server/discovery'
import {
  mergeOAuth2Scopes,
  oauth2ScopesEqual,
  optionalOAuth2Scopes,
  REQUIRED_OAUTH2_SCOPES,
} from '@/lib/oauth2-server/scopes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { projectQueryOptions, useProject, useOrganizationScopes } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canShowProjectOAuth2Server } from '@/lib/console-access-checks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { InputTags } from '@/components/ui/input-tags'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'

const MAX_SCOPES = 100
const MAX_SCOPE_LENGTH = 128

type OAuth2ServerFormState = {
  enabled: boolean
  authorizationUrl: string
  scopes: string[]
  accessTokenDuration: number | null
  refreshTokenDuration: number | null
  publicAccessTokenDuration: number | null
  publicRefreshTokenDuration: number | null
  confidentialPkce: boolean
}

function formStateFromProject(project: Models.Project): OAuth2ServerFormState {
  return {
    enabled: project.oAuth2ServerEnabled ?? false,
    authorizationUrl: project.oAuth2ServerAuthorizationUrl ?? '',
    scopes: mergeOAuth2Scopes(project.oAuth2ServerScopes ?? []),
    accessTokenDuration: project.oAuth2ServerAccessTokenDuration ?? null,
    refreshTokenDuration: project.oAuth2ServerRefreshTokenDuration ?? null,
    publicAccessTokenDuration:
      project.oAuth2ServerPublicAccessTokenDuration ?? null,
    publicRefreshTokenDuration:
      project.oAuth2ServerPublicRefreshTokenDuration ?? null,
    confidentialPkce: project.oAuth2ServerConfidentialPkce ?? false,
  }
}

function validateScopes(scopes: string[]): string | null {
  if (scopes.length > MAX_SCOPES) {
    return `Maximum of ${MAX_SCOPES} scopes allowed.`
  }
  const invalid = scopes.find((scope) => scope.length > MAX_SCOPE_LENGTH)
  if (invalid) {
    return `Scope "${invalid}" exceeds ${MAX_SCOPE_LENGTH} characters.`
  }
  return null
}

function tokensStateEqual(
  a: Pick<
    OAuth2ServerFormState,
    | 'accessTokenDuration'
    | 'refreshTokenDuration'
    | 'publicAccessTokenDuration'
    | 'publicRefreshTokenDuration'
    | 'confidentialPkce'
  >,
  b: Pick<
    OAuth2ServerFormState,
    | 'accessTokenDuration'
    | 'refreshTokenDuration'
    | 'publicAccessTokenDuration'
    | 'publicRefreshTokenDuration'
    | 'confidentialPkce'
  >,
) {
  return (
    a.accessTokenDuration === b.accessTokenDuration &&
    a.refreshTokenDuration === b.refreshTokenDuration &&
    a.publicAccessTokenDuration === b.publicAccessTokenDuration &&
    a.publicRefreshTokenDuration === b.publicRefreshTokenDuration &&
    a.confidentialPkce === b.confidentialPkce
  )
}

function SectionUpdateButton({
  disabled,
  pending,
  onClick,
}: {
  disabled?: boolean
  pending?: boolean
  onClick: () => void
}) {
  const t = useT()
  return (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      disabled={disabled || pending}
      onClick={onClick}
    >
      {t('Update')}
    </Button>
  )
}

function SettingsSection({
  title,
  description,
  children,
  footer,
  headerExtra,
}: {
  title: string
  description?: string
  children: ReactNode
  footer?: ReactNode
  headerExtra?: ReactNode
}) {
  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
            {description ? (
              <p className="mt-2 text-[13px] text-muted-foreground">{description}</p>
            ) : null}
          </div>
          {headerExtra}
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">{children}</div>
      </div>
      {footer ? (
        <>
          <div className="border-t border-border" />
          <div className="px-6 py-4 bg-muted/30">{footer}</div>
        </>
      ) : null}
    </div>
  )
}

function FieldHint({ label, hint }: { label: string; hint: string }) {
  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            className="inline-flex items-center gap-1 text-[12px] font-medium text-foreground"
          >
            {label}
            <Info className="h-3.5 w-3.5 text-muted-foreground" />
          </button>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs text-[12px]">{hint}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

function DurationField({
  id,
  label,
  hint,
  value,
  unit,
  placeholder,
  disabled,
  onValueChange,
  onUnitChange,
}: {
  id: string
  label: string
  hint?: string
  value: number | null
  unit: OAuth2ServerTimeUnit
  placeholder: string
  disabled?: boolean
  onValueChange: (value: number | null) => void
  onUnitChange: (unit: OAuth2ServerTimeUnit) => void
}) {
  const t = useT()
  return (
    <div className="space-y-2">
      <div>
        <Label htmlFor={id} className="text-[13px] font-medium text-foreground">
          {label}
        </Label>
        {hint ? (
          <p className="mt-0.5 text-[12px] text-muted-foreground">{hint}</p>
        ) : null}
      </div>
      <div className="grid grid-cols-[1fr_7.5rem] gap-2">
        <Input
          id={id}
          type="number"
          min={1}
          value={value ?? ''}
          placeholder={placeholder}
          disabled={disabled}
          onChange={(event) => {
            const next = event.target.value.trim()
            onValueChange(next === '' ? null : Number(next))
          }}
          className="h-9 font-mono text-[13px] tabular-nums"
        />
        <Select
          value={unit}
          onValueChange={(next) => onUnitChange(next as OAuth2ServerTimeUnit)}
          disabled={disabled}
        >
          <SelectTrigger className="h-9 text-[12px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {OAUTH2_SERVER_TIME_UNIT_OPTIONS.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {t(option.label)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </div>
  )
}

function CopyableUrl({
  value,
  label,
}: {
  value: string
  label?: string
}) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    await navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="space-y-1.5">
      {label ? (
        <Label className="text-[12px] font-medium text-muted-foreground">
          {label}
        </Label>
      ) : null}
      <div className="flex min-w-0 items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2">
        <code className="min-w-0 flex-1 break-all font-mono text-[12px] leading-5 text-foreground">
          {value}
        </code>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 shrink-0 px-2 text-[12px]"
          onClick={handleCopy}
        >
          {copied ? (
            <>
              <Check className="me-1.5 h-3.5 w-3.5 text-emerald-500" />
              {t('Copied')}
            </>
          ) : (
            <>
              <Copy className="me-1.5 h-3.5 w-3.5" />
              {t('Copy')}
            </>
          )}
        </Button>
      </div>
    </div>
  )
}

function DiscoveryEndpoints({
  projectId,
  region,
}: {
  projectId: string
  region?: string
}) {
  const t = useT()
  const [open, setOpen] = useState(false)

  return (
    <Collapsible open={open} onOpenChange={setOpen}>
      <CollapsibleTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 -ms-2 px-2 text-[12px] text-muted-foreground hover:text-foreground"
        >
          {open ? t('Show less') : t('Show more')}
          <ChevronDown
            className={`ms-1.5 h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
          />
        </Button>
      </CollapsibleTrigger>
      <CollapsibleContent className="pt-3">
        <div className="space-y-3">
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Common endpoints from the discovery document. Most OAuth libraries only need the discovery URL.',
            )}
          </p>
          {OAUTH2_SERVER_COMMON_ENDPOINTS.map((endpoint) => (
            <CopyableUrl
              key={endpoint.id}
              label={t(endpoint.label)}
              value={getOAuth2ServerEndpointUrl(
                projectId,
                endpoint.path,
                region,
              )}
            />
          ))}
        </div>
      </CollapsibleContent>
    </Collapsible>
  )
}

const CONFIDENTIAL_EXAMPLES = [
  {
    title: 'Partner "Sign in with your product"',
    description:
      'A third-party app registers as a client and keeps client_secret on their backend.',
  },
  {
    title: 'Backend-for-frontend',
    description:
      'Your API is the OAuth client; mobile or SPA apps get a session without holding secrets.',
  },
]

const PUBLIC_EXAMPLES = [
  {
    title: 'Single-page app',
    description:
      'Browser completes the flow with PKCE because JavaScript cannot store a client secret.',
  },
  {
    title: 'Native mobile app',
    description:
      'The app binary is the client. PKCE is required because the secret cannot be protected.',
  },
]

function ExampleList({ items }: { items: typeof CONFIDENTIAL_EXAMPLES }) {
  const t = useT()
  return (
    <ul className="space-y-2 pt-1">
      {items.map((item) => (
        <li key={item.title} className="text-[12px] text-muted-foreground">
          <span className="font-medium text-foreground">{t(item.title)}.</span>{' '}
          {t(item.description)}
        </li>
      ))}
    </ul>
  )
}

function ClientTokenSettingsColumn({
  title,
  description,
  children,
}: {
  title: string
  description: ReactNode
  children: ReactNode
}) {
  return (
    <div className="space-y-4">
      <div>
        <h4 className="text-[13px] font-semibold text-foreground">{title}</h4>
        <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
      {children}
    </div>
  )
}

type OAuth2ServerSection = 'status' | 'integration' | 'tokens'

interface OAuth2ServerViewProps {
  projectId: string
}

export function View({ projectId }: OAuth2ServerViewProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { project, projectData } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canEdit = canShowProjectOAuth2Server(access, features)
  const [pendingSection, setPendingSection] =
    useState<OAuth2ServerSection | null>(null)

  const serverState = useMemo(
    () => (projectData ? formStateFromProject(projectData) : null),
    [projectData],
  )

  const [enabled, setEnabled] = useState(false)
  const [authorizationUrl, setAuthorizationUrl] = useState('')
  const [scopes, setScopes] = useState<string[]>([])
  const [confidentialPkce, setConfidentialPkce] = useState(false)
  const [accessTokenValue, setAccessTokenValue] = useState<number | null>(null)
  const [accessTokenUnit, setAccessTokenUnit] =
    useState<OAuth2ServerTimeUnit>('hours')
  const [refreshTokenValue, setRefreshTokenValue] = useState<number | null>(null)
  const [refreshTokenUnit, setRefreshTokenUnit] =
    useState<OAuth2ServerTimeUnit>('days')
  const [publicAccessTokenValue, setPublicAccessTokenValue] = useState<
    number | null
  >(null)
  const [publicAccessTokenUnit, setPublicAccessTokenUnit] =
    useState<OAuth2ServerTimeUnit>('hours')
  const [publicRefreshTokenValue, setPublicRefreshTokenValue] = useState<
    number | null
  >(null)
  const [publicRefreshTokenUnit, setPublicRefreshTokenUnit] =
    useState<OAuth2ServerTimeUnit>('days')

  useEffect(() => {
    if (!serverState) return
    setEnabled(serverState.enabled)
    setAuthorizationUrl(serverState.authorizationUrl)
    setScopes(mergeOAuth2Scopes(serverState.scopes))
    setConfidentialPkce(serverState.confidentialPkce)

    const accessToken = oauth2DurationFromSeconds(
      serverState.accessTokenDuration,
      'hours',
    )
    setAccessTokenValue(accessToken.value)
    setAccessTokenUnit(accessToken.unit)

    const refreshToken = oauth2DurationFromSeconds(
      serverState.refreshTokenDuration,
      'days',
    )
    setRefreshTokenValue(refreshToken.value)
    setRefreshTokenUnit(refreshToken.unit)

    const publicAccessToken = oauth2DurationFromSeconds(
      serverState.publicAccessTokenDuration,
      'hours',
    )
    setPublicAccessTokenValue(publicAccessToken.value)
    setPublicAccessTokenUnit(publicAccessToken.unit)

    const publicRefreshToken = oauth2DurationFromSeconds(
      serverState.publicRefreshTokenDuration,
      'days',
    )
    setPublicRefreshTokenValue(publicRefreshToken.value)
    setPublicRefreshTokenUnit(publicRefreshToken.unit)
  }, [serverState])

  const currentFormState = useMemo<OAuth2ServerFormState>(
    () => ({
      enabled,
      authorizationUrl,
      scopes: mergeOAuth2Scopes(scopes),
      confidentialPkce,
      accessTokenDuration: oauth2DurationToSeconds(
        accessTokenValue,
        accessTokenUnit,
      ),
      refreshTokenDuration: oauth2DurationToSeconds(
        refreshTokenValue,
        refreshTokenUnit,
      ),
      publicAccessTokenDuration: oauth2DurationToSeconds(
        publicAccessTokenValue,
        publicAccessTokenUnit,
      ),
      publicRefreshTokenDuration: oauth2DurationToSeconds(
        publicRefreshTokenValue,
        publicRefreshTokenUnit,
      ),
    }),
    [
      enabled,
      authorizationUrl,
      scopes,
      confidentialPkce,
      accessTokenValue,
      accessTokenUnit,
      refreshTokenValue,
      refreshTokenUnit,
      publicAccessTokenValue,
      publicAccessTokenUnit,
      publicRefreshTokenValue,
      publicRefreshTokenUnit,
    ],
  )

  const discoveryUrl = useMemo(
    () => getOAuth2ServerDiscoveryUrl(projectId, project?.region),
    [projectId, project?.region],
  )

  const isStatusUnchanged = serverState ? enabled === serverState.enabled : true
  const optionalScopes = useMemo(() => optionalOAuth2Scopes(scopes), [scopes])

  const isIntegrationUnchanged = serverState
    ? authorizationUrl === serverState.authorizationUrl &&
      oauth2ScopesEqual(scopes, serverState.scopes)
    : true
  const isTokensUnchanged = serverState
    ? tokensStateEqual(currentFormState, serverState)
    : true

  const requiresAuthorizationUrl = enabled && !authorizationUrl.trim()

  const updateMutation = useMutation({
    mutationFn: async () => {
      if (requiresAuthorizationUrl) {
        throw new Error(
          t('Authorization URL is required when the server is enabled.'),
        )
      }
      const scopeError = validateScopes(mergeOAuth2Scopes(scopes))
      if (scopeError) throw new Error(scopeError)

      return sdk
        .forProject(projectId, project?.region)
        .project.updateOAuth2Server({
          enabled,
          authorizationUrl: authorizationUrl.trim(),
          scopes: mergeOAuth2Scopes(scopes),
          accessTokenDuration: currentFormState.accessTokenDuration ?? undefined,
          refreshTokenDuration:
            currentFormState.refreshTokenDuration ?? undefined,
          publicAccessTokenDuration:
            currentFormState.publicAccessTokenDuration ?? undefined,
          publicRefreshTokenDuration:
            currentFormState.publicRefreshTokenDuration ?? undefined,
          confidentialPkce,
        })
    },
    onSuccess: (response) => {
      queryClient.setQueryData(projectQueryOptions(projectId).queryKey, response)
      const message =
        pendingSection === 'status'
          ? t('Server status has been updated.')
          : pendingSection === 'integration'
            ? t('Integration settings have been updated.')
            : t('Token lifetimes have been updated.')
      toast.success(message)
      setPendingSection(null)
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error, t('Failed to update OAuth2 server settings')),
      )
      setPendingSection(null)
    },
  })

  const handleUpdate = (section: OAuth2ServerSection) => {
    setPendingSection(section)
    updateMutation.mutate()
  }

  if (!projectData) {
    return null
  }

  return (
    <div className="space-y-6">
      <SettingsSection
        title={t('Server status')}
        description={t(
          'Let external apps authenticate users through this project. They register as OAuth clients, use your user directory, and receive tokens you issue.',
        )}
        headerExtra={
          <Badge
            variant={serverState?.enabled ? 'success' : 'secondary'}
            className="shrink-0 text-[10px] uppercase tracking-wide"
          >
            {serverState?.enabled ? t('Active') : t('Inactive')}
          </Badge>
        }
        footer={
          <SectionUpdateButton
            pending={updateMutation.isPending && pendingSection === 'status'}
            disabled={
              !canEdit || isStatusUnchanged || requiresAuthorizationUrl
            }
            onClick={() => handleUpdate('status')}
          />
        }
      >
        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="oauth2-server-enabled" className="text-[13px]">
            {t('Enable OAuth2 server')}
          </Label>
          <Switch
            id="oauth2-server-enabled"
            checked={enabled}
            disabled={!canEdit}
            onCheckedChange={setEnabled}
          />
        </div>
      </SettingsSection>

      {enabled ? (
        <>
          <SettingsSection
            title={t('Integration')}
            description={t(
              'Point your consent screen at the authorization URL and choose which scopes clients can request.',
            )}
            footer={
              <SectionUpdateButton
                pending={
                  updateMutation.isPending && pendingSection === 'integration'
                }
                disabled={
                  !canEdit || isIntegrationUnchanged || requiresAuthorizationUrl
                }
                onClick={() => handleUpdate('integration')}
              />
            }
          >
            <div className="space-y-2">
              <FieldHint
                label={t('Authorization URL')}
                hint={t(
                  'Your consent screen URL. Users are redirected here during authorization.',
                )}
              />
              <Input
                id="oauth2-authorization-url"
                value={authorizationUrl}
                disabled={!canEdit}
                placeholder="https://example.com/consent"
                onChange={(event) => setAuthorizationUrl(event.target.value)}
                className="h-9 font-mono text-[13px]"
              />
            </div>

            <div className="space-y-2">
              <FieldHint
                label={t('Scopes')}
                hint={`${t('openid, profile, email, and phone are always included. Add up to')} ${MAX_SCOPES} ${t('scopes total, each up to')} ${MAX_SCOPE_LENGTH} ${t('characters.')}`}
              />
              <InputTags
                id="oauth2-scopes"
                value={optionalScopes}
                lockedTags={[...REQUIRED_OAUTH2_SCOPES]}
                disabled={!canEdit}
                splitOnComma
                placeholder={t('Add custom scopes')}
                onChange={(next) => setScopes(mergeOAuth2Scopes(next))}
              />
            </div>
          </SettingsSection>

          <SettingsSection
            title={t('OIDC discovery')}
            description={t(
              'Share this URL with integrators. OAuth libraries fetch it once to learn authorize, token, and JWKS endpoints.',
            )}
          >
            <CopyableUrl
              label={t('OIDC discovery URL')}
              value={discoveryUrl}
            />
            <DiscoveryEndpoints
              projectId={projectId}
              region={project?.region}
            />
          </SettingsSection>

          <SettingsSection
            title={t('Token lifetimes')}
            description={t(
              'Confidential clients use a client secret on a backend. Public clients (SPAs, mobile) use PKCE only.',
            )}
            footer={
              <SectionUpdateButton
                pending={updateMutation.isPending && pendingSection === 'tokens'}
                disabled={!canEdit || isTokensUnchanged}
                onClick={() => handleUpdate('tokens')}
              />
            }
          >
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-0">
              <div className="lg:pe-8">
              <ClientTokenSettingsColumn
                title={t('Confidential clients')}
                description={
                  <>
                    {t('Server-side apps that store a')}{' '}
                    <code className="rounded bg-muted px-1 font-mono text-[11px]">
                      client_secret
                    </code>{' '}
                    {t('privately.')}
                  </>
                }
              >
                <DurationField
                  id="oauth2-access-token-duration"
                  label={t('Access token TTL')}
                  hint={t('Default: 8 hours when empty.')}
                  value={accessTokenValue}
                  unit={accessTokenUnit}
                  placeholder="8"
                  disabled={!canEdit}
                  onValueChange={setAccessTokenValue}
                  onUnitChange={setAccessTokenUnit}
                />
                <DurationField
                  id="oauth2-refresh-token-duration"
                  label={t('Refresh token TTL')}
                  hint={t('Default: 365 days when empty.')}
                  value={refreshTokenValue}
                  unit={refreshTokenUnit}
                  placeholder="365"
                  disabled={!canEdit}
                  onValueChange={setRefreshTokenValue}
                  onUnitChange={setRefreshTokenUnit}
                />
                <div className="flex items-center justify-between gap-4 rounded-lg border border-border px-4 py-3">
                  <div>
                    <Label
                      htmlFor="oauth2-confidential-pkce"
                      className="text-[13px] font-medium"
                    >
                      {t('Require PKCE')}
                    </Label>
                    <p className="mt-0.5 text-[12px] text-muted-foreground">
                      {t('Extra protection if an auth code is intercepted.')}
                    </p>
                  </div>
                  <Switch
                    id="oauth2-confidential-pkce"
                    checked={confidentialPkce}
                    disabled={!canEdit}
                    onCheckedChange={setConfidentialPkce}
                  />
                </div>
                <Collapsible>
                  <CollapsibleTrigger className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground hover:text-foreground">
                    <ChevronDown className="h-3.5 w-3.5" />
                    {t('Example use cases')}
                  </CollapsibleTrigger>
                  <CollapsibleContent>
                    <ExampleList items={CONFIDENTIAL_EXAMPLES} />
                  </CollapsibleContent>
                </Collapsible>
              </ClientTokenSettingsColumn>
              </div>

              <div className="border-t border-border pt-6 lg:border-s lg:border-t-0 lg:ps-8 lg:pt-0">
                <ClientTokenSettingsColumn
                title={t('Public clients')}
                description={t(
                  'Browser and mobile clients. PKCE is always required; no client secret is issued.',
                )}
              >
                <DurationField
                  id="oauth2-public-access-token-duration"
                  label={t('Access token TTL')}
                  hint={t('Default: 1 hour when empty.')}
                  value={publicAccessTokenValue}
                  unit={publicAccessTokenUnit}
                  placeholder="1"
                  disabled={!canEdit}
                  onValueChange={setPublicAccessTokenValue}
                  onUnitChange={setPublicAccessTokenUnit}
                />
                <DurationField
                  id="oauth2-public-refresh-token-duration"
                  label={t('Refresh token TTL')}
                  hint={t('Default: 30 days when empty.')}
                  value={publicRefreshTokenValue}
                  unit={publicRefreshTokenUnit}
                  placeholder="30"
                  disabled={!canEdit}
                  onValueChange={setPublicRefreshTokenValue}
                  onUnitChange={setPublicRefreshTokenUnit}
                />
                <Collapsible>
                  <CollapsibleTrigger className="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground hover:text-foreground">
                    <ChevronDown className="h-3.5 w-3.5" />
                    {t('Example use cases')}
                  </CollapsibleTrigger>
                  <CollapsibleContent>
                    <ExampleList items={PUBLIC_EXAMPLES} />
                  </CollapsibleContent>
                </Collapsible>
                </ClientTokenSettingsColumn>
              </div>
            </div>
          </SettingsSection>
        </>
      ) : null}
    </div>
  )
}
