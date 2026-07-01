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
  Square,
  Terminal,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  parsePostgresTableId,
  postgresNav,
  type PostgresTableTab,
} from '@/lib/postgres-database-routes'
import { useExecutePostgresSql } from '@/lib/react-query/hooks'
import { buildPostgresDropTableSql } from '@/lib/postgres-table-ddl'
import { usePostgresSidebar } from './PostgresSidebarContext'

type PostgresTableContextMenuProps = {
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
  path: PostgresTableTab
  icon: typeof LayoutGrid
}[] = [
  { id: 'rows', label: 'Rows', path: 'rows', icon: Rows3 },
  { id: 'columns', label: 'Columns', path: 'columns', icon: LayoutGrid },
  { id: 'indexes', label: 'Indexes', path: 'indexes', icon: Key },
  { id: 'settings', label: 'Settings', path: 'settings', icon: Settings },
]

export function PostgresTableContextMenu({
  projectId,
  databaseId,
  tableId,
  tableName,
  children,
  onDeleted,
}: PostgresTableContextMenuProps) {
  const navigate = useNavigate()
  const { openTableInSqlEditor } = usePostgresSidebar()
  const executeSql = useExecutePostgresSql(projectId, databaseId)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const nav = useMemo(
    () => postgresNav({ projectId, databaseId }).table({ tableId }),
    [projectId, databaseId, tableId],
  )

  const tableHref = useMemo(() => {
    const path = `/projects/${projectId}/databases/postgres/${databaseId}/tables/${encodeURIComponent(tableId)}/rows`
    return `${window.location.origin}${path}`
  }, [projectId, databaseId, tableId])

  const handleGoToTab = (path: PostgresTableTab) => {
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
    navigate({ ...nav.settings() })
  }

  const handleCopyId = async () => {
    try {
      await navigator.clipboard.writeText(tableId)
      toast.success('ID copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleCopyName = async () => {
    try {
      await navigator.clipboard.writeText(tableName)
      toast.success('Name copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleCopyLink = async () => {
    try {
      await navigator.clipboard.writeText(tableHref)
      toast.success('Link copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleCopyAsJson = async () => {
    const { schema, table } = parsePostgresTableId(tableId)
    try {
      await navigator.clipboard.writeText(
        JSON.stringify({ schema, table, id: tableId }, null, 2),
      )
      toast.success('Copied as JSON')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleDelete = async () => {
    try {
      await executeSql.mutateAsync(buildPostgresDropTableSql(tableId))
      toast.success(`${tableName} has been deleted`)
      setDeleteDialogOpen(false)
      navigate({
        ...postgresNav({ projectId, databaseId }).sql(),
        replace: true,
      })
      onDeleted?.()
    } catch (error) {
      toast.error(getErrorMessage(error) ?? 'Failed to delete table')
    }
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-52">
          {TABLE_TABS.map(({ id, label, path, icon: Icon }) => (
            <ContextMenuItem key={id} onSelect={() => handleGoToTab(path)}>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Icon className="size-4" />
              </span>
              {label}
            </ContextMenuItem>
          ))}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Copy className="size-4" />
              </span>
              Copy
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem onSelect={handleCopyId}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                Copy ID
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyName}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                Copy name
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyLink}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Link2 className="size-4" />
                </span>
                Copy link
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyAsJson}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Copy className="size-4" />
                </span>
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openTableInSqlEditor(tableId)}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Terminal className="size-4" />
            </span>
            Open in SQL editor
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
            Open in new tab
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
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => setDeleteDialogOpen(true)}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Trash2 className="size-4" />
            </span>
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Delete table</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete <strong>{tableName}</strong>? All
              rows and data will be permanently removed. This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={executeSql.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete()}
              disabled={executeSql.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
