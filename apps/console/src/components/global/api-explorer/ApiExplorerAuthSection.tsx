import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { ChevronDown, Eye, EyeOff, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  API_EXPLORER_PILL_CLASS,
} from '@/lib/api-explorer/form-field-type-badge'
import { cn } from '@/lib/utils'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { AuthRequirementDescription } from '@/components/global/shared/AuthRequirementDescription'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { ScopeEditor } from '@/components/global/shared/ScopeEditor'
import { useProjectUsers } from '@/lib/react-query/hooks'
import {
  createEphemeralApiKeyForExplorer,
  getMethodRequiredScopes,
  getScopesMissingFromKey,
  mergeUniqueScopes,
  methodRequiresApiKey,
  methodSupportsServerApiKey,
  methodUsesSessionAuthChoice,
  scopesIncludeRequired,
  type ApiExplorerClientAuthState,
  type ApiExplorerMethod,
  type ApiExplorerProjectPlatform,
  type ApiExplorerServerAuthState,
} from '@/lib/api-explorer'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type ProjectUserOption = {
  $id: string
  name?: string
  email?: string
  phone?: string
}

function buildUserSelectItem(user: ProjectUserOption) {
  const name = user.name?.trim() || ''
  const email = user.email?.trim() || ''
  const phone = user.phone?.trim() || ''
  const id = user.$id

  const label = name || email || phone || id
  const descriptionParts = [
    name && name !== label ? name : '',
    email && email !== label ? email : '',
    phone && phone !== label ? phone : '',
    id !== label ? id : '',
  ].filter(Boolean)

  return {
    value: id,
    label,
    description: descriptionParts.join(' · ') || undefined,
    searchText: [name, email, phone, id].filter(Boolean).join(' '),
  }
}

function scopeSetsEqual(left: string[], right: string[]): boolean {
  if (left.length !== right.length) return false
  const rightSet = new Set(right)
  return left.every((scope) => rightSet.has(scope))
}

