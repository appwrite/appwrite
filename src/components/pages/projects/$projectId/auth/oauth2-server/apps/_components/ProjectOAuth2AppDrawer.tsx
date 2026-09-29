import { useEffect, useMemo, useState, type ReactNode } from 'react'
import type { Models } from '@appwrite.io/console'
import {
  AlertTriangle,
  Check,
  Copy,
  Key,
  Loader2,
  Lock,
  Plus,
  ShieldCheck,
  Terminal,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import {
  isOAuth2AppReadOnly,
  OAuth2AppLabelBadges,
} from '@/components/global/shared/OAuth2AppLabelBadges'
import { OAuth2AppInstallationsCard } from '@/components/global/shared/OAuth2AppInstallationsCard'
import { OAuth2AppKeysCard } from '@/components/global/shared/OAuth2AppKeysCard'
import { OAuth2ScopePicker } from '@/components/global/shared/OAuth2ScopePicker'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { InputTags } from '@/components/ui/input-tags'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { MarkdownEditor } from '@/components/global/shared/MarkdownEditor'
import {
  OAUTH2_DEVICE_FLOW_DESCRIPTION,
  OAuth2ClientTypePicker,
} from '@/components/global/shared/OAuth2ClientTypePicker'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  useCreateProjectOAuth2App,
  useCreateProjectOAuth2AppKey,
  useCreateProjectOAuth2AppSecret,
  useDeleteProjectOAuth2App,
  useDeleteProjectOAuth2AppInstallation,
  useDeleteProjectOAuth2AppKey,
  useDeleteProjectOAuth2AppSecret,
  useDeleteProjectOAuth2AppTokens,
  useProject,
  useProjectOAuth2App,
  useProjectOAuth2AppInstallations,
  useProjectOAuth2AppKeys,
  useProjectOAuth2AppSecrets,
  useProjectOAuth2InstallationScopes,
  useUpdateProjectOAuth2App,
} from '@/lib/react-query/hooks'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { AppLogoFilePicker } from '@/components/pages/organizations/$orgId/apps/_components/AppLogoFilePicker'
import { AppImagesPicker } from '@/components/pages/organizations/$orgId/apps/_components/AppImagesPicker'

/** Nested dialogs must stack above the drawer overlay. */
const NESTED_DIALOG_CLASS = 'z-[130]'

function nonEmptyList(values: string[]): string[] {
  return values.map((v) => v.trim()).filter(Boolean)
}

function maskClientSecret(hint: string) {
  return `client_secret_${'•'.repeat(18)}${hint}`
}

function DrawerSection({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: ReactNode
}) {
  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-4 py-3">
        <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
        {description ? (
          <p className="mt-1 text-[12px] text-muted-foreground">
            {description}
          </p>
        ) : null}
      </div>
      <div className="border-t border-border" />
      <div className="space-y-4 px-4 py-3">{children}</div>
    </div>
  )
}

interface ProjectOAuth2AppDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  app?: Models.App | null
  onSuccess?: () => void
  onDelete?: (app: Models.App) => void
}

