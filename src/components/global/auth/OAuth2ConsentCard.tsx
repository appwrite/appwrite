'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  Building2,
  Check,
  ChevronDown,
  ChevronUp,
  CircleAlert,
  Copy,
  Folder,
  Lock,
  ShieldCheck,
  SlidersHorizontal,
  ArrowLeftRight,
  TriangleAlert,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { OAuth2AppAvatar } from '@/components/global/auth/OAuth2AppAvatar'
import {
  buildConsentPermissions,
  buildTierEditorRows,
  splitConsentScopes,
  PROJECT_SCOPE_PREFIX,
  ORGANIZATION_SCOPE_PREFIX,
  type EditorRow,
  type PermissionLine,
} from '@/lib/oauth2/scopes'
import {
  isMcpGrant,
  composeGrantedScopes,
  type TierSelection,
  type ResourceSelection,
} from '@/lib/oauth2/mcp'
import {
  mergeIdentifiers,
  parseAuthorizationDetails,
  resolveOrganizationNames,
  resolveProjectNames,
  searchProjects,
  serializeGrantedDetails,
  listOrganizationResources,
  ORGANIZATION_RAR_TYPE,
  PROJECT_RAR_TYPE,
  WILDCARD_IDENTIFIER,
  type ResolvedResource,
  type ResourceNameMap,
} from '@/lib/oauth2/authorization-details'
import { isWebRedirect } from '@/lib/oauth2/redirect'
import { OAuth2ResourceSelector } from './OAuth2ResourceSelector'

export type OAuth2Flow = 'authorization' | 'device'
export type OAuth2Outcome = 'approved' | 'denied'

interface OAuth2ConsentCardProps {
  grant: Models.Oauth2Grant
  app: Models.App
  /** Email/name of the signed-in account, shown so the user knows who they are. */
  accountLabel?: string
  /** 'authorization' redirects back to the client; 'device' shows a done state. */
  flow: OAuth2Flow
  /** Called when the flow completes without a browser-navigating web redirect. */
  onDone?: (outcome: OAuth2Outcome, redirectUrl?: string) => void
  /** When provided, the account chip becomes a menu with "Use a different account". */
  onSwitchAccount?: () => void | Promise<void>
  /** Debug preview: skip approve/reject API calls and invoke onDone instead. */
  preview?: boolean
}

function hostnameOf(uri: string): string | null {
  try {
    return new URL(uri).hostname
  } catch {
    return null
  }
}

