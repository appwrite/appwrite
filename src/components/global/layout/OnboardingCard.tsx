import { Link } from '@tanstack/react-router'
import { Progress } from '@/components/ui/progress'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useOnboardingProgress } from '@/lib/hooks/useOnboardingProgress'

interface OnboardingCardProps {
  projectId: string | undefined
  collapsed?: boolean
}

export function OnboardingCard({
  projectId,
  collapsed = false,
}: OnboardingCardProps) {
  const { progress, completedSteps, totalSteps } =
    useOnboardingProgress(projectId)

  // Don't show if onboarding is complete
  if (progress === 100) {
    return null
  }

  if (collapsed) {
    const size = 18
    const strokeWidth = 2
    const radius = (size - strokeWidth) / 2
    const circumference = 2 * Math.PI * radius
    const strokeDashoffset = circumference * (1 - progress / 100)

    const linkContent = (
      <Link
        to="/onboarding"
        className="flex aspect-square w-full items-center justify-center rounded-md p-2 text-muted-foreground transition-colors hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
      >
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
      </Link>
    )

    return (
      <div className="aspect-square w-full overflow-visible rounded-md border border-border bg-card/50">
        <Tooltip delayDuration={0}>
          <TooltipTrigger asChild>{linkContent}</TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            <p>Get started</p>
            <p className="text-xs text-muted-foreground mt-0.5">
              {completedSteps}/{totalSteps} completed
            </p>
          </TooltipContent>
        </Tooltip>
      </div>
    )
  }

  return (
    <div className="rounded-md border border-border bg-card/50 overflow-visible">
      <Link
        to="/onboarding"
        className="group flex w-full flex-col rounded-md transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background"
      >
        <div className="px-3 pt-2 pb-2.5">
          <h3 className="text-[13px] font-semibold text-foreground mb-1.5">
            Get started
          </h3>
          <div className="space-y-1">
            <div className="flex items-center justify-between">
              <span className="text-[11px] text-muted-foreground">
                Onboarding progress
              </span>
              <span className="text-[11px] font-medium text-muted-foreground">
                {completedSteps}/{totalSteps}
              </span>
            </div>
            <Progress value={progress} className="h-1.5" />
          </div>
        </div>
      </Link>
    </div>
  )
}
