import { useRef, type ChangeEvent, type ReactNode } from 'react'
import { ImageIcon, Loader2, Upload, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export interface ImageFilePickerProps {
  /** Rendered in the preview slot; a placeholder icon is shown when absent. */
  preview?: ReactNode
  /** `accept` attribute of the hidden file input. */
  accept: string
  description: ReactNode
  uploadLabel: string
  /** Shown together with `onRemove`; the caller decides when removal applies. */
  removeLabel?: string
  onFile: (file: File) => void | Promise<void>
  onRemove?: () => void
  uploading?: boolean
  disabled?: boolean
  className?: string
}

/**
 * Dashed drop-style box with a preview, a hidden file input behind an Upload
 * button, and an optional Remove button. Validation and the upload itself
 * belong to the caller (`onFile`), so the same shell serves app logos and
 * profile photos.
 */
export function ImageFilePicker({
  preview,
  accept,
  description,
  uploadLabel,
  removeLabel,
  onFile,
  onRemove,
  uploading = false,
  disabled = false,
  className,
}: ImageFilePickerProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const busy = disabled || uploading

  const handleChange = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    // Clear immediately so the same file can be re-selected after an error.
    event.target.value = ''
    if (!file) return

    try {
      await onFile(file)
    } finally {
      // Native file dialogs can leave focus/layout odd inside sheets; blur after.
      if (document.activeElement instanceof HTMLElement) {
        document.activeElement.blur()
      }
    }
  }

  return (
    <div
      className={cn(
        'rounded-lg border border-dashed border-border bg-muted/20 p-4',
        className,
      )}
    >
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
        {preview ?? (
          <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted">
            <ImageIcon className="h-8 w-8 text-muted-foreground" />
          </div>
        )}
        <div className="min-w-0 flex-1 space-y-2">
          <p className="text-[13px] text-muted-foreground">{description}</p>
          <div className="flex flex-wrap gap-2">
            <input
              ref={inputRef}
              type="file"
              accept={accept}
              className="sr-only"
              tabIndex={-1}
              disabled={busy}
              onChange={handleChange}
            />
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={() => inputRef.current?.click()}
            >
              {uploading ? (
                <Loader2 className="me-1.5 h-3.5 w-3.5 animate-spin" />
              ) : (
                <Upload className="me-1.5 h-3.5 w-3.5" />
              )}
              {uploadLabel}
            </Button>
            {onRemove && removeLabel ? (
              <Button
                type="button"
                size="sm"
                variant="ghost"
                disabled={busy}
                onClick={onRemove}
              >
                <X className="me-1.5 h-3.5 w-3.5" />
                {removeLabel}
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
