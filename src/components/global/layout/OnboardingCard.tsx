import { Link } from '@tanstack/react-router'
import { Progress } from '@/components/ui/progress'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { TrendingUp } from 'lucide-react'
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
    const linkContent = (
      <Link
        to="/onboarding"
        className="flex w-full items-center justify-center py-2.5 text-muted-foreground transition-colors hover:text-foreground"
      >
        <TrendingUp className="h-4 w-4" />
      </Link>
    )

    return (
      <div className="rounded-md border border-border bg-card/50 overflow-hidden">
        <Tooltip delayDuration={0}>
          <TooltipTrigger asChild>{linkContent}</TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            <p>Get started</p>
          </TooltipContent>
        </Tooltip>
      </div>
    )
  }

  return (
    <div className="rounded-md border border-border bg-card/50 overflow-hidden">
      <Link
        to="/onboarding"
        className="group flex w-full flex-col transition-colors"
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
