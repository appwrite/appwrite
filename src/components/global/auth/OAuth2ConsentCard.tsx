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
  Link2,
  Loader2,
  Lock,
  TriangleAlert,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { sdk } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import {
  buildConsentPermissions,
  splitConsentScopes,
  type PermissionLine,
} from '@/lib/oauth2/scopes'
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
}

function hostnameOf(uri: string): string | null {
  try {
    return new URL(uri).hostname
  } catch {
    return null
  }
}

function AppAvatar({ app }: { app: Models.App }) {
  const initial = (app.name || '?').charAt(0).toUpperCase()
  if (app.logoUri) {
    return (
      <img
        src={app.logoUri}
        alt={app.name}
        className="size-14 rounded-xl border object-cover"
      />
    )
  }
  return (
    <div className="bg-muted text-muted-foreground flex size-14 items-center justify-center rounded-xl border text-xl font-semibold">
      {initial}
    </div>
  )
}

export function OAuth2ConsentCard({
  grant,
  app,
  accountLabel,
  flow,
  onDone,
}: OAuth2ConsentCardProps) {
  const t = useT()
  const [error, setError] = useState<string | null>(null)
  const [showPermissions, setShowPermissions] = useState(true)
  const [copiedToken, setCopiedToken] = useState<string | null>(null)
  const copyTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const [projectSelected, setProjectSelected] = useState<string[]>([])
  const [organizationSelected, setOrganizationSelected] = useState<string[]>([])

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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [grant.$id])

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
  const blocked =
    nothingToGrant || projectNeedsResource || organizationNeedsResource

  // --- Resource search wiring -------------------------------------------------
  const orgCache = useRef<ResolvedResource[] | null>(null)
  const findProjects = (term: string) => searchProjects(term)
  const findOrganizations = async (term: string): Promise<ResolvedResource[]> => {
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
    mutationFn: () => {
      const authorizationDetails =
        projectRequested || organizationRequested
          ? serializeGrantedDetails({
              project: projectGranted ? projectSelected : undefined,
              organization: organizationGranted
                ? organizationSelected
                : undefined,
            })
          : undefined
      // Scopes are never downscoped — omit `scope` so the server keeps what the
      // client requested; only the resource binding is narrowed.
      return sdk.forConsole.oauth2.approve({
        grantId: grant.$id,
        authorizationDetails,
      })
    },
    onSuccess: (result) => {
      if (flow === 'device' || !result.redirectUrl) {
        onDone?.('approved', result.redirectUrl)
        return
      }
      window.location.href = result.redirectUrl
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
    mutationFn: () => sdk.forConsole.oauth2.reject({ grantId: grant.$id }),
    onSuccess: (result) => {
      if (flow === 'device' || !result.redirectUrl) {
        onDone?.('denied', result.redirectUrl)
        return
      }
      window.location.href = result.redirectUrl
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

  return (
    <Card className="gap-0 overflow-hidden p-0">
      {/* Header */}
      <div className="from-muted/60 flex flex-col items-center gap-4 bg-gradient-to-b to-transparent px-7 pb-4 pt-7 text-center">
        <div className="flex items-center justify-center">
          <AppAvatar app={app} />
          {accountLabel && (
            <>
              <div className="flex items-center px-2">
                <span className="border-border w-4 border-t border-dashed" />
                <span className="border-border text-muted-foreground flex size-7 items-center justify-center rounded-full border bg-[var(--card)]">
                  <Link2 className="size-3.5" />
                </span>
                <span className="border-border w-4 border-t border-dashed" />
              </div>
              <div className="bg-foreground text-background flex size-12 items-center justify-center rounded-full text-lg font-semibold">
                {accountInitial}
              </div>
            </>
          )}
        </div>
        <div className="space-y-1">
          <h1 className="text-xl font-semibold tracking-tight">
            {t('Authorize')} {app.name}
          </h1>
          <p className="text-muted-foreground text-sm">{summary}</p>
        </div>
        {accountLabel && (
          <div className="bg-muted/70 text-muted-foreground flex max-w-full items-center gap-1.5 rounded-full py-1 pe-3 ps-1 text-xs">
            <span className="bg-foreground text-background flex size-5 items-center justify-center rounded-full text-[0.6rem] font-semibold">
              {accountInitial}
            </span>
            <span className="truncate">{accountLabel}</span>
          </div>
        )}
      </div>

      {/* Body */}
      <div className="space-y-4 px-7 pb-7 pt-2">
        {/* Permissions panel */}
        {permissionGroups.length > 0 && (
          <div className="border-border overflow-hidden rounded-lg border">
            <button
              type="button"
              aria-expanded={showPermissions}
              onClick={() => setShowPermissions((v) => !v)}
              className="hover:bg-muted/50 flex w-full items-center justify-between px-3 py-2.5"
            >
              <span className="flex items-center gap-2 text-sm font-medium">
                <Lock className="text-muted-foreground size-4" />
                {t('Permissions')}
              </span>
              {showPermissions ? (
                <ChevronUp className="text-muted-foreground size-4" />
              ) : (
                <ChevronDown className="text-muted-foreground size-4" />
              )}
            </button>
            {showPermissions && (
              <div className="border-border space-y-4 border-t p-3">
                {permissionGroups.map((group) => (
                  <div key={group.heading} className="space-y-2">
                    <div>
                      <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                        {t(group.heading)}
                      </p>
                      {group.note && (
                        <p className="text-muted-foreground/80 text-[0.7rem]">
                          {t(group.note)}
                        </p>
                      )}
                    </div>
                    <ul className="space-y-2.5">
                      {group.lines.map((line) => (
                        <PermissionRow
                          key={line.token}
                          line={line}
                          copied={copiedToken === line.token}
                          onCopy={() => copyToken(line.token)}
                        />
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Project access */}
        {projectRequested && (
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
        )}

        {/* Organization access */}
        {organizationRequested && (
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
        )}

        {error && (
          <div className="border-destructive/20 bg-destructive/10 flex items-start gap-2 rounded-md border p-3">
            <TriangleAlert className="text-destructive mt-0.5 size-4 shrink-0" />
            <p className="text-destructive text-sm">{error}</p>
          </div>
        )}

        {/* Actions */}
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
            {approveMutation.isPending ? (
              <Loader2 className="me-1.5 size-4 animate-spin" />
            ) : (
              <Check className="me-1.5 size-4" />
            )}
            {approveMutation.isPending ? t('Authorizing…') : t('Authorize')}
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
            {rejectMutation.isPending ? t('Cancelling…') : t('Cancel')}
          </Button>
        </div>

        {/* Footnote */}
        <p className="text-muted-foreground flex flex-wrap items-center justify-center gap-x-1.5 gap-y-1 text-center text-xs">
          <span className="inline-flex items-center gap-1">
            <Lock className="size-3.5" />
            {flow === 'authorization' && redirectHost
              ? `${t("You'll be returned to")} ${redirectHost}`
              : flow === 'device'
                ? t('After authorizing, return to your device')
                : t('You can revoke access anytime')}
          </span>
          {app.privacyPolicyUrl && (
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
          )}
          {app.termsUrl && (
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
          )}
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
    <li className="group relative flex items-start gap-2.5">
      <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-500/12 text-emerald-600 dark:text-emerald-400">
        <Check className="size-3" />
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium">{t(line.title)}</span>
          {line.access && (
            <span
              className={cn(
                'rounded px-1.5 py-0.5 text-[0.58rem] font-medium uppercase tracking-wide',
                line.accessStrong
                  ? 'border border-amber-500/40 text-amber-600 dark:text-amber-400'
                  : 'bg-muted text-muted-foreground',
              )}
            >
              {t(line.access)}
            </span>
          )}
        </div>
        {line.description && (
          <p className="text-muted-foreground text-xs">{t(line.description)}</p>
        )}
      </div>
      <button
        type="button"
        title={t('Copy scope')}
        aria-label={`${t('Copy scope')} ${line.token}`}
        onClick={onCopy}
        className="text-muted-foreground hover:text-foreground absolute end-0 top-0 opacity-0 transition group-hover:opacity-100 focus:opacity-100"
      >
        {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
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
        'space-y-3 rounded-lg border p-3',
        needsAttention ? 'border-amber-500/50' : 'border-border',
      )}
    >
      <div className="flex items-start gap-2.5">
        <span className="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-lg">
          {icon}
        </span>
        <div className="min-w-0">
          <p className="text-sm font-medium">{title}</p>
          <p className="text-muted-foreground text-xs">{subtitle}</p>
        </div>
      </div>
      {children}
      {warning && (
        <p className="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500">
          <CircleAlert className="size-3.5 shrink-0" />
          {warning}
        </p>
      )}
    </div>
  )
}
