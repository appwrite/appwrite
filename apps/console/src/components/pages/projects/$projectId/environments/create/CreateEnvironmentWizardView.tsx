/**
 * Creates an environment of a project on one page: a name, the ID it will be
 * addressed by in the X-Appwrite-Environment header, and whether to start
 * empty or as a copy. The ID follows the name until it is typed, and is
 * generated when left blank. A copy's progress card follows the environment,
 * so the wizard does not wait. Cloud only, behind the projectEnvironments
 * feature like the switcher that opens this. Environments are offered on Pro
 * and above: on Free and Start the page shows what an environment is and leads
 * to the upgrade instead of creating one.
 */

import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import { AlertCircle, Box, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  getActiveEnvironmentId,
  setActiveEnvironment,
} from '@/lib/active-environment'
import { isValidProjectId, PROJECT_ID_MAX_LENGTH } from '@/lib/project-id'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  PROJECT_NAME_MAX_LENGTH,
  useCreateProjectEnvironment,
  useOrganizationPlan,
  useProject,
  useProjectEnvironments,
} from '@/lib/react-query/hooks'
import {
  defaultProjectEnvironmentCopySelection,
  projectEnvironmentCopyResources,
  projectEnvironmentsAllowance,
  suggestProjectEnvironmentId,
  suggestProjectEnvironmentName,
  type ProjectEnvironmentCopyGroupId,
  type ProjectEnvironmentCopySelection,
  type ProjectEnvironmentsAllowance,
} from '@/lib/project-environments'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT, type Translator } from '@/lib/i18n/translate'
import { CopyResources } from './_components/CopyResources'

type EnvironmentStartFrom = 'empty' | 'project'

/**
 * Create fails with its own copy for the per-hour creation limit, a taken ID
 * and the plan caps, which the server words well enough to show as-is.
 */
