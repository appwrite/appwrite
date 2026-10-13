import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useT } from '@/lib/i18n/translate'

type SpreadsheetBulkActionsBarProps = {
  selectedCount: number
  selectionBadge: string
  onClearSelection: () => void
  deleteDialogOpen: boolean
  onDeleteDialogOpenChange: (open: boolean) => void
  deleteDialogTitle: string
  deleteDialogDescription: string
  onConfirmDelete: () => void
  isDeleting: boolean
  extraActions?: ReactNode
}

export function SpreadsheetBulkActionsBar({
  selectedCount,
  selectionBadge,
  onClearSelection,
  deleteDialogOpen,
  onDeleteDialogOpenChange,
  deleteDialogTitle,
  deleteDialogDescription,
  onConfirmDelete,
  isDeleting,
  extraActions,
}: SpreadsheetBulkActionsBarProps) {
  const t = useT()

  if (selectedCount === 0) return null

  return (
    <>
      <div className="fixed bottom-4 start-1/2 z-50 w-[min(100%,calc(100vw-2rem))] max-w-md -translate-x-1/2 px-2 sm:px-0 sm:w-auto sm:max-w-none">
        <div className="mx-auto flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border bg-background px-4 py-3 sm:min-w-[400px] sm:gap-3 sm:px-6">
          <Badge variant="secondary" className="h-6 shrink-0 px-2.5 text-[10px]">
            {selectionBadge}
          </Badge>
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={onClearSelection}
              className="h-8 text-xs"
            >
              {t('Cancel')}
            </Button>
            {extraActions}
            <Button
              variant="destructive"
              size="sm"
              onClick={() => onDeleteDialogOpenChange(true)}
              disabled={isDeleting}
              className="h-8 gap-2"
            >
              {t('Delete')}
            </Button>
          </div>
        </div>
      </div>

      <Dialog open={deleteDialogOpen} onOpenChange={onDeleteDialogOpenChange}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{deleteDialogTitle}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {deleteDialogDescription}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => onDeleteDialogOpenChange(false)}
              disabled={isDeleting}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={onConfirmDelete}
              disabled={isDeleting}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
