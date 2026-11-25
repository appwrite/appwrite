import { useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import {
  AlertTriangle,
  Blocks,
  ExternalLink,
  FolderKey,
  Loader2,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
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
import { SettingsCardsList } from '@/components/global/shared/settings-search/SettingsCardsList'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { mapAppToMarketplaceApp } from '@/lib/marketplace/map-app'
import {
  PROJECT_RAR_TYPE,
  WILDCARD_IDENTIFIER,
} from '@/lib/oauth2/authorization-details'
import {
  useDeleteOrganizationInstalledApp,
  useOrganizationApp,
  useOrganizationInstalledApps,
  useOrganizationProjects,
  useOrganizationScopes,
  useUpdateOrganizationInstalledApp,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { MarketplaceAppLogo } from '../marketplace/_components/MarketplaceAppLogo'

export function InstalledApps() {
  return (
    <SettingsCardsList
      cards={[
        {
          id: 'installed-apps',
          search: {
            title: 'Installed apps',
            keywords: [
              'installed',
              'apps',
              'installations',
              'marketplace',
              'access',
              'uninstall',
            ],
          },
          node: <InstalledAppsCard />,
        },
      ]}
    />
  )
}

/** Apps installed on this organization from the marketplace, with a way to edit their access or uninstall them. */
function InstalledAppsCard() {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const { access } = useOrganizationScopes(orgId)
  const {
    installations,
    isLoading,
    hasMore,
    loadMore,
    isLoadingMore,
    loadMoreFailed,
    error,
    refetch,
  } = useOrganizationInstalledApps(orgId)
  const deleteMutation = useDeleteOrganizationInstalledApp(orgId)
  const [deleteTarget, setDeleteTarget] =
    useState<Models.AppInstallation | null>(null)
  const [editTarget, setEditTarget] = useState<Models.AppInstallation | null>(
    null,
  )

  const handleDelete = async () => {
    if (!deleteTarget) return
    try {
      await deleteMutation.mutateAsync(deleteTarget.$id)
      toast.success(t('App uninstalled'))
      setDeleteTarget(null)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to uninstall app')))
    }
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Installed apps')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Apps installed on this organization and the scopes they were granted. Uninstalling an app revokes its access tokens.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : error && installations.length === 0 ? (
            <EmptyState
              icon={AlertTriangle}
              title={t('Failed to load installed apps')}
              description={getErrorMessage(error, t('Please try again.'))}
              variant="card"
              action={
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => void refetch()}
                >
                  {t('Try again')}
                </Button>
              }
            />
          ) : installations.length === 0 ? (
            <EmptyState
              icon={Blocks}
              title={t('No installed apps')}
              description={t(
                'Apps you install from the marketplace appear here.',
              )}
              variant="card"
            />
          ) : (
            <div className="overflow-hidden rounded-lg border border-border divide-y divide-border">
              {installations.map((installation) => (
                <InstalledAppRow
                  key={installation.$id}
                  installation={installation}
                  organizationId={orgId!}
                  canUninstall={access.isOwner}
                  onEditAccess={() => setEditTarget(installation)}
                  onUninstall={() => setDeleteTarget(installation)}
                />
              ))}
            </div>
          )}
          {hasMore ? (
            <div className="flex flex-col items-center gap-2 pt-3">
              {loadMoreFailed ? (
                <p className="text-[12px] text-destructive">
                  {t('Loading more failed. Try again.')}
                </p>
              ) : null}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 text-[12px]"
                disabled={isLoadingMore}
                onClick={loadMore}
              >
                {t('Load more')}
              </Button>
            </div>
          ) : null}
        </div>
      </div>

      {editTarget ? (
        <EditAccessDialog
          installation={editTarget}
          organizationId={orgId!}
          onClose={() => setEditTarget(null)}
        />
      ) : null}

      <ConfirmActionDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => {
          if (!open) setDeleteTarget(null)
        }}
        title={t('Uninstall app')}
        description={t(
          'The app loses access to this organization immediately and its installation tokens stop working. You can install it again later.',
        )}
        confirmLabel={t('Uninstall')}
        confirmVariant="destructive"
        onConfirm={() => void handleDelete()}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}

