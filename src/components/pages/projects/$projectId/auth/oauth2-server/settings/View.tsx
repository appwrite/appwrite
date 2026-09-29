import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Check, ChevronDown, Copy, Info } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import {
  OAUTH2_SERVER_TIME_UNIT_OPTIONS,
  isOAuth2DurationWithin,
  oauth2DurationFromSeconds,
  oauth2DurationInputToSeconds,
  type OAuth2ServerDurationInput,
  type OAuth2ServerTimeUnit,
} from '@/lib/oauth2-server/duration'
import {
  OAUTH2_SERVER_DEVICE_CODE_MAX_EXPIRY,
  OAUTH2_SERVER_DEVICE_CODE_MIN_EXPIRY,
  OAUTH2_SERVER_MAX_TOKEN_EXPIRY,
  OAUTH2_SERVER_MIN_TOKEN_EXPIRY,
  OAUTH2_SERVER_USER_CODE_FORMAT_OPTIONS,
  OAUTH2_SERVER_USER_CODE_MAX_LENGTH,
  OAUTH2_SERVER_USER_CODE_MIN_LENGTH,
  isOAuth2ListWithinLimits,
  normalizeOAuth2UserCodeFormat,
  type OAuth2ServerUserCodeFormat,
} from '@/lib/oauth2-server/constants'
import {
  getOAuth2ServerDiscoveryUrl,
  getOAuth2ServerEndpointUrl,
  getOAuth2ServerMetadataUrl,
  OAUTH2_SERVER_COMMON_ENDPOINTS,
} from '@/lib/oauth2-server/discovery'
import {
  mergeOAuth2Scopes,
  optionalOAuth2Scopes,
  REQUIRED_OAUTH2_SCOPES,
} from '@/lib/oauth2-server/scopes'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  projectQueryOptions,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canShowProjectOAuth2Server } from '@/lib/console-access-checks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { InputTags } from '@/components/ui/input-tags'
import { OAuth2ScopePicker } from '@/components/global/shared/OAuth2ScopePicker'
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

type OAuth2ServerFormState = {
  enabled: boolean
  authorizationUrl: string
  scopes: string[]
  defaultScopes: string[]
  authorizationDetailsTypes: string[]
  accessToken: OAuth2ServerDurationInput
  refreshToken: OAuth2ServerDurationInput
  publicAccessToken: OAuth2ServerDurationInput
  publicRefreshToken: OAuth2ServerDurationInput
  confidentialPkce: boolean
  verificationUrl: string
  userCodeLength: number | null
  userCodeFormat: OAuth2ServerUserCodeFormat
  deviceCode: OAuth2ServerDurationInput
  installationScopes: string[]
  installationAccessToken: OAuth2ServerDurationInput
}

type DurationFieldKey =
  | 'accessToken'
  | 'refreshToken'
  | 'publicAccessToken'
  | 'publicRefreshToken'
  | 'deviceCode'
  | 'installationAccessToken'

type OAuth2ServerSection =
  | 'status'
  | 'integration'
  | 'tokens'
  | 'device'
  | 'installations'

/** Which form fields each card owns; a card's Update only writes these. */
const SECTION_FIELDS: Record<
  OAuth2ServerSection,
  ReadonlyArray<keyof OAuth2ServerFormState>
> = {
  status: ['enabled'],
  integration: [
    'authorizationUrl',
    'scopes',
    'defaultScopes',
    'authorizationDetailsTypes',
  ],
  tokens: [
    'accessToken',
    'refreshToken',
    'publicAccessToken',
    'publicRefreshToken',
    'confidentialPkce',
  ],
  device: ['verificationUrl', 'userCodeLength', 'userCodeFormat', 'deviceCode'],
  installations: ['installationScopes', 'installationAccessToken'],
}

/** Mirrors the `updateOAuth2Server` object parameter. */
type OAuth2ServerUpdatePayload = {
  enabled: boolean
  authorizationUrl: string
  scopes: string[]
  authorizationDetailsTypes: string[]
  accessTokenDuration?: number
  refreshTokenDuration?: number
  publicAccessTokenDuration?: number
  publicRefreshTokenDuration?: number
  installationAccessTokenDuration?: number
  confidentialPkce: boolean
  verificationUrl: string
  userCodeLength?: number
  userCodeFormat: string
  deviceCodeDuration?: number
  defaultScopes: string[]
  installationScopes: string[]
}

