import { FlaskConical } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { EXPERIMENTS, setExperimentEnabled } from '@/lib/experiments'
import { useT } from '@/lib/i18n/translate'

export function ExperimentsPopover() {
  const t = useT()
  const { features } = useConsoleProfile()

  return (
    <Popover>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-9 w-9 text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={t('Experiments')}
              data-analytics-track="false"
            >
              <FlaskConical className="h-4 w-4" />
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>{t('Experiments')}</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent
        align="end"
        className="w-80 p-0"
        data-analytics-track="false"
      >
        <div className="px-4 py-3 text-start">
          <p className="text-[13px] font-semibold text-foreground">
            {t('Experiments')}
          </p>
          <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
            {t(
              'Features the quality team can try before they launch. Choices stay in this browser.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <ul>
          {EXPERIMENTS.map((experiment) => (
            <li
              key={experiment.flag}
              className="flex items-start gap-3 px-4 py-3"
            >
              <div className="min-w-0 flex-1 text-start">
                <p className="text-[13px] font-medium text-foreground">
                  {t(experiment.name)}
                </p>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(experiment.description)}
                </p>
              </div>
              <Switch
                className="mt-0.5"
                checked={features[experiment.flag]}
                onCheckedChange={(checked) =>
                  setExperimentEnabled(experiment.flag, checked)
                }
                aria-label={t(experiment.name)}
              />
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  )
}
