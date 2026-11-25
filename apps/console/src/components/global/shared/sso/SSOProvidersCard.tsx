import { useRef, useState } from 'react'
import { Copy, KeyRound, Link2, Pencil, Trash2 } from 'lucide-react'
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
import type { SSOProvider } from '@/lib/appwrite/team-sso'
import { useT } from '@/lib/i18n/translate'
import {
  teamSSOFor,
  useDeleteSSOProvider,
  useSaveSSOProvider,
  useSSODomains,
  useSSOProviders,
  useSSORequired,
} from '@/lib/react-query/hooks'
import { teamRoleError, type SSOTarget } from '@/lib/sso-target'
import { buildConsoleUrl, copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { EmailDomainRoles, emailDomainRoleLabel } from './EmailDomainRoles'
import { TeamRolesInput } from './TeamRolesInput'
import {
  focusOnOpen,
  SSO_ERROR_TEXT,
  SSOLockedTooltip,
  SSOListLoadError,
  useDialogFocusReturn,
  useSingleSubmit,
  useSSOAddonErrorHandler,
  useSSOAddonGate,
} from './SSOCardHelpers'

const HEAD =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/**
 * The identity providers of an organization, or of a team of a project: each
 * signs people in with its own roles. An organization's give Console roles, a
 * team's give role names of the team, typed like a membership's.
 */
export function SSOProvidersCard({ target }: { target: SSOTarget }) {
  const t = useT()
  const forTeam = target.scope === 'project'
  const {
    providers,
    isError: loadFailed,
    isFetching,
    refetch,
  } = useSSOProviders(target)
  const { domains, isSuccess: domainsLoaded } = useSSODomains(target)
  const save = useSaveSSOProvider(target)
  const remove = useDeleteSSOProvider(target)
  // Adding, updating and turning providers on or off need the Single sign-on
  // add-on; deleting works without it.
  const gate = useSSOAddonGate(target)
  const onAddonError = useSSOAddonErrorHandler(target)

  const hasVerifiedDomain = domains.some(
    (domain) => domain.status === 'verified',
  )
  const required = useSSORequired(target)
  /** Whether `provider` is the only one people can sign in through while SSO is required. */
  const isLastEnabledWhileRequired = (provider: SSOProvider) =>
    required &&
    provider.enabled &&
    providers.filter((item) => item.enabled).length === 1

  const [editing, setEditing] = useState<SSOProvider | 'new' | null>(null)
  const [deleting, setDeleting] = useState<SSOProvider | null>(null)

  // Focus goes back to what opened a dialog: the dialogs open from state, so
  // Radix has no trigger to return it to.
  const addButton = useRef<HTMLButtonElement>(null)
  const rowMenus = useRef(new Map<string, HTMLButtonElement>())
  const deleteCancel = useRef<HTMLButtonElement>(null)
  const formFocus = useDialogFocusReturn()
  const deleteFocus = useDialogFocusReturn()
  const rowMenuRef = (id: string) => (element: HTMLButtonElement | null) => {
    if (element) rowMenus.current.set(id, element)
    else rowMenus.current.delete(id)
  }
  const openForm = (provider: SSOProvider | 'new') => {
    formFocus.returnTo(
      () =>
        provider === 'new'
          ? addButton.current
          : rowMenus.current.get(provider.$id),
      () => addButton.current,
    )
    setEditing(provider)
  }
  const openDelete = (provider: SSOProvider) => {
    deleteFocus.returnTo(
      () => rowMenus.current.get(provider.$id),
      () => addButton.current,
    )
    setDeleting(provider)
  }

  // Only the row being saved waits. Its switch stays focusable (a focused
  // control that turns disabled drops focus to the page) and ignores input
  // until the save settles.
  const [toggling, setToggling] = useState<ReadonlySet<string>>(new Set())
  const togglingNow = useRef(new Set<string>())
  const toggle = (provider: SSOProvider, enabled: boolean) => {
    if (togglingNow.current.has(provider.$id)) return
    togglingNow.current.add(provider.$id)
    setToggling(new Set(togglingNow.current))
    const settle = () => {
      togglingNow.current.delete(provider.$id)
      setToggling(new Set(togglingNow.current))
    }
    save.mutate(
      {
        providerId: provider.$id,
        params: {
          name: provider.name,
          roles: provider.roles,
          clientId: provider.oidcClientId,
          wellKnownURL: provider.oidcWellKnownURL,
          authorizationURL: provider.oidcAuthorizationURL,
          tokenURL: provider.oidcTokenURL,
          userInfoURL: provider.oidcUserInfoURL,
          enabled,
        },
      },
      {
        onSuccess: () =>
          toast.success(
            enabled
              ? t('Identity provider enabled')
              : isLastEnabledWhileRequired(provider)
                ? t(
                    'Identity provider disabled. Require SSO is paused until you enable an identity provider.',
                  )
                : t('Identity provider disabled'),
          ),
        onError: (failure: Error) => {
          if (onAddonError(failure)) return
          toast.error(
            getErrorMessage(failure) || t('Failed to update identity provider'),
          )
        },
        onSettled: settle,
      },
    )
  }

  const submitOnce = useSingleSubmit()
  const confirmDelete = () => {
    if (!deleting) return
    const provider = deleting
    submitOnce((settle) => {
      // The row goes away: focus its neighbour, or Add provider.
      const index = providers.findIndex((item) => item.$id === provider.$id)
      const neighbour = providers[index + 1] ?? providers[index - 1]
      deleteFocus.returnTo(
        () => (neighbour ? rowMenus.current.get(neighbour.$id) : null),
        () => addButton.current,
      )
      closeDialogBeforeOverlayUnmount(() => setDeleting(null))
      remove.mutate(provider.$id, {
        onSuccess: () => toast.success(t('Identity provider deleted')),
        onError: (failure: Error) =>
          toast.error(
            getErrorMessage(failure) || t('Failed to delete identity provider'),
          ),
        onSettled: settle,
      })
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
            {forTeam
              ? t(
                  "Users on the team's verified domains sign in to your app through an identity provider, like Okta, Microsoft Entra ID, Google Workspace or Keycloak, and join the team with its roles.",
                )
              : t(
                  'People on your verified domains sign in through your identity provider, like Okta, Microsoft Entra ID, Google Workspace or Keycloak, and join with its roles.',
                )}
          </p>
        </div>
        <div className="border-t border-border" />
        {providers.length > 0 && domainsLoaded && !hasVerifiedDomain ? (
          <div className="border-b border-border px-6 py-4">
            <Alert>
              <AlertDescription className="text-[13px]">
                {t(
                  'Nobody can sign in through these identity providers yet. Verify a domain first.',
                )}
              </AlertDescription>
            </Alert>
          </div>
        ) : null}
        {loadFailed ? (
          <SSOListLoadError
            title={t('Failed to load identity providers')}
            onRetry={() => void refetch()}
            retrying={isFetching}
          />
        ) : providers.length === 0 ? (
          <div className="px-6 py-4">
            <EmptyState
              icon={KeyRound}
              title={t('No identity providers yet')}
              description={
                forTeam
                  ? t(
                      "Connect an identity provider so users on the team's domains sign in to your app with single sign-on.",
                    )
                  : t(
                      'Connect your identity provider so people on your domains sign in with single sign-on.',
                    )
              }
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
                      dir="ltr"
                      className="w-fit max-w-full truncate font-mono text-[12px] text-muted-foreground"
                      title={provider.oidcClientId}
                    >
                      {provider.oidcClientId}
                    </div>
                  </TableCell>
                  <TableCell className="hidden px-4 py-3 sm:table-cell">
                    <div className="flex flex-wrap gap-1">
                      {provider.roles.map((role) => (
                        <Badge key={role} variant="outline">
                          {/* A team's roles are names its admins typed */}
                          {forTeam ? role : t(emailDomainRoleLabel(role))}
                        </Badge>
                      ))}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <SSOLockedTooltip locked={gate.locked}>
                      <Switch
                        checked={provider.enabled}
                        onCheckedChange={(enabled) => toggle(provider, enabled)}
                        aria-label={`${t('Enable')} ${provider.name}`}
                        aria-disabled={toggling.has(provider.$id) || undefined}
                        disabled={gate.locked}
                        className="aria-disabled:cursor-wait aria-disabled:opacity-50"
                      />
                    </SSOLockedTooltip>
                  </TableCell>
                  <TableCell className="px-4 py-3 text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger
                          ref={rowMenuRef(provider.$id)}
                          aria-label={`${t('Actions for')} ${provider.name}`}
                        />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-56">
                        {forTeam ? (
                          // The app signs users in through a provider by its
                          // ID; the Console's sign-in link is not theirs.
                          <DropdownMenuItem
                            onClick={() => copyToClipboard('ID', provider.$id)}
                          >
                            <MenuItemContent icon={Copy}>
                              {t('Copy ID')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        ) : (
                          // The link to share with the team: it opens this provider's sign-in directly.
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
                        )}
                        <DropdownMenuItem
                          onClick={() => openForm(provider)}
                          disabled={gate.locked}
                        >
                          <MenuItemContent icon={Pencil}>
                            {t('Update')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => openDelete(provider)}>
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
          <SSOLockedTooltip locked={gate.locked}>
            <Button
              ref={addButton}
              size="sm"
              className="h-9 text-[13px]"
              disabled={gate.locked}
              onClick={() => openForm('new')}
              {...analyticsAttrs('create-sso-provider')}
            >
              {t('Add identity provider')}
            </Button>
          </SSOLockedTooltip>
        </div>
      </div>

      <ProviderDialog
        provider={editing}
        target={target}
        onOpenChange={(open) => !open && setEditing(null)}
        onCloseAutoFocus={formFocus.onCloseAutoFocus}
      />

      <Dialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
      >
        <DialogContent
          className="sm:max-w-md p-0"
          onOpenAutoFocus={focusOnOpen(() => deleteCancel.current)}
          onCloseAutoFocus={deleteFocus.onCloseAutoFocus}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete identity provider')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete "{name}"?').replace(
                '{name}',
                deleting?.name ?? '',
              )}{' '}
              {forTeam
                ? t(
                    'Users can no longer sign in through it. Users who already joined stay members.',
                  )
                : t(
                    'People can no longer sign in through it. People who already joined stay members.',
                  )}
              {deleting && isLastEnabledWhileRequired(deleting)
                ? ` ${
                    forTeam
                      ? t(
                          "Require SSO is on and this is the team's only enabled identity provider: deleting it pauses Require SSO, and members keep the team's roles however they sign in.",
                        )
                      : t(
                          'Require SSO is on and this is your only enabled identity provider: deleting it pauses Require SSO, and members can sign in any way they like.',
                        )
                  }`
                : null}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              ref={deleteCancel}
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
  target,
  onOpenChange,
  onCloseAutoFocus,
}: {
  provider: SSOProvider | 'new' | null
  target: SSOTarget
  onOpenChange: (open: boolean) => void
  onCloseAutoFocus: (event: Event) => void
}) {
  const open = provider !== null
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-xl p-0"
        onCloseAutoFocus={onCloseAutoFocus}
      >
        {open ? (
          <ProviderForm
            key={provider === 'new' ? 'new' : provider.$id}
            provider={provider === 'new' ? null : provider}
            target={target}
            onDone={() => onOpenChange(false)}
          />
        ) : null}
      </DialogContent>
    </Dialog>
  )
}

function ProviderForm({
  provider,
  target,
  onDone,
}: {
  provider: SSOProvider | null
  target: SSOTarget
  onDone: () => void
}) {
  const t = useT()
  const forTeam = target.scope === 'project'
  const save = useSaveSSOProvider(target)
  const onAddonError = useSSOAddonErrorHandler(target)
  const submitOnce = useSingleSubmit()
  const [name, setName] = useState(provider?.name ?? '')
  // A team has no roles of its own to start from: its admins name them.
  const [roles, setRoles] = useState<string[]>(
    provider?.roles ?? (forTeam ? [] : ['developer']),
  )
  const [roleDraft, setRoleDraft] = useState('')
  const [clientId, setClientId] = useState(provider?.oidcClientId ?? '')
  const [clientSecret, setClientSecret] = useState('')
  const [wellKnownURL, setWellKnownURL] = useState(
    provider?.oidcWellKnownURL ?? '',
  )
  const [authorizationURL, setAuthorizationURL] = useState(
    provider?.oidcAuthorizationURL ?? '',
  )
  const [tokenURL, setTokenURL] = useState(provider?.oidcTokenURL ?? '')
  const [userInfoURL, setUserInfoURL] = useState(
    provider?.oidcUserInfoURL ?? '',
  )
  const [enabled, setEnabled] = useState(provider?.enabled ?? true)
  const [advanced, setAdvanced] = useState(
    !!(
      provider?.oidcAuthorizationURL ||
      provider?.oidcTokenURL ||
      provider?.oidcUserInfoURL
    ),
  )

  // Missing fields are named once the person tries to save.
  const [submitted, setSubmitted] = useState(false)

  // The Console's callback, or the project's with its ID in it.
  const redirectURL = teamSSOFor(target).redirectURL()
  // A team's role typed but not added yet goes with the save, when it is one.
  const pendingRole = forTeam ? roleDraft.trim() : ''
  const pendingRoleValid =
    pendingRole !== '' && teamRoleError(pendingRole) === null
  const savedRoles =
    pendingRoleValid && !roles.includes(pendingRole)
      ? [...roles, pendingRole]
      : roles
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
    savedRoles.length > 0 &&
    (pendingRole === '' || pendingRoleValid) &&
    clientId.trim() !== '' &&
    (provider !== null || clientSecret.trim() !== '') &&
    hasEndpoints &&
    [wellKnownURL, authorizationURL, tokenURL, userInfoURL].every(
      (value) => urlError(value) === null,
    )

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    setSubmitted(true)
    if (!valid) return
    submitOnce((settle) =>
      save.mutate(
        {
          providerId: provider?.$id,
          params: {
            name: name.trim(),
            roles: savedRoles,
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
          onError: (failure: Error) => {
            if (onAddonError(failure)) {
              onDone()
              return
            }
            toast.error(
              getErrorMessage(failure) || t('Failed to save identity provider'),
            )
          },
          onSettled: settle,
        },
      ),
    )
  }

  const enabledRow = (
    <div className="flex items-center justify-between gap-4">
      <div>
        <Label htmlFor="sso-enabled" className="text-[13px] font-medium">
          {t('Enabled')}
        </Label>
        <p id="sso-enabled-hint" className="text-[12px] text-muted-foreground">
          {forTeam
            ? t('Users can sign in through this identity provider.')
            : t('People can sign in through this identity provider.')}
        </p>
      </div>
      <Switch
        id="sso-enabled"
        aria-describedby="sso-enabled-hint"
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
                'Change how people sign in through this identity provider. Leave the client secret empty to keep the stored one.',
              )
            : t(
                'Create an OpenID Connect application at your identity provider, add the redirect URL there, then fill in its details.',
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
              dir="ltr"
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
            aria-describedby="sso-name-message"
            disabled={save.isPending}
          />
          {errors.name ? (
            <p
              id="sso-name-message"
              className={`text-[12px] ${SSO_ERROR_TEXT}`}
            >
              {errors.name}
            </p>
          ) : (
            <p
              id="sso-name-message"
              className="text-[12px] text-muted-foreground"
            >
              {forTeam
                ? t(
                    'Your app can show this name when users choose how to sign in.',
                  )
                : t('People see the name when they choose how to sign in.')}
            </p>
          )}
        </div>
        {forTeam ? (
          <div className="space-y-2">
            <Label htmlFor="sso-roles" className="text-[13px] font-medium">
              {t('Roles')}
            </Label>
            <TeamRolesInput
              id="sso-roles"
              value={roles}
              onChange={setRoles}
              draft={roleDraft}
              onDraftChange={setRoleDraft}
              disabled={save.isPending}
              describedBy="sso-roles-message"
            />
            <p
              id="sso-roles-message"
              className={`text-[12px] ${
                submitted && savedRoles.length === 0
                  ? SSO_ERROR_TEXT
                  : 'text-muted-foreground'
              }`}
            >
              {savedRoles.length === 0
                ? t('Add at least one role.')
                : t(
                    'Roles users get in this team when they sign in through this identity provider.',
                  )}
            </p>
          </div>
        ) : (
          <div className="space-y-2">
            <Label id="sso-roles-label" className="text-[13px] font-medium">
              {t('Roles')}
            </Label>
            <EmailDomainRoles
              value={roles}
              onChange={setRoles}
              disabled={save.isPending}
              labelledBy="sso-roles-label"
              describedBy="sso-roles-message"
            />
            <p
              id="sso-roles-message"
              className={`text-[12px] ${
                submitted && roles.length === 0
                  ? SSO_ERROR_TEXT
                  : 'text-muted-foreground'
              }`}
            >
              {roles.length === 0
                ? t('Pick at least one role.')
                : t(
                    'Roles people get when they join through this identity provider.',
                  )}
            </p>
          </div>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="sso-client-id" className="text-[13px] font-medium">
              {t('Client ID')}
            </Label>
            <Input
              id="sso-client-id"
              dir="ltr"
              value={clientId}
              autoComplete="off"
              maxLength={256}
              onChange={(event) => setClientId(event.target.value)}
              aria-invalid={!!errors.clientId}
              aria-describedby={
                errors.clientId ? 'sso-client-id-message' : undefined
              }
              disabled={save.isPending}
            />
            {errors.clientId ? (
              <p
                id="sso-client-id-message"
                className={`text-[12px] ${SSO_ERROR_TEXT}`}
              >
                {errors.clientId}
              </p>
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
              dir="ltr"
              type="password"
              value={clientSecret}
              autoComplete="new-password"
              placeholder={
                provider?.oidcClientSecretSet ? t('Unchanged') : undefined
              }
              maxLength={512}
              onChange={(event) => setClientSecret(event.target.value)}
              aria-invalid={!!errors.clientSecret}
              aria-describedby={
                errors.clientSecret ? 'sso-client-secret-message' : undefined
              }
              disabled={save.isPending}
            />
            {errors.clientSecret ? (
              <p
                id="sso-client-secret-message"
                className={`text-[12px] ${SSO_ERROR_TEXT}`}
              >
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
            dir="ltr"
            value={wellKnownURL}
            placeholder="https://example.okta.com/.well-known/openid-configuration"
            maxLength={2048}
            onChange={(event) => setWellKnownURL(event.target.value)}
            aria-invalid={!!errors.wellKnownURL}
            aria-describedby={
              errors.wellKnownURL ? 'sso-well-known-message' : undefined
            }
            disabled={save.isPending}
          />
          {errors.wellKnownURL ? (
            <p
              id="sso-well-known-message"
              className={`text-[12px] ${SSO_ERROR_TEXT}`}
            >
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
                'Override what the discovery URL publishes, or set all three when your identity provider has none.',
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
                  dir="ltr"
                  value={value}
                  maxLength={2048}
                  onChange={(event) => setValue(event.target.value)}
                  aria-invalid={!!error}
                  aria-describedby={error ? `${id}-message` : undefined}
                  disabled={save.isPending}
                />
                {error ? (
                  <p
                    id={`${id}-message`}
                    className={`text-[12px] ${SSO_ERROR_TEXT}`}
                  >
                    {error}
                  </p>
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
