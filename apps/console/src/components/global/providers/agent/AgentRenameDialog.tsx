import { useEffect, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

type AgentRenameDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  onRename: (title: string) => Promise<void> | void
}

export function AgentRenameDialog({
  open,
  onOpenChange,
  title,
  onRename,
}: AgentRenameDialogProps) {
  const t = useT()
  const [draftTitle, setDraftTitle] = useState(title)
  const [isRenaming, setIsRenaming] = useState(false)

  useEffect(() => {
    if (open) setDraftTitle(title)
  }, [open, title])

  const handleRename = async () => {
    const nextTitle = draftTitle.trim()
    if (!nextTitle || nextTitle === title.trim()) {
      closeDialogBeforeOverlayUnmount(() => onOpenChange(false))
      return
    }
    setIsRenaming(true)
    try {
      await onRename(nextTitle)
      closeDialogBeforeOverlayUnmount(() => onOpenChange(false))
    } finally {
      setIsRenaming(false)
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) {
          closeDialogBeforeOverlayUnmount(() => onOpenChange(false))
          return
        }
        onOpenChange(true)
      }}
    >
      <DialogContent
        className="z-[140] sm:max-w-md p-0"
        overlayClassName="z-[140]"
      >
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
              closeDialogBeforeOverlayUnmount(() => onOpenChange(false))
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
  )
}
