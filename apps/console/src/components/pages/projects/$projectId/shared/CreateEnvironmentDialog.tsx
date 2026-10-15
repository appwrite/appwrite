import { useEffect, useState } from 'react'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { IdInput } from '@/components/ui/id-input'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  PROJECT_NAME_MAX_LENGTH,
  useCreateProjectEnvironment,
} from '@/lib/react-query/hooks'
import { isValidProjectId, PROJECT_ID_MAX_LENGTH } from '@/lib/project-id'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT, type Translator } from '@/lib/i18n/translate'

interface CreateEnvironmentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  /** Called with the new environment id so the caller can switch to it. */
  onCreated?: (environmentId: string) => void
}

/**
 * Create fails with its own copy for the per-hour creation limit and for the
 * plan caps, which the server words well enough to show as-is.
 */
function getCreateErrorMessage(error: unknown, t: Translator): string {
  if (error instanceof AppwriteException) {
    if (error.code === 429) {
      return t(
        'Too many environments created for this project in the last hour. Try again later.',
      )
    }
    if (
      error.type === 'billing_plan_not_allowed' ||
      error.type === 'billing_additional_resource_not_allowed'
    ) {
      return error.message
    }
  }
  return getErrorMessage(error, 'Failed to create environment')
}

export function CreateEnvironmentDialog({
  open,
  onOpenChange,
  projectId,
  region,
  onCreated,
}: CreateEnvironmentDialogProps) {
  const t = useT()
  const [name, setName] = useState('')
  const [environmentId, setEnvironmentId] = useState<string | undefined>(
    undefined,
  )
  const [errors, setErrors] = useState<Record<string, string>>({})

  const createEnvironmentMutation = useCreateProjectEnvironment(
    projectId,
    region,
  )

  const resetForm = () => {
    setName('')
    setEnvironmentId(undefined)
    setErrors({})
  }

  useEffect(() => {
    if (!open) {
      resetForm()
    }
  }, [open])

  const handleOpenChange = (newOpen: boolean) => {
    if (!createEnvironmentMutation.isPending) {
      onOpenChange(newOpen)
    }
  }

  // `IdInput` reports a bad id inline but still hands it up, so the dialog is
  // what keeps a server-side `ProjectId` rejection from being the first signal.
  const trimmedEnvironmentId = environmentId?.trim() ?? ''
  const isEnvironmentIdValid = isValidProjectId(trimmedEnvironmentId)

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    const trimmedName = name.trim()
    if (!trimmedName) {
      newErrors.name = t('Name is required')
    } else if (trimmedName.length > PROJECT_NAME_MAX_LENGTH) {
      newErrors.name = `${t('Name must be no longer than')} ${PROJECT_NAME_MAX_LENGTH} ${t('characters')}`
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()

    // An invalid id is reported inline by `IdInput` and already disables
    // Create; this only stops a submit that got past the disabled button.
    if (!isEnvironmentIdValid || !validate()) {
      return
    }

    try {
      const environment = await createEnvironmentMutation.mutateAsync({
        environmentId,
        name: name.trim(),
      })
      toast.success(t('Environment created'))
      onOpenChange(false)
      if (environment?.$id) {
        onCreated?.(environment.$id)
      }
    } catch (error: unknown) {
      toast.error(getCreateErrorMessage(error, t))
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Create environment')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'An environment gets its own resources and users, in the same region as this project. Its usage is billed under this project.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="environment-name">
                {t('Name')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="environment-name"
                type="text"
                placeholder={t('Enter environment name')}
                value={name}
                onChange={(event) => {
                  setName(event.target.value)
                  if (errors.name) {
                    setErrors((prev) => ({ ...prev, name: '' }))
                  }
                }}
                disabled={createEnvironmentMutation.isPending}
                maxLength={PROJECT_NAME_MAX_LENGTH}
                className={errors.name ? 'border-destructive' : ''}
                autoFocus
              />
              {errors.name && (
                <p className="text-[12px] text-destructive">{errors.name}</p>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="environment-id">{t('Environment ID')}</Label>
              <IdInput
                id="environment-id"
                value={environmentId}
                onChange={setEnvironmentId}
                idFormat="project"
                maxLength={PROJECT_ID_MAX_LENGTH}
                disabled={createEnvironmentMutation.isPending}
                placeholder={t('Leave blank to auto-generate')}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={createEnvironmentMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              variant="brandCta"
              disabled={
                createEnvironmentMutation.isPending ||
                !name.trim() ||
                name.trim().length > PROJECT_NAME_MAX_LENGTH ||
                !isEnvironmentIdValid
              }
            >
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
