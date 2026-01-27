import { useState, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { ArrowLeft, Mail, Phone, Bell, Trash2, Settings } from 'lucide-react'
import { useProvider, useProject } from '@/lib/react-query/hooks'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ServiceHeader } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'

export function ProviderDetailView() {
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
      await projectSdk.messaging.updateProvider({
        providerId,
        enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['provider', 'project', projectId, providerId],
      })
      toast.success('Provider status updated successfully')
      setEnabled(!enabled)
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to update provider status')
    },
  })

  // Update name mutation
  const updateNameMutation = useMutation({
    mutationFn: async (name: string) => {
      if (!projectId || !providerId) {
        throw new Error('Project ID and Provider ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      await projectSdk.messaging.updateProvider({
        providerId,
        name,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['provider', 'project', projectId, providerId],
      })
      toast.success('Provider name updated successfully')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to update provider name')
    },
  })

  // Update settings mutation
  const updateSettingsMutation = useMutation({
    mutationFn: async (settings: any) => {
      if (!projectId || !providerId) {
        throw new Error('Project ID and Provider ID are required')
      }
      const projectSdk = sdk.forProject(projectId)

      // Map provider-specific parameters based on provider type
      const params: any = { providerId }

      if (provider?.type === 'sms') {
        // SMS Providers (Twilio, Msg91, Telesign, Textmagic, Vonage)
        params.credentials = settings.credentials || {}
        params.options = settings.options || {}
      } else if (provider?.type === 'email') {
        // Email Providers (Mailgun, Sendgrid, Resend, SMTP)
        params.credentials = settings.credentials || {}
        params.options = {
          fromEmail: settings.fromEmail,
          fromName: settings.fromName,
          replyToEmail: settings.replyToEmail,
          replyToName: settings.replyToName,
        }
      } else if (provider?.type === 'push') {
        // Push Providers (FCM, APNS)
        if (provider.name === 'fcm') {
          // FCM uses serviceAccountJSON
          const serviceAccountJSON = settings.serviceAccountJSON
          params.credentials = {
            serviceAccountJSON:
              typeof serviceAccountJSON === 'string'
                ? serviceAccountJSON
                : JSON.stringify(serviceAccountJSON),
          }
        } else if (provider.name === 'apns') {
          // APNS uses authKey, authKeyId, teamId, bundleId
          params.credentials = {
            authKey: settings.authKey,
            authKeyId: settings.authKeyId,
            teamId: settings.teamId,
            bundleId: settings.bundleId,
          }
        }
      }

      await projectSdk.messaging.updateProvider(params)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['provider', 'project', projectId, providerId],
      })
      toast.success('Provider settings updated successfully')
    },
    onError: (error: Error) => {
      toast.error(
        getErrorMessage(error) || 'Failed to update provider settings',
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
      toast.success('Provider deleted successfully')
      navigate({
        to: '/projects/$projectId/messaging/providers',
        params: { projectId: projectId! },
      })
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) || 'Failed to delete provider')
    },
  })

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/messaging/providers',
      params: { projectId: projectId! },
    })
  }

  const getProviderTypeIcon = () => {
    if (provider?.type === 'email') return Mail
    if (provider?.type === 'sms') return Phone
    if (provider?.type === 'push') return Bell
    return Settings
  }

  if (providerLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            Loading provider...
          </p>
        </div>
      </div>
    )
  }

  if (!provider) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="rounded-lg border border-border bg-card py-12 px-6 text-center">
          <p className="text-[13px] text-muted-foreground">
            Provider not found
          </p>
        </div>
      </div>
    )
  }

  const TypeIcon = getProviderTypeIcon()

  // Extract provider settings from credentials and options
  const getProviderSettings = () => {
    const settings: any = {}

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
      if (provider.name === 'fcm') {
        const serviceAccountJSON = provider.credentials?.serviceAccountJSON
        settings.serviceAccountJSON =
          typeof serviceAccountJSON === 'string'
            ? serviceAccountJSON
            : JSON.stringify(serviceAccountJSON || {})
      } else if (provider.name === 'apns') {
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
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span>{provider.name}</span>
          </div>
        }
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Update Name Section - First Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Name
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                Update your provider's display name. This will be visible to all
                team members.
              </p>
              <Input
                id="provider-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Provider name"
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
                Update
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
                    {enabled ? 'Enabled' : 'Disabled'}
                  </Label>
                </div>
              </div>
              <div className="mt-4 space-y-1">
                <p className="text-[13px] text-muted-foreground">
                  Provider ID:{' '}
                  <span className="ml-1.5">
                    <CopyableId id={provider.$id} size="sm" />
                  </span>
                </p>
                <p className="text-[13px] text-muted-foreground">
                  Type:{' '}
                  <span className="text-foreground capitalize">
                    {provider.type}
                  </span>
                </p>
                {provider.$createdAt && (
                  <p className="text-[13px] text-muted-foreground">
                    Created:{' '}
                    <DateTooltip
                      date={provider.$createdAt}
                      showFormattedDate
                      className="text-foreground"
                    />
                  </p>
                )}
                <p className="text-[13px] text-muted-foreground">
                  Last updated:{' '}
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
                Update
              </Button>
            </div>
          </div>

          {/* Update Settings Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Settings
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
                        From Email
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
                        From Name
                      </Label>
                      <Input
                        id="from-name"
                        defaultValue={providerSettings.fromName}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="Sender Name"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="reply-to-email"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Reply To Email
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
                        Reply To Name
                      </Label>
                      <Input
                        id="reply-to-name"
                        defaultValue={providerSettings.replyToName}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="Reply Name"
                      />
                    </div>
                  </>
                )}
                {provider.type === 'push' && provider.name === 'fcm' && (
                  <div>
                    <Label
                      htmlFor="service-account-json"
                      className="text-[13px] font-medium text-foreground"
                    >
                      Service Account JSON
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
                {provider.type === 'push' && provider.name === 'apns' && (
                  <>
                    <div>
                      <Label
                        htmlFor="auth-key"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Auth Key
                      </Label>
                      <Input
                        id="auth-key"
                        defaultValue={providerSettings.authKey}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="Auth key"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="auth-key-id"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Auth Key ID
                      </Label>
                      <Input
                        id="auth-key-id"
                        defaultValue={providerSettings.authKeyId}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="Auth key ID"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="team-id"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Team ID
                      </Label>
                      <Input
                        id="team-id"
                        defaultValue={providerSettings.teamId}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="Team ID"
                      />
                    </div>
                    <div>
                      <Label
                        htmlFor="bundle-id"
                        className="text-[13px] font-medium text-foreground"
                      >
                        Bundle ID
                      </Label>
                      <Input
                        id="bundle-id"
                        defaultValue={providerSettings.bundleId}
                        className="mt-1.5 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        placeholder="Bundle ID"
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
                  toast.info('Settings update functionality coming soon')
                }}
                disabled={updateSettingsMutation.isPending}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Overview Section */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Overview
              </h3>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="space-y-4">
                <div className="flex items-start justify-between gap-4">
                  <div className="flex-1 min-w-0">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      Provider ID
                    </p>
                    <CopyableId id={provider.$id} size="sm" />
                  </div>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">
                      Created
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
                      Updated
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
                Delete provider
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                The provider's instance will be permanently deleted. This action
                is irreversible.
              </p>
            </div>
            <div className="border-t border-red-500/20" />
            <div className="px-6 py-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <MessagingProviderIcon
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
                      Last updated: {formatDateTime(provider.$updatedAt)}
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
                <Trash2 className="mr-1.5 h-4 w-4" />
                Delete
              </Button>
            </div>
          </div>
        </div>

        {/* Delete Confirmation Dialog */}
        <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <DialogContent className="sm:max-w-md p-0">
            <DialogHeader className="px-6 pt-6 text-left">
              <DialogTitle>Delete Provider</DialogTitle>
              <DialogDescription className="text-[13px] mt-2">
                Are you sure you want to delete {provider.name} from '
                {project?.name || projectId}'?
              </DialogDescription>
            </DialogHeader>

            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button
                variant="outline"
                onClick={() => setDeleteDialogOpen(false)}
                disabled={deleteProviderMutation.isPending}
              >
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={() => deleteProviderMutation.mutate()}
                disabled={deleteProviderMutation.isPending}
              >
                Delete
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </div>
  )
}
