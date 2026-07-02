import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { ArrowLeft, Trash2 } from 'lucide-react'
import { useProvider, useProject } from '@/lib/react-query/hooks'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader, type Tab } from '../../../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { formatDateTime } from '@/lib/date-utils'
import { MessagingProviderIcon } from '@/components/global/shared/MessagingProviderIcon'

import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { sdk } from '@/lib/appwrite/sdk'
import { patchMessagingProvider } from '@/lib/messaging/patch-messaging-provider'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'

function formatJsonConfig(value: unknown) {
  try {
    return JSON.stringify(value ?? {}, null, 2)
  } catch {
    return '{}'
  }
}

function jsonStableEqual(a: string, b: string) {
  try {
    return (
      JSON.stringify(JSON.parse(a || '{}')) ===
      JSON.stringify(JSON.parse(b || '{}'))
    )
  } catch {
    return false
  }
}

export function View({
  initialProvider,
}: {
  initialProvider?: Models.Provider
} = {}) {
  const t = useT()
  const { projectId, providerId } = useParams({
    strict: false,
  })
  const { project } = useProject(projectId)
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()

  // Fetch provider
  const { data: providerFromHook, isLoading: providerLoading } = useProvider(
    projectId,
    providerId,
    initialProvider,
  )
  const provider = providerFromHook ?? initialProvider

  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const idx = pathParts.findIndex(
      (part, i) =>
        part === 'providers' &&
        providerId != null &&
        pathParts[i + 1] === providerId,
    )
    if (idx >= 0 && pathParts[idx + 2] === 'settings') {
      return 'settings' as const
    }
    return 'overview' as const
  }, [location.pathname, providerId])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'overview',
        label: t('Overview'),
        to: '/projects/$projectId/messaging/providers/$providerId',
        params: {
          projectId: projectId as string,
          providerId: providerId as string,
        },
      },
      {
        id: 'settings',
        label: t('Settings'),
        to: '/projects/$projectId/messaging/providers/$providerId/settings',
        params: {
          projectId: projectId as string,
          providerId: providerId as string,
        },
      },
    ],
    [projectId, providerId, t],
  )

  const showOverview = activeTab === 'overview'
  const showSettings = activeTab === 'settings'

  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [name, setName] = useState('')
  const [enabled, setEnabled] = useState(false)

  const [fromEmail, setFromEmail] = useState('')
  const [fromName, setFromName] = useState('')
  const [replyToEmail, setReplyToEmail] = useState('')
  const [replyToName, setReplyToName] = useState('')

  const [smsCredentialsJson, setSmsCredentialsJson] = useState('{}')
  const [smsOptionsJson, setSmsOptionsJson] = useState('{}')

  const [fcmServiceAccountJson, setFcmServiceAccountJson] = useState('')
  const [apnsAuthKey, setApnsAuthKey] = useState('')
  const [apnsAuthKeyId, setApnsAuthKeyId] = useState('')
  const [apnsTeamId, setApnsTeamId] = useState('')
  const [apnsBundleId, setApnsBundleId] = useState('')

  // Sync local form state when provider loads or refetches
  useEffect(() => {
    if (!provider) return
    setName(provider.name || '')
    setEnabled(provider.enabled || false)

    if (provider.type === 'email') {
      setFromEmail(provider.options?.fromEmail || '')
      setFromName(provider.options?.fromName || '')
      setReplyToEmail(provider.options?.replyToEmail || '')
      setReplyToName(provider.options?.replyToName || '')
    } else if (provider.type === 'sms') {
      setSmsCredentialsJson(formatJsonConfig(provider.credentials))
      setSmsOptionsJson(formatJsonConfig(provider.options))
    } else if (provider.type === 'push') {
      if (provider.provider === 'fcm') {
        const raw = provider.credentials?.serviceAccountJSON
        setFcmServiceAccountJson(
          typeof raw === 'string' ? raw : formatJsonConfig(raw ?? {}),
        )
      } else if (provider.provider === 'apns') {
        setApnsAuthKey(
          typeof provider.credentials?.authKey === 'string'
            ? provider.credentials.authKey
            : '',
        )
        setApnsAuthKeyId(
          typeof provider.credentials?.authKeyId === 'string'
            ? provider.credentials.authKeyId
            : '',
        )
        setApnsTeamId(
          typeof provider.credentials?.teamId === 'string'
            ? provider.credentials.teamId
            : '',
        )
        setApnsBundleId(
          typeof provider.credentials?.bundleId === 'string'
            ? provider.credentials.bundleId
            : '',
        )
      }
    }
  }, [provider])

  const hasConfigurationChanges = useMemo(() => {
    if (!provider) return false
    if (provider.type === 'email') {
      const o = provider.options
      return (
        fromEmail.trim() !== (o?.fromEmail || '').trim() ||
        fromName.trim() !== (o?.fromName || '').trim() ||
        replyToEmail.trim() !== (o?.replyToEmail || '').trim() ||
        replyToName.trim() !== (o?.replyToName || '').trim()
      )
    }
    if (provider.type === 'sms') {
      return (
        !jsonStableEqual(
          smsCredentialsJson,
          formatJsonConfig(provider.credentials),
        ) ||
        !jsonStableEqual(smsOptionsJson, formatJsonConfig(provider.options))
      )
    }
    if (provider.type === 'push' && provider.provider === 'fcm') {
      const raw = provider.credentials?.serviceAccountJSON
      const baseline =
        typeof raw === 'string' ? raw : formatJsonConfig(raw ?? {})
      return !jsonStableEqual(fcmServiceAccountJson, baseline)
    }
    if (provider.type === 'push' && provider.provider === 'apns') {
      const c = provider.credentials
      return (
        apnsAuthKey !== (typeof c?.authKey === 'string' ? c.authKey : '') ||
        apnsAuthKeyId !== (typeof c?.authKeyId === 'string' ? c.authKeyId : '') ||
        apnsTeamId !== (typeof c?.teamId === 'string' ? c.teamId : '') ||
        apnsBundleId !== (typeof c?.bundleId === 'string' ? c.bundleId : '')
      )
    }
    return false
  }, [
    provider,
    fromEmail,
    fromName,
    replyToEmail,
    replyToName,
    smsCredentialsJson,
    smsOptionsJson,
    fcmServiceAccountJson,
    apnsAuthKey,
    apnsAuthKeyId,
    apnsTeamId,
    apnsBundleId,
  ])

  const handleSaveConfiguration = () => {
    if (!provider || !projectId || !providerId) return

    if (provider.type === 'email') {
      updateSettingsMutation.mutate({
        credentials: (provider.credentials ?? {}) as Record<string, unknown>,
        fromEmail: fromEmail.trim(),
        fromName: fromName.trim(),
        replyToEmail: replyToEmail.trim(),
        replyToName: replyToName.trim(),
      })
      return
    }

    if (provider.type === 'sms') {
      let credentials: Record<string, unknown>
      let options: Record<string, unknown>
      try {
        credentials = JSON.parse(smsCredentialsJson || '{}') as Record<
          string,
          unknown
        >
        options = JSON.parse(smsOptionsJson || '{}') as Record<string, unknown>
      } catch {
        toast.error(t('Credentials and options must be valid JSON'))
        return
      }
      updateSettingsMutation.mutate({ credentials, options })
      return
    }

    if (provider.type === 'push' && provider.provider === 'fcm') {
      try {
        JSON.parse(fcmServiceAccountJson || '{}')
      } catch {
        toast.error(t('Service account JSON must be valid JSON'))
        return
      }
      updateSettingsMutation.mutate({
        serviceAccountJSON: fcmServiceAccountJson.trim(),
      })
      return
    }

    if (provider.type === 'push' && provider.provider === 'apns') {
      updateSettingsMutation.mutate({
        authKey: apnsAuthKey.trim(),
        authKeyId: apnsAuthKeyId.trim(),
        teamId: apnsTeamId.trim(),
        bundleId: apnsBundleId.trim(),
      })
    }
  }

  // Update status mutation
  const updateStatusMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId || !providerId) {
        throw new Error('Project ID and Provider ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      if (!provider) throw new Error('Provider is required')
      await patchMessagingProvider(projectSdk.messaging, provider, {
        providerId,
        enabled,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['provider', 'project', projectId, providerId],
      })
      await queryClient.refetchQueries({
        queryKey: ['providers', 'project', projectId],
      })
      toast.success(t('Provider status updated successfully'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to update provider status'))
    },
  })

  // Update name mutation
  const updateNameMutation = useMutation({
    mutationFn: async (name: string) => {
      if (!projectId || !providerId) {
        throw new Error('Project ID and Provider ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      if (!provider) throw new Error('Provider is required')
      await patchMessagingProvider(projectSdk.messaging, provider, {
        providerId,
        name,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['provider', 'project', projectId, providerId],
      })
      await queryClient.refetchQueries({
        queryKey: ['providers', 'project', projectId],
      })
      toast.success(t('Provider name updated successfully'))
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to update provider name'))
    },
  })

  // Update settings mutation
  const updateSettingsMutation = useMutation({
    mutationFn: async (
      settings: Record<string, unknown> & {
        credentials?: Record<string, unknown>
        options?: Record<string, unknown>
        fromEmail?: string
        fromName?: string
        replyToEmail?: string
        replyToName?: string
        serviceAccountJSON?: string | object
        authKey?: string
        authKeyId?: string
        teamId?: string
        bundleId?: string
      },
    ) => {
      if (!projectId || !providerId) {
        throw new Error('Project ID and Provider ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      if (!provider) throw new Error('Provider is required')

      let credentials: Record<string, unknown> | undefined
      let options: Record<string, unknown> | undefined

      if (provider.type === 'sms') {
        credentials = settings.credentials || {}
        options = settings.options || {}
      } else if (provider.type === 'email') {
        credentials = settings.credentials || {}
        options = {
          fromEmail: settings.fromEmail,
          fromName: settings.fromName,
          replyToEmail: settings.replyToEmail,
          replyToName: settings.replyToName,
        }
      } else if (provider.type === 'push') {
        if (provider.provider === 'fcm') {
          const serviceAccountJSON = settings.serviceAccountJSON
          credentials = {
            serviceAccountJSON:
              typeof serviceAccountJSON === 'string'
                ? serviceAccountJSON
                : JSON.stringify(serviceAccountJSON),
          }
        } else if (provider.provider === 'apns') {
          credentials = {
            authKey: settings.authKey,
            authKeyId: settings.authKeyId,
            teamId: settings.teamId,
            bundleId: settings.bundleId,
          }
        } else {
          throw new Error(`Unsupported push provider: ${provider.provider}`)
        }
      } else {
        throw new Error(`Unsupported provider type: ${provider.type}`)
      }

      await patchMessagingProvider(projectSdk.messaging, provider, {
        providerId,
        credentials,
        options,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['provider', 'project', projectId, providerId],
      })
      await queryClient.refetchQueries({
        queryKey: ['providers', 'project', projectId],
      })
      toast.success(t('Provider settings updated successfully'))
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error) || t('Failed to update provider settings'),
      )
    },
  })

  // Delete provider mutation
  const deleteProviderMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !providerId) {
        throw new Error('Project ID and Provider ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.deleteProvider({ providerId })
    },
    onSuccess: async () => {
      // Refetch providers list so the list view shows updated data (uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['providers', 'project', projectId],
      })
      toast.success(t('Provider deleted successfully'))
      navigate({
        to: '/projects/$projectId/messaging/providers',
        params: { projectId: projectId! },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || t('Failed to delete provider'))
    },
  })

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/messaging/providers',
      params: { projectId: projectId! },
    })
  }

  if (providerLoading && !initialProvider) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading provider...')}
          </p>
        </div>
      </div>
    )
  }

  if (!provider) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Provider not found')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <DetailResourceHeaderTitle
            kind="provider"
            label={provider.name}
            resourceId={provider.$id}
            projectId={projectId}
            back={{
              onClick: handleBack,
              'aria-label': t('Back to providers'),
            }}
          />
        }
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 pt-4 sm:px-6 sm:pb-6 sm:pt-6">
        {showOverview && (
        <div className="space-y-6">
          {/* Update Name Section - First Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Name')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                {t(
                  "Update your provider's display name. This will be visible to all organization members.",
                )}
              </p>
              <Input
                id="provider-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('Provider name')}
                className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  name.trim() === provider.name ||
                  !name.trim() ||
                  updateNameMutation.isPending
                }
                onClick={() => updateNameMutation.mutate(name)}
              >
                {t('Update')}
              </Button>
            </div>
          </div>

          {/* Update Status Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">{t('Status')}</h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t('Enable or disable this provider for your project.')}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <Switch
                    id="toggle"
                    checked={enabled ?? false}
                    onCheckedChange={setEnabled}
                    disabled={updateStatusMutation.isPending}
                  />
                  <Label
                    htmlFor="toggle"
                    className="text-[13px] text-foreground"
                  >
                    {enabled ? t('Enabled') : t('Disabled')}
                  </Label>
                </div>
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  enabled === provider.enabled || updateStatusMutation.isPending
                }
                onClick={() => {
                  if (enabled !== provider.enabled) {
                    updateStatusMutation.mutate(enabled)
                  }
                }}
              >
                {t('Update')}
              </Button>
            </div>
          </div>

          {/* Provider credentials / channel configuration */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Configuration')}
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                {t('Connection details for this provider instance.')}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="space-y-4">
                {provider.type === 'email' && (
                  <>
                    <div>
                      <Label
                        htmlFor="from-email"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('From Email')}
                      </Label>
                      <Input
                        id="from-email"
                        value={fromEmail}
                        onChange={(e) => setFromEmail(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="sender@example.com"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="from-name"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('From Name')}
                      </Label>
                      <Input
                        id="from-name"
                        value={fromName}
                        onChange={(e) => setFromName(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Sender Name')}
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="reply-to-email"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Reply To Email')}
                      </Label>
                      <Input
                        id="reply-to-email"
                        value={replyToEmail}
                        onChange={(e) => setReplyToEmail(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="reply@example.com"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="reply-to-name"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Reply To Name')}
                      </Label>
                      <Input
                        id="reply-to-name"
                        value={replyToName}
                        onChange={(e) => setReplyToName(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Reply Name')}
                      />
                    </div>
                  </>
                )}
                {provider.type === 'sms' && (
                  <>
                    <div>
                      <Label
                        htmlFor="sms-credentials-json"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Credentials (JSON)')}
                      </Label>
                      <p className="text-[12px] text-muted-foreground mt-1">
                        {t('API keys and provider-specific fields from the console API.')}
                      </p>
                      <Textarea
                        id="sms-credentials-json"
                        value={smsCredentialsJson}
                        onChange={(e) => setSmsCredentialsJson(e.target.value)}
                        className="mt-1.5 min-h-[140px] font-mono text-xs border-border bg-background text-foreground focus:border-border focus:ring-0"
                        spellCheck={false}
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="sms-options-json"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Options (JSON)')}
                      </Label>
                      <p className="text-[12px] text-muted-foreground mt-1">
                        {t('Optional provider options object.')}
                      </p>
                      <Textarea
                        id="sms-options-json"
                        value={smsOptionsJson}
                        onChange={(e) => setSmsOptionsJson(e.target.value)}
                        className="mt-1.5 min-h-[100px] font-mono text-xs border-border bg-background text-foreground focus:border-border focus:ring-0"
                        spellCheck={false}
                      />
                    </div>
                  </>
                )}
                {provider.type === 'push' && provider.provider === 'fcm' && (
                  <div>
                    <Label
                      htmlFor="service-account-json"
                      className="text-[13px] font-medium text-foreground"
                    >
                      {t('Service Account JSON')}
                    </Label>
                    <Textarea
                      id="service-account-json"
                      value={fcmServiceAccountJson}
                      onChange={(e) => setFcmServiceAccountJson(e.target.value)}
                      className="mt-1.5 font-mono text-xs border-border bg-background text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                      rows={10}
                      placeholder='{"type": "service_account", ...}'
                      spellCheck={false}
                    />
                  </div>
                )}
                {provider.type === 'push' && provider.provider === 'apns' && (
                  <>
                    <div>
                      <Label
                        htmlFor="auth-key"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Auth Key')}
                      </Label>
                      <Input
                        id="auth-key"
                        value={apnsAuthKey}
                        onChange={(e) => setApnsAuthKey(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Auth key')}
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="auth-key-id"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Auth Key ID')}
                      </Label>
                      <Input
                        id="auth-key-id"
                        value={apnsAuthKeyId}
                        onChange={(e) => setApnsAuthKeyId(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Auth key ID')}
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="team-id"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Team ID')}
                      </Label>
                      <Input
                        id="team-id"
                        value={apnsTeamId}
                        onChange={(e) => setApnsTeamId(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Team ID')}
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="bundle-id"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Bundle ID')}
                      </Label>
                      <Input
                        id="bundle-id"
                        value={apnsBundleId}
                        onChange={(e) => setApnsBundleId(e.target.value)}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Bundle ID')}
                      />
                    </div>
                  </>
                )}
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={handleSaveConfiguration}
                disabled={
                  !hasConfigurationChanges || updateSettingsMutation.isPending
                }
              >
                {t('Update')}
              </Button>
            </div>
          </div>
        </div>
        )}

        {showSettings && (
          <div className="space-y-6">
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">{t('Details')}</h3>
                <p className="text-[13px] text-muted-foreground mt-2">
                  {t('Provider ID, channel type, and timestamps.')}
                </p>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="space-y-4">
                  <div className="min-w-0">
                    <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      {t('Provider ID')}
                    </p>
                    <CopyableId id={provider.$id} size="sm" />
                  </div>
                  <div>
                    <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      {t('Channel type')}
                    </p>
                    <p className="text-[13px] text-foreground capitalize">
                      {provider.type}
                    </p>
                  </div>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                      <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                        {t('Created')}
                      </p>
                      {provider.$createdAt ? (
                        <DateTooltip
                          date={provider.$createdAt}
                          className="text-[13px] text-foreground"
                          showFormattedDate
                        />
                      ) : (
                        <span className="text-[13px] text-muted-foreground/50 italic">
                          N/A
                        </span>
                      )}
                    </div>
                    <div>
                      <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                        {t('Updated')}
                      </p>
                      <DateTooltip
                        date={provider.$updatedAt || provider.$createdAt}
                        className="text-[13px] text-foreground"
                        showFormattedDate
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Delete provider')}
                </h3>
                <p className="text-[13px] text-muted-foreground mt-2">
                  {t(
                    "The provider's instance will be permanently deleted. This action is irreversible.",
                  )}
                </p>
              </div>
              <div className="border-t border-destructive/20" />
              <div className="px-6 py-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <MessagingProviderIcon
                      serviceKey={provider.provider}
                      providerName={provider.name}
                      providerType={provider.type as 'email' | 'sms' | 'push'}
                      size="md"
                      className="h-5 w-5"
                    />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-[14px] font-medium text-foreground truncate">
                      {provider.name}
                    </p>
                    {provider.$updatedAt && (
                      <p className="text-[12px] text-muted-foreground">
                        {t('Last updated:')} {formatDateTime(provider.$updatedAt)}
                      </p>
                    )}
                  </div>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => setDeleteDialogOpen(true)}
                  disabled={deleteProviderMutation.isPending}
                >
                  <Trash2 className="me-1.5 h-4 w-4" />
                  {t('Delete')}
                </Button>
              </div>
            </div>
          </div>
        )}

        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-start">
              <DialogTitle>{t('Delete Provider')}</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                {t('Are you sure you want to delete')} {provider.name}{' '}
                {t('from')} '{project?.name || projectId}'?
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={deleteProviderMutation.isPending}
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteProviderMutation.mutate()}
                disabled={deleteProviderMutation.isPending}
              >
                {t('Delete')}
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
