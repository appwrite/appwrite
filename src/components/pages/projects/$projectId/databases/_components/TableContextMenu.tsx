import { useState, useMemo } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
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
  FileJson,
  CopyPlus,
  ExternalLink,
  Square,
  Link2,
  Table2,
  LayoutGrid,
  Key,
  Lock,
  Settings,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { deleteProjectTable, fetchProjectTable } from '@/lib/react-query/hooks'
import { copyResourceAsJson } from '@/lib/utils/context-menu'
import {
  dbNavLink,
  type DatabaseRouteKind,
  usesCollectionsPath,
} from '@/lib/database-routes'
import { CreateTableSimilar } from './CreateTableSimilar'
import { useT } from '@/lib/i18n/translate'

interface TableContextMenuProps {
  projectId: string
  databaseId: string
  /** Route segment for this database product (drives tables vs collections URLs). */
  dbKind: DatabaseRouteKind
  table: { $id: string; name?: string }
  children: React.ReactNode
  onCreateSimilar?: (newTableId: string) => void
  onDeleted?: () => void
  /** When false, Security and Settings are hidden from the context menu (e.g. read-only roles) */
  showSecuritySettings?: boolean
}

type TableContextTabPath =
  | 'rows'
  | 'columns'
  | 'indexes'
  | 'security'
  | 'settings'

const TABLE_TABS_ALL: {
  id: string
  label: string
  path: TableContextTabPath
  icon: typeof Table2
}[] = [
  { id: 'rows', label: 'Rows', path: 'rows', icon: Table2 },
  { id: 'columns', label: 'Columns', path: 'columns', icon: LayoutGrid },
  { id: 'indexes', label: 'Indexes', path: 'indexes', icon: Key },
  { id: 'security', label: 'Security', path: 'security', icon: Lock },
  { id: 'settings', label: 'Settings', path: 'settings', icon: Settings },
]

export function TableContextMenu({
  projectId,
  databaseId,
  dbKind,
  table,
  children,
  onCreateSimilar,
  onDeleted,
  showSecuritySettings = true,
}: TableContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [createSimilarOpen, setCreateSimilarOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const nav = useMemo(() => dbNavLink(dbKind), [dbKind])

  const tableTabs = useMemo(() => {
    let tabs = TABLE_TABS_ALL
    if (usesCollectionsPath(dbKind)) {
      tabs = tabs.filter((t) => t.id !== 'columns')
    }
    if (!showSecuritySettings) {
      tabs = tabs.filter((t) => t.id !== 'security' && t.id !== 'settings')
    }
    return tabs
  }, [dbKind, showSecuritySettings])

  const deleteTableMutation = useMutation({
    mutationFn: () =>
      deleteProjectTable(projectId, databaseId, dbKind, table.$id),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(`${table.name ?? table.$id} ${t('has been deleted')}`)
      navigate({
        ...nav.dataGrid({
          projectId,
          dbKind,
          databaseId,
          resourceId: '-',
        }),
        replace: true,
      })
      onDeleted?.()
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? t('Failed to delete table'))
    },
  })

  const tableHref = useMemo(() => {
    const coll = usesCollectionsPath(dbKind)
    const path = coll
      ? `/projects/${projectId}/databases/${dbKind}/${databaseId}/collections/${table.$id}/documents`
      : `/projects/${projectId}/databases/${dbKind}/${databaseId}/tables/${table.$id}/rows`
    return `${window.location.origin}${path}`
  }, [projectId, dbKind, databaseId, table.$id])

  const handleCopyId = async () => {
    try {
      await navigator.clipboard.writeText(table.$id)
      toast.success(t('ID copied to clipboard'))
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

  const handleDuplicateStructure = () => {
    openDialogAfterOverlayCloses(() => setCreateSimilarOpen(true))
  }

  const handleOpenInNewTab = () => {
    window.open(tableHref, '_blank', 'noopener,noreferrer')
  }

  const handleOpenInNewWindow = () => {
    window.open(
      tableHref,
      '_blank',
      'noopener,noreferrer,width=1200,height=800',
    )
  }

  const handleCopyAsJson = async () => {
    await copyResourceAsJson(
      () => fetchProjectTable(projectId, databaseId, dbKind, table.$id),
      { fallback: table },
    )
  }

  const handleGoToTab = (path: TableContextTabPath) => {
    const p = {
      projectId,
      dbKind,
      databaseId,
      resourceId: table.$id,
    }
    if (path === 'rows') {
      navigate({ ...nav.dataGrid(p) })
      return
    }
    if (path === 'columns') {
      navigate({ ...nav.columns(p) })
      return
    }
    if (path === 'indexes') {
      navigate({ ...nav.indexes(p) })
      return
    }
    if (path === 'security') {
      navigate({ ...nav.security(p) })
      return
    }
    navigate({ ...nav.settings(p) })
  }

  const handleDeleteClick = () => {
    openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
  }

  const handleDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteTableMutation.mutate()
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-52">
          {tableTabs.map(({ id, label, path, icon: Icon }) => (
            <ContextMenuItem key={id} onSelect={() => handleGoToTab(path)}>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Icon className="size-4" />
              </span>
              {t(label)}
            </ContextMenuItem>
          ))}
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDuplicateStructure}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <CopyPlus className="size-4" />
            </span>
            {t('Duplicate structure')}
          </ContextMenuItem>
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
              <ContextMenuItem onSelect={handleCopyLink}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Link2 className="size-4" />
                </span>
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyAsJson}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <FileJson className="size-4" />
                </span>
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleOpenInNewTab}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <ExternalLink className="size-4" />
            </span>
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={handleOpenInNewWindow}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Square className="size-4" />
            </span>
            {t('Open in new window')}
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDeleteClick}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Trash2 className="size-4" />
            </span>
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete table')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              <strong>{table.name ?? table.$id}</strong>?{' '}
              {t('All rows and data will be permanently removed. This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteTableMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={handleDelete}
              disabled={deleteTableMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <CreateTableSimilar
        open={createSimilarOpen}
        onOpenChange={setCreateSimilarOpen}
        projectId={projectId}
        databaseId={databaseId}
        sourceTable={{ $id: table.$id, name: table.name }}
        onCreated={onCreateSimilar}
      />
    </>
  )
}
