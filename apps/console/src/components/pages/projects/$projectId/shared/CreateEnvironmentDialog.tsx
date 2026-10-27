import { useEffect, useState } from 'react'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  PROJECT_NAME_MAX_LENGTH,
  useCreateProjectEnvironment,
} from '@/lib/react-query/hooks'
import { isValidProjectId, PROJECT_ID_MAX_LENGTH } from '@/lib/project-id'
import {
  PROJECT_ENVIRONMENT_COPY_GROUPS,
  defaultProjectEnvironmentCopySelection,
  projectEnvironmentCopyResources,
  type ProjectEnvironmentCopyGroupId,
  type ProjectEnvironmentCopySelection,
} from '@/lib/project-environments'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { useT, type Translator } from '@/lib/i18n/translate'

interface CreateEnvironmentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  /** Called with the new environment id so the caller can switch to it. */
  onCreated?: (environmentId: string) => void
}

type StartFrom = 'empty' | 'project'

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
  return getErrorMessage(error, t('Failed to create environment'))
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
  const [startFrom, setStartFrom] = useState<StartFrom>('empty')
  const [selection, setSelection] = useState<ProjectEnvironmentCopySelection>(
    defaultProjectEnvironmentCopySelection,
  )
  const [errors, setErrors] = useState<Record<string, string>>({})

  const createEnvironmentMutation = useCreateProjectEnvironment(
    projectId,
    region,
  )

  const resetForm = () => {
    setName('')
    setEnvironmentId(undefined)
    setStartFrom('empty')
    setSelection(defaultProjectEnvironmentCopySelection())
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

  // What the API is asked to copy; a copy with nothing picked has no meaning
  const resources =
    startFrom === 'project' ? projectEnvironmentCopyResources(selection) : []
  const isCopyEmpty = startFrom === 'project' && resources.length === 0

  const setGroup = (
    groupId: ProjectEnvironmentCopyGroupId,
    patch: Partial<{ structure: boolean; data: boolean }>,
  ) => {
    setSelection((prev) => ({
      ...prev,
      [groupId]: { ...prev[groupId], ...patch },
    }))
  }

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

    if (!isEnvironmentIdValid || isCopyEmpty || !validate()) {
      return
    }

    try {
      const environment = await createEnvironmentMutation.mutateAsync({
        environmentId,
        name: name.trim(),
        resources,
      })
      toast.success(
        resources.length > 0
          ? t('Environment created. The copy from the project is under way.')
          : t('Environment created'),
      )
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
          <div className="px-6 pb-4 pt-0 space-y-4 max-h-[min(60dvh,560px)] overflow-y-auto">
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

            <div className="space-y-2">
              <Label>{t('Start from')}</Label>
              <RadioGroup
                value={startFrom}
                onValueChange={(value) => setStartFrom(value as StartFrom)}
                className="grid gap-2"
                disabled={createEnvironmentMutation.isPending}
              >
                <Label
                  htmlFor="environment-start-empty"
                  className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                    startFrom === 'empty'
                      ? 'border-primary/50 bg-muted/50'
                      : 'border-border bg-card/50 hover:bg-muted/30',
                  )}
                >
                  <RadioGroupItem
                    value="empty"
                    id="environment-start-empty"
                    className="mt-0.5 shrink-0"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold text-foreground">
                      {t('Empty environment')}
                    </span>
                    <span className="block text-[12px] text-muted-foreground">
                      {t('Nothing is copied from the project.')}
                    </span>
                  </span>
                </Label>
                <Label
                  htmlFor="environment-start-project"
                  className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                    startFrom === 'project'
                      ? 'border-primary/50 bg-muted/50'
                      : 'border-border bg-card/50 hover:bg-muted/30',
                  )}
                >
                  <RadioGroupItem
                    value="project"
                    id="environment-start-project"
                    className="mt-0.5 shrink-0"
                  />
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold text-foreground">
                      {t('Copy of the project')}
                    </span>
                    <span className="block text-[12px] text-muted-foreground">
                      {t(
                        'Structure and settings are copied, data only where you ask for it. The copy runs after the environment is created and keeps the IDs of what it copies.',
                      )}
                    </span>
                  </span>
                </Label>
              </RadioGroup>
            </div>

            {startFrom === 'project' && (
              <div className="space-y-2">
                <Label>{t('What to copy')}</Label>
                <div className="rounded-lg border border-border divide-y divide-border">
                  {PROJECT_ENVIRONMENT_COPY_GROUPS.map((group) => {
                    const picked = selection[group.id]
                    return (
                      <div key={group.id} className="px-3 py-2.5 space-y-2">
                        <div className="flex items-start gap-3">
                          <Checkbox
                            id={`environment-copy-${group.id}`}
                            checked={picked.structure}
                            onCheckedChange={(checked) =>
                              setGroup(group.id, {
                                structure: checked === true,
                              })
                            }
                            disabled={createEnvironmentMutation.isPending}
                            className="mt-0.5"
                          />
                          <div className="min-w-0 flex-1">
                            <Label
                              htmlFor={`environment-copy-${group.id}`}
                              className="cursor-pointer text-[13px] font-medium"
                            >
                              {t(group.label)}
                            </Label>
                            <p className="text-[12px] text-muted-foreground">
                              {t(group.description)}
                            </p>
                          </div>
                        </div>
                        {group.data && (
                          <div className="flex items-start gap-3 ps-7">
                            <Checkbox
                              id={`environment-copy-${group.id}-data`}
                              checked={picked.structure && picked.data}
                              onCheckedChange={(checked) =>
                                setGroup(group.id, { data: checked === true })
                              }
                              disabled={
                                !picked.structure ||
                                createEnvironmentMutation.isPending
                              }
                              className="mt-0.5"
                            />
                            <div className="min-w-0 flex-1">
                              <Label
                                htmlFor={`environment-copy-${group.id}-data`}
                                className={cn(
                                  'cursor-pointer text-[13px]',
                                  !picked.structure && 'text-muted-foreground',
                                )}
                              >
                                {t(group.data.label)}
                              </Label>
                              {group.data.description && (
                                <p className="text-[12px] text-muted-foreground">
                                  {t(group.data.description)}
                                </p>
                              )}
                            </div>
                          </div>
                        )}
                      </div>
                    )
                  })}
                </div>
                {isCopyEmpty && (
                  <p className="text-[12px] text-muted-foreground">
                    {t(
                      'Pick what to copy, or start from an empty environment.',
                    )}
                  </p>
                )}
              </div>
            )}
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
                !isEnvironmentIdValid ||
                isCopyEmpty
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