export function OAuth2ConsentCard({
  grant,
  app,
  accountLabel,
  flow,
  onDone,
  onSwitchAccount,
  preview = false,
}: OAuth2ConsentCardProps) {
  const t = useT()
  const [error, setError] = useState<string | null>(null)
  const [showPermissions, setShowPermissions] = useState(true)
  const [permissionGroupOpen, setPermissionGroupOpen] = useState<
    Record<string, boolean>
  >({})
  const [copiedToken, setCopiedToken] = useState<string | null>(null)
  const copyTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const [projectSelected, setProjectSelected] = useState<string[]>([])
  const [organizationSelected, setOrganizationSelected] = useState<string[]>([])

  // Scope-narrowing editor state (MCP grants only). Rows absent from a tier's
  // selection are selected with full requested access, so the empty record is
  // the "full access" default.
  const [customize, setCustomize] = useState(false)
  const [readOnlyAll, setReadOnlyAll] = useState(false)
  const [projectSelection, setProjectSelection] = useState<TierSelection>({})
  const [organizationSelection, setOrganizationSelection] =
    useState<TierSelection>({})
  const [projectPermissionsOpen, setProjectPermissionsOpen] = useState(true)
  const [organizationPermissionsOpen, setOrganizationPermissionsOpen] =
    useState(true)

  const scopeModel = useMemo(
    () => splitConsentScopes(grant.scopes ?? []),
    [grant.scopes],
  )
  const details = useMemo(
    () => parseAuthorizationDetails(grant.authorizationDetails),
    [grant.authorizationDetails],
  )
  const permissionGroups = useMemo(
    () => buildConsentPermissions(scopeModel),
    [scopeModel],
  )

  // MCP grants (detected via the grant's RFC 8707 resources) get a narrowing
  // editor: the client requested the full scope catalog, so consent is the
  // control point. Every other grant renders exactly as before - read-only
  // permissions, no scope narrowing.
  const canNarrow = useMemo(() => isMcpGrant(grant), [grant])

  const projectRows = useMemo<EditorRow[]>(
    () =>
      canNarrow
        ? buildTierEditorRows(scopeModel.project, PROJECT_SCOPE_PREFIX)
        : [],
    [canNarrow, scopeModel.project],
  )
  const organizationRows = useMemo<EditorRow[]>(
    () =>
      canNarrow
        ? buildTierEditorRows(
            scopeModel.organization,
            ORGANIZATION_SCOPE_PREFIX,
          )
        : [],
    [canNarrow, scopeModel.organization],
  )

  const projectScopesRequested =
    scopeModel.project.all || scopeModel.project.scopes.length > 0
  const organizationScopesRequested =
    scopeModel.organization.all || scopeModel.organization.scopes.length > 0

  // When a tier's scopes are requested but left unbound, fall back to the
  // wildcard so the user still gets the full picker.
  const projectIdentifiers = useMemo(() => {
    const merged = mergeIdentifiers(details, PROJECT_RAR_TYPE)
    if (merged.length > 0) return merged
    return projectScopesRequested ? [WILDCARD_IDENTIFIER] : []
  }, [details, projectScopesRequested])
  const organizationIdentifiers = useMemo(() => {
    const merged = mergeIdentifiers(details, ORGANIZATION_RAR_TYPE)
    if (merged.length > 0) return merged
    return organizationScopesRequested ? [WILDCARD_IDENTIFIER] : []
  }, [details, organizationScopesRequested])

  const projectRequested =
    projectScopesRequested && projectIdentifiers.length > 0
  const organizationRequested =
    organizationScopesRequested && organizationIdentifiers.length > 0

  const redirectHost = hostnameOf(grant.redirectUri)
  const accountInitial = (accountLabel || '?').charAt(0).toUpperCase()

  // Reset the selection to exactly what the client requested whenever the grant
  // changes, so a stale selection can't leak across requests.
  useEffect(() => {
    setProjectSelected([...projectIdentifiers])
    setOrganizationSelected([...organizationIdentifiers])
    setCustomize(false)
    setReadOnlyAll(false)
    setProjectSelection({})
    setOrganizationSelection({})
    setProjectPermissionsOpen(true)
    setOrganizationPermissionsOpen(true)
    setPermissionGroupOpen({})
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [grant.$id])

  function sameIdentifiers(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((id) => b.includes(id))
  }
  const resourcesNarrowed =
    !sameIdentifiers(projectSelected, projectIdentifiers) ||
    !sameIdentifiers(organizationSelected, organizationIdentifiers)

  // The narrowed grant to send on approve. `scope: undefined` means the user
  // kept the full request - the approve call then omits `scope` so the server
  // grants the full literal requested list and re-authorizations skip consent.
  const composed = useMemo(
    () =>
      canNarrow
        ? composeGrantedScopes({
            model: scopeModel,
            project: projectSelection,
            organization: organizationSelection,
            readOnly: readOnlyAll,
            resourcesNarrowed,
          })
        : null,
    [
      canNarrow,
      scopeModel,
      projectSelection,
      organizationSelection,
      readOnlyAll,
      resourcesNarrowed,
    ],
  )

  function rowState(
    selection: TierSelection,
    resource: string,
  ): ResourceSelection {
    return selection[resource] ?? { selected: true, level: 'full' }
  }

  function updateRow(
    tier: 'project' | 'organization',
    resource: string,
    patch: Partial<ResourceSelection>,
  ) {
    const selection =
      tier === 'project' ? projectSelection : organizationSelection
    const next = {
      ...selection,
      [resource]: { ...rowState(selection, resource), ...patch },
    }
    if (tier === 'project') setProjectSelection(next)
    else setOrganizationSelection(next)
  }

  function setAllRows(
    tier: 'project' | 'organization',
    rows: EditorRow[],
    selected: boolean,
  ) {
    // Only toggles selection - each row keeps its chosen access level, so
    // unchecking and rechecking the tier doesn't discard Read-only choices.
    const selection =
      tier === 'project' ? projectSelection : organizationSelection
    const next: TierSelection = {}
    for (const row of rows) {
      next[row.resource] = {
        selected,
        level: rowState(selection, row.resource).level,
      }
    }
    if (tier === 'project') setProjectSelection(next)
    else setOrganizationSelection(next)
  }

  function tierAllSelected(
    rows: EditorRow[],
    selection: TierSelection,
  ): boolean {
    return rows.every((row) => rowState(selection, row.resource).selected)
  }

  const projectGranted = projectRequested && projectSelected.length > 0
  const organizationGranted =
    organizationRequested && organizationSelected.length > 0
  const projectNeedsResource = projectRequested && projectSelected.length === 0
  const organizationNeedsResource =
    organizationRequested && organizationSelected.length === 0

  const nothingToGrant =
    scopeModel.identity.length === 0 &&
    !scopeModel.all &&
    !projectGranted &&
    !organizationGranted
  // With the narrowing editor, the selection must keep at least one
  // non-identity scope - an identity-only grant would leave the app
  // "authorized" but unable to act.
  const nothingSelected = composed?.blocked ?? false
  const blocked =
    nothingToGrant ||
    projectNeedsResource ||
    organizationNeedsResource ||
    nothingSelected

  // --- Resource search wiring -------------------------------------------------
  const orgCache = useRef<ResolvedResource[] | null>(null)
  const findProjects = (term: string) => searchProjects(term)
  const findOrganizations = async (
    term: string,
  ): Promise<ResolvedResource[]> => {
    if (orgCache.current === null) {
      orgCache.current = await listOrganizationResources()
    }
    const trimmed = term.trim().toLowerCase()
    const all = orgCache.current
    const filtered = trimmed
      ? all.filter((r) => r.name.toLowerCase().includes(trimmed))
      : all
    return filtered.slice(0, 8)
  }
  const resolveProjects = (ids: string[]): Promise<ResourceNameMap> =>
    resolveProjectNames(ids)
  const resolveOrganizations = (ids: string[]): Promise<ResourceNameMap> =>
    resolveOrganizationNames(ids)

  // --- Header subtitle --------------------------------------------------------
  const summary = useMemo(() => {
    const parts: string[] = []
    if (scopeModel.identity.length > 0) parts.push(t('view your identity'))
    if (scopeModel.all) parts.push(t('fully manage your Appwrite account'))
    if (projectRequested) parts.push(t('access the projects you choose'))
    if (organizationRequested)
      parts.push(t('manage the organizations you choose'))

    if (parts.length === 0) {
      return `${app.name} ${t('is requesting access to your Appwrite account.')}`
    }
    let joined: string
    if (parts.length === 1) {
      joined = parts[0]
    } else {
      joined = `${parts.slice(0, -1).join(', ')} ${t('and')} ${parts[parts.length - 1]}`
    }
    return `${t('This will allow')} ${app.name} ${t('to')} ${joined}.`
  }, [scopeModel, projectRequested, organizationRequested, app.name, t])

  // --- Copy scope -------------------------------------------------------------
  const copyToken = (token: string) => {
    if (!navigator.clipboard) return
    void navigator.clipboard.writeText(token).then(() => {
      setCopiedToken(token)
      if (copyTimer.current) clearTimeout(copyTimer.current)
      copyTimer.current = setTimeout(() => setCopiedToken(null), 1500)
    })
  }
  useEffect(
    () => () => {
      if (copyTimer.current) clearTimeout(copyTimer.current)
    },
    [],
  )

  // --- Approve / reject -------------------------------------------------------
  const approveMutation = useMutation({
    mutationFn: async () => {
      if (preview) {
        return {
          redirectUrl:
            flow === 'device'
              ? undefined
              : (grant.redirectUri || 'https://example.com/callback'),
        }
      }
      // For MCP grants the editor may downscope the requested catalog; `scope`
      // stays omitted when the user kept the full request so the server grants
      // the full literal requested list (keeping the consent-skip diff empty on
      // re-authorization). Non-MCP grants never send `scope` - only the
      // resource binding is narrowed.
      //
      // Same consent-skip reasoning for the resource binding: on an MCP grant
      // with untouched pickers, omit `authorizationDetails` so the server keeps
      // exactly what the client requested. A narrowed selection is sent and
      // deliberately forces re-consent on the next full request.
      const authorizationDetails =
        projectRequested || organizationRequested
          ? canNarrow && !resourcesNarrowed
            ? undefined
            : serializeGrantedDetails({
                project: projectGranted ? projectSelected : undefined,
                organization: organizationGranted
                  ? organizationSelected
                  : undefined,
              })
          : undefined
      return sdk.forConsole.oauth2.approve({
        grantId: grant.$id,
        scope: composed?.scope,
        authorizationDetails,
      })
    },
    onSuccess: (result) => {
      if (preview || flow === 'device' || !result.redirectUrl) {
        onDone?.('approved', result.redirectUrl)
        return
      }
      window.location.assign(result.redirectUrl)
      if (!isWebRedirect(result.redirectUrl)) {
        onDone?.('approved', result.redirectUrl)
      }
    },
    onError: (e: unknown) => {
      const message = getErrorMessage(
        e,
        t('Failed to authorize the application'),
      )
      setError(message)
      toast.error(message)
    },
  })

  const rejectMutation = useMutation({
    mutationFn: async () => {
      if (preview) {
        return {
          redirectUrl:
            flow === 'device'
              ? undefined
              : (grant.redirectUri || 'https://example.com/callback'),
        }
      }
      return sdk.forConsole.oauth2.reject({ grantId: grant.$id })
    },
    onSuccess: (result) => {
      if (preview || flow === 'device' || !result.redirectUrl) {
        onDone?.('denied', result.redirectUrl)
        return
      }
      window.location.assign(result.redirectUrl)
      if (!isWebRedirect(result.redirectUrl)) {
        onDone?.('denied', result.redirectUrl)
      }
    },
    onError: (e: unknown) => {
      const message = getErrorMessage(e, t('Failed to cancel the request'))
      setError(message)
      toast.error(message)
    },
  })

  const isBusy = approveMutation.isPending || rejectMutation.isPending

  const editorGroup = (
    tierKey: 'project' | 'organization',
    heading: string,
    note: string,
    rows: EditorRow[],
    selection: TierSelection,
    open: boolean,
    setOpen: (next: boolean) => void,
  ) => {
    if (rows.length === 0) return null
    return (
      <div className="space-y-3">
        <div>
          <div className="flex items-center gap-2">
            <Checkbox
              checked={tierAllSelected(rows, selection)}
              onCheckedChange={(checked) =>
                setAllRows(tierKey, rows, checked === true)
              }
              disabled={isBusy}
              aria-label={`${t('Allow all')} ${heading.toLowerCase()} ${t('permissions')}`}
            />
            <button
              type="button"
              aria-expanded={open}
              aria-controls={`${tierKey}-permissions-list`}
              onClick={() => setOpen(!open)}
              className="cursor-pointer text-muted-foreground hover:text-foreground flex items-center gap-1 text-[12px] font-semibold uppercase tracking-wider"
            >
              {t(heading)}
              {open ? (
                <ChevronUp className="size-3.5" />
              ) : (
                <ChevronDown className="size-3.5" />
              )}
            </button>
          </div>
          <p className="text-muted-foreground mt-1 ps-6 text-[12px] leading-relaxed">
            {t(note)}
          </p>
        </div>
        {open && (
          <ul id={`${tierKey}-permissions-list`} className="space-y-4">
            {rows.map((row) => {
              const state = rowState(selection, row.resource)
              return (
                <li
                  key={row.resource}
                  className={cn(
                    'flex items-start gap-2.5',
                    !state.selected && 'opacity-50',
                  )}
                >
                  <Checkbox
                    className="mt-0.5"
                    checked={state.selected}
                    onCheckedChange={(checked) =>
                      updateRow(tierKey, row.resource, {
                        selected: checked === true,
                      })
                    }
                    disabled={isBusy}
                    aria-label={`${t('Allow access to')} ${row.title}`}
                  />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-[13px] font-medium">
                        {t(row.title)}
                      </span>
                      {row.hasRead && row.hasWrite ? (
                        <span
                          className={cn(
                            'border-border flex shrink-0 overflow-hidden rounded-md border text-[10px] font-medium',
                            !state.selected && 'pointer-events-none',
                          )}
                        >
                          <button
                            type="button"
                            disabled={isBusy || !state.selected}
                            onClick={() =>
                              updateRow(tierKey, row.resource, {
                                level: 'read',
                              })
                            }
                            className={cn(
                              'cursor-pointer px-1.5 py-0.5 transition',
                              state.level === 'read' || readOnlyAll
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                            )}
                          >
                            {t('Read')}
                          </button>
                          <button
                            type="button"
                            disabled={isBusy || !state.selected || readOnlyAll}
                            onClick={() =>
                              updateRow(tierKey, row.resource, {
                                level: 'full',
                              })
                            }
                            className={cn(
                              'border-border cursor-pointer border-s px-1.5 py-0.5 transition',
                              state.level === 'full' && !readOnlyAll
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                              readOnlyAll && 'opacity-50',
                            )}
                          >
                            {t('Read + Write')}
                          </button>
                        </span>
                      ) : (
                        <Badge
                          variant={row.accessStrong ? 'warning' : 'info'}
                          className="text-[10px] shrink-0"
                        >
                          {t(row.access)}
                        </Badge>
                      )}
                    </div>
                    {row.description && (
                      <p className="text-muted-foreground mt-1 text-[12px] leading-relaxed">
                        {t(row.description)}
                      </p>
                    )}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </div>
    )
  }

  return (
    <Card className="overflow-hidden p-6 md:p-8">
      <div className="space-y-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <OAuth2AppAvatar app={app} />
          <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">
              {t('Authorize')} {app.name}
            </h1>
            <p className="text-muted-foreground text-[13px] leading-relaxed">
              {summary}
            </p>
          </div>

          {accountLabel ? (
            onSwitchAccount ? (
              <DropdownMenu>
                <DropdownMenuTrigger asChild disabled={isBusy}>
                  <button
                    type="button"
                    className="cursor-pointer text-muted-foreground hover:text-foreground border-border hover:bg-muted/50 flex max-w-full items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12px] transition disabled:opacity-60"
                  >
                    <span className="bg-muted text-muted-foreground flex size-5 items-center justify-center rounded-md text-[10px] font-semibold">
                      {accountInitial}
                    </span>
                    <span className="truncate">{accountLabel}</span>
                    <ChevronDown className="size-3.5 shrink-0" />
                  </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="center" className="w-72">
                  <div className="flex items-center gap-2 px-2 py-1.5">
                    <span className="bg-muted text-muted-foreground flex size-7 shrink-0 items-center justify-center rounded-md text-xs font-semibold">
                      {accountInitial}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-start text-[13px]">
                      {accountLabel}
                    </span>
                    <Check className="text-muted-foreground size-4 shrink-0" />
                  </div>
                  <DropdownMenuItem
                    disabled={isBusy}
                    onSelect={() => void onSwitchAccount()}
                  >
                    <ArrowLeftRight className="size-4" />
                    {t('Use a different account')}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            ) : (
              <p className="text-muted-foreground text-[12px]">
                {t('Signed in as')}{' '}
                <span className="text-foreground font-medium">{accountLabel}</span>
              </p>
            )
          ) : null}
        </div>

        {canNarrow ? (
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="flex items-start gap-3 px-4 py-4">
              <span className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-lg">
                <ShieldCheck className="size-4" />
              </span>
              <div className="min-w-0">
                <p className="text-[13px] font-medium">
                  {composed?.untouched ? t('Full access') : t('Custom access')}
                </p>
                <p className="text-muted-foreground mt-1 text-[12px] leading-relaxed">
                  {composed?.untouched
                    ? `${app.name} ${t('will be able to manage your organizations, projects, and their data on your behalf.')}`
                    : `${app.name} ${t('only gets the permissions you selected below.')}`}
                </p>
              </div>
            </div>

            <div className="border-t border-border" />

            <button
              type="button"
              aria-expanded={customize}
              onClick={() => setCustomize((v) => !v)}
              className="cursor-pointer hover:bg-muted/40 flex w-full items-center justify-between px-4 py-3"
            >
              <span className="flex items-center gap-2.5 text-start">
                <span className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-lg">
                  <SlidersHorizontal className="size-4" />
                </span>
                <span className="min-w-0">
                  <span className="block text-[13px] font-medium">
                    {t('Customize access')}
                  </span>
                  <span className="text-muted-foreground mt-0.5 block text-[12px] leading-relaxed">
                    {t('You can limit')} {app.name}{' '}
                    {t('to specific projects and actions.')}
                  </span>
                </span>
              </span>
              {customize ? (
                <ChevronUp className="text-muted-foreground size-4 shrink-0" />
              ) : (
                <ChevronDown className="text-muted-foreground size-4 shrink-0" />
              )}
            </button>

            {customize ? (
              <>
                <div className="border-t border-border" />
                <div className="space-y-5 px-4 py-4">
                  <label className="flex items-start gap-2.5">
                    <Checkbox
                      className="mt-0.5"
                      checked={readOnlyAll}
                      onCheckedChange={(checked) =>
                        setReadOnlyAll(checked === true)
                      }
                      disabled={isBusy}
                    />
                    <span className="min-w-0">
                      <span className="block text-[13px] font-medium">
                        {t('Read-only')}
                      </span>
                      <span className="text-muted-foreground mt-0.5 block text-[12px] leading-relaxed">
                        {t(
                          'Limit every selected permission to viewing data - nothing can be created, changed, or deleted.',
                        )}
                      </span>
                    </span>
                  </label>

                  {scopeModel.identity.length > 0 ? (
                    <p className="text-muted-foreground text-[12px] leading-relaxed">
                      {t('Basic identity')} (
                      {scopeModel.identity.map((scope) => scope.id).join(', ')}){' '}
                      {t('is always shared so')} {app.name}{' '}
                      {t('can recognize your account.')}
                    </p>
                  ) : null}

                  {editorGroup(
                    'project',
                    'Projects',
                    'Applies only to the projects you select below.',
                    projectRows,
                    projectSelection,
                    projectPermissionsOpen,
                    setProjectPermissionsOpen,
                  )}
                  {editorGroup(
                    'organization',
                    'Organizations',
                    'Applies only to the organizations you select below.',
                    organizationRows,
                    organizationSelection,
                    organizationPermissionsOpen,
                    setOrganizationPermissionsOpen,
                  )}

                  {nothingSelected ? (
                    <p className="text-muted-foreground flex items-center gap-1.5 text-[12px]">
                      <CircleAlert className="size-3.5 shrink-0" />
                      {t('Select at least one permission.')}
                    </p>
                  ) : null}
                  {composed?.lengthCollapsed ? (
                    <p className="text-muted-foreground flex items-center gap-1.5 text-[12px]">
                      <CircleAlert className="size-3.5 shrink-0" />
                      {t(
                        'Your selection was too long to grant scope-by-scope, so a fully selected tier was granted as full tier access instead.',
                      )}
                    </p>
                  ) : null}
                </div>
              </>
            ) : null}
          </div>
        ) : permissionGroups.length > 0 ? (
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <button
              type="button"
              aria-expanded={showPermissions}
              onClick={() => setShowPermissions((v) => !v)}
              className="cursor-pointer hover:bg-muted/40 flex w-full items-center justify-between px-4 py-3"
            >
              <span className="flex items-center gap-2 text-[13px] font-medium">
                <Lock className="text-muted-foreground size-4" />
                {t('Permissions')}
              </span>
              {showPermissions ? (
                <ChevronUp className="text-muted-foreground size-4" />
              ) : (
                <ChevronDown className="text-muted-foreground size-4" />
              )}
            </button>
            {showPermissions ? (
              <>
                <div className="border-t border-border" />
                <div className="space-y-5 px-4 py-4">
                  {permissionGroups.map((group) => {
                    const collapsible = group.collapsible === true
                    const open = permissionGroupOpen[group.heading] !== false
                    return (
                      <div key={group.heading} className="space-y-2">
                        <div>
                          {collapsible ? (
                            <button
                              type="button"
                              aria-expanded={open}
                              aria-controls={`permission-group-${group.heading.toLowerCase()}`}
                              onClick={() =>
                                setPermissionGroupOpen((current) => ({
                                  ...current,
                                  [group.heading]:
                                    current[group.heading] === false,
                                }))
                              }
                              className="cursor-pointer text-muted-foreground hover:text-foreground flex items-center gap-1 text-[12px] font-semibold uppercase tracking-wider"
                            >
                              {t(group.heading)}
                              {open ? (
                                <ChevronUp className="size-3.5" />
                              ) : (
                                <ChevronDown className="size-3.5" />
                              )}
                            </button>
                          ) : (
                            <p className="text-muted-foreground text-[12px] font-semibold uppercase tracking-wider">
                              {t(group.heading)}
                            </p>
                          )}
                          {group.note ? (
                            <p className="text-muted-foreground mt-1 text-[12px] leading-relaxed">
                              {t(group.note)}
                            </p>
                          ) : null}
                        </div>
                        {!collapsible || open ? (
                          <ul
                            id={`permission-group-${group.heading.toLowerCase()}`}
                            className="space-y-3"
                          >
                            {group.lines.map((line) => (
                              <PermissionRow
                                key={line.token}
                                line={line}
                                copied={copiedToken === line.token}
                                onCopy={() => copyToken(line.token)}
                              />
                            ))}
                          </ul>
                        ) : null}
                      </div>
                    )
                  })}
                </div>
              </>
            ) : null}
          </div>
        ) : null}

        {projectRequested ? (
          <ScopePanel
            icon={<Folder className="size-4" />}
            title={t('Project access')}
            subtitle={`${t('Choose which projects')} ${app.name} ${t('can access.')}`}
            needsAttention={projectNeedsResource}
            warning={
              projectNeedsResource
                ? `${t('Pick at least one project, or')} ${app.name} ${t('gets no project access.')}`
                : undefined
            }
          >
            <OAuth2ResourceSelector
              pluralLabel="projects"
              requested={projectIdentifiers}
              selected={projectSelected}
              onSelectedChange={setProjectSelected}
              find={findProjects}
              resolveNames={resolveProjects}
              disabled={isBusy}
            />
          </ScopePanel>
        ) : null}

        {organizationRequested ? (
          <ScopePanel
            icon={<Building2 className="size-4" />}
            title={t('Organization access')}
            subtitle={`${t('Choose which organizations')} ${app.name} ${t('can access.')}`}
            needsAttention={organizationNeedsResource}
            warning={
              organizationNeedsResource
                ? `${t('Pick at least one organization, or')} ${app.name} ${t('gets no organization access.')}`
                : undefined
            }
          >
            <OAuth2ResourceSelector
              pluralLabel="organizations"
              requested={organizationIdentifiers}
              selected={organizationSelected}
              onSelectedChange={setOrganizationSelected}
              find={findOrganizations}
              resolveNames={resolveOrganizations}
              disabled={isBusy}
            />
          </ScopePanel>
        ) : null}

        {error ? (
          <div className="border-destructive/20 bg-destructive/10 flex items-start gap-2 rounded-lg border p-3">
            <TriangleAlert className="text-destructive mt-0.5 size-4 shrink-0" />
            <p className="text-destructive text-[13px]">{error}</p>
          </div>
        ) : null}

        <div className="flex flex-col gap-2">
          <Button
            variant="brandCta"
            className="w-full"
            disabled={isBusy || blocked}
            onClick={() => {
              setError(null)
              approveMutation.mutate()
            }}
          >
            {t('Authorize')}
          </Button>
          <Button
            variant="outline"
            className="w-full"
            disabled={isBusy}
            onClick={() => {
              setError(null)
              rejectMutation.mutate()
            }}
          >
            {t('Cancel')}
          </Button>
        </div>

        <p className="text-muted-foreground flex flex-wrap items-center justify-center gap-x-1.5 gap-y-1 text-center text-[12px]">
          <span className="inline-flex items-center gap-1">
            <Lock className="size-3.5" />
            {flow === 'authorization' && redirectHost
              ? `${t("You'll be returned to")} ${redirectHost}`
              : flow === 'device'
                ? t('After authorizing, return to your device')
                : t('You can revoke access anytime')}
          </span>
          {app.privacyPolicyUrl ? (
            <>
              <span aria-hidden>·</span>
              <a
                href={app.privacyPolicyUrl}
                target="_blank"
                rel="noreferrer"
                className="link-neutral"
              >
                {t('Privacy')}
              </a>
            </>
          ) : null}
          {app.termsUrl ? (
            <>
              <span aria-hidden>·</span>
              <a
                href={app.termsUrl}
                target="_blank"
                rel="noreferrer"
                className="link-neutral"
              >
                {t('Terms')}
              </a>
            </>
          ) : null}
        </p>
      </div>
    </Card>
  )
}

function PermissionRow({
  line,
  copied,
  onCopy,
}: {
  line: PermissionLine
  copied: boolean
  onCopy: () => void
}) {
  const t = useT()
  return (
    <li className="group relative flex items-start gap-2.5 pe-6">
      <span className="bg-muted text-muted-foreground mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md">
        <Check className="size-3" />
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[13px] font-medium">{t(line.title)}</span>
          {line.access ? (
            <Badge
              variant={line.accessStrong ? 'warning' : 'info'}
              className="text-[10px] shrink-0"
            >
              {t(line.access)}
            </Badge>
          ) : null}
        </div>
        {line.description ? (
          <p className="text-muted-foreground mt-1 text-[12px] leading-relaxed">
            {t(line.description)}
          </p>
        ) : null}
      </div>
      <button
        type="button"
        title={t('Copy scope')}
        aria-label={`${t('Copy scope')} ${line.token}`}
        onClick={onCopy}
        className="cursor-pointer text-muted-foreground hover:text-foreground absolute end-0 top-0 opacity-0 transition group-hover:opacity-100 focus:opacity-100"
      >
        {copied ? (
          <Check className="size-3.5" />
        ) : (
          <Copy className="size-3.5" />
        )}
      </button>
    </li>
  )
}

function ScopePanel({
  icon,
  title,
  subtitle,
  needsAttention,
  warning,
  children,
}: {
  icon: React.ReactNode
  title: string
  subtitle: string
  needsAttention?: boolean
  warning?: string
  children: React.ReactNode
}) {
  return (
    <div
      className={cn(
        'rounded-xl border bg-card/50 overflow-hidden',
        needsAttention ? 'border-amber-500/40' : 'border-border',
      )}
    >
      <div className="flex items-start gap-2.5 px-4 py-4">
        <span className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-lg">
          {icon}
        </span>
        <div className="min-w-0">
          <p className="text-[13px] font-medium">{title}</p>
          <p className="text-muted-foreground mt-1 text-[12px] leading-relaxed">
            {subtitle}
          </p>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="space-y-3 px-4 py-4">
        {children}
        {warning ? (
          <p className="text-muted-foreground flex items-center gap-1.5 text-[12px]">
            <CircleAlert className="size-3.5 shrink-0" />
            {warning}
          </p>
        ) : null}
      </div>
    </div>
  )
}
