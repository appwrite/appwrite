import { useState } from 'react'
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
  Pencil,
  CopyPlus,
  Trash2,
  Link2,
  Lock,
  ExternalLink,
  Square,
} from 'lucide-react'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  deleteProjectTableRow,
  createProjectTableRow,
} from '@/lib/react-query/hooks'

/** Minimal row shape for context menu (matches RowData from View) */
export interface RowContextMenuRow {
  $id: string
  data: Record<string, string | number | boolean | unknown>
  $createdAt?: string
  $updatedAt?: string
}

interface RowContextMenuProps {
  projectId: string
  databaseId: string
  tableId: string
  row: RowContextMenuRow
  /** When set, right-click was on a cell; show "Copy value" in Copy submenu for this column */
  contextColumnKey?: string | null
  onUpdateRow: (row: RowContextMenuRow) => void
  onUpdatePermissions?: (row: RowContextMenuRow) => void
  children: React.ReactNode
  queryKey: readonly unknown[]
}

const rowsPath = (
  projectId: string,
  databaseId: string,
  tableId: string,
  rowId?: string,
) => {
  const path = `${window.location.origin}/projects/${projectId}/databases/${databaseId}/tables/${tableId}/rows`
  return rowId ? `${path}#row-${rowId}` : path
}

const rowJsonPayload = (row: RowContextMenuRow): Record<string, unknown> => {
  const payload: Record<string, unknown> = { $id: row.$id, ...row.data }
  if (row.$createdAt) payload.$createdAt = row.$createdAt
  if (row.$updatedAt) payload.$updatedAt = row.$updatedAt
  return payload
}

function formatCellValueForCopy(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function getCellValue(row: RowContextMenuRow, columnKey: string): unknown {
  if (columnKey === '$id') return row.$id
  if (columnKey === '$sequence')
    return (row as { $sequence?: number }).$sequence
  if (columnKey === '$createdAt') return row.$createdAt
  if (columnKey === '$updatedAt') return row.$updatedAt
  return row.data[columnKey]
}

export function RowContextMenu({
  projectId,
  databaseId,
  tableId,
  row,
  contextColumnKey,
  onUpdateRow,
  onUpdatePermissions,
  children,
  queryKey,
}: RowContextMenuProps) {
  const queryClient = useQueryClient()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteMutation = useMutation({
    mutationFn: () =>
      deleteProjectTableRow(projectId, databaseId, tableId, row.$id),
    onSuccess: async () => {
      setDeleteDialogOpen(false)
      await queryClient.refetchQueries({ queryKey: [...queryKey] })
      toast.success('Row deleted')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? 'Failed to delete row')
    },
  })

  const duplicateMutation = useMutation({
    mutationFn: async () => {
      const data = { ...row.data }
      if (Object.prototype.hasOwnProperty.call(data, '$id')) {
        delete (data as Record<string, unknown>).$id
      }
      return createProjectTableRow(
        projectId,
        databaseId,
        tableId,
        data as Record<string, unknown>,
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: [...queryKey] })
      toast.success('Row duplicated')
    },
    onError: (error: Error) => {
      toast.error(getErrorMessage(error) ?? 'Failed to duplicate row')
    },
  })

  const handleCopyId = async () => {
    try {
      await navigator.clipboard.writeText(row.$id)
      toast.success('ID copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleCopyAsJson = async () => {
    try {
      await navigator.clipboard.writeText(
        JSON.stringify(rowJsonPayload(row), null, 2),
      )
      toast.success('JSON copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleOpenInNewTab = () => {
    window.open(rowHref, '_blank', 'noopener,noreferrer')
  }

  const handleOpenInNewWindow = () => {
    window.open(rowHref, '_blank', 'noopener,noreferrer,width=1200,height=800')
  }

  const handleCopyValue = async () => {
    if (contextColumnKey == null) return
    try {
      const value = getCellValue(row, contextColumnKey)
      await navigator.clipboard.writeText(formatCellValueForCopy(value))
      toast.success('Value copied')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleUpdateRow = () => {
    onUpdateRow(row)
  }

  const rowHref = rowsPath(projectId, databaseId, tableId, row.$id)

  const handleCopyLink = async () => {
    try {
      await navigator.clipboard.writeText(rowHref)
      toast.success('Link copied to clipboard')
    } catch {
      toast.error('Failed to copy')
    }
  }

  const handleUpdatePermissions = () => {
    onUpdatePermissions?.(row)
  }

  const handleDuplicate = () => {
    duplicateMutation.mutate()
  }

  const handleDeleteClick = () => {
    setDeleteDialogOpen(true)
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-52">
          <ContextMenuItem onSelect={handleUpdateRow}>
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <Pencil className="size-4" />
            </span>
            Update row
          </ContextMenuItem>
          {onUpdatePermissions && (
            <ContextMenuItem onSelect={handleUpdatePermissions}>
              <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                <Lock className="size-4" />
              </span>
              Update permissions
            </ContextMenuItem>
          )}
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
              {contextColumnKey != null && contextColumnKey !== '' && (
                <ContextMenuItem onSelect={handleCopyValue}>
                  <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                    <Copy className="size-4" />
                  </span>
                  Copy value
                </ContextMenuItem>
              )}
              <ContextMenuItem onSelect={handleCopyAsJson}>
                <span className="flex h-4 w-4 shrink-0 items-center justify-center">
                  <FileJson className="size-4" />
                </span>
                Copy as JSON
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuItem
            onSelect={handleDuplicate}
            disabled={duplicateMutation.isPending}
          >
            <span className="flex h-4 w-4 shrink-0 items-center justify-center">
              <CopyPlus className="size-4" />
            </span>
            Duplicate
          </ContextMenuItem>
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
            <DialogTitle>Delete row</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this row? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteMutation.mutate()}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

    </>
  )
}
