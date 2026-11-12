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
import { Label } from '@/components/ui/label'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface DeletePropertyProps {
  property: Models.AnalyticsProperty | undefined
  open: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: (propertyId: string) => void
  isLoading?: boolean
}

/**
 * Deleting a property also purges every event and session collected for it, so
 * the confirmation requires typing the property name back.
 */
export function DeleteProperty({
  property,
  open,
  onOpenChange,
  onConfirm,
  isLoading = false,
}: DeletePropertyProps) {
  const t = useT()
  const [confirmation, setConfirmation] = useState('')

  useEffect(() => {
    if (!open) setConfirmation('')
  }, [open])

  const handleOpenChange = (newOpen: boolean) => {
    if (isLoading) return
    onOpenChange(newOpen)
    if (!newOpen) setConfirmation('')
  }

  if (!property) return null

  const canDelete = confirmation === property.name && !isLoading

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Delete property')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Are you sure you want to delete')}{' '}
            <strong>{property.name}</strong>?{' '}
            {t(
              'This permanently deletes the property and every event and session collected for it. This action cannot be undone.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0">
          <div className="space-y-4">
            <div>
              <Label
                htmlFor="delete-property-confirmation"
                className="text-[13px] font-medium text-foreground"
              >
                {t('Type the property name to confirm')}
              </Label>
              <Input
                id="delete-property-confirmation"
                value={confirmation}
                onChange={(e) => setConfirmation(e.target.value)}
                placeholder={property.name}
                className="mt-1.5"
                disabled={isLoading}
              />
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => handleOpenChange(false)}
            disabled={isLoading}
          >
            {t('Cancel')}
          </Button>
          <Button
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            disabled={!canDelete}
            onClick={() => {
              if (canDelete) onConfirm(property.$id)
            }}
          >
            {t('Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