function formStateFromProject(project: Models.Project): OAuth2ServerFormState {
  return {
    enabled: project.oAuth2ServerEnabled ?? false,
    authorizationUrl: project.oAuth2ServerAuthorizationUrl ?? '',
    scopes: mergeOAuth2Scopes(project.oAuth2ServerScopes ?? []),
    defaultScopes: project.oAuth2ServerDefaultScopes ?? [],
    authorizationDetailsTypes:
      project.oAuth2ServerAuthorizationDetailsTypes ?? [],
    accessToken: oauth2DurationFromSeconds(
      project.oAuth2ServerAccessTokenDuration ?? null,
      'hours',
    ),
    refreshToken: oauth2DurationFromSeconds(
      project.oAuth2ServerRefreshTokenDuration ?? null,
      'days',
    ),
    publicAccessToken: oauth2DurationFromSeconds(
      project.oAuth2ServerPublicAccessTokenDuration ?? null,
      'hours',
    ),
    publicRefreshToken: oauth2DurationFromSeconds(
      project.oAuth2ServerPublicRefreshTokenDuration ?? null,
      'days',
    ),
    confidentialPkce: project.oAuth2ServerConfidentialPkce ?? false,
    verificationUrl: project.oAuth2ServerVerificationUrl ?? '',
    userCodeLength: project.oAuth2ServerUserCodeLength ?? null,
    userCodeFormat: normalizeOAuth2UserCodeFormat(
      project.oAuth2ServerUserCodeFormat,
    ),
    deviceCode: oauth2DurationFromSeconds(
      project.oAuth2ServerDeviceCodeDuration ?? null,
      'minutes',
    ),
    installationScopes: project.oAuth2ServerInstallationScopes ?? [],
    installationAccessToken: oauth2DurationFromSeconds(
      project.oAuth2ServerInstallationAccessTokenDuration ?? null,
      'hours',
    ),
  }
}

function pickSection(
  state: OAuth2ServerFormState,
  section: OAuth2ServerSection,
): Partial<OAuth2ServerFormState> {
  const picked: Record<string, unknown> = {}
  for (const field of SECTION_FIELDS[section]) {
    picked[field] = state[field]
  }
  return picked as Partial<OAuth2ServerFormState>
}

function isDurationInput(value: unknown): value is OAuth2ServerDurationInput {
  return typeof value === 'object' && value !== null && 'unit' in value
}

/** Comparable snapshot of a card's fields: durations as seconds, strings trimmed. */
function sectionSnapshot(
  state: OAuth2ServerFormState,
  section: OAuth2ServerSection,
): string {
  const values = SECTION_FIELDS[section].map((field) => {
    const value = state[field]
    if (field === 'scopes') return mergeOAuth2Scopes(value as string[])
    if (isDurationInput(value)) return oauth2DurationInputToSeconds(value)
    if (typeof value === 'string') return value.trim()
    return value
  })
  return JSON.stringify(values)
}

/**
 * The API replaces the whole configuration on every call and resets any
 * omitted parameter to its default, so every field is always sent: the
 * saved card's values come from the form, everything else from the server.
 */
function buildUpdatePayload(
  server: OAuth2ServerFormState,
  form: OAuth2ServerFormState,
  section: OAuth2ServerSection,
): OAuth2ServerUpdatePayload {
  const merged: OAuth2ServerFormState = {
    ...server,
    ...pickSection(form, section),
    // The authorization URL is required by the API whenever the server is
    // saved, so the form value wins for every card (see the status hint).
    authorizationUrl: form.authorizationUrl,
  }
  const scopes = mergeOAuth2Scopes(merged.scopes)
  const seconds = (input: OAuth2ServerDurationInput) =>
    oauth2DurationInputToSeconds(input) ?? undefined

  return {
    enabled: merged.enabled,
    authorizationUrl: merged.authorizationUrl.trim(),
    scopes,
    defaultScopes: merged.defaultScopes.filter((scope) =>
      scopes.includes(scope),
    ),
    authorizationDetailsTypes: merged.authorizationDetailsTypes,
    accessTokenDuration: seconds(merged.accessToken),
    refreshTokenDuration: seconds(merged.refreshToken),
    publicAccessTokenDuration: seconds(merged.publicAccessToken),
    publicRefreshTokenDuration: seconds(merged.publicRefreshToken),
    installationAccessTokenDuration: seconds(merged.installationAccessToken),
    confidentialPkce: merged.confidentialPkce,
    verificationUrl: merged.verificationUrl.trim(),
    userCodeLength: merged.userCodeLength ?? undefined,
    userCodeFormat: merged.userCodeFormat,
    deviceCodeDuration: seconds(merged.deviceCode),
    installationScopes: merged.installationScopes,
  }
}

