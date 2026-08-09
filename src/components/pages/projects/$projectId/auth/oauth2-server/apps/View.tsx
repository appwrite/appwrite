import { useMemo, useState } from 'react'
import {
  Copy,
  ExternalLink,
  FileJson,
  Info,
  KeyRound,
  Link2,
  Loader2,
  Pencil,
  Plus,
  Square,
  Trash2,
} from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import {
  fetchProjectOAuth2App,
  useDeleteProjectOAuth2App,
  useProject,
  useProjectOAuth2Apps,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { ProjectOAuth2AppDrawer } from './_components/ProjectOAuth2AppDrawer'
import { ProjectOAuth2AppContextMenu } from './_components/ProjectOAuth2AppContextMenu'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

function AppLogoThumb({
  logoUri,
  region,
  name,
}: {
  logoUri?: string | null
  region?: string | null
  name: string
}) {
  const t = useT()
  const src = resolveAppLogoDisplayUrl(logoUri, {
    width: 64,
    height: 64,
    region,
  })

  return (
    <div
      className={cn(
        'flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted/40',
      )}
    >
      {src ? (
        <img
          src={src}
          alt={t('App logo preview')}
          className="h-full w-full object-contain"
        />
      ) : (
        <span className="text-[11px] font-semibold uppercase text-muted-foreground">
          {name.trim().charAt(0) || '?'}
        </span>
      )}
    </div>
  )
}

interface OAuth2ServerAppsViewProps {
  projectId: string
}

export function View({ projectId }: OAuth2ServerAppsViewProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const { apps, isLoading, isFetching } = useProjectOAuth2Apps(
    projectId,
    project?.region,
  )
  const deleteMutation = useDeleteProjectOAuth2App(projectId, project?.region)

  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedApp, setSelectedApp] = useState<Models.App | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<Models.App | null>(null)

  const sortedApps = useMemo(
    () =>
      [...apps].sort(
        (a, b) =>
          new Date(b.$createdAt).getTime() - new Date(a.$createdAt).getTime(),
      ),
    [apps],
  )

  const openCreate = () => {
    openDialogAfterOverlayCloses(() => {
      setSelectedApp(null)
      setDrawerOpen(true)
    })
  }

  const openUpdate = (app: Models.App) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedApp(app)
      setDrawerOpen(true)
    })
  }

  const requestDelete = (app: Models.App) => {
    openDialogAfterOverlayCloses(() => {
      setDeleteTarget(app)
    })
  }

  const getAppHref = (app: Models.App) =>
    buildConsoleUrl(
      `/projects/${projectId}/auth/oauth2-server/apps?appId=${app.$id}`,
    )

  const handleDelete = async () => {
    if (!deleteTarget) return
    const appId = deleteTarget.$id
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteTarget(null)
      setSelectedApp(null)
    })
    try {
      await deleteMutation.mutateAsync(appId)
      toast.success(t('OAuth2 app deleted'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to delete OAuth2 app')))
    }
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="flex items-start justify-between gap-4 px-6 py-4">
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Apps')}
            </h3>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t(
                "OAuth2 clients registered against this project. These apps authenticate users through your project's authorization server.",
              )}
            </p>
          </div>
          <Button
            size="sm"
            className="h-9 shrink-0 text-[13px]"
            onClick={openCreate}
          >
            <Plus className="me-1.5 h-3.5 w-3.5" />
            {t('Create app')}
          </Button>
        </div>
        <div className="border-t border-border" />
        <div className="border-b border-border bg-blue-500/5 px-6 py-3">
          <Alert
            variant="default"
            className="border-blue-500/30 bg-transparent"
          >
            <Info className="h-4 w-4 text-blue-500" />
            <AlertTitle className="min-w-0 truncate text-[13px] font-medium text-blue-600 dark:text-blue-400">
              {project?.name?.trim()
                ? `${t('Connect with')} ${project.name.trim()}`
                : t('Connect with this project')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-blue-600/80 dark:text-blue-400/80">
              <p>
                {t(
                  'Register OAuth2 clients here when you want other products to let users connect with this project. Organization apps serve a different purpose. Create those under your organization when you want your users to connect their Appwrite account with your application.',
                )}
              </p>
              <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                <DocsRouteLink
                  href="/docs"
                  className="inline-flex items-center gap-1 font-medium text-blue-600 underline-offset-2 hover:underline dark:text-blue-400"
                >
                  {t('OAuth2 server docs')}
                  <ExternalLink className="h-3 w-3 shrink-0" />
                </DocsRouteLink>
                <DocsRouteLink
                  href="/docs/partners/apps"
                  className="inline-flex items-center gap-1 font-medium text-blue-600 underline-offset-2 hover:underline dark:text-blue-400"
                >
                  {t('Connect with Appwrite')}
                  <ExternalLink className="h-3 w-3 shrink-0" />
                </DocsRouteLink>
              </div>
            </AlertDescription>
          </Alert>
        </div>
        <div className="relative px-6 py-4">
          {isFetching && !isLoading ? (
            <div className="absolute end-6 top-4 z-10">
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          ) : null}
          {isLoading && apps.length === 0 ? (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : sortedApps.length === 0 ? (
            <EmptyState
              icon={KeyRound}
              title={t('No OAuth2 apps')}
              description={t(
                'Create an app to register redirect URIs and issue client credentials for this project.',
              )}
              action={
                <Button size="sm" onClick={openCreate}>
                  {t('Create app')}
                </Button>
              }
              variant="card"
            />
          ) : (
            <div className="overflow-hidden rounded-lg border border-border">
              <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Name')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Client ID')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Type')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Redirect URIs')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground text-end">
                  {t('Created')}
                </TableHead>
                <TableHead className="w-[80px] px-4 py-3 text-end" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {sortedApps.map((app) => (
                <ProjectOAuth2AppContextMenu
                  key={app.$id}
                  projectId={projectId}
                  region={project?.region}
                  app={app}
                  onUpdate={openUpdate}
                  onDelete={requestDelete}
                >
                  <TableRow
                    className="cursor-pointer"
                    onClick={() => openUpdate(app)}
                  >
                    <TableCell className="px-4 py-3">
                      <div className="flex items-center gap-3 min-w-0">
                        <AppLogoThumb
                          logoUri={app.logoUri}
                          region={project?.region}
                          name={app.name}
                        />
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                          <span className="text-[13px] font-medium truncate">
                            {app.name}
                          </span>
                          <Badge
                            variant={app.enabled ? 'success' : 'inactive'}
                            className="text-[10px] shrink-0"
                          >
                            {app.enabled ? t('Enabled') : t('Disabled')}
                          </Badge>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <CopyableId id={app.$id} size="xs" />
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Badge
                        variant="info"
                        className="text-[10px] capitalize shrink-0"
                      >
                        {app.type || 'confidential'}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                      {app.redirectUris?.length ?? 0}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <DateTooltip date={app.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <div
                        className="flex justify-end"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger
                              aria-label={`${t('Actions for')} ${app.name}`}
                            />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuItem onClick={() => openUpdate(app)}>
                              <MenuItemContent icon={Pencil}>
                                {t('Update')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuSub>
                              <DropdownMenuSubTrigger>
                                <MenuItemIcon icon={Copy} />
                                {t('Copy')}
                              </DropdownMenuSubTrigger>
                              <DropdownMenuSubContent>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('ID', app.$id)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy ID')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('Name', app.name)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy name')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('Link', getAppHref(app))
                                  }
                                >
                                  <MenuItemContent icon={Link2}>
                                    {t('Copy link')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    void copyResourceAsJson(() =>
                                      fetchProjectOAuth2App(
                                        projectId,
                                        app.$id,
                                        project?.region,
                                      ),
                                    )
                                  }
                                >
                                  <MenuItemContent icon={FileJson}>
                                    {t('Copy as JSON')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() => openInNewTab(getAppHref(app))}
                            >
                              <MenuItemContent icon={ExternalLink}>
                                {t('Open in new tab')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() => openInNewWindow(getAppHref(app))}
                            >
                              <MenuItemContent icon={Square}>
                                {t('Open in new window')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() => requestDelete(app)}
                            >
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </TableCell>
                  </TableRow>
                </ProjectOAuth2AppContextMenu>
              ))}
            </TableBody>
              </Table>
            </div>
          )}
        </div>
      </div>

      <ProjectOAuth2AppDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        projectId={projectId}
        region={project?.region}
        app={selectedApp}
        onSuccess={() => setSelectedApp(null)}
        onDelete={requestDelete}
      />

      <Dialog
        open={!!deleteTarget}
        onOpenChange={(open) => {
          if (!open && !deleteMutation.isPending) setDeleteTarget(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete OAuth2 app')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Delete')}{' '}
              <span className="font-medium text-foreground">
                {deleteTarget?.name}
              </span>
              ? {t('Active tokens for this client will stop working.')}{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border px-6 py-4 bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteTarget(null)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              disabled={deleteMutation.isPending}
              onClick={handleDelete}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
