import { useState, type FormEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import type { ConnectRepositoryValue } from '@/components/global/shared/ConnectRepositorySection'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import type {
  CreateDistributionAppParams,
  DistributionApp,
  DistributionFramework,
  DistributionPlatform,
} from '@/lib/appwrite/distribution'
import { isValidIdentifier } from '@/lib/distribution/identifiers'
import { isRepositorySaved } from '@/lib/distribution/repository'
import { useT } from '@/lib/i18n/translate'
import {
  useCreateDistributionApp,
  useCreateDistributionTarget,
  useProject,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { frameworkPlatforms, platformLabel } from '../_components/platform'
import { FrameworkStep } from './FrameworkStep'
import { APP_NAME_MAX_LENGTH, NameStep } from './NameStep'
import { PlatformsStep } from './PlatformsStep'
import { RepositoryStep } from './RepositoryStep'
import { StepProgress } from './StepProgress'
import { CREATE_APP_STEPS, type AppSource } from './steps'

const FORM_ID = 'create-distribution-app'

const NO_REPOSITORY: ConnectRepositoryValue = {
  installationId: undefined,
  providerRepositoryId: undefined,
  repositoryName: undefined,
  repositoryOwner: undefined,
}

export function CreateAppView({ projectId }: { projectId: string }) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { data: installationList } = useVcsInstallations(projectId)
  const createApp = useCreateDistributionApp(projectId)
  const createTarget = useCreateDistributionTarget(projectId)

  const [stepIndex, setStepIndex] = useState(0)
  const [name, setName] = useState('')
  const [framework, setFramework] = useState<DistributionFramework>('flutter')
  const [platforms, setPlatforms] = useState<DistributionPlatform[]>(
    frameworkPlatforms('flutter'),
  )
  const [identifiers, setIdentifiers] = useState<
    Record<DistributionPlatform, string>
  >({ ios: '', android: '' })
  const [source, setSource] = useState<AppSource>('repository')
  const [repository, setRepository] =
    useState<ConnectRepositoryValue>(NO_REPOSITORY)
  const [branch, setBranch] = useState('')
  const [rootDirectory, setRootDirectory] = useState('./')
  const [isSubmitting, setIsSubmitting] = useState(false)

  const step = CREATE_APP_STEPS[stepIndex].id
  const isLastStep = stepIndex === CREATE_APP_STEPS.length - 1
  const trimmedName = name.trim()
  const hasRepository =
    !!repository.installationId && !!repository.providerRepositoryId

  const canContinue = {
    name: trimmedName.length > 0 && trimmedName.length <= APP_NAME_MAX_LENGTH,
    framework: true,
    platforms:
      platforms.length > 0 &&
      platforms.every((platform) =>
        isValidIdentifier(platform, identifiers[platform].trim()),
      ),
    repository: source === 'upload' || hasRepository,
  }[step]

  const close = () =>
    void navigate({
      to: '/projects/$projectId/distribution',
      params: { projectId },
    })

  const changeFramework = (next: DistributionFramework) => {
    setFramework(next)
    setPlatforms(frameworkPlatforms(next))
  }

  const togglePlatform = (platform: DistributionPlatform, selected: boolean) =>
    setPlatforms((current) =>
      selected
        ? frameworkPlatforms(framework).filter(
            (candidate) =>
              candidate === platform || current.includes(candidate),
          )
        : current.filter((candidate) => candidate !== platform),
    )

  const changeRepository = (next: ConnectRepositoryValue) => {
    setRepository(next)
    if (!next.providerRepositoryId) {
      setBranch('')
      setRootDirectory('./')
    }
  }

  const createTargets = async (app: DistributionApp): Promise<boolean> => {
    const results = await Promise.allSettled(
      platforms.map((platform) =>
        createTarget.mutateAsync({
          appId: app.$id,
          platform,
          identifier: identifiers[platform].trim(),
        }),
      ),
    )
    const failed = platforms.filter(
      (_, index) => results[index].status === 'rejected',
    )
    const rejection = results.find(
      (result): result is PromiseRejectedResult => result.status === 'rejected',
    )
    if (!rejection) return true
    toast.error(
      `${t('App created, but these targets were not added:')} ${failed.map(platformLabel).join(', ')}`,
      { description: getErrorMessage(rejection.reason) },
    )
    return false
  }

  const create = async () => {
    setIsSubmitting(true)
    const connectsRepository = source === 'repository' && hasRepository
    const params: Omit<CreateDistributionAppParams, 'appId'> = {
      name: trimmedName,
      framework,
      ...(connectsRepository
        ? {
            installationId: repository.installationId,
            providerRepositoryId: repository.providerRepositoryId,
            providerRootDirectory: rootDirectory,
            ...(branch ? { providerBranch: branch } : {}),
          }
        : {}),
    }
    let app: DistributionApp
    try {
      app = await createApp.mutateAsync(params)
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create app')))
      setIsSubmitting(false)
      return
    }
    const repositoryLinked = isRepositorySaved(params, app)
    if (!repositoryLinked) {
      toast.error(t('App created, but the repository was not linked.'), {
        description: t('Connect it from the app settings.'),
      })
    }
    const targetsAdded = await createTargets(app)
    if (repositoryLinked && targetsAdded) {
      toast.success(t('App created'))
    }
    await navigate({
      to: '/projects/$projectId/distribution/$appId/targets',
      params: { projectId, appId: app.$id },
    })
  }

  const handleSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (!canContinue || isSubmitting) return
    if (isLastStep) {
      void create()
      return
    }
    setStepIndex(stepIndex + 1)
  }

  return (
    <WizardLayout
      title="Create app"
      fullscreen
      useSidebar={false}
      maxWidth="max-w-3xl"
      fallbackPath={`/projects/${projectId}/distribution`}
      footerAlign="right"
      initialFocusKey={step}
      contentTop={
        <StepProgress currentIndex={stepIndex} onSelect={setStepIndex} />
      }
      footer={
        <>
          <Button
            type="button"
            variant="outline"
            disabled={isSubmitting}
            onClick={() =>
              stepIndex === 0 ? close() : setStepIndex(stepIndex - 1)
            }
          >
            {stepIndex === 0 ? t('Cancel') : t('Back')}
          </Button>
          <Button
            type="submit"
            form={FORM_ID}
            disabled={!canContinue || isSubmitting}
          >
            {isLastStep ? t('Create app') : t('Continue')}
          </Button>
        </>
      }
    >
      <form id={FORM_ID} onSubmit={handleSubmit} className="w-full">
        {step === 'name' && (
          <NameStep
            name={name}
            onNameChange={setName}
            disabled={isSubmitting}
          />
        )}
        {step === 'framework' && (
          <FrameworkStep
            framework={framework}
            onFrameworkChange={changeFramework}
            disabled={isSubmitting}
          />
        )}
        {step === 'platforms' && (
          <PlatformsStep
            framework={framework}
            platforms={platforms}
            identifiers={identifiers}
            onPlatformToggle={togglePlatform}
            onIdentifierChange={(platform, identifier) =>
              setIdentifiers((current) => ({
                ...current,
                [platform]: identifier,
              }))
            }
            disabled={isSubmitting}
          />
        )}
        {step === 'repository' && (
          <RepositoryStep
            projectId={projectId}
            region={project?.region}
            name={trimmedName}
            installations={installationList?.installations ?? []}
            source={source}
            onSourceChange={setSource}
            repository={repository}
            onRepositoryChange={changeRepository}
            branch={branch}
            onBranchChange={setBranch}
            rootDirectory={rootDirectory}
            onRootDirectoryChange={setRootDirectory}
            disabled={isSubmitting}
          />
        )}
      </form>
    </WizardLayout>
  )
}