function splitMetadataList(value?: string): string[] {
  if (!value?.trim()) return []
  return value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function ScopeRow({
  label,
  children,
}: {
  label: string
  children: ReactNode
}) {
  return (
    <div className="grid gap-1.5 @[480px]/request-panel:grid-cols-[88px_minmax(0,1fr)] @[480px]/request-panel:items-start @[480px]/request-panel:gap-3">
      <span className="text-[12px] text-muted-foreground">{label}</span>
      <div className="min-w-0">{children}</div>
    </div>
  )
}

function KeyInput({
  value,
  onChange,
  readOnly = false,
  placeholder,
}: {
  value: string
  onChange?: (value: string) => void
  readOnly?: boolean
  placeholder?: string
}) {
  const t = useT()
  const [visible, setVisible] = useState(false)

  return (
    <div className="relative">
      <Input
        type={visible ? 'text' : 'password'}
        value={value}
        onChange={
          onChange
            ? (event) => onChange(event.target.value)
            : undefined
        }
        readOnly={readOnly}
        placeholder={placeholder}
        className="h-9 pe-10 font-mono text-[13px]"
        autoComplete="off"
        spellCheck={false}
      />
      <button
        type="button"
        onClick={() => setVisible((current) => !current)}
        className="absolute end-2 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
        aria-label={visible ? t('Hide API key') : t('Show API key')}
      >
        {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
      </button>
    </div>
  )
}

type ApiExplorerAuthSectionProps = {
  projectId: string
  platform: ApiExplorerProjectPlatform
  method: ApiExplorerMethod
  clientAuth: ApiExplorerClientAuthState
  serverAuth: ApiExplorerServerAuthState
  onClientAuthChange: (state: ApiExplorerClientAuthState) => void
  onServerAuthChange: (state: ApiExplorerServerAuthState) => void
}

export function ApiExplorerAuthSection({
  projectId,
  platform,
  method,
  clientAuth,
  serverAuth,
  onClientAuthChange,
  onServerAuthChange,
}: ApiExplorerAuthSectionProps) {
  const t = useT()
  const usesSessionAuth = methodUsesSessionAuthChoice(method, platform)
  const supportsServerApiKey = methodSupportsServerApiKey(method, platform)
  const requiresApiKey = methodRequiresApiKey(method, platform)
  const endpointScopes = useMemo(() => getMethodRequiredScopes(method), [method])
  const requiredScopes = useMemo(
    () => splitMetadataList(method.scope),
    [method.scope],
  )
  const missingKeyScopes = useMemo(
    () =>
      getScopesMissingFromKey(serverAuth.ephemeralKeyScopes, endpointScopes),
    [endpointScopes, serverAuth.ephemeralKeyScopes],
  )
  const keyCoversEndpoint = scopesIncludeRequired(
    serverAuth.ephemeralKeyScopes,
    endpointScopes,
  )
  const draftMatchesEndpoint = scopeSetsEqual(
    serverAuth.ephemeralDraftScopes,
    endpointScopes,
  )
  const [userSearch, setUserSearch] = useState('')
  const [isGeneratingKey, setIsGeneratingKey] = useState(false)
  const [scopesOpen, setScopesOpen] = useState(!draftMatchesEndpoint)

  const { users, isLoading: usersLoading } = useProjectUsers(
    usesSessionAuth ? projectId : null,
    0,
    100,
    userSearch,
  )

  const userItems = useMemo(
    () => users.map((user) => buildUserSelectItem(user)),
    [users],
  )

  const ensureDraftScopesForEphemeral = useCallback(
    (next: ApiExplorerServerAuthState): ApiExplorerServerAuthState => {
      if (next.ephemeralDraftScopes.length > 0 || endpointScopes.length === 0) {
        return next
      }
      return { ...next, ephemeralDraftScopes: endpointScopes }
    },
    [endpointScopes],
  )

  const handleServerModeChange = useCallback(
    (value: string) => {
      if (value !== 'manual' && value !== 'ephemeral') return
      onServerAuthChange(
        ensureDraftScopesForEphemeral({ ...serverAuth, mode: value }),
      )
    },
    [ensureDraftScopesForEphemeral, onServerAuthChange, serverAuth],
  )

  const handleResetDraftToEndpoint = useCallback(() => {
    onServerAuthChange({
      ...serverAuth,
      ephemeralDraftScopes: endpointScopes,
    })
    setScopesOpen(false)
  }, [endpointScopes, onServerAuthChange, serverAuth])

  const handleAddMissingEndpointScopesToDraft = useCallback(() => {
    onServerAuthChange({
      ...serverAuth,
      ephemeralDraftScopes: mergeUniqueScopes(
        serverAuth.ephemeralDraftScopes,
        missingKeyScopes,
      ),
    })
    setScopesOpen(true)
  }, [missingKeyScopes, onServerAuthChange, serverAuth])

  const handleGenerateEphemeralKey = useCallback(async () => {
    const scopes = serverAuth.ephemeralDraftScopes
    if (scopes.length === 0) {
      toast.error(t('Select at least one scope for the ephemeral key.'))
      return
    }

    setIsGeneratingKey(true)
    try {
      const result = await createEphemeralApiKeyForExplorer(projectId, scopes)
      onServerAuthChange({
        ...serverAuth,
        mode: 'ephemeral',
        ephemeralApiKey: result.secret,
        ephemeralKeyScopes: result.scopes,
      })
      toast.success(t('Ephemeral API key generated (expires in 1 hour)'))
    } catch (error: unknown) {
      toast.error(getErrorMessage(error) || t('Failed to generate API key'))
    } finally {
      setIsGeneratingKey(false)
    }
  }, [onServerAuthChange, projectId, serverAuth, t])

  const showServerAuth = supportsServerApiKey
  const hasActiveEphemeralKey = Boolean(serverAuth.ephemeralApiKey)
  const matchEndpointTooltip = draftMatchesEndpoint
    ? t('Draft scopes already match this endpoint.')
    : t('Reset draft scopes to the scopes required by this endpoint.')

  return (
    <div className="@container/request-panel rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="space-y-4 px-4 py-4 sm:px-6">
        <div className="space-y-1">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Authentication')}
          </p>
          <AuthRequirementDescription method={method} platform={platform} />
        </div>

        {requiredScopes.length > 0 && (
          <div className="space-y-2">
            <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Required scopes')}
            </p>
            <div className="flex flex-wrap gap-1.5">
              {requiredScopes.map((scope) => (
                <Badge
                  key={scope}
                  variant="info"
                  className={cn(
                    'text-[10px] font-mono',
                    API_EXPLORER_PILL_CLASS,
                  )}
                >
                  {scope}
                </Badge>
              ))}
            </div>
          </div>
        )}

        <ScopeRow label={t('Project')}>
          <CopyableId id={projectId} size="sm" maxWidth={280} />
        </ScopeRow>

        {usesSessionAuth ? (
          <div className="space-y-3 border-t border-border pt-4">
            <Label className="text-[12px] text-muted-foreground">{t('Act as')}</Label>
            <ToggleGroup
              type="single"
              variant="outline"
              size="sm"
              value={clientAuth.mode}
              onValueChange={(value) => {
                if (value === 'guest' || value === 'user') {
                  onClientAuthChange({ ...clientAuth, mode: value })
                }
              }}
              className="grid w-full max-w-xs grid-cols-2"
              aria-label={t('Session authentication')}
            >
              <ToggleGroupItem
                value="guest"
                className="h-9 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
              >
                {t('Guest')}
              </ToggleGroupItem>
              <ToggleGroupItem
                value="user"
                className="h-9 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
              >
                {t('User')}
              </ToggleGroupItem>
            </ToggleGroup>

            {clientAuth.mode === 'guest' ? (
              <p className="text-[12px] text-muted-foreground">
                {t('No session or JWT is sent on the request.')}
              </p>
            ) : (
              <div className="space-y-2">
                <SearchableSelect
                  value={clientAuth.userId}
                  onValueChange={(userId) =>
                    onClientAuthChange({ ...clientAuth, userId })
                  }
                  items={userItems}
                  placeholder={
                    usersLoading ? t('Loading users…') : t('Select a project user')
                  }
                  searchPlaceholder={t('Search by name, email, phone, or ID...')}
                  emptyMessage={t('No users found')}
                  disabled={usersLoading}
                  onSearchChange={setUserSearch}
                />
                <p className="text-[12px] text-muted-foreground">
                  {t('A JWT is created for this user when you send the request.')}
                </p>
              </div>
            )}
          </div>
        ) : null}

        {showServerAuth && (
          <div className="space-y-4 border-t border-border pt-4">
            <div className="space-y-3">
              <Label className="text-[12px] text-muted-foreground">{t('API key')}</Label>
              <ToggleGroup
                type="single"
                variant="outline"
                size="sm"
                value={serverAuth.mode}
                onValueChange={handleServerModeChange}
                className="grid w-full max-w-xs grid-cols-2"
                aria-label={t('Server API key source')}
              >
                <ToggleGroupItem
                  value="manual"
                  className="h-9 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
                >
                  {t('Manual')}
                </ToggleGroupItem>
                <ToggleGroupItem
                  value="ephemeral"
                  className="h-9 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
                >
                  {t('Ephemeral')}
                </ToggleGroupItem>
              </ToggleGroup>
            </div>

            {serverAuth.mode === 'manual' ? (
              <div className="space-y-3">
                <KeyInput
                  value={serverAuth.manualApiKey}
                  onChange={(manualApiKey) =>
                    onServerAuthChange({ ...serverAuth, manualApiKey })
                  }
                  placeholder={
                    requiresApiKey
                      ? t('Paste a project API key')
                      : t('Paste a project API key (optional)')
                  }
                />
                <p className="text-[12px] text-muted-foreground">
                  {t('Reused across methods. Stored locally in this browser.')}
                </p>
              </div>
            ) : (
              <div className="space-y-4">
                {hasActiveEphemeralKey ? (
                  <div className="space-y-3 rounded-xl border border-border bg-card/50 px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[12px] text-muted-foreground">
                        {t('Active key')}
                      </span>
                      <Badge
                        variant={keyCoversEndpoint ? 'success' : 'warning'}
                        className={cn('text-[10px]', API_EXPLORER_PILL_CLASS)}
                      >
                        {keyCoversEndpoint ? t('Ready') : t('Missing scopes')}
                      </Badge>
                    </div>
                    {!keyCoversEndpoint && missingKeyScopes.length > 0 ? (
                      <div className="flex flex-wrap items-center gap-2 text-[12px]">
                        <span className="text-muted-foreground">
                          {t('Needs')} {missingKeyScopes.join(', ')}.
                        </span>
                        <Button
                          type="button"
                          variant="link"
                          size="sm"
                          className="h-auto p-0 text-[12px]"
                          onClick={handleAddMissingEndpointScopesToDraft}
                        >
                          {t('Add to draft')}
                        </Button>
                      </div>
                    ) : null}

                    <KeyInput value={serverAuth.ephemeralApiKey} readOnly />
                    <p className="text-[12px] text-muted-foreground">
                      {t('Expires in 1 hour. Regenerate after editing scopes below.')}
                    </p>
                  </div>
                ) : null}

                <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                  <Collapsible open={scopesOpen} onOpenChange={setScopesOpen}>
                    <div className="flex items-center gap-3 px-4 py-4 sm:px-6">
                      <div className="min-w-0 flex-1">
                        <p className="text-[13px] font-medium text-foreground">
                          {t('Key scopes')}
                        </p>
                        <p className="text-[12px] text-muted-foreground">
                          {serverAuth.ephemeralDraftScopes.length}{' '}
                          {serverAuth.ephemeralDraftScopes.length === 1
                            ? t('scope')
                            : t('scopes')}{' '}
                          {t('selected for the next key')}
                        </p>
                      </div>
                      {endpointScopes.length > 0 ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="inline-flex shrink-0">
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 shrink-0 border-border bg-transparent px-2 text-[12px] text-muted-foreground hover:bg-accent hover:text-foreground"
                                disabled={draftMatchesEndpoint}
                                onClick={handleResetDraftToEndpoint}
                              >
                                {t('Match endpoint')}
                              </Button>
                            </span>
                          </TooltipTrigger>
                          <TooltipContent
                            side="bottom"
                            sideOffset={6}
                            className="text-[12px] whitespace-nowrap"
                          >
                            {matchEndpointTooltip}
                          </TooltipContent>
                        </Tooltip>
                      ) : null}
                      <CollapsibleTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-8 w-8 shrink-0 border-border bg-transparent p-0 text-muted-foreground hover:bg-accent hover:text-foreground"
                          aria-label={
                            scopesOpen ? t('Collapse key scopes') : t('Expand key scopes')
                          }
                        >
                          <ChevronDown
                            className={cn(
                              'h-4 w-4 transition-transform',
                              scopesOpen && 'rotate-180',
                            )}
                          />
                        </Button>
                      </CollapsibleTrigger>
                    </div>

                    <CollapsibleContent>
                      <div className="border-t border-border" />
                      <div className="px-4 py-4 sm:px-6">
                        <ScopeEditor
                          value={serverAuth.ephemeralDraftScopes}
                          onChange={(ephemeralDraftScopes) =>
                            onServerAuthChange({
                              ...serverAuth,
                              ephemeralDraftScopes,
                            })
                          }
                          disabled={isGeneratingKey}
                        />
                      </div>
                    </CollapsibleContent>
                  </Collapsible>

                  <div className="border-t border-border bg-muted/30 px-4 py-4 sm:px-6">
                    <Button
                      type="button"
                      size="sm"
                      className="h-9 text-[13px]"
                      disabled={
                        isGeneratingKey ||
                        serverAuth.ephemeralDraftScopes.length === 0
                      }
                      onClick={handleGenerateEphemeralKey}
                    >
                      {isGeneratingKey ? (
                        <Loader2 className="me-1.5 h-4 w-4 animate-spin" />
                      ) : null}
                      {hasActiveEphemeralKey ? t('Regenerate key') : t('Generate key')}
                    </Button>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
