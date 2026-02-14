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
import { deleteProjectTable } from '@/lib/react-query/hooks'
import { CreateTableSimilar } from './CreateTableSimilar'

interface TableContextMenuProps {
  projectId: string
  databaseId: string
  table: { $id: string; name?: string }
  children: React.ReactNode
  onCreateSimilar?: (newTableId: string) => void
  onDeleted?: () => void
}

const TABLE_TABS = [
  { id: 'rows', label: 'Rows', path: 'rows', icon: Table2 },
  { id: 'columns', label: 'Columns', path: 'columns', icon: LayoutGrid },
  { id: 'indexes', label: 'Indexes', path: 'indexes', icon: Key },
  { id: 'security', label: 'Security', path: 'security', icon: Lock },
  { id: 'settings', label: 'Settings', path: 'settings', icon: Settings },
] as const

export function TableContextMenu({
  projectId,
  databaseId,
  table,
  children,
  onCreateSimilar,
  onDeleted,
}: TableContextMenuProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [createSimilarOpen, setCreateSimilarOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteTableMutation = useMutation({
    mutationFn: () => deleteProjectTable(projectId, databaseId, table.$id),
    onSuccess: async () => {
      setDeleteDialogOpen(false)
      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(`${table.name ?? table.$id} has been deleted`)
      navigate({
        to: '/projects/$projectId/databases/$databaseId/tables/$tableId/rows',
        params: { projectId, databaseId, tableId: '-' },
        replace: true,
      })
      onDeleted?.()
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? 'Failed to delete table')
    },
  })

  const tableHref = useMemo(
    () =>
      `${window.location.origin}/projects/${projectId}/databases/${databaseId}/tables/${table.$id}/rows`,
    [projectId, databaseId, table.$id],
  )

  const handleCopyId = async () => {
    try {
      await navigator.clipboard.writeText(table.$id)
      toast.success('ID copied to clipboard')
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

  const handleDuplicateStructure = () => {
    setCreateSimilarOpen(true)
  }

  const handleOpenInNewTab = () => {
    window.open(tableHref, '_blank', 'noopener,noreferrer')
  }

  const handleOpenInNewWindow = () => {
    window.open(tableHref, '_blank', 'noopener,noreferrer,width=1200,height=800')
  }

  const handleCopyJson = async () => {
    try {
      await navigator.clipboard.writeText(JSON.stringify(table, null, 2))
      toast.success('JSON copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleCopyAsJson = async () => {
    await handleCopyJson()
  }

  const handleGoToTab = (
    path: 'rows' | 'columns' | 'indexes' | 'security' | 'settings',
  ) => {
    navigate({
      to: `/projects/$projectId/databases/$databaseId/tables/$tableId/${path}` as
        | '/projects/$projectId/databases/$databaseId/tables/$tableId/rows'
        | '/projects/$projectId/databases/$databaseId/tables/$tableId/columns'
        | '/projects/$projectId/databases/$databaseId/tables/$tableId/indexes'
        | '/projects/$projectId/databases/$databaseId/tables/$tableId/security'
        | '/projects/$projectId/databases/$databaseId/tables/$tableId/settings',
      params: { projectId, databaseId, tableId: table.$id },
    })
  }

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-52">
          {TABLE_TABS.map(({ id, label, path, icon: Icon }) => (
            <ContextMenuItem
              key={id}
              onSelect={() => handleGoToTab(path)}
            >
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Icon className="size-4" />
              </span>
              {label}
            </ContextMenuItem>
          ))}
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDuplicateStructure}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <CopyPlus className="size-4" />
            </span>
            Duplicate structure
          </ContextMenuItem>
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
              <ContextMenuItem onSelect={handleCopyLink}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <Link2 className="size-4" />
                </span>
                Copy link
              </ContextMenuItem>
              <ContextMenuItem onSelect={handleCopyAsJson}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <FileJson className="size-4" />
                </span>
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleOpenInNewTab}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <ExternalLink className="size-4" />
            </span>
            Open in new tab
          </ContextMenuItem>
          <ContextMenuItem onSelect={handleOpenInNewWindow}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Square className="size-4" />
            </span>
            Open in new window
          </ContextMenuItem>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={handleDeleteClick}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Trash2 className="size-4" />
            </span>
            Delete
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete table</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete <strong>{table.name ?? table.$id}</strong>?
              All rows and data will be permanently removed. This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteTableMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteTableMutation.mutate()}
              disabled={deleteTableMutation.isPending}
            >
              Delete
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
