import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'

type RenameSavedGenerationDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  initialName: string
  maxLength: number
  isSubmitting?: boolean
  onSubmit: (name: string) => void | Promise<void>
}

export function RenameSavedGenerationDialog({
  open,
  onOpenChange,
  initialName,
  maxLength,
  isSubmitting = false,
  onSubmit,
}: RenameSavedGenerationDialogProps) {
  const [name, setName] = useState(initialName)

  useEffect(() => {
    if (open) {
      setName(initialName)
    }
  }, [initialName, open])

  const trimmed = name.trim()
  const canSubmit = trimmed.length > 0 && trimmed.length <= maxLength && !isSubmitting

  const handleSubmit = () => {
    if (!canSubmit) return
    void onSubmit(trimmed)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>Update name</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            This name is shown in your saved list.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <Input
            value={name}
            onChange={(event) => setName(event.target.value)}
            maxLength={maxLength}
            placeholder="Name"
            className="h-9 text-[13px]"
            autoFocus
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                handleSubmit()
              }
            }}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            disabled={isSubmitting}
            onClick={() => onOpenChange(false)}
          >
            Cancel
          </Button>
          <Button type="button" disabled={!canSubmit} onClick={handleSubmit}>
            Update
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
