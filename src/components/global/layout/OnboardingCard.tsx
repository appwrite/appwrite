import { Link } from '@tanstack/react-router'
import { Progress } from '@/components/ui/progress'
import { TrendingUp } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useOnboardingProgress } from '@/lib/hooks/useOnboardingProgress'

interface OnboardingCardProps {
  projectId: string | undefined
  collapsed?: boolean
}

export function OnboardingCard({
  projectId,
  collapsed = false,
}: OnboardingCardProps) {
  const { progress, completedSteps, totalSteps } = useOnboardingProgress(
    projectId,
  )

  // Don't show if onboarding is complete
  if (progress === 100) {
    return null
  }

  if (collapsed) {
    return (
      <div className="flex h-[54px] w-full items-center border-b border-border px-3">
        <div className="w-full">
          <Link
            to="/onboarding"
            className="flex h-9 w-9 items-center justify-center text-muted-foreground transition-colors hover:text-foreground"
            title="Get started"
          >
            <TrendingUp className="h-4 w-4" />
          </Link>
        </div>
      </div>
    )
  }

  return (
    <div className="flex w-full flex-col border-b border-border px-3 py-3">
      <div className="w-full">
        <Link
          to="/onboarding"
          className="group flex w-full flex-col gap-2 transition-colors"
        >
          <div className="space-y-1.5 px-2.5">
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
        </Link>
      </div>
    </div>
  )
}