function getCreateErrorMessage(error: unknown, t: Translator): string {
  if (error instanceof AppwriteException) {
    if (error.code === 429) {
      return t(
        'Too many environments created for this project in the last hour. Try again later.',
      )
    }
    if (error.type === 'project_environment_already_exists') {
      return t('An environment with this ID already exists in this project.')
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
  const { project } = useProject(projectId)
  const region = project?.region

  const [name, setName] = useState('')
  // Suggested from the name until typed; blank leaves the ID to the server
  const [environmentId, setEnvironmentId] = useState('')
  const [idError, setIdError] = useState<string | undefined>(undefined)
  const idTouched = useRef(false)
  const [startFrom, setStartFrom] = useState<EnvironmentStartFrom>('empty')
  const [selection, setSelection] = useState<ProjectEnvironmentCopySelection>(
    defaultProjectEnvironmentCopySelection,
  )
  const [nameError, setNameError] = useState<string | undefined>(undefined)
  const nameTouched = useRef(false)
  const nameSuggested = useRef(false)
  const nameInputRef = useRef<HTMLInputElement>(null)

  const {
    environments,
    total: environmentCount,
    isLoading: isLoadingEnvironments,
    error: environmentsError,
  } = useProjectEnvironments(projectId, region)
  const createEnvironmentMutation = useCreateProjectEnvironment(
    projectId,
    region,
  )
  const isCreating = createEnvironmentMutation.isPending
  const isCopy = startFrom === 'project'

  const { plan } = useOrganizationPlan(project?.teamId)
  const allowance = projectEnvironmentsAllowance(
    plan,
    isLoadingEnvironments || environmentsError ? undefined : environmentCount,
  )
  // Shown, not usable: the notice above the form says why and what lifts it
  const isBlocked =
    allowance.status === 'not-offered' || allowance.status === 'at-limit'
  const isFormDisabled = isCreating || isBlocked

  // Environments are created from production: opened inside an environment, this
  // page leaves it once, on entry. It does not react to the active environment
  // afterwards, so entering the environment just created here is not undone.
  // Read the live profile (including the experiment override); useConsoleProfile's
  // first paint is the canonical cloud default, which has this flag off and would
  // bounce to overview.
  const leftEnvironmentOnEntry = useRef(false)
  useEffect(() => {
    if (!getActiveProfileFeatures().projectEnvironments) {
      void navigate({ to: '/projects/$projectId', params: { projectId } })
      return
    }
    if (leftEnvironmentOnEntry.current) return
    leftEnvironmentOnEntry.current = true
    if (getActiveEnvironmentId(projectId)) {
      setActiveEnvironment(projectId, null)
    }
  }, [projectId, navigate])

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
    // An ID typed while the environments were loading is the user's; keep it.
    if (!idTouched.current) {
      setEnvironmentId(suggestProjectEnvironmentId(suggested))
    }
    if (suggested) {
      requestAnimationFrame(() => nameInputRef.current?.select())
    }
  }, [environments, isLoadingEnvironments, project?.name])

  const resources = isCopy ? projectEnvironmentCopyResources(selection) : []
  const isCopyEmpty = isCopy && resources.length === 0
  const trimmedName = name.trim()
  const trimmedId = environmentId.trim()
  const idTaken = environments.some(
    (environment) => environment.$id === trimmedId,
  )
  const canCreate =
    !isBlocked &&
    trimmedName.length > 0 &&
    trimmedName.length <= PROJECT_NAME_MAX_LENGTH &&
    isValidProjectId(trimmedId) &&
    !idTaken &&
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

  const validateId = (): boolean => {
    if (!isValidProjectId(trimmedId)) {
      setIdError(
        t(
          'Use lowercase letters, numbers and hyphens, starting with a letter or a number.',
        ),
      )
      return false
    }
    if (idTaken) {
      setIdError(
        t('An environment with this ID already exists in this project.'),
      )
      return false
    }
    setIdError(undefined)
    return true
  }

  const close = () =>
    void navigate({
      to: '/projects/$projectId',
      params: { projectId },
    })

  const handleCreate = async (event: FormEvent) => {
    event.preventDefault()
    const nameValid = validateName()
    const idValid = validateId()
    if (!nameValid || !idValid || isCopyEmpty) return

    try {
      const created = await createEnvironmentMutation.mutateAsync({
        environmentId: trimmedId,
        name: trimmedName,
        resources,
      })
      // Open the project in the new environment.
      setActiveEnvironment(projectId, created.$id)
      await navigate({ to: '/projects/$projectId', params: { projectId } })
    } catch (error: unknown) {
      toast.error(getCreateErrorMessage(error, t))
    }
  }

  return (
    <WizardLayout
      title={
        <>
          {t('Create environment')}
          <Badge variant="outline" className="px-1.5 py-0 text-[11px]">
            {t('Beta')}
          </Badge>
        </>
      }
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
        <EnvironmentsPlanNotice
          allowance={allowance}
          orgId={project?.teamId}
          planName={resolveOrganizationPlanDisplayLabel({
            planName: plan?.name ?? null,
            planId: plan?.$id,
          })}
        />

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
                // The ID follows the name until it is typed by hand
                if (!idTouched.current) {
                  setEnvironmentId(
                    suggestProjectEnvironmentId(event.target.value),
                  )
                  if (idError) setIdError(undefined)
                }
              }}
              disabled={isFormDisabled}
              maxLength={PROJECT_NAME_MAX_LENGTH}
              className={nameError ? 'border-destructive' : ''}
            />
            {nameError && (
              <p className="text-[12px] text-destructive">{nameError}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="environment-id">{t('Environment ID')}</Label>
            <Input
              id="environment-id"
              type="text"
              placeholder={t('Leave blank to auto-generate')}
              value={environmentId}
              onChange={(event) => {
                idTouched.current = true
                setEnvironmentId(event.target.value)
                if (idError) setIdError(undefined)
              }}
              disabled={isFormDisabled}
              maxLength={PROJECT_ID_MAX_LENGTH}
              spellCheck={false}
              autoCapitalize="off"
              autoCorrect="off"
              className={cn('font-mono', idError && 'border-destructive')}
            />
            {idError ? (
              <p className="text-[12px] text-destructive">{idError}</p>
            ) : (
              <p className="text-[12px] text-muted-foreground">
                {t(
                  'What your apps pass as the environment next to the project ID, so pick one you can type: staging, preview, qa.',
                )}
              </p>
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
              disabled={isFormDisabled}
              onSelect={() => setStartFrom('empty')}
            />
            <StartFromCard
              selected={startFrom === 'project'}
              icon={Copy}
              title={t('Clone of the project')}
              description={t(
                'Structure and settings are cloned, data only where you ask for it.',
              )}
              disabled={isFormDisabled}
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
            disabled={isFormDisabled}
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

/**
 * Why no environment can be created here: the plan offers none, which the
 * upgrade lifts, or the project has as many as its plan allows, which no other
 * plan raises, so it points at deleting one rather than at an upgrade.
 */
function EnvironmentsPlanNotice({
  allowance,
  orgId,
  planName,
}: {
  allowance: ProjectEnvironmentsAllowance
  orgId: string | undefined
  planName: string
}) {
  const t = useT()

  if (allowance.status !== 'not-offered' && allowance.status !== 'at-limit') {
    return null
  }

  const canUpgrade =
    allowance.status === 'not-offered' &&
    !!orgId &&
    getActiveProfileFeatures().billing

  return (
    <Alert className="border-amber-500/30 bg-amber-500/5">
      <AlertCircle className="h-4 w-4 text-amber-500" />
      <div className="flex flex-1 items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
            {allowance.status === 'not-offered'
              ? t('Project environments are available on Pro')
              : `${t("You've reached the limit of")} ${allowance.limit} ${t('environments')}`}
          </AlertTitle>
          <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
            {allowance.status === 'not-offered' ? (
              <>
                {t('Your plan')} ({planName}){' '}
                {t(
                  'does not include project environments. Upgrade to give each project environments such as staging and preview, with resources and users of their own.',
                )}
              </>
            ) : (
              <>
                {t('Your plan')} ({planName}) {t('includes up to')}{' '}
                {allowance.limit} {t('environments per project.')}{' '}
                {t('Delete an environment to create another.')}
              </>
            )}
          </AlertDescription>
        </div>
        {canUpgrade ? (
          <Button
            asChild
            size="sm"
            className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
          >
            <Link
              to="/upgrade"
              search={{ orgId }}
              {...analyticsAttrs('upgrade-clicked')}
            >
              {t('Upgrade')}
            </Link>
          </Button>
        ) : null}
      </div>
    </Alert>
  )
}