function SectionUpdateButton({
  disabled,
  pending,
  disabledTooltip,
  onClick,
}: {
  disabled?: boolean
  pending?: boolean
  disabledTooltip?: string
  onClick: () => void
}) {
  const t = useT()
  const button = (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      disabled={disabled || pending}
      onClick={onClick}
    >
      {t('Update')}
    </Button>
  )

  if (!disabled || pending || !disabledTooltip) {
    return button
  }

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">{button}</span>
        </TooltipTrigger>
        <TooltipContent>
          <p>{disabledTooltip}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
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
            <h3 className="text-[15px] font-semibold text-foreground">
              {title}
            </h3>
            {description ? (
              <p className="mt-2 text-[13px] text-muted-foreground">
                {description}
              </p>
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
  labelExtra,
}: {
  value: string
  label?: string
  labelExtra?: ReactNode
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
        <div className="flex flex-wrap items-center gap-2">
          <Label className="text-[12px] font-medium text-muted-foreground">
            {label}
          </Label>
          {labelExtra}
        </div>
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
  deviceFlowEnabled,
}: {
  projectId: string
  region?: string
  deviceFlowEnabled: boolean
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
              'Endpoints from the discovery document. Most OAuth libraries only need the discovery URL.',
            )}
          </p>
          <CopyableUrl
            label={t('OAuth authorization server metadata URL')}
            value={getOAuth2ServerMetadataUrl(projectId, region)}
          />
          {OAUTH2_SERVER_COMMON_ENDPOINTS.map((endpoint) => (
            <CopyableUrl
              key={endpoint.id}
              label={t(endpoint.label)}
              labelExtra={
                endpoint.requiresDeviceFlow && !deviceFlowEnabled ? (
                  <Badge variant="inactive" className="text-[10px] shrink-0">
                    {t('Requires device flow')}
                  </Badge>
                ) : null
              }
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
  const savedSectionRef = useRef<OAuth2ServerSection | null>(null)

  const serverState = useMemo(
    () => (projectData ? formStateFromProject(projectData) : null),
    [projectData],
  )
  // Lazy init so the first paint already shows the loader-prefetched project.
  const [form, setForm] = useState<OAuth2ServerFormState | null>(
    () => serverState,
  )
  const syncedStateRef = useRef(serverState)

  useEffect(() => {
    if (!serverState || syncedStateRef.current === serverState) return
    syncedStateRef.current = serverState
    const savedSection = savedSectionRef.current
    savedSectionRef.current = null
    setForm((previous) => {
      // After a card is saved only its fields are synced from the server, so
      // unsaved edits in the other cards survive the project refetch.
      if (previous && savedSection) {
        return { ...previous, ...pickSection(serverState, savedSection) }
      }
      return serverState
    })
  }, [serverState])

  const discoveryUrl = useMemo(
    () => getOAuth2ServerDiscoveryUrl(projectId, project?.region),
    [projectId, project?.region],
  )

  const updateMutation = useMutation({
    mutationFn: async ({
      section,
      payload,
    }: {
      section: OAuth2ServerSection
      payload: OAuth2ServerUpdatePayload
    }) => {
      const response = await sdk
        .forProject(projectId, project?.region)
        .project.updateOAuth2Server(payload)
      return { section, response }
    },
    onSuccess: ({ section, response }) => {
      savedSectionRef.current = section
      queryClient.setQueryData(
        projectQueryOptions(projectId).queryKey,
        response,
      )
      const message =
        section === 'status'
          ? t('Server status has been updated.')
          : section === 'integration'
            ? t('Integration settings have been updated.')
            : section === 'tokens'
              ? t('Token lifetimes have been updated.')
              : section === 'device'
                ? t('Device flow settings have been updated.')
                : t('App installation settings have been updated.')
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

  if (!form || !serverState) {
    return null
  }

  const patch = (changes: Partial<OAuth2ServerFormState>) =>
    setForm((previous) => (previous ? { ...previous, ...changes } : previous))

  const durationProps = (field: DurationFieldKey) => ({
    value: form[field].value,
    unit: form[field].unit,
    onValueChange: (value: number | null) =>
      patch({
        [field]: { ...form[field], value },
      } as Partial<OAuth2ServerFormState>),
    onUnitChange: (unit: OAuth2ServerTimeUnit) =>
      patch({
        [field]: { ...form[field], unit },
      } as Partial<OAuth2ServerFormState>),
  })

  const isDirty = (section: OAuth2ServerSection) =>
    sectionSnapshot(form, section) !== sectionSnapshot(serverState, section)

  const mergedScopes = mergeOAuth2Scopes(form.scopes)
  const optionalScopes = optionalOAuth2Scopes(form.scopes)
  const requiresAuthorizationUrl = form.enabled && !form.authorizationUrl.trim()
  const deviceFlowEnabled = serverState.verificationUrl.trim() !== ''
  const selectedUserCodeFormat =
    OAUTH2_SERVER_USER_CODE_FORMAT_OPTIONS.find(
      (option) => option.value === form.userCodeFormat,
    ) ?? OAUTH2_SERVER_USER_CODE_FORMAT_OPTIONS[0]

  const tokenWithinLimits = (input: OAuth2ServerDurationInput) =>
    isOAuth2DurationWithin(
      oauth2DurationInputToSeconds(input),
      OAUTH2_SERVER_MIN_TOKEN_EXPIRY,
      OAUTH2_SERVER_MAX_TOKEN_EXPIRY,
    )

  /** Validation mirrors the API so users get the message before the request. */
  const validateSection = (section: OAuth2ServerSection): string | null => {
    if (requiresAuthorizationUrl) {
      return t('Authorization URL is required when the server is enabled.')
    }
    switch (section) {
      case 'integration': {
        if (!isOAuth2ListWithinLimits(mergedScopes)) {
          return t(
            'Up to 100 scopes are allowed, each 128 characters or fewer.',
          )
        }
        if (!isOAuth2ListWithinLimits(form.authorizationDetailsTypes)) {
          return t(
            'Up to 100 authorization details types are allowed, each 128 characters or fewer.',
          )
        }
        return null
      }
      case 'tokens': {
        const withinLimits = [
          form.accessToken,
          form.refreshToken,
          form.publicAccessToken,
          form.publicRefreshToken,
        ].every(tokenWithinLimits)
        return withinLimits
          ? null
          : t('Token lifetimes must be between 1 minute and 1 year.')
      }
      case 'device': {
        if (
          form.userCodeLength != null &&
          (!Number.isInteger(form.userCodeLength) ||
            form.userCodeLength < OAUTH2_SERVER_USER_CODE_MIN_LENGTH ||
            form.userCodeLength > OAUTH2_SERVER_USER_CODE_MAX_LENGTH)
        ) {
          return t('User code length must be between 6 and 12 characters.')
        }
        if (
          !isOAuth2DurationWithin(
            oauth2DurationInputToSeconds(form.deviceCode),
            OAUTH2_SERVER_DEVICE_CODE_MIN_EXPIRY,
            OAUTH2_SERVER_DEVICE_CODE_MAX_EXPIRY,
          )
        ) {
          return t('Device code lifetime must be between 1 and 30 minutes.')
        }
        return null
      }
      case 'installations': {
        if (!isOAuth2ListWithinLimits(form.installationScopes)) {
          return t(
            'Up to 100 installation scopes are allowed, each 128 characters or fewer.',
          )
        }
        if (!tokenWithinLimits(form.installationAccessToken)) {
          return t(
            'Installation token lifetime must be between 1 minute and 1 year.',
          )
        }
        return null
      }
      default:
        return null
    }
  }

  const handleUpdate = (section: OAuth2ServerSection) => {
    const error = validateSection(section)
    if (error) {
      toast.error(error)
      if (requiresAuthorizationUrl) {
        document.getElementById('oauth2-authorization-url')?.focus()
      }
      return
    }
    setPendingSection(section)
    updateMutation.mutate({
      section,
      payload: buildUpdatePayload(serverState, form, section),
    })
  }

  const noEditPermissionTooltip = !canEdit
    ? t("You don't have permission to update OAuth2 server settings.")
    : undefined
  const missingAuthorizationUrlTooltip = requiresAuthorizationUrl
    ? t('Authorization URL is required when the server is enabled.')
    : undefined

  const sectionFooter = (section: OAuth2ServerSection) => (
    <SectionUpdateButton
      pending={updateMutation.isPending && pendingSection === section}
      disabled={!canEdit || !isDirty(section) || requiresAuthorizationUrl}
      disabledTooltip={
        noEditPermissionTooltip ?? missingAuthorizationUrlTooltip
      }
      onClick={() => handleUpdate(section)}
    />
  )

  return (
    <div className="space-y-6">
      <SettingsSection
        title={t('Server status')}
        description={t(
          'Let external apps authenticate users through this project. They register as OAuth clients, use your user directory, and receive tokens you issue.',
        )}
        headerExtra={
          <Badge
            variant={serverState.enabled ? 'success' : 'inactive'}
            className="shrink-0 text-[10px] uppercase tracking-wide"
          >
            {serverState.enabled ? t('Active') : t('Inactive')}
          </Badge>
        }
        footer={
          <SectionUpdateButton
            pending={updateMutation.isPending && pendingSection === 'status'}
            disabled={!canEdit || !isDirty('status')}
            disabledTooltip={noEditPermissionTooltip}
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
            checked={form.enabled}
            disabled={!canEdit}
            onCheckedChange={(enabled) => patch({ enabled })}
          />
        </div>
        {requiresAuthorizationUrl ? (
          <p className="text-[12px] text-muted-foreground">
            {t('Set an authorization URL below, then click Update.')}
          </p>
        ) : null}
      </SettingsSection>

      {form.enabled ? (
        <>
          <SettingsSection
            title={t('Integration')}
            description={t(
              'Point your consent screen at the authorization URL and choose which scopes clients can request.',
            )}
            footer={sectionFooter('integration')}
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
                value={form.authorizationUrl}
                disabled={!canEdit}
                placeholder="https://example.com/consent"
                onChange={(event) =>
                  patch({ authorizationUrl: event.target.value })
                }
                className="h-9 font-mono text-[13px]"
              />
            </div>

            <div className="space-y-2">
              <FieldHint
                label={t('Scopes')}
                hint={t(
                  'openid, profile, email, and phone are always included. Add up to 100 scopes in total, each up to 128 characters.',
                )}
              />
              <InputTags
                id="oauth2-scopes"
                value={optionalScopes}
                lockedTags={[...REQUIRED_OAUTH2_SCOPES]}
                disabled={!canEdit}
                splitOnComma
                placeholder={t('Add custom scopes')}
                onChange={(next) => patch({ scopes: mergeOAuth2Scopes(next) })}
              />
            </div>

            <div className="space-y-2">
              <FieldHint
                label={t('Default scopes')}
                hint={t(
                  'Granted when an authorization request omits the scope parameter. Leave empty to require clients to request scopes explicitly.',
                )}
              />
              <OAuth2ScopePicker
                idPrefix="oauth2-default-scope"
                className="max-h-72 overflow-y-auto"
                options={mergedScopes.map((value) => ({ value }))}
                value={form.defaultScopes}
                onChange={(defaultScopes) => patch({ defaultScopes })}
                disabled={!canEdit}
              />
            </div>

            <div className="space-y-2">
              <FieldHint
                label={t('Authorization details types')}
                hint={t(
                  'Types accepted in RFC 9396 authorization_details requests. Leave empty to reject rich authorization requests.',
                )}
              />
              <InputTags
                id="oauth2-authorization-details-types"
                value={form.authorizationDetailsTypes}
                disabled={!canEdit}
                splitOnComma
                placeholder={t('Add a type and press Enter')}
                onChange={(authorizationDetailsTypes) =>
                  patch({ authorizationDetailsTypes })
                }
              />
            </div>
          </SettingsSection>

          <SettingsSection
            title={t('OIDC discovery')}
            description={t(
              'Share this URL with integrators. OAuth libraries fetch it once to learn authorize, token, and JWKS endpoints.',
            )}
          >
            <CopyableUrl label={t('OIDC discovery URL')} value={discoveryUrl} />
            <DiscoveryEndpoints
              projectId={projectId}
              region={project?.region}
              deviceFlowEnabled={deviceFlowEnabled}
            />
          </SettingsSection>

          <SettingsSection
            title={t('Token lifetimes')}
            description={t(
              'Confidential clients use a client secret on a backend. Public clients (SPAs, mobile) use PKCE only.',
            )}
            footer={sectionFooter('tokens')}
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
                    placeholder="8"
                    disabled={!canEdit}
                    {...durationProps('accessToken')}
                  />
                  <DurationField
                    id="oauth2-refresh-token-duration"
                    label={t('Refresh token TTL')}
                    hint={t('Default: 365 days when empty.')}
                    placeholder="365"
                    disabled={!canEdit}
                    {...durationProps('refreshToken')}
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
                      checked={form.confidentialPkce}
                      disabled={!canEdit}
                      onCheckedChange={(confidentialPkce) =>
                        patch({ confidentialPkce })
                      }
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
                    placeholder="1"
                    disabled={!canEdit}
                    {...durationProps('publicAccessToken')}
                  />
                  <DurationField
                    id="oauth2-public-refresh-token-duration"
                    label={t('Refresh token TTL')}
                    hint={t('Default: 30 days when empty.')}
                    placeholder="30"
                    disabled={!canEdit}
                    {...durationProps('publicRefreshToken')}
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

          <SettingsSection
            title={t('Device flow')}
            description={t(
              'Lets TVs, CLIs, and other input-constrained clients sign in with a short code. Each app must also have Device flow enabled.',
            )}
            headerExtra={
              <Badge
                variant={deviceFlowEnabled ? 'success' : 'inactive'}
                className="shrink-0 text-[10px] uppercase tracking-wide"
              >
                {deviceFlowEnabled ? t('Enabled') : t('Disabled')}
              </Badge>
            }
            footer={sectionFooter('device')}
          >
            <div className="space-y-2">
              <FieldHint
                label={t('Verification URL')}
                hint={t(
                  'Page in your app where users enter the code shown on the device. Leave empty to keep the device authorization grant off.',
                )}
              />
              <Input
                id="oauth2-verification-url"
                value={form.verificationUrl}
                disabled={!canEdit}
                placeholder="https://example.com/device"
                onChange={(event) =>
                  patch({ verificationUrl: event.target.value })
                }
                className="h-9 font-mono text-[13px]"
              />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label
                  htmlFor="oauth2-user-code-format"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('User code format')}
                </Label>
                <Select
                  value={form.userCodeFormat}
                  onValueChange={(next) =>
                    patch({
                      userCodeFormat: normalizeOAuth2UserCodeFormat(next),
                    })
                  }
                  disabled={!canEdit}
                >
                  <SelectTrigger
                    id="oauth2-user-code-format"
                    className="h-9 text-[13px]"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {OAUTH2_SERVER_USER_CODE_FORMAT_OPTIONS.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {t(option.label)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-[12px] text-muted-foreground">
                  {t(selectedUserCodeFormat.hint)}
                </p>
              </div>

              <div className="space-y-2">
                <Label
                  htmlFor="oauth2-user-code-length"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('User code length')}
                </Label>
                <Input
                  id="oauth2-user-code-length"
                  type="number"
                  min={OAUTH2_SERVER_USER_CODE_MIN_LENGTH}
                  max={OAUTH2_SERVER_USER_CODE_MAX_LENGTH}
                  value={form.userCodeLength ?? ''}
                  placeholder="8"
                  disabled={!canEdit}
                  onChange={(event) => {
                    const next = event.target.value.trim()
                    patch({ userCodeLength: next === '' ? null : Number(next) })
                  }}
                  className="h-9 font-mono text-[13px] tabular-nums"
                />
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    '6 to 12 characters, excluding the separator. Default: 8 when empty.',
                  )}
                </p>
              </div>
            </div>

            <DurationField
              id="oauth2-device-code-duration"
              label={t('Device code lifetime')}
              hint={t('1 to 30 minutes. Default: 10 minutes when empty.')}
              placeholder="10"
              disabled={!canEdit}
              {...durationProps('deviceCode')}
            />
          </SettingsSection>

          <SettingsSection
            title={t('App installations')}
            description={t(
              'Apps can be installed on teams in this project to act on their behalf. Choose which scopes an installed app may request and how long installation access tokens live.',
            )}
            footer={sectionFooter('installations')}
          >
            <div className="space-y-2">
              <FieldHint
                label={t('Installation scopes')}
                hint={t(
                  'Scopes an app may request when it is installed on a team. Leave empty to disallow installations.',
                )}
              />
              <InputTags
                id="oauth2-installation-scopes"
                value={form.installationScopes}
                disabled={!canEdit}
                splitOnComma
                placeholder={t('Add installation scope and press Enter')}
                onChange={(installationScopes) => patch({ installationScopes })}
              />
            </div>

            <DurationField
              id="oauth2-installation-access-token-duration"
              label={t('Installation access token TTL')}
              hint={t('Default: 1 hour when empty.')}
              placeholder="1"
              disabled={!canEdit}
              {...durationProps('installationAccessToken')}
            />
          </SettingsSection>
        </>
      ) : null}
    </div>
  )
}
