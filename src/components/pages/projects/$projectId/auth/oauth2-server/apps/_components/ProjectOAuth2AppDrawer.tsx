import { useEffect, useState, type ReactNode } from 'react'
import type { Models } from '@appwrite.io/console'
import {
  AlertTriangle,
  Check,
  Copy,
  Key,
  Loader2,
  Lock,
  Plus,
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
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { InputTags } from '@/components/ui/input-tags'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
  useCreateProjectOAuth2AppSecret,
  useDeleteProjectOAuth2App,
  useDeleteProjectOAuth2AppSecret,
  useDeleteProjectOAuth2AppTokens,
  useProject,
  useProjectOAuth2App,
  useProjectOAuth2AppSecrets,
  useUpdateProjectOAuth2App,
} from '@/lib/react-query/hooks'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { AppLogoFilePicker } from '@/components/pages/organizations/$orgId/apps/_components/AppLogoFilePicker'
import { AppImagesPicker } from '@/components/pages/organizations/$orgId/apps/_components/AppImagesPicker'

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
          <p className="mt-1 text-[12px] text-muted-foreground">{description}</p>
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
  const { project } = useProject(projectId)
  const teamId = project?.teamId ?? ''

  const { app: fullApp } = useProjectOAuth2App(
    projectId,
    isEditing ? app?.$id : null,
    region,
  )
  const source = fullApp ?? app

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
  const { secrets, isLoading: secretsLoading } = useProjectOAuth2AppSecrets(
    projectId,
    isEditing && source?.type !== 'public' ? source?.$id : null,
    region,
  )

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

  const handleOpenChange = (next: boolean) => {
    if (!isPending) onOpenChange(next)
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    const uris = nonEmptyList(redirectUris)
    if (!name.trim() || uris.length === 0 || isPending) return

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

  const canSubmit = !!name.trim() && nonEmptyList(redirectUris).length > 0

  return (
    <>
      <BaseDrawer
        open={open}
        onOpenChange={handleOpenChange}
        title={isEditing ? t('Update app') : t('Create OAuth2 app')}
        description={
          isEditing
            ? t('OAuth2 client settings, consent screen, and marketplace details.')
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
                  {isEditing && source ? (
                    <div className="space-y-2">
                      <Label className="text-[12px] font-medium">
                        {t('Client ID')}
                      </Label>
                      <CopyableId id={source.$id} />
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
                      disabled={isPending}
                      onCheckedChange={setEnabled}
                    />
                  </div>

                  <OAuth2ClientTypePicker
                    value={clientType}
                    onChange={setClientType}
                    disabled={isPending}
                  />

                  <div className="space-y-2">
                    <Label className="text-[12px] font-medium">
                      {t('Redirect URIs')}{' '}
                      <span className="text-destructive">*</span>
                    </Label>
                    <InputTags
                      value={redirectUris}
                      onChange={setRedirectUris}
                      splitOnComma
                      placeholder={t('Add redirect URI and press Enter')}
                      disabled={isPending}
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
                      disabled={isPending}
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
                    <Switch
                      id="oauth2-app-device-flow"
                      checked={deviceFlow}
                      disabled={isPending}
                      onCheckedChange={setDeviceFlow}
                    />
                  </div>

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
                          disabled={isPending}
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
                                  <p className="mt-1 text-[11px] text-muted-foreground">
                                    {t('Created')}{' '}
                                    <DateTooltip
                                      date={secret.$createdAt}
                                      className="text-[11px] text-muted-foreground"
                                    />
                                  </p>
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
                  description={t(
                    'May appear on the OAuth2 consent screen.',
                  )}
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
                      disabled={isPending}
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
                        disabled={isPending}
                      />
                    ) : (
                      <Input
                        value={logoUri}
                        onChange={(e) => setLogoUri(e.target.value)}
                        placeholder="https://example.com/logo.png"
                        className="h-9 text-[13px]"
                        disabled={isPending}
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
                      disabled={isPending}
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
                      disabled={isPending}
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
                      disabled={isPending}
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
                      disabled={isPending}
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
                        disabled={isPending}
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
                      disabled={isPending}
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
                      disabled={isPending}
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
                      disabled={isPending}
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
                      disabled={isPending}
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
                      disabled={isPending}
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
                  disabled={isPending}
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
        <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] flex flex-col overflow-hidden">
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
        <DialogContent className="sm:max-w-md p-0">
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
        <DialogContent className="sm:max-w-md p-0">
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
