/**
 * Creates an environment of a project on one page: a name, and whether to
 * start empty or as a copy. The id is generated. A copy's progress card
 * follows the environment, so the wizard does not wait. Cloud only, behind
 * the projectEnvironments feature like the switcher that opens this.
 */

import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { Box, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  PROJECT_NAME_MAX_LENGTH,
  useCreateProjectEnvironment,
  useProject,
  useProjectEnvironments,
} from '@/lib/react-query/hooks'
import {
  defaultProjectEnvironmentCopySelection,
  projectEnvironmentCopyResources,
  suggestProjectEnvironmentName,
  type ProjectEnvironmentCopyGroupId,
  type ProjectEnvironmentCopySelection,
} from '@/lib/project-environments'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT, type Translator } from '@/lib/i18n/translate'
import { CopyResources } from './_components/CopyResources'

type EnvironmentStartFrom = 'empty' | 'project'

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

export function CreateEnvironmentWizardView({
  projectId,
}: {
  projectId: string
}) {
  const t = useT()
  const navigate = useNavigate()
  const { project, projectData } = useProject(projectId)
  const region = project?.region

  const [name, setName] = useState('')
  const [startFrom, setStartFrom] = useState<EnvironmentStartFrom>('empty')
  const [selection, setSelection] = useState<ProjectEnvironmentCopySelection>(
    defaultProjectEnvironmentCopySelection,
  )
  const [nameError, setNameError] = useState<string | undefined>(undefined)
  const nameTouched = useRef(false)
  const nameSuggested = useRef(false)
  const nameInputRef = useRef<HTMLInputElement>(null)

  const { environments, isLoading: isLoadingEnvironments } =
    useProjectEnvironments(projectId, region)
  const createEnvironmentMutation = useCreateProjectEnvironment(
    projectId,
    region,
  )
  const isCreating = createEnvironmentMutation.isPending
  const isCopy = startFrom === 'project'

  // Environments are created from the production project: an environment's own
  // URL here goes to its parent's. Read the live profile (including the
  // experiment override); useConsoleProfile's first paint is the canonical
  // cloud default, which has this flag off and would bounce to overview.
  useEffect(() => {
    if (!getActiveProfileFeatures().projectEnvironments) {
      void navigate({ to: '/projects/$projectId', params: { projectId } })
      return
    }
    if (projectData?.parentId) {
      void navigate({
        to: '/projects/$projectId/environments/create',
        params: { projectId: projectData.parentId },
        replace: true,
      })
    }
  }, [projectData?.parentId, projectId, navigate])

  useEffect(() => {
    if (nameSuggested.current || nameTouched.current || isLoadingEnvironments) {
      return
    }
    nameSuggested.current = true
    const suggested = suggestProjectEnvironmentName([
      ...environments.map((environment) => environment.name),
      project?.name ?? '',
    ])
    setName(suggested)
    if (suggested) {
      requestAnimationFrame(() => nameInputRef.current?.select())
    }
  }, [environments, isLoadingEnvironments, project?.name])

  const resources = isCopy ? projectEnvironmentCopyResources(selection) : []
  const isCopyEmpty = isCopy && resources.length === 0
  const trimmedName = name.trim()
  const canCreate =
    trimmedName.length > 0 &&
    trimmedName.length <= PROJECT_NAME_MAX_LENGTH &&
    !isCopyEmpty

  const setGroup = (
    groupId: ProjectEnvironmentCopyGroupId,
    patch: Partial<{ structure: boolean; data: boolean }>,
  ) => {
    setSelection((prev) => ({
      ...prev,
      [groupId]: { ...prev[groupId], ...patch },
    }))
  }

  const validateName = (): boolean => {
    if (!trimmedName) {
      setNameError(t('Name is required'))
      return false
    }
    if (trimmedName.length > PROJECT_NAME_MAX_LENGTH) {
      setNameError(
        `${t('Name must be no longer than')} ${PROJECT_NAME_MAX_LENGTH} ${t('characters')}`,
      )
      return false
    }
    setNameError(undefined)
    return true
  }

  const close = () =>
    void navigate({
      to: '/projects/$projectId',
      params: { projectId },
    })

  const handleCreate = async (event: FormEvent) => {
    event.preventDefault()
    if (!validateName() || isCopyEmpty) return

    try {
      const created = await createEnvironmentMutation.mutateAsync({
        name: trimmedName,
        resources,
      })
      await navigate({
        to: '/projects/$projectId',
        params: { projectId: created.$id },
      })
    } catch (error: unknown) {
      toast.error(getCreateErrorMessage(error, t))
    }
  }

  return (
    <WizardLayout
      title={t('Create environment')}
      fullscreen
      useSidebar={false}
      constrainWidth
      maxWidth="max-w-4xl"
      fallbackPath={`/projects/${projectId}`}
      footerAlign="right"
      footer={
        <>
          <Button
            type="button"
            variant="outline"
            onClick={close}
            disabled={isCreating}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="submit"
            form="create-environment"
            disabled={!canCreate || isCreating}
          >
            {isCreating ? t('Creating...') : t('Create environment')}
          </Button>
        </>
      }
    >
      <form
        id="create-environment"
        onSubmit={(event) => void handleCreate(event)}
        className="w-full space-y-8"
      >
        <section className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="environment-name">
              {t('Name')} <span className="text-destructive">*</span>
            </Label>
            <Input
              id="environment-name"
              ref={nameInputRef}
              type="text"
              placeholder={t('Enter environment name')}
              value={name}
              autoFocus
              onChange={(event) => {
                nameTouched.current = true
                setName(event.target.value)
                if (nameError) setNameError(undefined)
              }}
              disabled={isCreating}
              maxLength={PROJECT_NAME_MAX_LENGTH}
              className={nameError ? 'border-destructive' : ''}
            />
            {nameError && (
              <p className="text-[12px] text-destructive">{nameError}</p>
            )}
          </div>
        </section>

        <section className="space-y-3">
          <div>
            <Label>{t('Start from')}</Label>
            <p className="mt-1 text-[12px] text-muted-foreground">
              {t(
                'An environment is a separate project. Copying keeps the IDs of what it brings over.',
              )}
            </p>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StartFromCard
              selected={startFrom === 'empty'}
              icon={Box}
              title={t('Empty environment')}
              description={t(
                'Nothing is copied from the project. You handle the scaffolding.',
              )}
              disabled={isCreating}
              onSelect={() => setStartFrom('empty')}
            />
            <StartFromCard
              selected={startFrom === 'project'}
              icon={Copy}
              title={t('Clone of the project')}
              description={t(
                'Structure and settings are cloned, data only where you ask for it.',
              )}
              disabled={isCreating}
              onSelect={() => setStartFrom('project')}
            />
          </div>
        </section>

        {isCopy ? (
          <CopyResources
            selection={selection}
            onGroupChange={setGroup}
            onSelectionChange={setSelection}
            isCopyEmpty={isCopyEmpty}
            disabled={isCreating}
          />
        ) : null}
      </form>
    </WizardLayout>
  )
}

function StartFromCard({
  selected,
  icon: Icon,
  title,
  description,
  disabled,
  onSelect,
}: {
  selected: boolean
  icon: typeof Box
  title: string
  description: string
  disabled: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      disabled={disabled}
      className={cn(
        'flex w-full cursor-pointer items-start gap-3 rounded-xl border border-border bg-card/50 p-3.5 text-start transition-all hover:border-border/80 hover:bg-card/60 disabled:cursor-not-allowed disabled:opacity-60',
        selected &&
          'border-primary ring-1 ring-primary/20 hover:border-primary',
      )}
    >
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
        <Icon className="h-4 w-4" />
      </div>
      <div className="min-w-0">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
          {description}
        </p>
      </div>
    </button>
  )
}
