import { Maximize2, Minimize2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'

type DatabaseRowsFullscreenToggleProps = {
  active: boolean
  onToggle: () => void
}

export function DatabaseRowsFullscreenToggle({
  active,
  onToggle,
}: DatabaseRowsFullscreenToggleProps) {
  const t = useT()

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onToggle}
            className="h-9 w-9 shrink-0 p-0 border-border bg-transparent text-muted-foreground hover:bg-accent hover:text-foreground"
            aria-label={active ? t('Exit full screen') : t('Full screen')}
          >
            {active ? (
              <Minimize2 className="h-4 w-4" />
            ) : (
              <Maximize2 className="h-4 w-4" />
            )}
          </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          <p>{active ? t('Exit full screen') : t('Full screen')}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
