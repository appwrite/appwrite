import { useState } from 'react'
import { Archive, Pencil, Trash2 } from 'lucide-react'
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
import { Input } from '@/components/ui/input'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

type AssistantConversationContextMenuProps = {
  title: string
  disabled?: boolean
  isArchived?: boolean
  children: React.ReactNode
  onRename: (title: string) => Promise<void> | void
  onArchive: () => Promise<void> | void
  onDelete: () => Promise<void> | void
}

export function AssistantConversationContextMenu({
  title,
  disabled = false,
  isArchived = false,
  children,
  onRename,
  onArchive,
  onDelete,
}: AssistantConversationContextMenuProps) {
  const t = useT()
  const [renameOpen, setRenameOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [draftTitle, setDraftTitle] = useState(title)
  const [isRenaming, setIsRenaming] = useState(false)
  const [isDeleting, setIsDeleting] = useState(false)

  if (disabled) {
    return <>{children}</>
  }

  const openRename = () => {
    setDraftTitle(title)
    openDialogAfterOverlayCloses(() => setRenameOpen(true))
  }

  const openDelete = () => {
    openDialogAfterOverlayCloses(() => setDeleteOpen(true))
  }

  const handleRename = async () => {
    const nextTitle = draftTitle.trim()
    if (!nextTitle || nextTitle === title.trim()) {
      closeDialogBeforeOverlayUnmount(() => setRenameOpen(false))
      return
    }
    setIsRenaming(true)
    try {
      await onRename(nextTitle)
      closeDialogBeforeOverlayUnmount(() => setRenameOpen(false))
    } finally {
      setIsRenaming(false)
    }
  }

  const handleDelete = async () => {
    setIsDeleting(true)
    try {
      await onDelete()
      closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
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

      <Dialog
        open={renameOpen}
        onOpenChange={(open) => {
          if (!open) {
            closeDialogBeforeOverlayUnmount(() => setRenameOpen(false))
            return
          }
          setRenameOpen(true)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Update agent')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Change the title for this agent.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-4">
            <Input
              value={draftTitle}
              onChange={(event) => setDraftTitle(event.target.value)}
              placeholder={t('Agent title')}
              className="h-9 text-[13px]"
              autoFocus
              onKeyDown={(event) => {
                if (event.key === 'Enter') {
                  event.preventDefault()
                  void handleRename()
                }
              }}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isRenaming}
              onClick={() =>
                closeDialogBeforeOverlayUnmount(() => setRenameOpen(false))
              }
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              disabled={isRenaming || !draftTitle.trim()}
              onClick={() => void handleRename()}
            >
              {t('Update')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog
        open={deleteOpen}
        onOpenChange={(open) => {
          if (!open) {
            closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
            return
          }
          setDeleteOpen(true)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>{t('Delete agent')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('This permanently deletes the agent and its messages.')}{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isDeleting}
              onClick={() =>
                closeDialogBeforeOverlayUnmount(() => setDeleteOpen(false))
              }
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
