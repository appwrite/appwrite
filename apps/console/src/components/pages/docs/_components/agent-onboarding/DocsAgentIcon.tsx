import { Sparkles } from 'lucide-react'
import type { DocsOnboardingAgent } from '@/lib/docs/agent-onboarding'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

export function DocsAgentIcon({
  agent,
  className,
}: {
  agent: Pick<DocsOnboardingAgent, 'iconSrc'>
  className?: string
}) {
  if (!agent.iconSrc) {
    return (
      <Sparkles
        className={cn('text-muted-foreground', className)}
        aria-hidden
      />
    )
  }
  return (
    <img
      src={agent.iconSrc}
      alt=""
      className={cn('object-contain', PUBLIC_ICON_MUTED_CLASSES, className)}
    />
  )
}
