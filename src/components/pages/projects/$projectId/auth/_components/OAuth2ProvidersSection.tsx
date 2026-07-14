import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type Dispatch,
  type SetStateAction,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { OAuthProvider, type Models } from '@appwrite.io/console'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { AppwriteProviderSetup } from './AppwriteProviderSetup'
import { OAuth2ProviderHelpers } from './OAuth2ProviderHelpers'
import {
  projectQueryOptions,
  useConsoleOAuth2Catalog,
  useProjectOAuth2Providers,
  useUpdateProjectOAuth2Provider,
} from '@/lib/react-query/hooks'
import type { AuthOAuth2SettingsInitialData } from '@/lib/react-query/hooks/oauth2-providers'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME,
  RESOURCE_CARD_SHELL_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { cn } from '@/lib/utils'
import {
  getOAuth2ProviderFieldErrors,
  hasOAuth2ProviderFieldErrors,
  isOAuth2ParameterAlwaysOptional,
  isOAuth2ParameterOptionalInForm,
  isOAuth2ParameterRequiredWhenEnabling,
  isOAuth2SecretParameter,
  isOidcManualDiscoveryParam,
  OIDC_MANUAL_DISCOVERY_PARAM_IDS,
  OIDC_WELL_KNOWN_PARAM_ID,
} from '@/lib/oauth2/provider-field-requirements'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { canUpdateProjectOAuth2Provider } from '@/lib/oauth2/update-project-oauth2'
import {
  getOAuth2ProviderDisplayName,
  getOAuth2ProviderIconPath,
  OAUTH2_POPULAR_PROVIDER_IDS,
} from '@/lib/oauth2/provider-display'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Check,
  ChevronDown,
  Copy,
  FileKey,
  FileUp,
  Search,
  X,
} from 'lucide-react'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'

type OAuth2ProviderRow = Models.ConsoleOAuth2Provider

/** Catalog example is one line; PEM placeholders should show real line breaks. */
const APPLE_P8_FILE_PLACEHOLDER = `-----BEGIN PRIVATE KEY-----
MIGTAg...jy2Xbna
-----END PRIVATE KEY-----`

const APPLE_P8_MAX_FILE_BYTES = 64 * 1024

const OIDC_WELL_KNOWN_HINT =
  'URL of the OpenID Provider metadata document (/.well-known/openid-configuration). Appwrite loads authorization, token, and userinfo endpoints from the JSON response.'

type CatalogParameter = OAuth2ProviderRow['parameters'][number]

type OAuth2ParameterFieldProps = {
  param: CatalogParameter
  providerId: string
  formFields: Record<string, string>
  setFormFields: Dispatch<SetStateAction<Record<string, string>>>
  initialEnabled: boolean
  formEnabled: boolean
  validationTouched: boolean
  formFieldErrors: Record<string, string>
  fieldsDisabled: boolean
  hintOverride?: string
}

type AppleP8KeyFieldProps = {
  param: CatalogParameter
  value: string
  onChange: (value: string) => void
  showOptional: boolean
  showRequired: boolean
  fieldError?: string
  disabled: boolean
  hint?: string
}

