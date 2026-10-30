/**
 * Creates an environment of a project in steps: its details, what to copy when
 * it starts as a copy, and how an app reaches it once created. A copy runs on
 * the environment, where the progress card follows it. Cloud only, behind the
 * projectEnvironments feature like the switcher that opens this.
 */

import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { useSessionMigrations } from '@/components/global/providers/SessionMigrationsContext'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  PROJECT_NAME_MAX_LENGTH,
  useCreateProjectEnvironment,
  useEnvironmentCopyMigration,
  useProject,
} from '@/lib/react-query/hooks'
import { isValidProjectId } from '@/lib/project-id'
import {
  defaultProjectEnvironmentCopySelection,
  projectEnvironmentCopyResources,
  type ProjectEnvironmentCopyGroupId,
  type ProjectEnvironmentCopySelection,
} from '@/lib/project-environments'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT, type Translator } from '@/lib/i18n/translate'
import { ConnectStep } from './_components/ConnectStep'
import {
  DetailsStep,
  type EnvironmentStartFrom,
} from './_components/DetailsStep'
import { ResourcesStep } from './_components/ResourcesStep'
import { WizardSteps, type WizardStep } from './_components/WizardSteps'

type StepId = 'details' | 'resources' | 'connect'

const STEP_DESCRIPTIONS: Record<StepId, string> = {
  details: 'Name the environment and choose where it starts.',
  resources: 'Pick what to copy from the project.',
  connect: 'Point your app at the new environment.',
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
  return getErrorMessage(error, t('Failed to create environment'))
}

export function CreateEnvironmentWizardView({
  projectId,
}: {
  projectId: string
}) {
  const t = useT()
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const { project, projectData } = useProject(projectId)
  const region = project?.region

  const [step, setStep] = useState<StepId>('details')
  const [name, setName] = useState('')
  const [environmentId, setEnvironmentId] = useState<string | undefined>(
    undefined,
  )
  const [startFrom, setStartFrom] = useState<EnvironmentStartFrom>('empty')
  const [selection, setSelection] = useState<ProjectEnvironmentCopySelection>(
    defaultProjectEnvironmentCopySelection,
  )
  const [nameError, setNameError] = useState<string | undefined>(undefined)
  const [environment, setEnvironment] = useState<Models.Project | null>(null)

  const createEnvironmentMutation = useCreateProjectEnvironment(
    projectId,
    region,
  )
  const isCreating = createEnvironmentMutation.isPending
  const isCopy = startFrom === 'project'

  // The copy is looked up once the environment exists and was asked to copy,
  // and handed to the environment's progress card before the wizard is left:
  // the session outlives the switch of project, so the card shows the copy at
  // once, finished or not, instead of looking it up on arrival
  const migration = useEnvironmentCopyMigration(
    environment?.$id,
    environment?.region || region,
    isCopy && !!environment,
  )
  const { addCopyId } = useSessionMigrations(environment?.$id)
  useEffect(() => {
    if (environment && migration) addCopyId(environment.$id, migration.$id)
  }, [environment, migration, addCopyId])

  // Environments are created from the production project: an environment's own
  // URL here goes to its parent's, and the wizard is Cloud only like the switcher
  useEffect(() => {
    if (!features.projectEnvironments) {
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
  }, [features.projectEnvironments, projectData?.parentId, projectId, navigate])

  const steps = useMemo<WizardStep[]>(
    () => [
      { id: 'details', title: 'Details' },
      ...(isCopy ? [{ id: 'resources', title: 'Resources' }] : []),
      { id: 'connect', title: 'Connect' },
    ],
    [isCopy],
  )

  // `IdInput` reports a bad id inline but still hands it up, so the wizard is
  // what keeps a server-side `ProjectId` rejection from being the first signal.
  const trimmedEnvironmentId = environmentId?.trim() ?? ''
  const isEnvironmentIdValid = isValidProjectId(trimmedEnvironmentId)

  // What the API is asked to copy; a copy with nothing picked has no meaning
  const resources = isCopy ? projectEnvironmentCopyResources(selection) : []
  const isCopyEmpty = isCopy && resources.length === 0

  const trimmedName = name.trim()
  const isNameValid =
    trimmedName.length > 0 && trimmedName.length <= PROJECT_NAME_MAX_LENGTH
  const detailsValid = isNameValid && isEnvironmentIdValid

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

  const openEnvironment = () => {
    if (!environment) return
    void navigate({
      to: '/projects/$projectId',
      params: { projectId: environment.$id },
    })
  }

  const handleCreate = async () => {
    if (!validateName() || !isEnvironmentIdValid || isCopyEmpty) return

    try {
      const created = await createEnvironmentMutation.mutateAsync({
        environmentId,
        name: trimmedName,
        resources,
      })
      setEnvironment(created)
      setStep('connect')
    } catch (error: unknown) {
      toast.error(getCreateErrorMessage(error, t))
    }
  }

  const handleDetailsContinue = () => {
    if (!validateName() || !isEnvironmentIdValid) return
    setStep('resources')
  }

  const footer = (
    <div className="flex w-full justify-end gap-2">
      {step === 'details' && (
        <>
          <Button
            type="button"
            variant="outline"
            onClick={() =>
              void navigate({
                to: '/projects/$projectId',
                params: { projectId },
              })
            }
            disabled={isCreating}
          >
            {t('Cancel')}
          </Button>
          {isCopy ? (
            <Button
              type="button"
              onClick={handleDetailsContinue}
              disabled={!detailsValid}
            >
              {t('Continue')}
            </Button>
          ) : (
            <Button
              type="button"
              variant="brandCta"
              onClick={() => void handleCreate()}
              disabled={!detailsValid || isCreating}
            >
              {t('Create')}
            </Button>
          )}
        </>
      )}
      {step === 'resources' && (
        <>
          <Button
            type="button"
            variant="outline"
            onClick={() => setStep('details')}
            disabled={isCreating}
          >
            {t('Back')}
          </Button>
          <Button
            type="button"
            variant="brandCta"
            onClick={() => void handleCreate()}
            disabled={!detailsValid || isCopyEmpty || isCreating}
          >
            {t('Create')}
          </Button>
        </>
      )}
      {step === 'connect' && (
        <Button type="button" variant="brandCta" onClick={openEnvironment}>
          {t('Open environment')}
        </Button>
      )}
    </div>
  )

  return (
    <WizardLayout
      title={t('Create environment')}
      description={t(STEP_DESCRIPTIONS[step])}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-3xl"
      fallbackPath={`/projects/${projectId}`}
      footer={footer}
      footerAlign="right"
      skipInitialFieldFocus={step !== 'details'}
    >
      <div className="space-y-6">
        <WizardSteps steps={steps} current={step} />

        {step === 'details' && (
          <DetailsStep
            name={name}
            onNameChange={(value) => {
              setName(value)
              if (nameError) setNameError(undefined)
            }}
            nameError={nameError}
            environmentId={environmentId}
            onEnvironmentIdChange={setEnvironmentId}
            startFrom={startFrom}
            onStartFromChange={setStartFrom}
            disabled={isCreating}
          />
        )}

        {step === 'resources' && (
          <ResourcesStep
            selection={selection}
            onGroupChange={setGroup}
            isCopyEmpty={isCopyEmpty}
            disabled={isCreating}
          />
        )}

        {step === 'connect' && environment && (
          <ConnectStep environment={environment} copying={isCopy} />
        )}
      </div>
    </WizardLayout>
  )
}
