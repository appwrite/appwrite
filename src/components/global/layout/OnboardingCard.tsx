import { Link } from '@tanstack/react-router'
import { Progress } from '@/components/ui/progress'
import { cn } from '@/lib/utils'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useOnboardingProgress } from '@/lib/hooks/useOnboardingProgress'
import { useI18n } from '@/lib/i18n'

interface OnboardingCardProps {
  projectId: string | undefined
  collapsed?: boolean
}

/** Visual shell shared by expanded (link) and collapsed layouts. */
const CARD_BASE = 'rounded-md border border-border bg-card/50 overflow-visible'

const EXPANDED_LINK =
  'block w-full text-start transition-colors hover:bg-muted/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'

export function OnboardingCard({
  projectId,
  collapsed = false,
}: OnboardingCardProps) {
  const { catalog } = useI18n()
  const onboardingCopy = catalog.app.sidebar.onboarding
  const { progress, completedSteps, totalSteps, isPending } =
    useOnboardingProgress(projectId)

  const progressAriaLabel = isPending
    ? onboardingCopy.getStarted
    : `${onboardingCopy.getStarted} · ${completedSteps} ${onboardingCopy.of} ${totalSteps} ${onboardingCopy.completed}`

  if (!projectId) {
    return null
  }

  if (collapsed) {
    const size = 18
    const strokeWidth = 2
    const radius = (size - strokeWidth) / 2
    const circumference = 2 * Math.PI * radius
    const strokeDashoffset = circumference * (1 - progress / 100)

    return (
      <div className={CARD_BASE}>
        <Tooltip delayDuration={0}>
          <TooltipTrigger asChild>
            <Link
              to="/projects/$projectId/onboarding"
              params={{ projectId }}
              className="flex aspect-square w-full items-center justify-center rounded-md p-2 text-[var(--brand-cta)]"
              aria-label={progressAriaLabel}
            >
              {isPending ? (
                <span className="h-[18px] w-[18px] rounded-full bg-muted animate-pulse" />
              ) : (
                <svg
                  width={size}
                  height={size}
                  viewBox={`0 0 ${size} ${size}`}
                  className="shrink-0 -rotate-90"
                  aria-hidden
                >
                  <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    className="opacity-20"
                  />
                  <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={strokeDashoffset}
                    className="transition-all duration-300"
                  />
                </svg>
              )}
            </Link>
          </TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            <p>{onboardingCopy.getStarted}</p>
            <p className="text-xs text-muted-foreground mt-0.5">
              {isPending
                ? onboardingCopy.loading
                : `${completedSteps}/${totalSteps} ${onboardingCopy.completed}`}
            </p>
          </TooltipContent>
        </Tooltip>
      </div>
    )
  }

  return (
    <Link
      to="/projects/$projectId/onboarding"
      params={{ projectId }}
      className={cn(
        CARD_BASE,
        EXPANDED_LINK,
        'block px-3 pt-2 pb-2.5 min-h-[4.75rem] flex flex-col justify-center',
      )}
      aria-label={progressAriaLabel}
    >
      <h3 className="text-[13px] font-semibold text-foreground mb-1.5">
        {onboardingCopy.getStarted}
      </h3>
      {isPending ? (
        <div className="space-y-1">
          <div className="flex items-center justify-between">
            <span className="text-[11px] text-muted-foreground">
              {onboardingCopy.progress}
            </span>
            <span className="h-3 w-8 rounded bg-muted animate-pulse" />
          </div>
          <div className="h-1.5 rounded-full bg-muted animate-pulse" />
        </div>
      ) : (
        <div className="space-y-1">
          <div className="flex items-center justify-between">
            <span className="text-[11px] text-muted-foreground">
              {onboardingCopy.progress}
            </span>
            <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
              {completedSteps}/{totalSteps}
            </span>
          </div>
          <Progress
            value={progress}
            className="h-1.5 bg-[color-mix(in_srgb,var(--brand-cta)_20%,transparent)] [&_[data-slot=progress-indicator]]:bg-[var(--brand-cta)] pointer-events-none"
          />
        </div>
      )}
    </Link>
  )
}
