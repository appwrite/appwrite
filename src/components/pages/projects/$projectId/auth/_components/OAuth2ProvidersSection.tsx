import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import {
  useProject,
  useConsoleOAuth2Catalog,
  useProjectOAuth2Providers,
  useUpdateProjectOAuth2Provider,
} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import {
  Drawer,
  DrawerContent,
  DrawerDescription,
  DrawerHeader,
  DrawerTitle,
} from '@/components/ui/drawer'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import {
  getOAuth2ProviderDisplayName,
  getOAuth2ProviderIconPath,
  OAUTH2_POPULAR_PROVIDER_IDS,
} from '@/lib/oauth2/provider-display'
import { Loader2, Search } from 'lucide-react'

type OAuth2ProviderRow = Models.ConsoleOAuth2Provider

function isSecretishParameter(paramId: string): boolean {
  return /secret|p8|password|shared|apiSecret|privateKey|secretKey/i.test(paramId)
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

export function OAuth2ProvidersSection({ projectId }: { projectId: string }) {
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  const { data: catalog, isLoading: catalogLoading } = useConsoleOAuth2Catalog()
  const { data: providerList, isLoading: listLoading } =
    useProjectOAuth2Providers(projectId)
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

  const catalogEntries = useMemo(
    () => catalog?.oAuth2Providers ?? [],
    [catalog?.oAuth2Providers],
  )

  const selectedCatalog = useMemo(() => {
    if (!selectedProviderId) return undefined
    return catalogEntries.find((p) => p.$id === selectedProviderId)
  }, [catalogEntries, selectedProviderId])

  const openDrawerFor = useCallback(
    (providerId: string) => {
      const entry = catalogEntries.find((p) => p.$id === providerId)
      const model = findProjectProviderModel(providerList, providerId)
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
      setDrawerOpen(true)
    },
    [catalogEntries, providerList],
  )

  useEffect(() => {
    if (!drawerOpen) {
      setSelectedProviderId(null)
      setFormEnabled(false)
      setFormFields({})
      setInitialSnapshot(null)
      setProviderError('')
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
      const aModel = findProjectProviderModel(providerList, a.$id)
      const bModel = findProjectProviderModel(providerList, b.$id)
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
  }, [filteredEntries, providerList])

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

  const validateAndSubmit = () => {
    if (!selectedProviderId || !selectedCatalog) return

    const parameters = selectedCatalog.parameters
    const wasEnabled = initialSnapshot?.enabled ?? false

    if (!formEnabled) {
      void submitValues({ enabled: false })
      return
    }

    for (const p of parameters) {
      const raw = (formFields[p.$id] ?? '').trim()
      if (raw) continue
      if (wasEnabled && isSecretishParameter(p.$id)) continue
      setProviderError(`${p.name} is required`)
      return
    }

    const values: Record<string, string | boolean> = { enabled: true }
    for (const p of parameters) {
      const raw = (formFields[p.$id] ?? '').trim()
      if (raw) {
        values[p.$id] = raw
      } else if (wasEnabled && isSecretishParameter(p.$id)) {
        // omit — server keeps existing secret
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
            `${getOAuth2ProviderDisplayName(selectedProviderId)} has been updated`,
          )
          void queryClient.invalidateQueries({
            queryKey: ['oauth2', 'project', projectId, 'providers'],
          })
          void queryClient.invalidateQueries({ queryKey: ['project', projectId] })
          setDrawerOpen(false)
        },
        onError: (error: unknown) => {
          const message =
            error instanceof Error
              ? error.message
              : 'Failed to update OAuth2 provider'
          setProviderError(message)
        },
      },
    )
  }

  const loading = catalogLoading || listLoading

  if (loading && catalogEntries.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            OAuth2 providers
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-12 flex justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      </div>
    )
  }

  const selectedName = selectedProviderId
    ? getOAuth2ProviderDisplayName(selectedProviderId)
    : ''

  const renderProviderGrid = (rows: OAuth2ProviderRow[]) => (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {rows.map((row) => {
        const model = findProjectProviderModel(providerList, row.$id)
        const enabled = Boolean(model?.enabled)
        return (
          <button
            key={row.$id}
            type="button"
            onClick={() => openDrawerFor(row.$id)}
            className="flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-border bg-card/50 p-4 text-left transition-colors hover:bg-card"
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
              variant={enabled ? 'default' : 'secondary'}
              className={cn(
                'shrink-0 text-[11px]',
                enabled
                  ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30'
                  : 'bg-muted text-muted-foreground',
              )}
            >
              {enabled ? 'enabled' : 'disabled'}
            </Badge>
          </button>
        )
      })}
    </div>
  )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          OAuth2 providers
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground mb-4">
          Configure OAuth2 providers for social login. Providers and fields come
          from your Appwrite server.
        </p>

        <div className="mb-6">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              type="text"
              placeholder="Search providers..."
              value={providerSearch}
              onChange={(e) => setProviderSearch(e.target.value)}
              className="pl-9 h-9 text-[13px]"
            />
          </div>
        </div>

        {popularRows.length > 0 && (
          <div className="mb-6">
            <h4 className="text-[13px] font-medium text-foreground mb-3">
              Popular
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
                  All providers
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
              No providers match{' '}
              <span className="font-medium text-foreground">{providerSearch}</span>
            </p>
          </div>
        )}
      </div>

      <Drawer open={drawerOpen} onOpenChange={setDrawerOpen} direction="right">
        <DrawerContent className="h-full p-0 flex flex-col">
          <DrawerHeader className="px-6 pt-6 text-left shrink-0">
            <DrawerTitle className="text-[15px]">
              {selectedName} OAuth2 settings
            </DrawerTitle>
            <DrawerDescription className="text-[13px] mt-2">
              Use the parameter labels below as they appear in the provider
              dashboard. Add this redirect URI to the provider configuration.
            </DrawerDescription>
          </DrawerHeader>
          <div className="border-t border-border shrink-0" />
          <div className="px-6 pb-4 pt-4 overflow-y-auto flex-1 min-h-0 space-y-4">
            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label
                    htmlFor="oauth2-provider-enabled"
                    className="text-[13px] font-semibold text-foreground"
                  >
                    {formEnabled ? 'Enabled' : 'Disabled'}
                  </Label>
                  <p className="text-[12px] text-muted-foreground">
                    {formEnabled
                      ? 'This provider can be used for new sessions'
                      : 'This provider is turned off for this project'}
                  </p>
                </div>
                <Switch
                  id="oauth2-provider-enabled"
                  checked={formEnabled}
                  onCheckedChange={setFormEnabled}
                />
              </div>
            </div>

            {formEnabled &&
              selectedCatalog?.parameters.map((param) => {
                const isP8 = param.$id === 'p8File'
                const secretish = isSecretishParameter(param.$id)
                const Control = isP8 ? Textarea : Input
                return (
                  <div key={param.$id} className="space-y-2">
                    <Label htmlFor={`oauth2-${param.$id}`} className="text-[12px]">
                      {param.name}
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
                      className={cn('text-[13px]', isP8 && 'font-mono min-h-[120px]')}
                      type={!isP8 && secretish ? 'password' : 'text'}
                      autoComplete="off"
                    />
                    {param.hint ? (
                      <p className="text-[11px] text-muted-foreground">{param.hint}</p>
                    ) : null}
                  </div>
                )
              })}

            {formEnabled && (
              <div className="space-y-2">
                <Label className="text-[12px]">Redirect URI</Label>
                <Input
                  readOnly
                  value={redirectUri}
                  className="font-mono text-[13px]"
                />
              </div>
            )}

            {providerError ? (
              <Alert variant="destructive">
                <AlertDescription className="text-[13px]">
                  {providerError}
                </AlertDescription>
              </Alert>
            ) : null}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col gap-2 sm:flex-row sm:justify-start shrink-0">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              onClick={validateAndSubmit}
              disabled={updateMutation.isPending || !hasProviderChanges}
            >
              Update
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDrawerOpen(false)}
              disabled={updateMutation.isPending}
            >
              Cancel
            </Button>
          </div>
        </DrawerContent>
      </Drawer>
    </div>
  )
}