function InstalledAppRow({
  installation,
  organizationId,
  canUninstall,
  onEditAccess,
  onUninstall,
}: {
  installation: Models.AppInstallation
  organizationId: string
  canUninstall: boolean
  onEditAccess: () => void
  onUninstall: () => void
}) {
  const t = useT()
  const { app } = useOrganizationApp(installation.appId)
  const mapped = app ? mapAppToMarketplaceApp(app, { organizationId }) : null
  const projectIds = grantedProjectIds(installation)
  const logoUrl = mapped
    ? resolveAppLogoDisplayUrl(mapped.logoUri, { width: 64, height: 64 })
    : null

  return (
    <div className="flex items-start justify-between gap-3 p-3">
      <div className="flex min-w-0 flex-1 gap-3">
        {logoUrl ? (
          <MarketplaceAppLogo
            src={logoUrl}
            size="sm"
            monochrome={mapped?.isOfficial}
          />
        ) : (
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
            <Blocks className="h-4 w-4 text-muted-foreground" />
          </div>
        )}
        <div className="min-w-0 flex-1 space-y-2">
          <div className="truncate text-[13px] font-medium text-foreground">
            {mapped?.name ?? installation.appId}
          </div>
          <div className="flex flex-wrap items-center gap-1">
            {installation.scopes.length === 0 ? (
              <span className="text-[12px] text-muted-foreground">
                {t('No scopes granted')}
              </span>
            ) : (
              installation.scopes.map((scope) => (
                <Badge
                  key={scope}
                  variant="info"
                  className="font-mono text-[10px] shrink-0"
                >
                  {scope}
                </Badge>
              ))
            )}
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted-foreground">
            <span className="whitespace-nowrap">
              {t('Installed')}{' '}
              <DateTooltip
                date={installation.$createdAt}
                className="text-[12px] text-muted-foreground"
              />
            </span>
            {installation.createdByName ? (
              <span className="whitespace-nowrap">
                {t('Installed by')} {installation.createdByName}
              </span>
            ) : null}
            <span className="whitespace-nowrap">
              {installation.lastAccessedAt ? (
                <>
                  {t('Last used')}{' '}
                  <DateTooltip
                    date={installation.lastAccessedAt}
                    className="text-[12px] text-muted-foreground"
                  />
                </>
              ) : (
                t('Never used')
              )}
            </span>
            <span className="whitespace-nowrap">
              {projectIds.includes(WILDCARD_IDENTIFIER)
                ? t('All projects')
                : projectIds.length === 0
                  ? t('No project access')
                  : `${t('Projects')}: ${projectIds.length}`}
            </span>
          </div>
        </div>
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <RowActionsMenuTrigger />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem asChild>
            <Link
              to="/organizations/$orgId/marketplace/$appId"
              params={{ orgId: organizationId, appId: installation.appId }}
            >
              <MenuItemContent icon={ExternalLink}>
                {t('View in marketplace')}
              </MenuItemContent>
            </Link>
          </DropdownMenuItem>
          {canUninstall ? (
            <DropdownMenuItem onClick={onEditAccess}>
              <MenuItemContent icon={FolderKey}>
                {t('Edit access')}
              </MenuItemContent>
            </DropdownMenuItem>
          ) : null}
          {canUninstall ? (
            <DropdownMenuItem onClick={onUninstall}>
              <MenuItemContent icon={Trash2}>{t('Uninstall')}</MenuItemContent>
            </DropdownMenuItem>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}

/** Project ids in the installation's `project` authorization details entry; `['*']` means all. */
function grantedProjectIds(installation: Models.AppInstallation): string[] {
  return installation.authorizationDetails
    .filter((detail) => detail.type === PROJECT_RAR_TYPE)
    .flatMap((detail) =>
      Array.isArray(detail.identifiers) ? (detail.identifiers as string[]) : [],
    )
}

function EditAccessDialog({
  installation,
  organizationId,
  onClose,
}: {
  installation: Models.AppInstallation
  organizationId: string
  onClose: () => void
}) {
  const t = useT()
  const { projects, isLoading } = useOrganizationProjects(organizationId)
  const updateMutation = useUpdateOrganizationInstalledApp(organizationId)
  const initial = grantedProjectIds(installation)
  const [mode, setMode] = useState<'all' | 'specific'>(
    initial.includes(WILDCARD_IDENTIFIER) ? 'all' : 'specific',
  )
  const [selected, setSelected] = useState<string[]>(
    initial.filter((id) => id !== WILDCARD_IDENTIFIER),
  )

  const toggle = (projectId: string, checked: boolean) =>
    setSelected((current) =>
      checked
        ? [...current, projectId]
        : current.filter((id) => id !== projectId),
    )

  const handleSave = async () => {
    const identifiers = mode === 'all' ? [WILDCARD_IDENTIFIER] : selected
    // Other entry types are app-defined; only the project entry is edited here.
    const details = [
      ...installation.authorizationDetails.filter(
        (detail) => detail.type !== PROJECT_RAR_TYPE,
      ),
      ...(identifiers.length > 0
        ? [{ type: PROJECT_RAR_TYPE, identifiers }]
        : []),
    ]
    try {
      await updateMutation.mutateAsync({
        installationId: installation.$id,
        authorizationDetails: JSON.stringify(details),
      })
      toast.success(t('Access updated'))
      onClose()
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to update access')))
    }
  }

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !updateMutation.isPending) onClose()
      }}
    >
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Edit access')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Choose which projects this app can reach. Saving also grants any scopes the app added since it was installed, and signs it out so it picks up the new access.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="px-6 pb-4 space-y-3">
          <RadioGroup
            value={mode}
            onValueChange={(value) => setMode(value as 'all' | 'specific')}
            className="flex flex-row gap-4"
          >
            <div className="flex items-center gap-2">
              <RadioGroupItem id="installation-access-all" value="all" />
              <Label
                htmlFor="installation-access-all"
                className="cursor-pointer text-[13px] font-normal"
              >
                {t('All projects')}
              </Label>
            </div>
            <div className="flex items-center gap-2">
              <RadioGroupItem
                id="installation-access-specific"
                value="specific"
              />
              <Label
                htmlFor="installation-access-specific"
                className="cursor-pointer text-[13px] font-normal"
              >
                {t('Specific projects')}
              </Label>
            </div>
          </RadioGroup>
          {mode === 'specific' ? (
            isLoading ? (
              <div className="flex items-center justify-center py-6">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
              </div>
            ) : projects.length === 0 ? (
              <p className="text-[12px] text-muted-foreground">
                {t('This organization has no projects yet.')}
              </p>
            ) : (
              <div className="max-h-64 overflow-y-auto rounded-lg border border-border divide-y divide-border">
                {projects.map((project) => (
                  <label
                    key={project.$id}
                    className="flex cursor-pointer items-center gap-3 px-3 py-2 text-[13px]"
                  >
                    <Checkbox
                      checked={selected.includes(project.$id)}
                      onCheckedChange={(checked) =>
                        toggle(project.$id, checked === true)
                      }
                    />
                    <span className="truncate text-foreground">
                      {project.name}
                    </span>
                  </label>
                ))}
              </div>
            )
          ) : null}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={updateMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            disabled={updateMutation.isPending}
            onClick={() => void handleSave()}
          >
            {t('Save')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
