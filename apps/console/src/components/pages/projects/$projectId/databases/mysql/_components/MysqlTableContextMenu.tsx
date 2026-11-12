import { useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  Copy,
  ExternalLink,
  Key,
  LayoutGrid,
  Link2,
  Rows3,
  Settings,
  Shield,
  Square,
  Terminal,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import {
  parseMysqlTableId,
  mysqlNav,
  type MysqlTableTab,
} from '@/lib/mysql-database-routes'
import { useExecuteMysqlSql } from '@/lib/react-query/hooks'
import { buildMysqlDropTableSql } from '@/lib/mysql-table-ddl'
import { canShowTableSecuritySettings } from '@/lib/console-access-checks'
import { useDatabaseTableOperationsAccess } from '../../_components/DatabaseOperationsLockContext'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useOrganizationScopes } from '@/lib/react-query/hooks/organizations'
import { useProject } from '@/lib/react-query/hooks/projects'
import { useMysqlSidebar } from './MysqlSidebarContext'
import { useT } from '@/lib/i18n/translate'

type MysqlTableContextMenuProps = {
  projectId: string
  databaseId: string
  tableId: string
  tableName: string
  children: React.ReactNode
  onDeleted?: () => void
}

const TABLE_TABS: {
  id: string
  label: string
  path: MysqlTableTab
  icon: typeof LayoutGrid
}[] = [
  { id: 'rows', label: 'Rows', path: 'rows', icon: Rows3 },
  { id: 'columns', label: 'Columns', path: 'columns', icon: LayoutGrid },
  { id: 'indexes', label: 'Indexes', path: 'indexes', icon: Key },
  { id: 'security', label: 'Security', path: 'security', icon: Shield },
  { id: 'settings', label: 'Settings', path: 'settings', icon: Settings },
]

export function MysqlTableContextMenu({
  projectId,
  databaseId,
  tableId,
  tableName,
  children,
  onDeleted,
}: MysqlTableContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId ?? undefined)
  const showSecurityTab = canShowTableSecuritySettings(access, features)
  const { canWrite } = useDatabaseTableOperationsAccess()
  const { openTableInSqlEditor } = useMysqlSidebar()
  const executeSql = useExecuteMysqlSql(projectId, databaseId)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const nav = useMemo(
    () => mysqlNav({ projectId, databaseId }).table({ tableId }),
    [projectId, databaseId, tableId],
  )

  const visibleTableTabs = useMemo(
    () =>
      TABLE_TABS.filter(
        (tab) => tab.path !== 'security' || showSecurityTab,
      ),
    [showSecurityTab],
  )

  const tableHref = useMemo(() => {
    const path = `/projects/${projectId}/databases/mysql/${databaseId}/tables/${encodeURIComponent(tableId)}/rows`
    return `${window.location.origin}${path}`
  }, [projectId, databaseId, tableId])

  const handleGoToTab = (path: MysqlTableTab) => {
    if (path === 'rows') {
      navigate({ ...nav.rows() })
      return
    }
    if (path === 'columns') {
      navigate({ ...nav.columns() })
      return
    }
    if (path === 'indexes') {
      navigate({ ...nav.indexes() })
      return
    }
    if (path === 'security') {
      navigate({ ...nav.security() })
      return
    }
    navigate({ ...nav.settings() })
  }

  const handleCopyId = async () => {
    try {
      await navigator.clipboard.writeText(tableId)
      toast.success(t('ID copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  const handleCopyName = async () => {
    try {
      await navigator.clipboard.writeText(tableName)
      toast.success(t('Name copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  const handleCopyLink = async () => {
    try {
      await navigator.clipboard.writeText(tableHref)
      toast.success(t('Link copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  const handleCopyAsJson = async () => {
    const { schema, table } = parseMysqlTableId(tableId)
    try {
      await navigator.clipboard.writeText(
        JSON.stringify({ schema, table, id: tableId }, null, 2),
      )
      toast.success(t('Copied as JSON'))
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleDelete = async () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    try {
      await executeSql.mutateAsync(buildMysqlDropTableSql(tableId))
      toast.success(`${tableName} ${t('has been deleted')}`)
      navigate({
        ...mysqlNav({ projectId, databaseId }).sql(),
        replace: true,
      })
      onDeleted?.()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? t('Failed to delete table'))
    }
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-52">
          {visibleTableTabs.map(({ id, label, path, icon: Icon }) => (
            <ContextMenuItem key={id} onSelect={() => handleGoToTab(path)}>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Icon className="size-4" />
              </span>
              {t(label)}
            </ContextMenuItem>
          ))}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Copy className="size-4" />
              </span>
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={handleCopyId}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                {t('Copy ID')}
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyName}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                {t('Copy name')}
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyLink}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Link2 className="size-4" />
                </span>
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyAsJson}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openTableInSqlEditor(tableId)}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Terminal className="size-4" />
            </span>
            {t('Open in SQL editor')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem
            onSelect={() =>
              window.open(tableHref, '_blank', 'noopener,noreferrer')
            }
          >
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <ExternalLink className="size-4" />
            </span>
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem
            onSelect={() =>
              window.open(
                tableHref,
                '_blank',
                'noopener,noreferrer,width=1200,height=800',
              )
            }
          >
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Square className="size-4" />
            </span>
            {t('Open in new window')}
          </ContextMenuItem>
          {canWrite ? (
            <>
              <ContextMenuSeparator />
              <ContextMenuItem onSelect={handleDeleteClick}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Trash2 className="size-4" />
                </span>
                {t('Delete')}
              </ContextMenuItem>
            </>
          ) : null}
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete table')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              <strong>{tableName}</strong>?{' '}
              {t(
                'All rows and data will be permanently removed. This action cannot be undone.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={executeSql.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={executeSql.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
