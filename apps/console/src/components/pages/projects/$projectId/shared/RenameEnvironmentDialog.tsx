import { useEffect, useState } from 'react'
import { toast } from 'sonner'
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
import {
  PROJECT_NAME_MAX_LENGTH,
  useUpdateProjectEnvironment,
} from '@/lib/react-query/hooks'
import type { ProjectEnvironmentOption } from '@/lib/project-environments'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface RenameEnvironmentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  environment: ProjectEnvironmentOption | null
}

export function RenameEnvironmentDialog({
  open,
  onOpenChange,
  projectId,
  region,
  environment,
}: RenameEnvironmentDialogProps) {
  const t = useT()
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)

  const updateEnvironmentMutation = useUpdateProjectEnvironment(
    projectId,
    region,
  )

  // Opening is what seeds the field, so a rename that failed does not reset
  // what the user typed while the dialog stays open. Keyed on the id, not the
  // object: the parent rebuilds the option on every list refetch.
  useEffect(() => {
    if (open) {
      setName(environment?.name ?? '')
      setError(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- seeded by id, not name
  }, [open, environment?.id])

  const handleOpenChange = (newOpen: boolean) => {
    if (!updateEnvironmentMutation.isPending) {
      onOpenChange(newOpen)
    }
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()

    if (!environment) return

    const trimmedName = name.trim()
    if (!trimmedName) {
      setError(t('Name is required'))
      return
    }
    if (trimmedName.length > PROJECT_NAME_MAX_LENGTH) {
      setError(
        `${t('Name must be no longer than')} ${PROJECT_NAME_MAX_LENGTH} ${t('characters')}`,
      )
      return
    }

    try {
      await updateEnvironmentMutation.mutateAsync({
        environmentId: environment.id,
        name: trimmedName,
      })
      toast.success(t('Environment renamed'))
      onOpenChange(false)
    } catch (err: unknown) {
      toast.error(getErrorMessage(err, 'Failed to rename environment'))
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Rename environment')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Only the name changes. The environment ID stays the same.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="rename-environment-name">
                {t('Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="rename-environment-name"
                type="text"
                placeholder={t('Enter environment name')}
                value={name}
                onChange={(event) => {
                  setName(event.target.value)
                  if (error) setError(null)
                }}
                disabled={updateEnvironmentMutation.isPending}
                maxLength={PROJECT_NAME_MAX_LENGTH}
                className={error ? 'border-destructive' : ''}
                autoFocus
              />
              {error && <p className="text-[12px] text-destructive">{error}</p>}
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={updateEnvironmentMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              variant="brandCta"
              disabled={
                updateEnvironmentMutation.isPending ||
                !name.trim() ||
                name.trim().length > PROJECT_NAME_MAX_LENGTH ||
                name.trim() === environment?.name
              }
            >
              {t('Rename')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
