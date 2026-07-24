import { Check, Loader2 } from '@/lib/icons'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type DatabaseSetupPhase =
  | 'creating'
  | 'provisioning'
  | 'configuring-ha'
  | 'enabling-pitr'
  | 'preparing-workspace'
  | 'enabling-backups'
  | 'complete'

export type DatabaseSetupProgressState = {
  phase: DatabaseSetupPhase
  databaseName: string
  showProvisioningStep: boolean
  showHaStep: boolean
  showPitrStep: boolean
  showWorkspaceStep: boolean
  showBackupsStep: boolean
}

type SetupStep = {
  phase: DatabaseSetupPhase
  label: string
  description: string
}

function buildSteps(state: DatabaseSetupProgressState): SetupStep[] {
  const {
    databaseName,
    showProvisioningStep,
    showHaStep,
    showPitrStep,
    showWorkspaceStep,
    showBackupsStep,
  } = state

  const name = databaseName.trim()
  const steps: SetupStep[] = [
    {
      phase: 'creating',
      label: 'Creating database',
      description: name
        ? `Setting up ${name} for your project.`
        : 'Setting up your database resource.',
    },
  ]

  if (showProvisioningStep) {
    steps.push({
      phase: 'provisioning',
      label: 'Provisioning compute',
      description: 'Allocating dedicated compute for your database.',
    })
  }

  if (showHaStep) {
    steps.push({
      phase: 'configuring-ha',
      label: 'Configuring high availability',
      description: 'Setting up read replicas for failover resilience.',
    })
  }

  if (showPitrStep) {
    steps.push({
      phase: 'enabling-pitr',
      label: 'Enabling point-in-time recovery',
      description: 'Configuring continuous recovery for your database.',
    })
  }

  if (showWorkspaceStep) {
    steps.push({
      phase: 'preparing-workspace',
      label: 'Preparing workspace',
      description: 'Preparing your database workspace.',
    })
  }

  if (showBackupsStep) {
    steps.push({
      phase: 'enabling-backups',
      label: 'Setting up backups',
      description: 'Creating backup policies for your database.',
    })
  }

  steps.push({
    phase: 'complete',
    label: 'Finishing up',
    description: 'Opening your database.',
  })

  return steps
}

function getPhaseIndex(steps: SetupStep[], phase: DatabaseSetupPhase): number {
  const index = steps.findIndex((step) => step.phase === phase)
  return index >= 0 ? index : 0
}

interface CreateDatabaseSetupProgressProps {
  progress: DatabaseSetupProgressState
}

export function CreateDatabaseSetupProgress({
  progress,
}: CreateDatabaseSetupProgressProps) {
  const t = useT()
  const steps = buildSteps(progress)
  const activeIndex = getPhaseIndex(steps, progress.phase)

  return (
    <div className="mx-auto flex w-full max-w-md flex-col items-center px-4 py-16 sm:py-24">
      <div className="mb-8 flex h-14 w-14 items-center justify-center rounded-full border border-border bg-muted/40">
        <Loader2 className="h-6 w-6 animate-spin text-foreground" />
      </div>

      <h2 className="text-center text-[20px] font-semibold tracking-tight text-foreground">
        {t('Setting up your database')}
      </h2>
      <p className="mt-2 max-w-xs text-center text-[13px] leading-relaxed text-muted-foreground">
        {t('This usually takes a few seconds. Please keep this window open.')}
      </p>

      <ol className="mt-10 mx-auto w-full max-w-xs space-y-0">
        {steps.map((step, index) => {
          const isComplete = index < activeIndex
          const isCurrent = index === activeIndex
          const isUpcoming = index > activeIndex

          return (
            <li key={step.phase} className="flex gap-3">
              <div className="flex flex-col items-center">
                <div
                  className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 text-[12px] font-semibold transition-colors',
                    isComplete &&
                      'border-green-600 bg-green-600 text-white dark:border-green-500 dark:bg-green-500',
                    isCurrent &&
                      'border-primary bg-background text-primary',
                    isUpcoming &&
                      'border-border bg-muted/40 text-muted-foreground',
                  )}
                >
                  {isComplete ? (
                    <Check className="h-4 w-4" strokeWidth={2.5} />
                  ) : isCurrent ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    index + 1
                  )}
                </div>
                {index < steps.length - 1 ? (
                  <div
                    className={cn(
                      'my-1 w-px flex-1 min-h-[2rem]',
                      isComplete ? 'bg-green-600 dark:bg-green-500' : 'bg-border',
                    )}
                  />
                ) : null}
              </div>

              <div
                className={cn('min-w-0 pb-8', index === steps.length - 1 && 'pb-0')}
              >
                <p
                  className={cn(
                    'text-[13px] font-semibold',
                    isCurrent ? 'text-foreground' : 'text-muted-foreground',
                  )}
                >
                  {t(step.label)}
                </p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(step.description)}
                </p>
              </div>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
