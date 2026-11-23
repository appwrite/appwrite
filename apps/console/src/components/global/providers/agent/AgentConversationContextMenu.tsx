import { useState } from 'react'
import { Archive, Pencil, Pin, PinOff, Trash2 } from 'lucide-react'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
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
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { AgentRenameDialog } from '@/components/global/providers/agent/AgentRenameDialog'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

type AgentConversationContextMenuProps = {
  title: string
  disabled?: boolean
  isArchived?: boolean
  isPinned?: boolean
  children: React.ReactNode
  onRename: (title: string) => Promise<void> | void
  onPin?: () => void
  onUnpin?: () => void
  onArchive: () => Promise<void> | void
  onDelete: () => Promise<void> | void
}

export function AgentConversationContextMenu({
  title,
  disabled = false,
  isArchived = false,
  isPinned = false,
  children,
  onRename,
  onPin,
  onUnpin,
  onArchive,
  onDelete,
}: AgentConversationContextMenuProps) {
  const t = useT()
  const [renameOpen, setRenameOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [isDeleting, setIsDeleting] = useState(false)

  if (disabled) {
    return <>{children}</>
  }

  const openRename = () => {
    openDialogAfterOverlayCloses(() => setRenameOpen(true))
  }

  const openDelete = () => {
    openDialogAfterOverlayCloses(() => setDeleteOpen(true))
  }

  const closeDeleteDialog = () => {
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteOpen(false)
    })
  }

  const handleDelete = async () => {
    setIsDeleting(true)
    try {
      await onDelete()
      closeDeleteDialog()
    } finally {
      setIsDeleting(false)
    }
  }

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-48">
          <ContextMenuItem onSelect={openRename}>
            <ContextMenuIcon icon={Pencil} />
            {t('Update')}
          </ContextMenuItem>
          {isPinned ? (
            <ContextMenuItem
              onSelect={() => {
                onUnpin?.()
              }}
            >
              <ContextMenuIcon icon={PinOff} />
              {t('Unpin')}
            </ContextMenuItem>
          ) : (
            <ContextMenuItem
              onSelect={() => {
                onPin?.()
              }}
            >
              <ContextMenuIcon icon={Pin} />
              {t('Pin')}
            </ContextMenuItem>
          )}
          {!isArchived ? (
            <ContextMenuItem
              onSelect={() => {
                void onArchive()
              }}
            >
              <ContextMenuIcon icon={Archive} />
              {t('Archive')}
            </ContextMenuItem>
          ) : null}
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={openDelete}>
            <ContextMenuIcon icon={Trash2} />
            {t('Delete')}
          </ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>

      <AgentRenameDialog
        open={renameOpen}
        onOpenChange={setRenameOpen}
        title={title}
        onRename={onRename}
      />

      <Dialog
        open={deleteOpen}
        onOpenChange={(open) => {
          if (!open) {
            closeDeleteDialog()
            return
          }
          setDeleteOpen(true)
        }}
      >
        <DialogContent
          className="z-[140] sm:max-w-md p-0"
          overlayClassName="z-[140]"
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete agent')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('This permanently deletes the agent and its messages.')}{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isDeleting}
              onClick={closeDeleteDialog}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={isDeleting}
              onClick={() => void handleDelete()}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
