import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Package, Plus } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  organizationAppsQueryOptions,
  useCreateOrganizationApp,
  useCreateOrganizationAppSecret,
  useUpdateOrganizationApp,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

type AppwriteProviderSetupProps = {
  organizationId: string | null | undefined
  redirectUri: string
  onCredentials: (clientId: string, clientSecret: string) => void
  disabled?: boolean
}

function mergeRedirectUris(
  existing: string[] | undefined,
  redirectUri: string,
): string[] {
  const next = [...(existing ?? [])]
  if (redirectUri && !next.includes(redirectUri)) {
    next.push(redirectUri)
  }
  return next.length > 0 ? next : redirectUri ? [redirectUri] : []
}

export function AppwriteProviderSetup({
  organizationId,
  redirectUri,
  onCredentials,
  disabled = false,
}: AppwriteProviderSetupProps) {
  const t = useT()
  const [mode, setMode] = useState<'create' | 'select'>('create')
  const [appName, setAppName] = useState('')
  const [selectedAppId, setSelectedAppId] = useState('')
  const [setupError, setSetupError] = useState('')
  const [isPreparing, setIsPreparing] = useState(false)

  const { data: appsData, isLoading: appsLoading } = useQuery(
    organizationAppsQueryOptions(organizationId),
  )
  const apps = appsData?.apps ?? []

  const createAppMutation = useCreateOrganizationApp(organizationId)
  const updateAppMutation = useUpdateOrganizationApp(organizationId)
  const createSecretMutation = useCreateOrganizationAppSecret(null)

  const isBusy =
    disabled ||
    isPreparing ||
    createAppMutation.isPending ||
    updateAppMutation.isPending ||
    createSecretMutation.isPending

  const selectedApp = useMemo(
    () => apps.find((app) => app.$id === selectedAppId) ?? null,
    [apps, selectedAppId],
  )

  const fillCredentials = (clientId: string, clientSecret: string) => {
    onCredentials(clientId, clientSecret)
    toast.success(t('Client ID and secret filled from your Appwrite app'))
  }

  const ensureAppReady = async (app: Models.App): Promise<Models.App> => {
    const redirectUris = mergeRedirectUris(app.redirectUris, redirectUri)
    const needsRedirectUpdate =
      Boolean(redirectUri) && !(app.redirectUris ?? []).includes(redirectUri)
    const needsConfidential = app.type === 'public'
    const needsEnabled = !app.enabled

    if (!needsRedirectUpdate && !needsConfidential && !needsEnabled) {
      return app
    }

    return await updateAppMutation.mutateAsync({
      appId: app.$id,
      name: app.name,
      enabled: true,
      type: 'confidential',
      redirectUris,
      postLogoutRedirectUris: app.postLogoutRedirectUris,
      description: app.description,
      tagline: app.tagline,
      tags: app.tags,
      clientUri: app.clientUri,
      logoUri: app.logoUri,
      privacyPolicyUrl: app.privacyPolicyUrl,
      termsUrl: app.termsUrl,
      contacts: app.contacts,
      images: app.images,
      supportUrl: app.supportUrl,
      dataDeletionUrl: app.dataDeletionUrl,
      deviceFlow: app.deviceFlow,
    })
  }

  const issueSecret = async (appId: string): Promise<string> => {
    const created = await createSecretMutation.mutateAsync({ appId })
    const secret = created.secret?.trim()
    if (!secret) {
      throw new Error(
        t('OAuth secret was created but the plaintext value was empty'),
      )
    }
    return secret
  }

  const handleCreate = async () => {
    if (!organizationId || !appName.trim() || isBusy) return
    setSetupError('')
    setIsPreparing(true)
    try {
      const app = await createAppMutation.mutateAsync({
        name: appName.trim(),
        shortDescription: t('Sign in with Appwrite'),
        description: t(
          'OAuth app for Sign in with Appwrite on this project.',
        ),
        redirectUri: redirectUri || undefined,
        enabled: true,
      })
      setSelectedAppId(app.$id)
      const secret = await issueSecret(app.$id)
      fillCredentials(app.$id, secret)
      setAppName('')
    } catch (error) {
      setSetupError(getErrorMessage(error, t('Failed to create Appwrite app')))
    } finally {
      setIsPreparing(false)
    }
  }

  const handleSelect = async () => {
    if (!organizationId || !selectedApp || isBusy) return
    setSetupError('')
    setIsPreparing(true)
    try {
      const app = await ensureAppReady(selectedApp)
      const secret = await issueSecret(app.$id)
      fillCredentials(app.$id, secret)
    } catch (error) {
      setSetupError(
        getErrorMessage(error, t('Failed to use selected Appwrite app')),
      )
    } finally {
      setIsPreparing(false)
    }
  }

  if (!organizationId) {
    return (
      <div className="rounded-lg border border-border bg-muted/30 p-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            'Organization context is required to create or select an Appwrite app. Enter client ID and secret manually below.',
          )}
        </p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-border bg-muted/30 p-4 space-y-4">
      <div>
        <h4 className="text-[13px] font-semibold text-foreground">
          {t('Quick setup')}
        </h4>
        <p className="text-[12px] text-muted-foreground mt-1">
          {t(
            'Create an Appwrite app or choose an existing one from your organization. We register the redirect URI and fill client ID and secret for you.',
          )}
        </p>
      </div>

      <Tabs
        value={mode}
        onValueChange={(value) => {
          setMode(value as 'create' | 'select')
          setSetupError('')
        }}
        className="gap-3"
      >
        <TabsList className="grid w-full grid-cols-2 h-9">
          <TabsTrigger value="create" className="text-[12px]" disabled={isBusy}>
            {t('Create app')}
          </TabsTrigger>
          <TabsTrigger value="select" className="text-[12px]" disabled={isBusy}>
            {t('Select app')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="create" className="space-y-3 mt-0">
          <div className="space-y-2">
            <Label htmlFor="appwrite-provider-app-name" className="text-[12px]">
              {t('App name')}
            </Label>
            <Input
              id="appwrite-provider-app-name"
              value={appName}
              onChange={(e) => setAppName(e.target.value)}
              placeholder={t('My project sign-in')}
              disabled={isBusy}
              className="h-9 text-[13px]"
            />
          </div>
          <Button
            type="button"
            size="sm"
            className="h-9 text-[13px]"
            disabled={isBusy || !appName.trim()}
            onClick={() => void handleCreate()}
          >
            <Plus className="me-1.5 h-3.5 w-3.5" />
            {t('Create and fill credentials')}
          </Button>
        </TabsContent>

        <TabsContent value="select" className="space-y-3 mt-0">
          {appsLoading ? (
            <div className="flex items-center justify-center py-6">
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          ) : apps.length === 0 ? (
            <div className="flex items-start gap-2 rounded-md border border-dashed border-border px-3 py-3">
              <Package className="h-4 w-4 shrink-0 text-muted-foreground mt-0.5" />
              <p className="text-[12px] text-muted-foreground">
                {t(
                  'No Appwrite apps in this organization yet. Create one from the Create app tab.',
                )}
              </p>
            </div>
          ) : (
            <>
              <div className="space-y-2">
                <Label
                  htmlFor="appwrite-provider-app-select"
                  className="text-[12px]"
                >
                  {t('Organization app')}
                </Label>
                <Select
                  value={selectedAppId}
                  onValueChange={setSelectedAppId}
                  disabled={isBusy}
                >
                  <SelectTrigger
                    id="appwrite-provider-app-select"
                    className="h-9 text-[13px]"
                  >
                    <SelectValue placeholder={t('Select an app')} />
                  </SelectTrigger>
                  <SelectContent>
                    {apps.map((app) => (
                      <SelectItem key={app.$id} value={app.$id}>
                        {app.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <p className="text-[11px] text-muted-foreground">
                {t(
                  'A new client secret is created for this app so the value can be filled in securely.',
                )}
              </p>
              <Button
                type="button"
                size="sm"
                className="h-9 text-[13px]"
                disabled={isBusy || !selectedAppId}
                onClick={() => void handleSelect()}
              >
                {t('Use app and fill credentials')}
              </Button>
            </>
          )}
        </TabsContent>
      </Tabs>

      {setupError ? (
        <p className="text-[12px] text-destructive">{setupError}</p>
      ) : null}
    </div>
  )
}
