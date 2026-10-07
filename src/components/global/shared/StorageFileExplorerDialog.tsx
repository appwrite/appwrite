import { useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  StorageFileExplorer,
  type StorageFileSelection,
} from '@/components/global/shared/StorageFileExplorer'
import type { StorageFileAcceptInput } from '@/lib/storage/file-accept'
import {
  WIZARD_DIALOG_CONTENT_Z,
  WIZARD_DIALOG_OVERLAY_Z,
} from '@/lib/wizard-portal-z'

export type { StorageFileAcceptInput } from '@/lib/storage/file-accept'
export {
  STORAGE_FILE_ACCEPT_SUBTITLE,
  STORAGE_FILE_ACCEPT_VIDEO_AUDIO,
} from '@/lib/storage/file-accept'
export type { StorageFileSelection } from '@/components/global/shared/StorageFileExplorer'

const MODAL_WIDTH = 1100
const MODAL_HEIGHT = 600

export interface StorageFileExplorerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string | null | undefined
  title?: string
  description?: string
  /** Primary action label (default Add) */
  confirmLabel?: string
  /** Return false to keep the dialog open (e.g. duplicate rejected). */
  onConfirm: (selection: StorageFileSelection) => void | boolean
  /**
   * When set, files that do not match these rules are disabled and cannot be
   * selected (HTML-style patterns: `video/*`, `.vtt`, `text/vtt`, etc.).
   */
  accept?: StorageFileAcceptInput
  /** Tooltip on disabled files when `accept` rejects them. */
  acceptMismatchTooltip?: string
  /** Bucket to open first instead of the first bucket in the list. */
  initialBucketId?: string | null
  /** Stack above a fullscreen `WizardLayout`. */
  aboveWizard?: boolean
}

/** Pick a file from project Storage in a modal. See `StorageFileExplorer`. */
export function StorageFileExplorerDialog({
  open,
  onOpenChange,
  projectId,
  title = 'Select file',
  description = 'Choose a bucket, then pick a file to attach.',
  confirmLabel = 'Add',
  onConfirm,
  accept,
  acceptMismatchTooltip,
  initialBucketId,
  aboveWizard = false,
}: StorageFileExplorerDialogProps) {
  const t = useT()
  const [selection, setSelection] = useState<StorageFileSelection | null>(null)

  const handleOpenChange = (next: boolean) => {
    if (!next) setSelection(null)
    onOpenChange(next)
  }

  const handleConfirm = () => {
    if (!selection) return
    const accepted = onConfirm(selection)
    if (accepted === false) return
    handleOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        className={cn(
          '!flex flex-col gap-0 p-0 max-h-[90dvh] !max-w-[min(95vw,1100px)] w-full overflow-hidden',
          aboveWizard && WIZARD_DIALOG_CONTENT_Z,
        )}
        overlayClassName={aboveWizard ? WIZARD_DIALOG_OVERLAY_Z : undefined}
        style={{ width: MODAL_WIDTH, height: MODAL_HEIGHT }}
      >
        <DialogHeader className="px-6 pt-6 pb-4 text-start shrink-0">
          <DialogTitle>{t(title)}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(description)}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <StorageFileExplorer
          projectId={projectId}
          selection={selection}
          onSelectionChange={setSelection}
          accept={accept}
          acceptMismatchTooltip={acceptMismatchTooltip}
          initialBucketId={initialBucketId}
          aboveWizard={aboveWizard}
          className="flex-1"
        />

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end shrink-0">
          <Button variant="outline" type="button" onClick={() => handleOpenChange(false)}>
            {t('Cancel')}
          </Button>
          <Button type="button" onClick={handleConfirm} disabled={!selection}>
            {t(confirmLabel)}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
