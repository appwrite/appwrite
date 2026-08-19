import { useState } from 'react'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  Copy,
  Link2,
  FileJson,
  ExternalLink,
  Square,
  Table2,
  Workflow,
  Shield,
  Archive,
  ArrowRightLeft,
  Settings,
  Activity,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { DatabaseType } from '@/lib/databases/database-type'
import {
  deleteProjectDatabase,
  fetchProjectDatabase,
  invalidateDatabaseModelAndType,
  refetchProjectDatabaseLists,
} from '@/lib/react-query/hooks'
import {
  databaseRouteKindFromApiType,
  productDatabaseHomePath,
} from '@/lib/database-routes'
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
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { useT } from '@/lib/i18n/translate'

type DatabaseContextMenuDatabase = {
  $id: string
  name?: string | null
  databaseType?: DatabaseType
}

interface DatabaseContextMenuProps {
  projectId: string
  database: DatabaseContextMenuDatabase
  showSecuritySettings: boolean
  showMonitor: boolean
  showBackups: boolean
  provisioningDisabled?: boolean
  children: React.ReactNode
}

export function DatabaseContextMenu({
  projectId,
  database,
  showSecuritySettings,
  showMonitor,
  showBackups,
  provisioningDisabled = false,
  children,
}: DatabaseContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const dbKind = databaseRouteKindFromApiType(database.databaseType)

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await deleteProjectDatabase(projectId, database.$id, dbKind)
    },
    onSuccess: async () => {
      invalidateDatabaseModelAndType(projectId, database.$id)
      await refetchProjectDatabaseLists(queryClient, projectId)
      toast.success(t('Database deleted successfully'))
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to delete database')))
    },
  })

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate()
  }

  const databaseHref = buildConsoleUrl(
    productDatabaseHomePath(projectId, database.$id, database.databaseType),
  )
  const hasName = !!database.name

  const navigateToTab = (
    path:
      | '/projects/$projectId/databases/$dbKind/$databaseId/'
      | '/projects/$projectId/databases/$dbKind/$databaseId/visualizer'
      | '/projects/$projectId/databases/$dbKind/$databaseId/monitor'
      | '/projects/$projectId/databases/$dbKind/$databaseId/settings/security'
      | '/projects/$projectId/databases/$dbKind/$databaseId/backups'
      | '/projects/$projectId/databases/$dbKind/$databaseId/export-import'
      | '/projects/$projectId/databases/$dbKind/$databaseId/settings',
  ) => {
    navigate({
      to: path,
      params: { projectId, dbKind, databaseId: database.$id },
    })
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$dbKind/$databaseId/',
              )
            }
          >
            <ContextMenuIcon icon={Table2} />
            {t('Tables')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$dbKind/$databaseId/visualizer',
              )
            }
          >
            <ContextMenuIcon icon={Workflow} />
            {t('Visualizer')}
          </ContextMenuItem>
          {showMonitor && (
            <ContextMenuItem
              disabled={provisioningDisabled}
              onSelect={() =>
                navigateToTab(
                  '/projects/$projectId/databases/$dbKind/$databaseId/monitor',
                )
              }
            >
              <ContextMenuIcon icon={Activity} />
              {t('Monitor')}
            </ContextMenuItem>
          )}
          {showSecuritySettings && (
            <ContextMenuItem
              disabled={provisioningDisabled}
              onSelect={() =>
                navigateToTab(
                  '/projects/$projectId/databases/$dbKind/$databaseId/settings/security',
                )
              }
            >
              <ContextMenuIcon icon={Shield} />
              {t('Security')}
            </ContextMenuItem>
          )}
          {showBackups && (
            <ContextMenuItem
              disabled={provisioningDisabled}
              onSelect={() =>
                navigateToTab(
                  '/projects/$projectId/databases/$dbKind/$databaseId/backups',
                )
              }
            >
              <ContextMenuIcon icon={Archive} />
              {t('Backups')}
            </ContextMenuItem>
          )}
          <ContextMenuItem
            onSelect={() =>
              navigateToTab(
                '/projects/$projectId/databases/$dbKind/$databaseId/export-import',
              )
            }
          >
            <ContextMenuIcon icon={ArrowRightLeft} />
            {t('Export / Import')}
          </ContextMenuItem>
          {showSecuritySettings && (
            <ContextMenuItem
              disabled={provisioningDisabled}
              onSelect={() =>
                navigateToTab(
                  '/projects/$projectId/databases/$dbKind/$databaseId/settings',
                )
              }
            >
              <ContextMenuIcon icon={Settings} />
              {t('Settings')}
            </ContextMenuItem>
          )}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', database.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {hasName && (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', database.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              )}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', databaseHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchProjectDatabase(
                      projectId,
                      database.$id,
                      databaseRouteKindFromApiType(database.databaseType),
                    ),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(databaseHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(databaseHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDeleteClick}>
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <ConfirmNameDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title="Delete database"
        description={
          <>
            {t(
              'Are you sure you want to delete this database? This action cannot be undone.',
            )}
          </>
        }
        confirmValue={database.name?.trim() || database.$id}
        confirmPlaceholder="Enter database name"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
