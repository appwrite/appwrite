import { useState, useEffect } from 'react'
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
import { IdInput } from '@/components/ui/id-input'
import { useT } from '@/lib/i18n/translate'

interface CreateOrganizationDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (orgData: { organizationId?: string; name: string }) => void
  isLoading?: boolean
}

export function CreateOrganizationDialog({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreateOrganizationDialogProps) {
  const t = useT()
  const [organizationId, setOrganizationId] = useState<string | undefined>(
    undefined,
  )
  const [name, setName] = useState('')

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setOrganizationId(undefined)
      setName('')
    }
  }, [open])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!name.trim()) {
      return
    }

    onCreate({
      organizationId,
      name: name.trim(),
    })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Create organization')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Create a new organization to manage your projects and organization members.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="organization-id">{t('Organization ID')}</Label>
              <IdInput
                id="organization-id"
                value={organizationId}
                onChange={setOrganizationId}
                maxLength={36}
                disabled={isLoading}
                placeholder={t('Leave blank to auto-generate')}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="name">
                {t('Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="name"
                type="text"
                placeholder={t('My Organization')}
                value={name}
                onChange={(e) => setName(e.target.value)}
                disabled={isLoading}
                maxLength={128}
                required
                autoFocus
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || !name.trim()}>
              {t('Create organization')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