function AppleP8KeyField({
  param,
  value,
  onChange,
  showOptional,
  showRequired,
  fieldError,
  disabled,
  hint,
}: AppleP8KeyFieldProps) {
  const t = useT()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [uploadError, setUploadError] = useState('')
  const [isDragging, setIsDragging] = useState(false)
  const [uploadedFileName, setUploadedFileName] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<'upload' | 'paste'>('upload')

  const hasContent = Boolean(value.trim())

  const clearKey = () => {
    onChange('')
    setUploadedFileName(null)
    setUploadError('')
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const processFile = async (file: File) => {
    setUploadError('')
    if (!file.name.toLowerCase().endsWith('.p8')) {
      setUploadError(t('Select a .p8 file downloaded from Apple Developer.'))
      return
    }
    if (file.size > APPLE_P8_MAX_FILE_BYTES) {
      setUploadError(
        t('File is too large. Private key files are typically under 4 KB.'),
      )
      return
    }
    try {
      const text = (await file.text()).trim()
      if (!text.includes('BEGIN PRIVATE KEY')) {
        setUploadError(t('File does not contain a valid PEM private key.'))
        return
      }
      onChange(text)
      setUploadedFileName(file.name)
      setActiveTab('upload')
    } catch {
      setUploadError(t('Failed to read file. Please try again.'))
    } finally {
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  const handleFileInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) void processFile(file)
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    setIsDragging(false)
    if (disabled) return
    const file = e.dataTransfer.files?.[0]
    if (file) void processFile(file)
  }

  const displayError = fieldError || uploadError

  return (
    <div className="space-y-2">
      <Label htmlFor={`oauth2-${param.$id}`} className="text-[12px] font-medium">
        {param.name}
        {showOptional ? (
          <span className="font-normal text-muted-foreground">
            {' '}
            {t('(optional)')}
          </span>
        ) : showRequired ? (
          <span className="font-normal text-muted-foreground">
            {' '}
            {t('(required)')}
          </span>
        ) : null}
      </Label>

      <Tabs
        value={activeTab}
        onValueChange={(v) => setActiveTab(v as 'upload' | 'paste')}
        className="gap-3"
      >
        <TabsList className="grid h-9 w-full grid-cols-2">
          <TabsTrigger value="upload" className="text-[13px]" disabled={disabled}>
            {t('Upload file')}
          </TabsTrigger>
          <TabsTrigger value="paste" className="text-[13px]" disabled={disabled}>
            {t('Paste key')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="upload" className="mt-0">
          <input
            ref={fileInputRef}
            type="file"
            accept=".p8"
            className="sr-only"
            id={`oauth2-${param.$id}-file`}
            onChange={handleFileInputChange}
            disabled={disabled}
          />
          {hasContent && uploadedFileName ? (
            <div
              className={cn(
                'rounded-lg border border-border bg-muted/30 p-4',
                displayError && 'border-destructive',
              )}
            >
              <div className="flex items-start gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-background border border-border">
                  <FileKey className="h-5 w-5 text-muted-foreground" />
                </div>
                <div className="min-w-0 flex-1 space-y-1">
                  <p className="text-[13px] font-medium text-foreground truncate">
                    {uploadedFileName}
                  </p>
                  <p className="text-[12px] text-muted-foreground">
                    {t(
                      'Private key loaded. Upload another file or paste to replace.',
                    )}
                  </p>
                </div>
                {!disabled ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-8 w-8 shrink-0 p-0 text-muted-foreground"
                    onClick={clearKey}
                    aria-label={t('Remove private key')}
                  >
                    <X className="h-4 w-4" />
                  </Button>
                ) : null}
              </div>
              {!disabled ? (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="mt-3 h-9 text-[13px]"
                  onClick={() => fileInputRef.current?.click()}
                >
                  {t('Replace file')}
                </Button>
              ) : null}
            </div>
          ) : (
            <div
              onDragOver={(e) => {
                e.preventDefault()
                if (!disabled) setIsDragging(true)
              }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={handleDrop}
              className={cn(
                'rounded-lg border-2 border-dashed p-6 text-center transition-colors',
                isDragging
                  ? 'border-primary bg-primary/5'
                  : 'border-border bg-muted/30',
                displayError && 'border-destructive',
                disabled && 'pointer-events-none opacity-60',
              )}
            >
              <FileUp className="mx-auto h-8 w-8 text-muted-foreground" />
              <p className="mt-3 text-[13px] font-medium text-foreground">
                {hasContent
                  ? t('Upload a new .p8 file to replace the current key')
                  : t('Drop your AuthKey .p8 file here')}
              </p>
              <p className="mt-1 text-[12px] text-muted-foreground">
                {t('Download from Apple Developer → Keys → Download')}
              </p>
              {!disabled ? (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="mt-4 h-9 text-[13px]"
                  onClick={() => fileInputRef.current?.click()}
                >
                  {t('Choose .p8 file')}
                </Button>
              ) : null}
              {hasContent && !uploadedFileName ? (
                <p className="mt-3 text-[12px] text-emerald-600 dark:text-emerald-400">
                  {t(
                    'A private key is configured. Upload a file or use Paste key to replace it.',
                  )}
                </p>
              ) : null}
            </div>
          )}
        </TabsContent>

        <TabsContent value="paste" className="mt-0 space-y-2">
          <Textarea
            id={`oauth2-${param.$id}`}
            value={value}
            onChange={(e) => {
              onChange(e.target.value)
              setUploadedFileName(null)
              setUploadError('')
            }}
            placeholder={APPLE_P8_FILE_PLACEHOLDER}
            className={cn(
              'font-mono min-h-[140px] text-[13px]',
              displayError && 'border-destructive',
            )}
            aria-invalid={displayError ? true : undefined}
            autoComplete="off"
            disabled={disabled}
          />
          {hasContent && !disabled ? (
            <div className="flex justify-end">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 text-[12px] text-muted-foreground"
                onClick={clearKey}
              >
                {t('Clear key')}
              </Button>
            </div>
          ) : null}
        </TabsContent>
      </Tabs>

      {displayError ? (
        <p className="text-[12px] text-destructive">{displayError}</p>
      ) : hint ? (
        <p className="text-[11px] text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  )
}

function OAuth2ParameterField({
  param,
  providerId,
  formFields,
  setFormFields,
  initialEnabled,
  formEnabled,
  validationTouched,
  formFieldErrors,
  fieldsDisabled,
  hintOverride,
}: OAuth2ParameterFieldProps) {
  const t = useT()
  const isP8 = param.$id === 'p8File'
  const secretish = isOAuth2SecretParameter(param.$id)
  const Control = isP8 ? Textarea : Input
  const showOptional =
    isOAuth2ParameterAlwaysOptional(param.$id) ||
    isOAuth2ParameterOptionalInForm(providerId, param.$id)
  const showRequired =
    formEnabled &&
    !showOptional &&
    isOAuth2ParameterRequiredWhenEnabling(providerId, param.$id, initialEnabled)
  const fieldError =
    validationTouched && formFieldErrors[param.$id]
      ? formFieldErrors[param.$id]
      : undefined

  if (isP8) {
    return (
      <AppleP8KeyField
        param={param}
        value={formFields[param.$id] ?? ''}
        onChange={(next) =>
          setFormFields((prev) => ({
            ...prev,
            [param.$id]: next,
          }))
        }
        showOptional={showOptional}
        showRequired={showRequired}
        fieldError={fieldError}
        disabled={fieldsDisabled}
        hint={hintOverride ?? param.hint}
      />
    )
  }

  return (
    <div className="space-y-2">
      <Label htmlFor={`oauth2-${param.$id}`} className="text-[12px] font-medium">
        {param.name}
        {showOptional ? (
          <span className="font-normal text-muted-foreground">
            {' '}
            {t('(optional)')}
          </span>
        ) : showRequired ? (
          <span className="font-normal text-muted-foreground">
            {' '}
            {t('(required)')}
          </span>
        ) : null}
      </Label>
      <Control
        id={`oauth2-${param.$id}`}
        value={formFields[param.$id] ?? ''}
        onChange={(e) =>
          setFormFields((prev) => ({
            ...prev,
            [param.$id]: e.target.value,
          }))
        }
        placeholder={param.example || undefined}
        className={cn('text-[13px]', fieldError && 'border-destructive')}
        aria-invalid={fieldError ? true : undefined}
        type={secretish ? 'password' : 'text'}
        autoComplete="off"
        disabled={fieldsDisabled}
      />
      {fieldError ? (
        <p className="text-[12px] text-destructive">{fieldError}</p>
      ) : hintOverride || param.hint ? (
        <p className="text-[11px] text-muted-foreground">
          {hintOverride ?? param.hint}
        </p>
      ) : null}
    </div>
  )
}

type OidcProviderFormFieldsProps = Omit<
  OAuth2ParameterFieldProps,
  'param' | 'providerId' | 'hintOverride'
> & {
  parameters: CatalogParameter[]
  advancedOpen: boolean
  onAdvancedOpenChange: (open: boolean) => void
}

function OidcProviderFormFields({
  parameters,
  advancedOpen,
  onAdvancedOpenChange,
  ...fieldProps
}: OidcProviderFormFieldsProps) {
  const t = useT()
  const wellKnownParam = parameters.find((p) => p.$id === OIDC_WELL_KNOWN_PARAM_ID)
  const credentialParams = parameters.filter(
    (p) => p.$id !== OIDC_WELL_KNOWN_PARAM_ID && !isOidcManualDiscoveryParam(p.$id),
  )
  const advancedParams = parameters.filter((p) =>
    isOidcManualDiscoveryParam(p.$id),
  )
  const wellKnownFilled = Boolean(
    fieldProps.formFields[OIDC_WELL_KNOWN_PARAM_ID]?.trim(),
  )

  return (
    <>
      {credentialParams.map((param) => (
        <OAuth2ParameterField
          key={param.$id}
          param={param}
          providerId="oidc"
          {...fieldProps}
        />
      ))}
      {wellKnownParam ? (
        <OAuth2ParameterField
          key={wellKnownParam.$id}
          param={wellKnownParam}
          providerId="oidc"
          hintOverride={t(OIDC_WELL_KNOWN_HINT)}
          {...fieldProps}
        />
      ) : null}
      {!wellKnownFilled && advancedParams.length > 0 ? (
        <Collapsible open={advancedOpen} onOpenChange={onAdvancedOpenChange}>
          <CollapsibleTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-9 gap-1.5 px-0 text-[13px] text-muted-foreground hover:text-foreground"
            >
              <ChevronDown
                className={cn(
                  'h-4 w-4 transition-transform duration-200',
                  advancedOpen && '-rotate-180',
                )}
              />
              {t('Advanced configuration')}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="space-y-4 pt-1">
            <p className="text-[12px] text-muted-foreground">
              {t(
                'Set authorization, token, and user info URLs manually only when your provider does not expose a well-known metadata URL.',
              )}
            </p>
            {advancedParams.map((param) => (
              <OAuth2ParameterField
                key={param.$id}
                param={param}
                providerId="oidc"
                {...fieldProps}
              />
            ))}
          </CollapsibleContent>
        </Collapsible>
      ) : null}
    </>
  )
}

function OAuth2RedirectUriCard({
  providerName,
  redirectUri,
  autoRegistered = false,
}: {
  providerName: string
  redirectUri: string
  /** When true, the URI is already registered via quick setup (e.g. Appwrite provider). */
  autoRegistered?: boolean
}) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    const ok = await copyToClipboard('Redirect URI', redirectUri, {
      showToast: true,
    })
    if (ok) {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    }
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Redirect URI')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {autoRegistered
            ? t(
                'Quick setup registers this callback URL on your Appwrite app automatically. Verify it matches if you configure credentials manually.',
              )
            : (
              <>
                {t('Register this callback URL in the')} {providerName}{' '}
                {t(
                  'developer console so OAuth sign-in can return to this project.',
                )}
              </>
            )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        {!autoRegistered ? (
          <ol className="text-[13px] text-muted-foreground space-y-2 list-decimal ps-4 [list-style-position:outside]">
            <li>
              {t('Open your')} {providerName}{' '}
              {t("application in the provider's developer console.")}
            </li>
            <li>
              {t(
                'Find the allowed redirect URIs, callback URLs, or equivalent authorized redirect field.',
              )}
            </li>
            <li>
              {t(
                'Paste the URI below exactly and save. Mismatched URLs will cause sign-in to fail.',
              )}
            </li>
          </ol>
        ) : null}
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <Input
            id="oauth2-redirect-uri"
            value={redirectUri}
            readOnly
            disabled
            className="font-mono text-[13px] flex-1 cursor-default opacity-100 disabled:opacity-100"
            aria-label={t('Redirect URI')}
          />
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px] shrink-0"
            onClick={() => void handleCopy()}
            disabled={!redirectUri}
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
    </div>
  )
}

function findProjectProviderModel(
  list: Models.OAuth2ProviderList | undefined,
  providerId: string,
): Record<string, unknown> | undefined {
  const hit = list?.providers?.find((p) => p.$id === providerId)
  return hit as Record<string, unknown> | undefined
}

function readStringField(
  model: Record<string, unknown> | undefined,
  key: string,
): string {
  if (!model) return ''
  const v = model[key]
  return typeof v === 'string' ? v : ''
}

export function OAuth2ProvidersSection({
  projectId,
  initialData,
  bare = false,
}: {
  projectId: string
  initialData?: AuthOAuth2SettingsInitialData
  /** When true, render provider UI without the settings card wrapper. */
  bare?: boolean
}) {
  const t = useT()
  const { data: projectData } = useQuery(projectQueryOptions(projectId))
  const projectEndpoint = useMemo(
    () => getApiEndpoint(projectData?.region),
    [projectData?.region],
  )

  const { data: catalog } = useConsoleOAuth2Catalog({
    initialData: initialData?.catalog,
  })
  const { data: providerList } = useProjectOAuth2Providers(projectId, {
    initialData: initialData?.providerList,
  })
  const resolvedProviderList = providerList ?? initialData?.providerList
  const updateMutation = useUpdateProjectOAuth2Provider(projectId)

  const [providerSearch, setProviderSearch] = useState('')
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedProviderId, setSelectedProviderId] = useState<string | null>(
    null,
  )
  const [formEnabled, setFormEnabled] = useState(false)
  const [formFields, setFormFields] = useState<Record<string, string>>({})
  const [initialSnapshot, setInitialSnapshot] = useState<{
    enabled: boolean
    fields: Record<string, string>
  } | null>(null)
  const [providerError, setProviderError] = useState('')
  const [validationTouched, setValidationTouched] = useState(false)
  const [oidcAdvancedOpen, setOidcAdvancedOpen] = useState(false)

  const catalogEntries = useMemo(() => {
    const fromHook = catalog?.oAuth2Providers ?? []
    const raw =
      fromHook.length > 0
        ? fromHook
        : (initialData?.catalog?.oAuth2Providers ?? [])
    return raw.filter((p) => canUpdateProjectOAuth2Provider(p.$id))
  }, [catalog?.oAuth2Providers, initialData?.catalog?.oAuth2Providers])

  const selectedCatalog = useMemo(() => {
    if (!selectedProviderId) return undefined
    return catalogEntries.find((p) => p.$id === selectedProviderId)
  }, [catalogEntries, selectedProviderId])

  const openDrawerFor = useCallback(
    (providerId: string) => {
      if (!canUpdateProjectOAuth2Provider(providerId)) {
        toast.error(
          `${getOAuth2ProviderDisplayName(providerId)} ${t('is not available on this server.')}`,
        )
        return
      }

      const entry = catalogEntries.find((p) => p.$id === providerId)
      const model = findProjectProviderModel(resolvedProviderList, providerId)
      if (!entry) return

      const fields: Record<string, string> = {}
      for (const param of entry.parameters) {
        fields[param.$id] = readStringField(model, param.$id)
      }
      const enabled = typeof model?.enabled === 'boolean' ? model.enabled : false

      setSelectedProviderId(providerId)
      setFormEnabled(enabled)
      setFormFields(fields)
      setInitialSnapshot({ enabled, fields: { ...fields } })
      setProviderError('')
      setValidationTouched(false)
      const wellKnownSet = Boolean(fields[OIDC_WELL_KNOWN_PARAM_ID]?.trim())
      const hasManualDiscovery = OIDC_MANUAL_DISCOVERY_PARAM_IDS.some((id) =>
        Boolean(fields[id]?.trim()),
      )
      setOidcAdvancedOpen(!wellKnownSet && hasManualDiscovery)
      setDrawerOpen(true)
    },
    [catalogEntries, resolvedProviderList, t],
  )

  useEffect(() => {
    if (!drawerOpen) {
      setSelectedProviderId(null)
      setFormEnabled(false)
      setFormFields({})
      setInitialSnapshot(null)
      setProviderError('')
      setValidationTouched(false)
      setOidcAdvancedOpen(false)
    }
  }, [drawerOpen])

  const redirectUri = useMemo(() => {
    if (!selectedProviderId || !projectId) return ''
    return `${projectEndpoint}/account/sessions/oauth2/callback/${selectedProviderId}/${projectId}`
  }, [selectedProviderId, projectId, projectEndpoint])

  const filteredEntries = useMemo(() => {
    const q = providerSearch.trim().toLowerCase()
    if (!q) return catalogEntries
    return catalogEntries.filter((p) => {
      const label = getOAuth2ProviderDisplayName(p.$id).toLowerCase()
      if (label.includes(q) || p.$id.toLowerCase().includes(q)) return true
      return p.parameters.some(
        (param) =>
          param.name.toLowerCase().includes(q) ||
          param.$id.toLowerCase().includes(q),
      )
    })
  }, [catalogEntries, providerSearch])

  const { popularRows, otherRows } = useMemo(() => {
    const popular: OAuth2ProviderRow[] = []
    const other: OAuth2ProviderRow[] = []
    for (const row of filteredEntries) {
      if (OAUTH2_POPULAR_PROVIDER_IDS.has(row.$id)) popular.push(row)
      else other.push(row)
    }
    const sortFn = (a: OAuth2ProviderRow, b: OAuth2ProviderRow) => {
      const aModel = findProjectProviderModel(resolvedProviderList, a.$id)
      const bModel = findProjectProviderModel(resolvedProviderList, b.$id)
      const aEn = Boolean(aModel?.enabled)
      const bEn = Boolean(bModel?.enabled)
      if (aEn !== bEn) return aEn ? -1 : 1
      return getOAuth2ProviderDisplayName(a.$id).localeCompare(
        getOAuth2ProviderDisplayName(b.$id),
      )
    }
    popular.sort(sortFn)
    other.sort(sortFn)
    return { popularRows: popular, otherRows: other }
  }, [filteredEntries, resolvedProviderList])

  const hasProviderChanges = useMemo(() => {
    if (!selectedCatalog || !initialSnapshot) return false
    if (formEnabled !== initialSnapshot.enabled) return true
    for (const p of selectedCatalog.parameters) {
      const cur = formFields[p.$id] ?? ''
      const init = initialSnapshot.fields[p.$id] ?? ''
      if (cur.trim() !== init.trim()) return true
    }
    return false
  }, [selectedCatalog, initialSnapshot, formEnabled, formFields])

  const formFieldErrors = useMemo(() => {
    if (!selectedProviderId || !selectedCatalog || !formEnabled) return {}
    return getOAuth2ProviderFieldErrors({
      providerId: selectedProviderId,
      parameters: selectedCatalog.parameters,
      formEnabled,
      formFields,
      initialEnabled: initialSnapshot?.enabled ?? false,
      initialFields: initialSnapshot?.fields ?? {},
    })
  }, [
    selectedProviderId,
    selectedCatalog,
    formEnabled,
    formFields,
    initialSnapshot,
  ])

  useEffect(() => {
    setProviderError('')
  }, [formEnabled, formFields, selectedProviderId])

  const validateAndSubmit = () => {
    if (!selectedProviderId || !selectedCatalog) return

    const parameters = selectedCatalog.parameters
    const wasEnabled = initialSnapshot?.enabled ?? false

    if (!formEnabled) {
      void submitValues({ enabled: false })
      return
    }

    const fieldErrors = getOAuth2ProviderFieldErrors({
      providerId: selectedProviderId,
      parameters,
      formEnabled,
      formFields,
      initialEnabled: wasEnabled,
      initialFields: initialSnapshot?.fields ?? {},
    })
    if (hasOAuth2ProviderFieldErrors(fieldErrors)) {
      setValidationTouched(true)
      if (
        selectedProviderId === 'oidc' &&
        OIDC_MANUAL_DISCOVERY_PARAM_IDS.some((id) => fieldErrors[id])
      ) {
        setOidcAdvancedOpen(true)
      }
      return
    }

    const wellKnownTrim =
      selectedProviderId === 'oidc'
        ? (formFields[OIDC_WELL_KNOWN_PARAM_ID] ?? '').trim()
        : ''

    const values: Record<string, string | boolean> = { enabled: true }
    for (const p of parameters) {
      if (
        selectedProviderId === 'oidc' &&
        wellKnownTrim &&
        isOidcManualDiscoveryParam(p.$id)
      ) {
        continue
      }
      const raw = (formFields[p.$id] ?? '').trim()
      if (raw) {
        values[p.$id] = raw
      } else if (wasEnabled && isOAuth2SecretParameter(p.$id)) {
        // omit - server keeps existing secret
      }
    }

    void submitValues(values)
  }

  const submitValues = (values: Record<string, string | boolean>) => {
    if (!selectedProviderId) return
    setProviderError('')
    updateMutation.mutate(
      { providerId: selectedProviderId, values },
      {
        onSuccess: () => {
          toast.success(
            `${getOAuth2ProviderDisplayName(selectedProviderId)} ${t('has been updated')}`,
          )
          setDrawerOpen(false)
        },
        onError: (error: unknown) => {
          const message =
            error instanceof Error
              ? error.message
              : t('Failed to update OAuth2 provider')
          setProviderError(message)
        },
      },
    )
  }

  const selectedName = selectedProviderId
    ? getOAuth2ProviderDisplayName(selectedProviderId)
    : ''

  const renderProviderGrid = (rows: OAuth2ProviderRow[]) => (
    <div className={RESOURCE_CARD_GRID_CLASSNAME}>
      {rows.map((row) => {
        const model = findProjectProviderModel(resolvedProviderList, row.$id)
        const enabled = Boolean(model?.enabled)
        return (
          <button
            key={row.$id}
            type="button"
            onClick={() => openDrawerFor(row.$id)}
            className={cn(
              'flex w-full min-w-0 items-center justify-between gap-2 text-start',
              RESOURCE_CARD_PADDED_CLASSNAME,
              RESOURCE_CARD_INTERACTIVE_CLASSNAME,
              RESOURCE_CARD_SHELL_CLASSNAME,
            )}
          >
            <div className="flex min-w-0 items-center gap-2">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                <img
                  src={getOAuth2ProviderIconPath(row.$id)}
                  alt=""
                  className={`h-5 w-5 ${PUBLIC_ICON_MUTED_CLASSES}`}
                  onError={(e) => {
                    const t = e.currentTarget
                    t.src = '/icons/empty.svg'
                  }}
                />
              </div>
              <span className="text-[13px] font-medium text-foreground truncate">
                {getOAuth2ProviderDisplayName(row.$id)}
              </span>
            </div>
            <Badge
              variant={enabled ? 'success' : 'secondary'}
              className="shrink-0 text-[11px]"
            >
              {enabled ? t('enabled') : t('disabled')}
            </Badge>
          </button>
        )
      })}
    </div>
  )

  const providersBody = (
    <>
      <p className="text-[13px] text-muted-foreground mb-4">
        {t(
          'Enable OAuth 2 providers so users can sign in with external accounts. Open a provider to set credentials, control availability for this project, and copy the redirect URI for its developer console.',
        )}
      </p>

      <div className="mb-6">
        <div className="relative">
          <Search className="absolute start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            type="text"
            placeholder={t('Search providers...')}
            value={providerSearch}
            onChange={(e) => setProviderSearch(e.target.value)}
            className="ps-9 h-9 text-[13px]"
          />
        </div>
      </div>

      {popularRows.length > 0 && (
        <div className="mb-6">
          <h4 className="text-[13px] font-medium text-foreground mb-3">
            {t('Popular')}
          </h4>
          {renderProviderGrid(popularRows)}
        </div>
      )}

      {otherRows.length > 0 && (
        <div>
          {popularRows.length > 0 && (
            <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
              <div className="h-px flex-1 bg-border" />
              <span className="font-medium text-foreground/80">
                {t('All providers')}
              </span>
              <div className="h-px flex-1 bg-border" />
            </div>
          )}
          {renderProviderGrid(otherRows)}
        </div>
      )}

      {filteredEntries.length === 0 && (
        <div className="text-center py-8">
          <p className="text-[13px] text-muted-foreground">
            {t('No providers match')}{' '}
            <span className="font-medium text-foreground">{providerSearch}</span>
          </p>
        </div>
      )}
    </>
  )

  return (
    <>
      {bare ? (
        providersBody
      ) : (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('OAuth2 providers')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">{providersBody}</div>
        </div>
      )}

      <BaseDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        title={
          selectedName
            ? `${selectedName} ${t('OAuth2 settings')}`
            : t('OAuth2 settings')
        }
        description={t(
          'Configure OAuth2 provider credentials and redirect URI for this project.',
        )}
        maxWidth="sm:max-w-lg"
        disableAutoFocus
      >
        <>
          <div className="border-t border-border shrink-0" />

          <div className="flex flex-1 flex-col min-h-0">
            <div className="flex-1 overflow-y-auto">
              <div className="px-6 py-6 space-y-5">
                <p className="text-[13px] text-muted-foreground">
                  {selectedProviderId === 'oidc'
                    ? t(
                        'Enter the client ID and secret from your OpenID provider, then the well-known metadata URL. Manual endpoint URLs are only needed under Advanced configuration.',
                      )
                    : selectedProviderId === OAuthProvider.Appwrite
                      ? t(
                          'Create or select an Appwrite app to fill credentials, or enter client ID and secret manually.',
                        )
                      : t(
                          'Use the field labels below as they appear in the provider dashboard when entering client credentials.',
                        )}
                </p>

                <div className="rounded-lg border border-border bg-muted/30 p-4">
                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label
                        htmlFor="oauth2-provider-enabled"
                        className="text-[13px] font-semibold text-foreground"
                      >
                        {formEnabled ? t('Enabled') : t('Disabled')}
                      </Label>
                      <p className="text-[12px] text-muted-foreground">
                        {formEnabled
                          ? t('This provider can be used for new sessions')
                          : t('This provider is turned off for this project')}
                      </p>
                    </div>
                    <Switch
                      id="oauth2-provider-enabled"
                      checked={formEnabled}
                      onCheckedChange={setFormEnabled}
                      disabled={updateMutation.isPending}
                    />
                  </div>
                </div>

                {formEnabled && selectedCatalog && selectedProviderId
                  ? selectedProviderId === 'oidc' ? (
                      <OidcProviderFormFields
                        parameters={selectedCatalog.parameters}
                        formFields={formFields}
                        setFormFields={setFormFields}
                        initialEnabled={initialSnapshot?.enabled ?? false}
                        formEnabled={formEnabled}
                        validationTouched={validationTouched}
                        formFieldErrors={formFieldErrors}
                        fieldsDisabled={updateMutation.isPending}
                        advancedOpen={oidcAdvancedOpen}
                        onAdvancedOpenChange={setOidcAdvancedOpen}
                      />
                    ) : selectedProviderId === OAuthProvider.Appwrite ? (
                      <>
                        <AppwriteProviderSetup
                          organizationId={projectData?.teamId}
                          redirectUri={redirectUri}
                          disabled={updateMutation.isPending}
                          onCredentials={(clientId, clientSecret) => {
                            setFormFields((prev) => ({
                              ...prev,
                              clientId,
                              clientSecret,
                            }))
                          }}
                        />
                        {selectedCatalog.parameters.map((param) => (
                          <OAuth2ParameterField
                            key={param.$id}
                            param={param}
                            providerId={selectedProviderId}
                            formFields={formFields}
                            setFormFields={setFormFields}
                            initialEnabled={initialSnapshot?.enabled ?? false}
                            formEnabled={formEnabled}
                            validationTouched={validationTouched}
                            formFieldErrors={formFieldErrors}
                            fieldsDisabled={updateMutation.isPending}
                          />
                        ))}
                      </>
                    ) : (
                      selectedCatalog.parameters.map((param) => (
                        <OAuth2ParameterField
                          key={param.$id}
                          param={param}
                          providerId={selectedProviderId}
                          formFields={formFields}
                          setFormFields={setFormFields}
                          initialEnabled={initialSnapshot?.enabled ?? false}
                          formEnabled={formEnabled}
                          validationTouched={validationTouched}
                          formFieldErrors={formFieldErrors}
                          fieldsDisabled={updateMutation.isPending}
                        />
                      ))
                    )
                  : null}

                {formEnabled && redirectUri && selectedName ? (
                  <OAuth2RedirectUriCard
                    providerName={selectedName}
                    redirectUri={redirectUri}
                    autoRegistered={
                      selectedProviderId === OAuthProvider.Appwrite
                    }
                  />
                ) : null}

                {formEnabled && selectedProviderId && selectedName ? (
                  <OAuth2ProviderHelpers
                    projectId={projectId}
                    endpoint={projectEndpoint}
                    providerId={selectedProviderId}
                    providerName={selectedName}
                  />
                ) : null}

                {providerError ? (
                  <Alert variant="destructive">
                    <AlertDescription className="text-[13px]">
                      {providerError}
                    </AlertDescription>
                  </Alert>
                ) : null}
              </div>
            </div>

            <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-start">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={validateAndSubmit}
                disabled={updateMutation.isPending || !hasProviderChanges}
              >
                {t('Update')}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setDrawerOpen(false)}
                disabled={updateMutation.isPending}
              >
                {t('Cancel')}
              </Button>
            </div>
          </div>
        </>
      </BaseDrawer>
    </>
  )
}
