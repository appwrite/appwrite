import { Pause, Play } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type FirewallChartLiveControlsProps = {
  isLive: boolean
  onToggle: () => void
  className?: string
}

export function FirewallChartLiveControls({
  isLive,
  onToggle,
  className,
}: FirewallChartLiveControlsProps) {
  const t = useT()
  const tooltip = isLive ? t('Pause live updates') : t('Resume live updates')
  const statusLabel = isLive ? t('Live') : t('Paused')

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onToggle}
            aria-pressed={isLive}
            aria-label={`${statusLabel}. ${tooltip}`}
            className={cn(
              'h-9 w-9 shrink-0 border-border bg-transparent p-0 text-muted-foreground hover:bg-accent hover:text-foreground',
              className,
            )}
          >
            {isLive ? (
              <Pause className="h-4 w-4" aria-hidden="true" />
            ) : (
              <Play className="h-4 w-4 fill-current" aria-hidden="true" />
            )}
          </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          <p>
            {statusLabel}. {tooltip}
          </p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
