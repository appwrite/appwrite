import { Check, Loader2 } from '@/lib/icons'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type OrganizationSetupPhase =
  | 'submitting'
  | 'updating-plan'
  | 'deleting-projects'
  | 'deleting-resources'
  | 'deleting-memberships'
  | 'deleting-organization'
  | 'confirming-payment'
  | 'activating'
  | 'complete'

export type OrganizationSetupProgressState = {
  mode: 'create' | 'upgrade' | 'downgrade'
  phase: OrganizationSetupPhase
  organizationName?: string
  planLabel: string
  showPaymentStep: boolean
  showActivationStep: boolean
  showPlanUpdateStep?: boolean
  showProjectDeletionStep?: boolean
  showResourceDeletionStep?: boolean
  showMembershipDeletionStep?: boolean
  showOrganizationDeletionStep?: boolean
}

type SetupStep = {
  phase: OrganizationSetupPhase
  label: string
  description: string
}

function buildSteps(state: OrganizationSetupProgressState): SetupStep[] {
  const {
    mode,
    planLabel,
    showPaymentStep,
    showActivationStep,
    organizationName,
    showPlanUpdateStep,
    showProjectDeletionStep,
    showResourceDeletionStep,
    showMembershipDeletionStep,
    showOrganizationDeletionStep,
  } = state

  if (mode === 'downgrade') {
    const steps: SetupStep[] = []

    if (showResourceDeletionStep) {
      steps.push({
        phase: 'deleting-resources',
        label: 'Deleting resources',
        description: 'Removing resources that are not kept for the target plan.',
      })
    }

    if (showOrganizationDeletionStep) {
      steps.push({
        phase: 'deleting-organization',
        label: 'Deleting organization',
        description: 'Removing the organization you chose not to keep.',
      })
    }

    if (showProjectDeletionStep) {
      steps.push({
        phase: 'deleting-projects',
        label: 'Deleting projects',
        description: 'Removing projects that are not kept for the target plan.',
      })
    }

    if (showPlanUpdateStep) {
      steps.push({
        phase: 'updating-plan',
        label: 'Updating plan',
        description: `Applying your ${planLabel} plan changes.`,
      })
    }

    if (showMembershipDeletionStep) {
      steps.push({
        phase: 'deleting-memberships',
        label: 'Deleting members',
        description: 'Removing members that are not kept for the target plan.',
      })
    }

    steps.push({
      phase: 'complete',
      label: 'Finishing up',
      description: 'Preparing your organization dashboard.',
    })

    return steps
  }

  const steps: SetupStep[] = [
    {
      phase: 'submitting',
      label:
        mode === 'create'
          ? 'Creating organization'
          : mode === 'downgrade'
            ? 'Preparing downgrade'
            : 'Updating plan',
      description:
        mode === 'create'
          ? organizationName
            ? `Setting up ${organizationName} and your billing profile.`
            : 'Setting up your workspace and billing profile.'
          : mode === 'downgrade'
            ? `Preparing your ${planLabel} plan changes.`
          : `Applying your ${planLabel} plan changes.`,
    },
  ]

  if (showProjectDeletionStep) {
    steps.push({
      phase: 'deleting-projects',
      label: 'Deleting projects',
      description: 'Removing projects that are not kept for the target plan.',
    })
  }

  if (showResourceDeletionStep) {
    steps.push({
      phase: 'deleting-resources',
      label: 'Deleting resources',
      description: 'Removing resources that are not kept for the target plan.',
    })
  }

  if (showOrganizationDeletionStep) {
    steps.push({
      phase: 'deleting-organization',
      label: 'Deleting organization',
      description: 'Removing the organization you chose not to keep.',
    })
  }

  if (showPaymentStep) {
    steps.push({
      phase: 'confirming-payment',
      label: 'Confirming payment',
      description: 'Securing your subscription with your payment method.',
    })
  }

  if (showActivationStep) {
    steps.push({
      phase: 'activating',
      label: 'Activating plan',
      description: `Enabling ${planLabel} features for your organization.`,
    })
  }

  steps.push({
    phase: 'complete',
    label: 'Finishing up',
    description: 'Preparing your organization dashboard.',
  })

  return steps
}

function getPhaseIndex(steps: SetupStep[], phase: OrganizationSetupPhase): number {
  const index = steps.findIndex((step) => step.phase === phase)
  return index >= 0 ? index : 0
}

interface OrganizationSetupProgressProps {
  progress: OrganizationSetupProgressState
}

export function OrganizationSetupProgress({
  progress,
}: OrganizationSetupProgressProps) {
  const t = useT()
  const steps = buildSteps(progress)
  const activeIndex = getPhaseIndex(steps, progress.phase)
  const headline =
    progress.mode === 'create'
      ? t('Setting up your organization')
      : progress.mode === 'downgrade'
        ? t('Downgrading your plan')
      : t('Updating your plan')

  return (
    <div className="mx-auto flex w-full max-w-md flex-col items-center px-4 py-16 sm:py-24">
      <div className="mb-8 flex h-14 w-14 items-center justify-center rounded-full border border-border bg-muted/40">
        <Loader2 className="h-6 w-6 animate-spin text-foreground" />
      </div>

      <h2 className="text-center text-[20px] font-semibold tracking-tight text-foreground">
        {headline}
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

              <div className={cn('min-w-0 pb-8', index === steps.length - 1 && 'pb-0')}>
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
