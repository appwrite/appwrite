import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { ArrowLeft, Trash2 } from 'lucide-react'
import { useProvider, useProject } from '@/lib/react-query/hooks'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
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

export function ProviderDetailView() {
  const t = useT()
  const { projectId, providerId } = useParams({
    strict: false,
  })
  const { project } = useProject(projectId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Fetch provider
  const { data: provider, isLoading: providerLoading } = useProvider(
    projectId,
    providerId,
  )

  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [name, setName] = useState('')
  const [enabled, setEnabled] = useState(false)

  // Initialize form when provider loads
  useMemo(() => {
    if (provider) {
      setName(provider.name || '')
      setEnabled(provider.enabled || false)
    }
  }, [provider])

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
    onSuccess: async (_data, enabledValue) => {
      await queryClient.refetchQueries({
        queryKey: ['provider', 'project', projectId, providerId],
      })
      toast.success(t('Provider status updated successfully'))
      setEnabled(enabledValue)
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
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['provider', 'project', projectId, providerId],
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
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['provider', 'project', projectId, providerId],
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
    onSuccess: () => {
      queryClient.invalidateQueries({
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

  if (providerLoading) {
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

  // Extract provider settings from credentials and options
  const getProviderSettings = (): Record<string, unknown> => {
    const settings: Record<string, unknown> = {}

    if (provider.type === 'sms') {
      // SMS providers
      settings.credentials = provider.credentials || {}
      settings.options = provider.options || {}
    } else if (provider.type === 'email') {
      // Email providers
      settings.credentials = provider.credentials || {}
      settings.fromEmail = provider.options?.fromEmail || ''
      settings.fromName = provider.options?.fromName || ''
      settings.replyToEmail = provider.options?.replyToEmail || ''
      settings.replyToName = provider.options?.replyToName || ''
    } else if (provider.type === 'push') {
      // Push providers
      if (provider.provider === 'fcm') {
        const serviceAccountJSON = provider.credentials?.serviceAccountJSON
        settings.serviceAccountJSON =
          typeof serviceAccountJSON === 'string'
            ? serviceAccountJSON
            : JSON.stringify(serviceAccountJSON || {})
      } else if (provider.provider === 'apns') {
        settings.authKey = provider.credentials?.authKey || ''
        settings.authKeyId = provider.credentials?.authKeyId || ''
        settings.teamId = provider.credentials?.teamId || ''
        settings.bundleId = provider.credentials?.bundleId || ''
      }
    }

    return settings
  }

  const providerSettings = getProviderSettings()

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span className="truncate">{provider.name}</span>
            <CopyableId id={provider.$id} size="xs" className="shrink-0" />
          </div>
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
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
              <h3 className="text-[15px] font-semibold text-foreground">
                {provider.name}
              </h3>
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
              <div className="mt-4 space-y-1">
                <p className="text-[13px] text-muted-foreground">
                  {t('Provider ID:')}{' '}
                  <span className="ms-1.5">
                    <CopyableId id={provider.$id} size="sm" />
                  </span>
                </p>
                <p className="text-[13px] text-muted-foreground">
                  {t('Type:')}{' '}
                  <span className="text-foreground capitalize">
                    {provider.type}
                  </span>
                </p>
                {provider.$createdAt && (
                  <p className="text-[13px] text-muted-foreground">
                    {t('Created:')}{' '}
                    <DateTooltip
                      date={provider.$createdAt}
                      showFormattedDate
                      className="text-foreground"
                    />
                  </p>
                )}
                <p className="text-[13px] text-muted-foreground">
                  {t('Last updated:')}{' '}
                  <DateTooltip
                    date={provider.$updatedAt || provider.$createdAt}
                    showFormattedDate
                    className="text-foreground"
                  />
                </p>
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

          {/* Update Settings Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Settings')}
              </h3>
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
                        defaultValue={providerSettings.fromEmail}
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
                        defaultValue={providerSettings.fromName}
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
                        defaultValue={providerSettings.replyToEmail}
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
                        defaultValue={providerSettings.replyToName}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Reply Name')}
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
                      defaultValue={providerSettings.serviceAccountJSON}
                      className="mt-1.5 font-mono text-xs border-border bg-background text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                      rows={10}
                      placeholder='{"type": "service_account", ...}'
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
                        defaultValue={providerSettings.authKey}
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
                        defaultValue={providerSettings.authKeyId}
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
                        defaultValue={providerSettings.teamId}
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
                        defaultValue={providerSettings.bundleId}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder={t('Bundle ID')}
                      />
                    </div>
                  </>
                )}
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => {
                  // TODO: Collect form values and call updateSettingsMutation
                  toast.info(t('Settings update functionality coming soon'))
                }}
                disabled={updateSettingsMutation.isPending}
              >
                {t('Update')}
              </Button>
            </div>
          </div>

          {/* Overview Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                {t('Overview')}
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="space-y-4">
                <div className="flex items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      {t('Provider ID')}
                    </p>
                    <CopyableId id={provider.$id} size="sm" />
                  </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
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
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
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

          {/* Delete Provider Card */}
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
            <div className="border-t border-red-500/20" />
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
            <div className="px-6 py-4 border-t border-red-500/20 bg-destructive/5">
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

        {/* Delete Confirmation Dialog */}
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