export function ProjectOAuth2AppDrawer({
  open,
  onOpenChange,
  projectId,
  region,
  app,
  onSuccess,
  onDelete,
}: ProjectOAuth2AppDrawerProps) {
  const t = useT()
  const isEditing = !!app
  const { project, projectData } = useProject(projectId)
  const teamId = project?.teamId ?? ''

  const { app: fullApp } = useProjectOAuth2App(
    projectId,
    isEditing ? app?.$id : null,
    region,
  )
  const source = fullApp ?? app
  const readOnly = isEditing && isOAuth2AppReadOnly(source?.labels)

  const createMutation = useCreateProjectOAuth2App(projectId, region)
  const updateMutation = useUpdateProjectOAuth2App(projectId, region)
  const deleteMutation = useDeleteProjectOAuth2App(projectId, region)
  const revokeTokensMutation = useDeleteProjectOAuth2AppTokens(
    projectId,
    region,
  )
  const createSecretMutation = useCreateProjectOAuth2AppSecret(
    projectId,
    region,
  )
  const deleteSecretMutation = useDeleteProjectOAuth2AppSecret(
    projectId,
    region,
  )
  const createKeyMutation = useCreateProjectOAuth2AppKey(projectId, region)
  const deleteKeyMutation = useDeleteProjectOAuth2AppKey(projectId, region)
  const deleteInstallationMutation = useDeleteProjectOAuth2AppInstallation(
    projectId,
    region,
  )
  const { secrets, isLoading: secretsLoading } = useProjectOAuth2AppSecrets(
    projectId,
    isEditing && source?.type !== 'public' ? source?.$id : null,
    region,
  )
  const { keys, isLoading: keysLoading } = useProjectOAuth2AppKeys(
    projectId,
    isEditing ? source?.$id : null,
    region,
  )
  const { installations, isLoading: installationsLoading } =
    useProjectOAuth2AppInstallations(
      projectId,
      isEditing ? source?.$id : null,
      region,
    )
  const {
    scopes: installationScopeCatalog,
    isLoading: installationScopesLoading,
  } = useProjectOAuth2InstallationScopes(isEditing ? projectId : null, region)

  const isPending =
    createMutation.isPending ||
    updateMutation.isPending ||
    deleteMutation.isPending ||
    revokeTokensMutation.isPending ||
    createSecretMutation.isPending ||
    deleteSecretMutation.isPending

  const [name, setName] = useState('')
  const [tagline, setTagline] = useState('')
  const [description, setDescription] = useState('')
  const [tags, setTags] = useState<string[]>([])
  const [enabled, setEnabled] = useState(true)
  const [clientType, setClientType] = useState('confidential')
  const [deviceFlow, setDeviceFlow] = useState(false)
  const [redirectUris, setRedirectUris] = useState<string[]>([])
  const [postLogoutRedirectUris, setPostLogoutRedirectUris] = useState<
    string[]
  >([])
  const [installationScopes, setInstallationScopes] = useState<string[]>([])
  const [installationRedirectUrl, setInstallationRedirectUrl] = useState('')
  const [clientUri, setClientUri] = useState('')
  const [logoUri, setLogoUri] = useState('')
  const [images, setImages] = useState<string[]>([])
  const [privacyPolicyUrl, setPrivacyPolicyUrl] = useState('')
  const [termsUrl, setTermsUrl] = useState('')
  const [dataDeletionUrl, setDataDeletionUrl] = useState('')
  const [supportUrl, setSupportUrl] = useState('')
  const [contacts, setContacts] = useState<string[]>([])
  const [newSecretPlaintext, setNewSecretPlaintext] = useState<string | null>(
    null,
  )
  const [copiedNewSecret, setCopiedNewSecret] = useState(false)
  const [deleteSecretId, setDeleteSecretId] = useState<string | null>(null)
  const [revokeDialogOpen, setRevokeDialogOpen] = useState(false)

  useEffect(() => {
    if (!open) {
      setName('')
      setTagline('')
      setDescription('')
      setTags([])
      setEnabled(true)
      setClientType('confidential')
      setDeviceFlow(false)
      setRedirectUris([])
      setPostLogoutRedirectUris([])
      setInstallationScopes([])
      setInstallationRedirectUrl('')
      setClientUri('')
      setLogoUri('')
      setImages([])
      setPrivacyPolicyUrl('')
      setTermsUrl('')
      setDataDeletionUrl('')
      setSupportUrl('')
      setContacts([])
      setDeleteSecretId(null)
      setRevokeDialogOpen(false)
      return
    }

    if (source) {
      setName(source.name || '')
      setTagline(source.tagline ?? '')
      setDescription(source.description ?? '')
      setTags(source.tags ?? [])
      setEnabled(source.enabled ?? true)
      setClientType(source.type || 'confidential')
      setDeviceFlow(source.deviceFlow ?? false)
      setRedirectUris(source.redirectUris ?? [])
      setPostLogoutRedirectUris(source.postLogoutRedirectUris ?? [])
      setInstallationScopes(source.installationScopes ?? [])
      setInstallationRedirectUrl(source.installationRedirectUrl ?? '')
      setClientUri(source.clientUri ?? '')
      setLogoUri(source.logoUri ?? '')
      setImages(source.images ?? [])
      setPrivacyPolicyUrl(source.privacyPolicyUrl ?? '')
      setTermsUrl(source.termsUrl ?? '')
      setDataDeletionUrl(source.dataDeletionUrl ?? '')
      setSupportUrl(source.supportUrl ?? '')
      setContacts(source.contacts ?? [])
    }
  }, [open, source])

  // The project decides which installation scopes an app may request; the
  // update endpoint rejects anything outside that list.
  const installationScopeOptions = useMemo(
    () =>
      installationScopeCatalog.map((scope) => ({
        value: scope.value,
        description: scope.description || undefined,
        category: scope.category || undefined,
        deprecated: scope.deprecated,
      })),
    [installationScopeCatalog],
  )
  const allowedInstallationScopes = useMemo(
    () => new Set(installationScopeCatalog.map((scope) => scope.value)),
    [installationScopeCatalog],
  )
  const droppedInstallationScopes = useMemo(
    () =>
      installationScopesLoading
        ? []
        : (source?.installationScopes ?? []).filter(
            (scope) => !allowedInstallationScopes.has(scope),
          ),
    [
      installationScopesLoading,
      source?.installationScopes,
      allowedInstallationScopes,
    ],
  )

  // Device flow needs a project-level verification URL (Server tab). Apps
  // that already have it on can still turn it off.
  const deviceFlowConfigured = !!projectData?.oAuth2ServerVerificationUrl
  const deviceFlowLocked = !deviceFlowConfigured && !deviceFlow

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    const uris = nonEmptyList(redirectUris)
    if (!name.trim() || isPending || readOnly) return

    const consentPayload = {
      tagline: tagline.trim(),
      description: description.trim(),
      tags: nonEmptyList(tags),
      enabled,
      type: clientType,
      deviceFlow,
      redirectUris: uris,
      postLogoutRedirectUris: nonEmptyList(postLogoutRedirectUris),
      clientUri: clientUri.trim(),
      logoUri: logoUri.trim(),
      images: nonEmptyList(images),
      privacyPolicyUrl: privacyPolicyUrl.trim(),
      termsUrl: termsUrl.trim(),
      dataDeletionUrl: dataDeletionUrl.trim(),
      supportUrl: supportUrl.trim(),
      contacts: nonEmptyList(contacts),
    }

    try {
      if (isEditing && source) {
        await updateMutation.mutateAsync({
          appId: source.$id,
          name: name.trim(),
          ...consentPayload,
          // Installation settings are only accepted on update; always send
          // them so the endpoint does not reset them.
          installationScopes: installationScopesLoading
            ? installationScopes
            : installationScopes.filter((scope) =>
                allowedInstallationScopes.has(scope),
              ),
          installationRedirectUrl: installationRedirectUrl.trim(),
        })
        toast.success(t('App updated'))
        handleOpenChange(false)
        onSuccess?.()
        return
      }

      const created = await createMutation.mutateAsync({
        name: name.trim(),
        ...consentPayload,
        description: description.trim() || undefined,
        tagline: tagline.trim() || undefined,
        clientUri: clientUri.trim() || undefined,
        logoUri: logoUri.trim() || undefined,
        privacyPolicyUrl: privacyPolicyUrl.trim() || undefined,
        termsUrl: termsUrl.trim() || undefined,
        dataDeletionUrl: dataDeletionUrl.trim() || undefined,
        supportUrl: supportUrl.trim() || undefined,
      })
      toast.success(t('OAuth2 app created'))

      if (created.type !== 'public') {
        try {
          const secret = await createSecretMutation.mutateAsync(created.$id)
          onOpenChange(false)
          window.setTimeout(() => setNewSecretPlaintext(secret.secret), 0)
          return
        } catch {
          // App created; secret can be added later when editing
        }
      }

      handleOpenChange(false)
      onSuccess?.()
    } catch (error) {
      toast.error(
        getErrorMessage(
          error,
          isEditing
            ? t('Failed to update app')
            : t('Failed to create OAuth2 app'),
        ),
      )
    }
  }

  const handleCreateSecret = async () => {
    if (!source) return
    try {
      const created = await createSecretMutation.mutateAsync(source.$id)
      setNewSecretPlaintext(created.secret)
      toast.success(t('OAuth secret created'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create OAuth secret')))
    }
  }

  const handleDeleteSecret = async () => {
    if (!source || !deleteSecretId) return
    try {
      await deleteSecretMutation.mutateAsync({
        appId: source.$id,
        secretId: deleteSecretId,
      })
      toast.success(t('OAuth secret deleted'))
      setDeleteSecretId(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete OAuth secret')))
    }
  }

  const handleRevokeTokens = async () => {
    if (!source) return
    try {
      await revokeTokensMutation.mutateAsync(source.$id)
      toast.success(t('Tokens revoked'))
      setRevokeDialogOpen(false)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to revoke tokens')))
    }
  }

  const handleRequestDelete = () => {
    if (!source || !onDelete || isPending) return
    handleOpenChange(false)
    window.setTimeout(() => onDelete(source), 0)
  }

  const canSubmit = !!name.trim() && !readOnly
  const fieldsDisabled = isPending || readOnly

  const deviceFlowSwitch = (
    <Switch
      id="oauth2-app-device-flow"
      checked={deviceFlow}
      disabled={fieldsDisabled || deviceFlowLocked}
      onCheckedChange={setDeviceFlow}
    />
  )

  return (
    <>
      <BaseDrawer
        open={open}
        onOpenChange={handleOpenChange}
        title={isEditing ? t('Update app') : t('Create OAuth2 app')}
        description={
          isEditing
            ? t(
                'OAuth2 client settings, consent screen, and marketplace details.',
              )
            : t(
                "Register a client that can authenticate users through this project's OAuth2 server.",
              )
        }
        maxWidth="sm:max-w-2xl"
      >
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <div className="shrink-0 border-t border-border" />
          <form
            onSubmit={handleSubmit}
            className="flex min-h-0 flex-1 flex-col overflow-hidden"
          >
            <div className="min-h-0 flex-1 overflow-y-auto">
              <div className="space-y-5 px-6 py-6">
                <div className="space-y-4">
                  {readOnly ? (
                    <Alert className="border-border bg-muted/30">
                      <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                      <AlertTitle className="text-[12px] font-medium">
                        {t('Managed by Appwrite')}
                      </AlertTitle>
                      <AlertDescription className="text-[12px] text-muted-foreground">
                        {t(
                          'Official apps are maintained by Appwrite and cannot be changed here.',
                        )}
                      </AlertDescription>
                    </Alert>
                  ) : null}

                  {isEditing && source ? (
                    <div className="space-y-2">
                      <Label className="text-[12px] font-medium">
                        {t('Client ID')}
                      </Label>
                      <div className="flex flex-wrap items-center gap-2">
                        <CopyableId id={source.$id} />
                        <OAuth2AppLabelBadges labels={source.labels} />
                      </div>
                    </div>
                  ) : null}

                  <div className="flex items-center justify-between gap-4">
                    <div>
                      <Label
                        htmlFor="oauth2-app-enabled"
                        className="text-[13px]"
                      >
                        {t('Enabled')}
                      </Label>
                      <p className="mt-0.5 text-[12px] text-muted-foreground">
                        {enabled
                          ? t('This client can request authorization.')
                          : t('This client is disabled.')}
                      </p>
                    </div>
                    <Switch
                      id="oauth2-app-enabled"
                      checked={enabled}
                      disabled={fieldsDisabled}
                      onCheckedChange={setEnabled}
                    />
                  </div>

                  <OAuth2ClientTypePicker
                    value={clientType}
                    onChange={setClientType}
                    disabled={fieldsDisabled}
                  />

                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Redirect URIs')}
                    </Label>
                    <InputTags
                      value={redirectUris}
                      onChange={setRedirectUris}
                      splitOnComma
                      placeholder={t('Add redirect URI and press Enter')}
                      disabled={fieldsDisabled}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Post-logout redirect URIs')}
                    </Label>
                    <InputTags
                      value={postLogoutRedirectUris}
                      onChange={setPostLogoutRedirectUris}
                      splitOnComma
                      placeholder={t('Add post-logout URI and press Enter')}
                      disabled={fieldsDisabled}
                    />
                  </div>

                  <div className="flex items-center justify-between gap-4">
                    <div>
                      <Label
                        htmlFor="oauth2-app-device-flow"
                        className="text-[13px]"
                      >
                        {t('Device flow')}
                      </Label>
                      <p className="mt-0.5 text-[12px] text-muted-foreground">
                        {t(OAUTH2_DEVICE_FLOW_DESCRIPTION)}
                      </p>
                    </div>
                    {deviceFlowLocked ? (
                      <TooltipProvider>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="inline-flex">
                              {deviceFlowSwitch}
                            </span>
                          </TooltipTrigger>
                          <TooltipContent className="max-w-xs text-[12px]">
                            {t(
                              'Set a verification URL on the Server tab to enable the device authorization grant.',
                            )}
                          </TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                    ) : (
                      deviceFlowSwitch
                    )}
                  </div>

                  {isEditing ? (
                    <div className="space-y-3 rounded-lg border border-border p-3">
                      <div>
                        <h4 className="text-[13px] font-semibold text-foreground">
                          {t('Installation settings')}
                        </h4>
                        <p className="mt-1 text-[12px] text-muted-foreground">
                          {t(
                            'Teams in this project can install the app to let it act on their behalf with the scopes below.',
                          )}
                        </p>
                      </div>
                      <div className="space-y-2">
                        <Label className="text-[12px] font-medium">
                          {t('Installation scopes')}
                        </Label>
                        <OAuth2ScopePicker
                          idPrefix="oauth2-app-installation-scope"
                          options={installationScopeOptions}
                          value={installationScopes}
                          onChange={setInstallationScopes}
                          disabled={fieldsDisabled}
                          emptyMessage={
                            installationScopesLoading
                              ? t('Loading scopes...')
                              : t(
                                  'No installation scopes are configured for this project. Configure them on the Server tab first.',
                                )
                          }
                        />
                        {droppedInstallationScopes.length > 0 ? (
                          <p className="text-[12px] text-amber-600 dark:text-amber-400">
                            {t(
                              'Some granted scopes are no longer allowed by the project and will be removed on update:',
                            )}{' '}
                            <code className="font-mono">
                              {droppedInstallationScopes.join(', ')}
                            </code>
                          </p>
                        ) : null}
                      </div>
                      <div className="space-y-2">
                        <Label
                          htmlFor="oauth2-app-installation-redirect-url"
                          className="text-[12px] font-medium"
                        >
                          {t('Installation redirect URL')}
                        </Label>
                        <Input
                          id="oauth2-app-installation-redirect-url"
                          value={installationRedirectUrl}
                          onChange={(e) =>
                            setInstallationRedirectUrl(e.target.value)
                          }
                          placeholder="https://example.com/installed"
                          className="h-9 text-[13px]"
                          disabled={fieldsDisabled}
                        />
                        <p className="text-[12px] text-muted-foreground">
                          {t(
                            'Optional. Users land here after creating or updating an installation.',
                          )}
                        </p>
                      </div>
                    </div>
                  ) : null}

                  {isEditing && clientType !== 'public' ? (
                    <div className="space-y-3 rounded-lg border border-border overflow-hidden">
                      <div className="flex items-start justify-between gap-3 px-3 py-3">
                        <div>
                          <div className="flex items-center gap-2">
                            <h4 className="text-[13px] font-semibold text-foreground">
                              {t('OAuth secrets')}
                            </h4>
                            {!secretsLoading && secrets.length > 0 ? (
                              <Badge
                                variant="info"
                                className="text-[10px] shrink-0"
                              >
                                {secrets.length} {t('active')}
                              </Badge>
                            ) : null}
                          </div>
                          <p className="mt-1 text-[12px] text-muted-foreground">
                            {t(
                              'Confidential clients authenticate token exchanges with a secret. Rotate regularly and store values in a secrets manager.',
                            )}
                          </p>
                        </div>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          className="h-8 shrink-0 text-[12px]"
                          disabled={fieldsDisabled}
                          onClick={handleCreateSecret}
                        >
                          <Plus className="me-1.5 h-3.5 w-3.5" />
                          {t('Create secret')}
                        </Button>
                      </div>
                      <div className="border-t border-border" />
                      <div className="space-y-3 px-3 py-3">
                        <Alert className="border-border bg-muted/30">
                          <Lock className="h-4 w-4 text-muted-foreground" />
                          <AlertTitle className="text-[12px] font-medium">
                            {t('Server-side only')}
                          </AlertTitle>
                          <AlertDescription className="text-[12px] text-muted-foreground">
                            {t(
                              'Never embed OAuth secrets in mobile apps, SPAs, or public repositories. Use environment variables such as',
                            )}{' '}
                            <code className="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">
                              OAUTH_CLIENT_SECRET
                            </code>
                            .
                          </AlertDescription>
                        </Alert>

                        {secretsLoading ? (
                          <div className="flex justify-center py-6">
                            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                          </div>
                        ) : secrets.length === 0 ? (
                          <EmptyState
                            icon={Key}
                            title={t('No OAuth secrets')}
                            description={t(
                              'Create a secret for confidential OAuth flows such as authorization code with server-side token exchange.',
                            )}
                            variant="card"
                          />
                        ) : (
                          <div className="overflow-hidden rounded-lg border border-border divide-y divide-border">
                            {secrets.map((secret) => (
                              <div
                                key={secret.$id}
                                className="flex items-center justify-between gap-3 p-3"
                              >
                                <div className="min-w-0">
                                  <p className="font-mono text-[12px] font-medium truncate">
                                    secret_{secret.hint}
                                  </p>
                                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                                    <span className="whitespace-nowrap">
                                      {t('Created')}{' '}
                                      <DateTooltip
                                        date={secret.$createdAt}
                                        className="text-[11px] text-muted-foreground"
                                      />
                                    </span>
                                    {secret.createdByName ? (
                                      <span className="whitespace-nowrap">
                                        {t('Created by')} {secret.createdByName}
                                      </span>
                                    ) : null}
                                    <span className="whitespace-nowrap">
                                      {secret.lastAccessedAt ? (
                                        <>
                                          {t('Last used')}{' '}
                                          <DateTooltip
                                            date={secret.lastAccessedAt}
                                            className="text-[11px] text-muted-foreground"
                                          />
                                        </>
                                      ) : (
                                        t('Never used')
                                      )}
                                    </span>
                                  </div>
                                  <code className="mt-1 block truncate font-mono text-[11px] text-muted-foreground">
                                    {maskClientSecret(secret.hint)}
                                  </code>
                                </div>
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <RowActionsMenuTrigger />
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                      onClick={() =>
                                        setDeleteSecretId(secret.$id)
                                      }
                                    >
                                      <MenuItemContent icon={Trash2}>
                                        {t('Delete')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    </div>
                  ) : null}

                  {isEditing && source ? (
                    <>
                      <OAuth2AppKeysCard
                        embedded
                        dialogClassName={NESTED_DIALOG_CLASS}
                        keys={keys}
                        isLoading={keysLoading}
                        onCreate={() =>
                          createKeyMutation.mutateAsync(source.$id)
                        }
                        onDelete={(keyId) =>
                          deleteKeyMutation.mutateAsync({
                            appId: source.$id,
                            keyId,
                          })
                        }
                        isCreating={createKeyMutation.isPending || readOnly}
                        isDeleting={deleteKeyMutation.isPending}
                      />
                      <OAuth2AppInstallationsCard
                        embedded
                        dialogClassName={NESTED_DIALOG_CLASS}
                        installations={installations}
                        isLoading={installationsLoading}
                        onDelete={(installationId) =>
                          deleteInstallationMutation.mutateAsync({
                            appId: source.$id,
                            installationId,
                          })
                        }
                        isDeleting={deleteInstallationMutation.isPending}
                        teamLabel={t('Team')}
                      />
                    </>
                  ) : null}

                  {isEditing ? (
                    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                      <div className="px-4 py-3">
                        <h3 className="text-[13px] font-semibold text-foreground">
                          {t('Revoke tokens')}
                        </h3>
                      </div>
                      <div className="border-t border-destructive/20" />
                      <div className="px-4 py-3">
                        <p className="text-[12px] text-muted-foreground">
                          {t(
                            'Invalidate all access and refresh tokens issued to this client. Users will need to authorize the app again.',
                          )}
                        </p>
                      </div>
                      <div className="px-4 py-3 border-t border-destructive/20 bg-destructive/5">
                        <Button
                          type="button"
                          variant="destructive"
                          size="sm"
                          className="h-8 text-[12px]"
                          disabled={isPending}
                          onClick={() => setRevokeDialogOpen(true)}
                        >
                          {t('Revoke all tokens')}
                        </Button>
                      </div>
                    </div>
                  ) : null}
                </div>

                <DrawerSection
                  title={t('Branding')}
                  description={t('May appear on the OAuth2 consent screen.')}
                >
                  <div className="space-y-2">
                    <Label
                      htmlFor="oauth2-app-name"
                      className="text-[12px] font-medium"
                    >
                      {t('Name')} <span className="text-destructive">*</span>
                    </Label>
                    <Input
                      id="oauth2-app-name"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder={t('My application')}
                      className="h-9 text-[13px]"
                      disabled={fieldsDisabled}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Logo')}
                    </Label>
                    {teamId ? (
                      <AppLogoFilePicker
                        teamId={teamId}
                        region={region ?? project?.region}
                        value={logoUri}
                        onChange={setLogoUri}
                        disabled={fieldsDisabled}
                      />
                    ) : (
                      <Input
                        value={logoUri}
                        onChange={(e) => setLogoUri(e.target.value)}
                        placeholder="https://example.com/logo.png"
                        className="h-9 text-[13px]"
                        disabled={fieldsDisabled}
                      />
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label
                      htmlFor="oauth2-app-tagline"
                      className="text-[12px] font-medium"
                    >
                      {t('Tagline')}
                    </Label>
                    <Input
                      id="oauth2-app-tagline"
                      value={tagline}
                      onChange={(e) => setTagline(e.target.value)}
                      placeholder={t('Short summary for the consent screen')}
                      className="h-9 text-[13px]"
                      disabled={fieldsDisabled}
                    />
                  </div>
                </DrawerSection>

                <DrawerSection
                  title={t('Marketplace')}
                  description={t(
                    'Additional listing details for the marketplace.',
                  )}
                >
                  <div className="space-y-2">
                    <Label
                      htmlFor="oauth2-app-description"
                      className="text-[12px] font-medium"
                    >
                      {t('Description')}
                    </Label>
                    <MarkdownEditor
                      id="oauth2-app-description"
                      value={description}
                      onChange={setDescription}
                      placeholder={t(
                        'Optional description for the marketplace listing',
                      )}
                      disabled={fieldsDisabled}
                      rows={5}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Homepage URL')}
                    </Label>
                    <Input
                      value={clientUri}
                      onChange={(e) => setClientUri(e.target.value)}
                      placeholder="https://example.com"
                      className="h-9 text-[13px]"
                      disabled={fieldsDisabled}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Tags')}
                    </Label>
                    <InputTags
                      value={tags}
                      onChange={setTags}
                      splitOnComma
                      placeholder={t('Add tag and press Enter')}
                      disabled={fieldsDisabled}
                    />
                    <p className="text-[12px] text-muted-foreground">
                      {t('Optional labels for marketplace discovery.')}
                    </p>
                  </div>

                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Images')}
                    </Label>
                    {teamId ? (
                      <AppImagesPicker
                        teamId={teamId}
                        region={region ?? project?.region}
                        value={images}
                        onChange={setImages}
                        disabled={fieldsDisabled}
                      />
                    ) : (
                      <p className="text-[12px] text-muted-foreground">
                        {t(
                          'Optional screenshots shown on the marketplace listing.',
                        )}
                      </p>
                    )}
                  </div>
                </DrawerSection>

                <DrawerSection
                  title={t('Privacy and support')}
                  description={t(
                    'Links and contacts for marketplace, compliance, and support.',
                  )}
                >
                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Privacy policy')}
                    </Label>
                    <Input
                      value={privacyPolicyUrl}
                      onChange={(e) => setPrivacyPolicyUrl(e.target.value)}
                      placeholder="https://example.com/privacy"
                      className="h-9 text-[13px]"
                      disabled={fieldsDisabled}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Terms of service')}
                    </Label>
                    <Input
                      value={termsUrl}
                      onChange={(e) => setTermsUrl(e.target.value)}
                      placeholder="https://example.com/terms"
                      className="h-9 text-[13px]"
                      disabled={fieldsDisabled}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Data deletion')}
                    </Label>
                    <Input
                      value={dataDeletionUrl}
                      onChange={(e) => setDataDeletionUrl(e.target.value)}
                      placeholder="https://example.com/delete-data"
                      className="h-9 text-[13px]"
                      disabled={fieldsDisabled}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Support page')}
                    </Label>
                    <Input
                      value={supportUrl}
                      onChange={(e) => setSupportUrl(e.target.value)}
                      placeholder="https://example.com/support"
                      className="h-9 text-[13px]"
                      disabled={fieldsDisabled}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Contact emails')}
                    </Label>
                    <InputTags
                      value={contacts}
                      onChange={setContacts}
                      validateEmail
                      splitOnComma
                      placeholder={t('Add email and press Enter')}
                      disabled={fieldsDisabled}
                    />
                  </div>
                </DrawerSection>
              </div>
            </div>

            <div className="mt-auto shrink-0 border-t border-border bg-muted/30 px-6 py-4 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
              {isEditing && onDelete ? (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={fieldsDisabled}
                  onClick={handleRequestDelete}
                >
                  <Trash2 className="me-1.5 h-3.5 w-3.5" />
                  {t('Delete')}
                </Button>
              ) : (
                <span />
              )}
              <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  disabled={isPending}
                  onClick={() => handleOpenChange(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button type="submit" disabled={isPending || !canSubmit}>
                  {isEditing ? t('Update') : t('Create')}
                </Button>
              </div>
            </div>
          </form>
        </div>
      </BaseDrawer>

      <Dialog
        open={newSecretPlaintext !== null}
        onOpenChange={(next) => {
          if (!next) {
            setNewSecretPlaintext(null)
            onSuccess?.()
          }
        }}
      >
        <DialogContent
          className={cn(
            'sm:max-w-lg p-0 max-h-[90dvh] flex flex-col overflow-hidden',
            NESTED_DIALOG_CLASS,
          )}
          overlayClassName={NESTED_DIALOG_CLASS}
        >
          <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('OAuth secret created')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Copy this value now. For security, the full secret cannot be retrieved after you close this dialog.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="shrink-0 border-t border-border" />
          <div className="flex-1 min-h-0 overflow-y-auto px-6 py-4 space-y-4">
            <Alert className="border-amber-500/20 bg-amber-500/10 text-foreground">
              <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
              <AlertTitle className="text-[13px] font-medium">
                {t('One-time display')}
              </AlertTitle>
              <AlertDescription className="text-[12px] text-muted-foreground">
                {t(
                  'Store this secret in your deployment environment before continuing. Active sessions using a deleted secret will fail token refresh immediately.',
                )}
              </AlertDescription>
            </Alert>
            <div className="min-w-0 overflow-hidden rounded-lg border border-border bg-muted/20">
              <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2">
                <div className="flex min-w-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  <Terminal className="h-3.5 w-3.5 shrink-0" />
                  {t('Secret value')}
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 shrink-0 px-2 text-[12px]"
                  onClick={async () => {
                    if (!newSecretPlaintext) return
                    await copyToClipboard('OAuth secret', newSecretPlaintext)
                    setCopiedNewSecret(true)
                    setTimeout(() => setCopiedNewSecret(false), 2000)
                  }}
                >
                  {copiedNewSecret ? (
                    <Check className="me-1.5 h-3.5 w-3.5 text-emerald-500" />
                  ) : (
                    <Copy className="me-1.5 h-3.5 w-3.5" />
                  )}
                  {t('Copy')}
                </Button>
              </div>
              <pre
                className={cn(
                  'max-h-[min(30dvh,200px)] overflow-auto p-4 font-mono text-[13px] leading-relaxed text-foreground',
                  'break-all whitespace-pre-wrap',
                )}
              >
                {newSecretPlaintext}
              </pre>
            </div>
          </div>
          <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
            <Button
              onClick={() => {
                setNewSecretPlaintext(null)
                onSuccess?.()
              }}
            >
              {t('Done')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog
        open={deleteSecretId !== null}
        onOpenChange={(next) => {
          if (!next) setDeleteSecretId(null)
        }}
      >
        <DialogContent
          className={cn('sm:max-w-md p-0', NESTED_DIALOG_CLASS)}
          overlayClassName={NESTED_DIALOG_CLASS}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete OAuth secret')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Token refresh and authorization flows using this secret will stop working immediately. This cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteSecretId(null)}
              disabled={deleteSecretMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              disabled={deleteSecretMutation.isPending}
              onClick={handleDeleteSecret}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={revokeDialogOpen} onOpenChange={setRevokeDialogOpen}>
        <DialogContent
          className={cn('sm:max-w-md p-0', NESTED_DIALOG_CLASS)}
          overlayClassName={NESTED_DIALOG_CLASS}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Revoke all tokens')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'All active tokens for this client will stop working immediately. This cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setRevokeDialogOpen(false)}
              disabled={revokeTokensMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              disabled={revokeTokensMutation.isPending}
              onClick={handleRevokeTokens}
            >
              {t('Revoke all tokens')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
