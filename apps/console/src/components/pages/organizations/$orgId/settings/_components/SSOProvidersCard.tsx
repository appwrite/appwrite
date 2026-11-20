import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { KeyRound, Link2, Pencil, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { sdk } from '@/lib/appwrite/sdk'
import type { SSOProvider } from '@/lib/appwrite/team-sso'
import { useT } from '@/lib/i18n/translate'
import {
  organizationQueryOptions,
  useDeleteOrganizationSSOProvider,
  useOrganizationEmailDomains,
  useOrganizationSSOProviders,
  useSaveOrganizationSSOProvider,
} from '@/lib/react-query/hooks'
import { buildConsoleUrl, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { EmailDomainRoles, emailDomainRoleLabel } from './EmailDomainRoles'

const HEAD =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/** The organization's identity providers: each signs people in with its own roles. */
export function SSOProvidersCard() {
  const t = useT()
  const { orgId = '' } = useParams({ strict: false })
  const { providers } = useOrganizationSSOProviders(orgId)
  const { domains } = useOrganizationEmailDomains(orgId)
  const { data: organization } = useQuery(organizationQueryOptions(orgId))
  const save = useSaveOrganizationSSOProvider(orgId)
  const remove = useDeleteOrganizationSSOProvider(orgId)

  const hasVerifiedDomain = domains.some(
    (domain) => domain.status === 'verified',
  )
  const required =
    (organization as { ssoRequired?: boolean } | null | undefined)
      ?.ssoRequired === true
  /** Whether `provider` is the only one people can sign in through while SSO is required. */
  const isLastEnabledWhileRequired = (provider: SSOProvider) =>
    required &&
    provider.enabled &&
    providers.filter((item) => item.enabled).length === 1

  const [editing, setEditing] = useState<SSOProvider | 'new' | null>(null)
  const [deleting, setDeleting] = useState<SSOProvider | null>(null)

  const toggle = (provider: SSOProvider, enabled: boolean) => {
    save.mutate(
      {
        providerId: provider.$id,
        params: {
          name: provider.name,
          roles: provider.roles,
          clientId: provider.clientId,
          wellKnownURL: provider.wellKnownURL,
          authorizationURL: provider.authorizationURL,
          tokenURL: provider.tokenURL,
          userInfoURL: provider.userInfoURL,
          enabled,
        },
      },
      {
        onSuccess: () =>
          toast.success(
            enabled
              ? t('Provider enabled')
              : isLastEnabledWhileRequired(provider)
                ? t(
                    'Provider disabled. Require SSO is paused until you enable a provider.',
                  )
                : t('Provider disabled'),
          ),
        onError: (failure: Error) =>
          toast.error(
            getErrorMessage(failure) || t('Failed to update provider'),
          ),
      },
    )
  }

  const confirmDelete = () => {
    if (!deleting) return
    const provider = deleting
    closeDialogBeforeOverlayUnmount(() => setDeleting(null))
    remove.mutate(provider.$id, {
      onSuccess: () => toast.success(t('Identity provider deleted')),
      onError: (failure: Error) =>
        toast.error(getErrorMessage(failure) || t('Failed to delete provider')),
    })
  }

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <h3 className="flex items-center gap-2 text-[15px] font-semibold text-foreground">
            {t('Identity providers')}
            <Badge variant="outline">OIDC</Badge>
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'People on your verified domains sign in with your company login, like Okta, Microsoft Entra ID, Google Workspace or Keycloak, and join with the provider’s roles.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        {providers.length > 0 && !hasVerifiedDomain ? (
          <div className="border-b border-border px-6 py-4">
            <Alert>
              <AlertDescription className="text-[13px]">
                {t(
                  'Nobody can sign in through these providers yet. Verify a domain first.',
                )}
              </AlertDescription>
            </Alert>
          </div>
        ) : null}
        {providers.length === 0 ? (
          <div className="px-6 py-4">
            <EmptyState
              icon={KeyRound}
              title={t('No identity providers yet')}
              description={t(
                'Connect your company login so people on your domains sign in with it.',
              )}
              isEmpty
              variant="default"
              className="py-2"
            />
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={HEAD}>{t('Name')}</TableHead>
                <TableHead className={`${HEAD} hidden sm:table-cell`}>
                  {t('Roles')}
                </TableHead>
                <TableHead className={HEAD}>{t('Enabled')}</TableHead>
                <TableHead className={`${HEAD} w-[80px] text-right`} />
              </TableRow>
            </TableHeader>
            <TableBody>
              {providers.map((provider) => (
                <TableRow key={provider.$id}>
                  <TableCell className="max-w-[10rem] px-4 py-3 sm:max-w-none">
                    <div
                      className="truncate text-[13px] font-medium"
                      title={provider.name}
                    >
                      {provider.name}
                    </div>
                    <div
                      className="truncate font-mono text-[12px] text-muted-foreground"
                      title={provider.clientId}
                    >
                      {provider.clientId}
                    </div>
                  </TableCell>
                  <TableCell className="hidden px-4 py-3 sm:table-cell">
                    <div className="flex flex-wrap gap-1">
                      {provider.roles.map((role) => (
                        <Badge key={role} variant="outline">
                          {t(emailDomainRoleLabel(role))}
                        </Badge>
                      ))}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <Switch
                      checked={provider.enabled}
                      onCheckedChange={(enabled) => toggle(provider, enabled)}
                      aria-label={`${t('Enable')} ${provider.name}`}
                      disabled={save.isPending}
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger
                          aria-label={`${t('Actions for')} ${provider.name}`}
                        />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-56">
                        {/* The link to share with the team: it opens this provider's sign-in directly. */}
                        <DropdownMenuItem
                          onClick={() =>
                            copyToClipboard(
                              t('SSO URL'),
                              buildConsoleUrl(
                                `/sso?provider=${encodeURIComponent(provider.$id)}`,
                              ),
                            )
                          }
                        >
                          <MenuItemContent icon={Link2}>
                            {t('Copy SSO URL')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => setEditing(provider)}>
                          <MenuItemContent icon={Pencil}>
                            {t('Update')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => setDeleting(provider)}>
                          <MenuItemContent icon={Trash2}>
                            {t('Delete')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
        <div className="border-t border-border bg-muted/30 px-6 py-4">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => setEditing('new')}
            {...analyticsAttrs('create-sso-provider')}
          >
            {t('Add provider')}
          </Button>
        </div>
      </div>

      <ProviderDialog
        provider={editing}
        organizationId={orgId}
        onOpenChange={(open) => !open && setEditing(null)}
      />

      <Dialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete identity provider')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')} "{deleting?.name}"?{' '}
              {t(
                'People can no longer sign in through it. People who already joined stay members.',
              )}
              {deleting && isLastEnabledWhileRequired(deleting)
                ? ` ${t('Require SSO is on and this is your only enabled provider: deleting it pauses Require SSO, and members can sign in any way they like.')}`
                : null}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleting(null)}
              disabled={remove.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              disabled={remove.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

/** Add an OpenID Connect provider, or update one: `provider` is `'new'` to add. */
function ProviderDialog({
  provider,
  organizationId,
  onOpenChange,
}: {
  provider: SSOProvider | 'new' | null
  organizationId: string
  onOpenChange: (open: boolean) => void
}) {
  const open = provider !== null
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl p-0">
        {open ? (
          <ProviderForm
            key={provider === 'new' ? 'new' : provider.$id}
            provider={provider === 'new' ? null : provider}
            organizationId={organizationId}
            onDone={() => onOpenChange(false)}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}

function ProviderForm({
  provider,
  organizationId,
  onDone,
}: {
  provider: SSOProvider | null
  organizationId: string
  onDone: () => void
}) {
  const t = useT()
  const save = useSaveOrganizationSSOProvider(organizationId)
  const [name, setName] = useState(provider?.name ?? '')
  const [roles, setRoles] = useState<string[]>(provider?.roles ?? ['developer'])
  const [clientId, setClientId] = useState(provider?.clientId ?? '')
  const [clientSecret, setClientSecret] = useState('')
  const [wellKnownURL, setWellKnownURL] = useState(provider?.wellKnownURL ?? '')
  const [authorizationURL, setAuthorizationURL] = useState(
    provider?.authorizationURL ?? '',
  )
  const [tokenURL, setTokenURL] = useState(provider?.tokenURL ?? '')
  const [userInfoURL, setUserInfoURL] = useState(provider?.userInfoURL ?? '')
  const [enabled, setEnabled] = useState(provider?.enabled ?? true)
  const [advanced, setAdvanced] = useState(
    !!(
      provider?.authorizationURL ||
      provider?.tokenURL ||
      provider?.userInfoURL
    ),
  )

  // Missing fields are named once the person tries to save.
  const [submitted, setSubmitted] = useState(false)

  const redirectURL = sdk.forConsole.teamSSO.redirectURL()
  const hasEndpoints =
    wellKnownURL.trim() !== '' ||
    (authorizationURL.trim() !== '' &&
      tokenURL.trim() !== '' &&
      userInfoURL.trim() !== '')
  /** The client secret travels to these, so they have to be `https://`. */
  const urlError = (value: string): string | null => {
    const trimmed = value.trim()
    if (trimmed === '') return null
    try {
      return new URL(trimmed).protocol === 'https:'
        ? null
        : t('Use an https:// URL.')
    } catch {
      return t('Enter a full URL, starting with https://')
    }
  }
  const required = (value: string) =>
    submitted && value.trim() === '' ? t('Required') : null
  const errors = {
    name: required(name),
    clientId: required(clientId),
    clientSecret: provider === null ? required(clientSecret) : null,
    wellKnownURL:
      urlError(wellKnownURL) ??
      (submitted && !hasEndpoints
        ? t('Enter the discovery URL, or set all three endpoints manually.')
        : null),
    authorizationURL: urlError(authorizationURL),
    tokenURL: urlError(tokenURL),
    userInfoURL: urlError(userInfoURL),
  }
  const valid =
    name.trim() !== '' &&
    roles.length > 0 &&
    clientId.trim() !== '' &&
    (provider !== null || clientSecret.trim() !== '') &&
    hasEndpoints &&
    [wellKnownURL, authorizationURL, tokenURL, userInfoURL].every(
      (value) => urlError(value) === null,
    )

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    setSubmitted(true)
    if (!valid || save.isPending) return
    save.mutate(
      {
        providerId: provider?.$id,
        params: {
          name: name.trim(),
          roles,
          clientId: clientId.trim(),
          // A pasted secret often carries a trailing space or newline.
          ...(clientSecret.trim() !== ''
            ? { clientSecret: clientSecret.trim() }
            : {}),
          wellKnownURL: wellKnownURL.trim(),
          authorizationURL: authorizationURL.trim(),
          tokenURL: tokenURL.trim(),
          userInfoURL: userInfoURL.trim(),
          enabled,
        },
      },
      {
        onSuccess: () => {
          toast.success(
            provider
              ? t('Identity provider updated')
              : t('Identity provider added'),
          )
          onDone()
        },
        onError: (failure: Error) =>
          toast.error(getErrorMessage(failure) || t('Failed to save provider')),
      },
    )
  }

  const enabledRow = (
    <div className="flex items-center justify-between gap-4">
      <div>
        <Label htmlFor="sso-enabled" className="text-[13px] font-medium">
          {t('Enabled')}
        </Label>
        <p className="text-[12px] text-muted-foreground">
          {t('People can sign in through this provider.')}
        </p>
      </div>
      <Switch
        id="sso-enabled"
        checked={enabled}
        onCheckedChange={setEnabled}
        disabled={save.isPending}
      />
    </div>
  )

  return (
    <form onSubmit={handleSubmit}>
      <DialogHeader className="px-6 pt-6 pb-4 text-start">
        <DialogTitle>
          {provider
            ? t('Update identity provider')
            : t('Add identity provider')}
        </DialogTitle>
        <DialogDescription className="text-[13px] mt-2">
          {provider
            ? t(
                'Change how people sign in through this provider. Leave the client secret empty to keep the stored one.',
              )
            : t(
                'Create an OpenID Connect application at your provider, add the redirect URL there, then fill in its details.',
              )}
        </DialogDescription>
      </DialogHeader>
      <div className="border-t border-border" />
      <div className="max-h-[60vh] space-y-4 overflow-y-auto px-6 py-4">
        {/* Turning a provider on or off is the usual reason to update one. */}
        {provider ? enabledRow : null}
        <div className="space-y-2">
          <Label htmlFor="sso-redirect" className="text-[13px] font-medium">
            {t('Redirect URL')}
          </Label>
          <div className="flex items-center gap-2">
            <Input
              id="sso-redirect"
              value={redirectURL}
              readOnly
              className="font-mono text-[12px]"
            />
            <CodeSnippetCopyButton content={redirectURL} />
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="sso-name" className="text-[13px] font-medium">
            {t('Name')}
          </Label>
          <Input
            id="sso-name"
            value={name}
            placeholder="Okta"
            maxLength={128}
            onChange={(event) => setName(event.target.value)}
            aria-invalid={!!errors.name}
            disabled={save.isPending}
          />
          {errors.name ? (
            <p className="text-[12px] text-destructive">{errors.name}</p>
          ) : (
            <p className="text-[12px] text-muted-foreground">
              {t('People see the name when they choose how to sign in.')}
            </p>
          )}
        </div>
        <div className="space-y-2">
          <Label className="text-[13px] font-medium">{t('Roles')}</Label>
          <EmailDomainRoles
            value={roles}
            onChange={setRoles}
            disabled={save.isPending}
          />
          <p className="text-[12px] text-muted-foreground">
            {roles.length === 0
              ? t('Pick at least one role.')
              : t('Roles people get when they join through this provider.')}
          </p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="sso-client-id" className="text-[13px] font-medium">
              {t('Client ID')}
            </Label>
            <Input
              id="sso-client-id"
              value={clientId}
              autoComplete="off"
              maxLength={256}
              onChange={(event) => setClientId(event.target.value)}
              aria-invalid={!!errors.clientId}
              disabled={save.isPending}
            />
            {errors.clientId ? (
              <p className="text-[12px] text-destructive">{errors.clientId}</p>
            ) : null}
          </div>
          <div className="space-y-2">
            <Label
              htmlFor="sso-client-secret"
              className="text-[13px] font-medium"
            >
              {t('Client secret')}
            </Label>
            <Input
              id="sso-client-secret"
              type="password"
              value={clientSecret}
              autoComplete="new-password"
              placeholder={
                provider?.clientSecretSet ? t('Unchanged') : undefined
              }
              maxLength={512}
              onChange={(event) => setClientSecret(event.target.value)}
              aria-invalid={!!errors.clientSecret}
              disabled={save.isPending}
            />
            {errors.clientSecret ? (
              <p className="text-[12px] text-destructive">
                {errors.clientSecret}
              </p>
            ) : null}
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor="sso-well-known" className="text-[13px] font-medium">
            {t('Discovery URL')}
          </Label>
          <Input
            id="sso-well-known"
            value={wellKnownURL}
            placeholder="https://example.okta.com/.well-known/openid-configuration"
            maxLength={2048}
            onChange={(event) => setWellKnownURL(event.target.value)}
            aria-invalid={!!errors.wellKnownURL}
            disabled={save.isPending}
          />
          {errors.wellKnownURL ? (
            <p className="text-[12px] text-destructive">
              {errors.wellKnownURL}
            </p>
          ) : null}
          {!advanced ? (
            <button
              type="button"
              className="text-[12px] text-muted-foreground underline underline-offset-2 hover:text-foreground"
              onClick={() => setAdvanced(true)}
            >
              {t('Set the endpoints manually')}
            </button>
          ) : null}
        </div>
        {advanced ? (
          <div className="space-y-4 rounded-md border border-border p-4">
            <p className="text-[12px] text-muted-foreground">
              {t(
                'Override what the discovery URL publishes, or set all three when your provider has none.',
              )}
            </p>
            {(
              [
                [
                  'sso-authorization',
                  t('Authorization URL'),
                  authorizationURL,
                  setAuthorizationURL,
                  errors.authorizationURL,
                ],
                [
                  'sso-token',
                  t('Token URL'),
                  tokenURL,
                  setTokenURL,
                  errors.tokenURL,
                ],
                [
                  'sso-user-info',
                  t('User info URL'),
                  userInfoURL,
                  setUserInfoURL,
                  errors.userInfoURL,
                ],
              ] as const
            ).map(([id, label, value, setValue, error]) => (
              <div key={id} className="space-y-2">
                <Label htmlFor={id} className="text-[13px] font-medium">
                  {label}
                </Label>
                <Input
                  id={id}
                  value={value}
                  maxLength={2048}
                  onChange={(event) => setValue(event.target.value)}
                  aria-invalid={!!error}
                  disabled={save.isPending}
                />
                {error ? (
                  <p className="text-[12px] text-destructive">{error}</p>
                ) : null}
              </div>
            ))}
          </div>
        ) : null}
        {provider ? null : enabledRow}
      </div>
      <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" onClick={onDone}>
          {t('Cancel')}
        </Button>
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? t('Saving...') : provider ? t('Update') : t('Add')}
        </Button>
      </div>
    </form>
  )
}
