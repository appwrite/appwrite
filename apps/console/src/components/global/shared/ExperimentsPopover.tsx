import {
  ArrowLeftRight,
  Bell,
  FlaskConical,
  Layers,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
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
import { ANALYTICS_PRODUCT_ICON } from '@/lib/analytics/product-icon'
import {
  countUnseenExperiments,
  EXPERIMENTS,
  setExperimentEnabled,
  type Experiment,
} from '@/lib/experiments'
import { useT } from '@/lib/i18n/translate'
import { useMarkExperimentsSeen } from '@/lib/react-query/hooks'
import { parseExperimentsSeenAt, type UserPrefs } from '@/lib/user-prefs-keys'

const EXPERIMENT_ICONS = {
  alerts: Bell,
  analytics: ANALYTICS_PRODUCT_ICON,
  projectEnvironments: Layers,
  projectTransfers: ArrowLeftRight,
} as const satisfies Record<Experiment['flag'], LucideIcon>

export function ExperimentsPopover() {
  const t = useT()
  const { features } = useConsoleProfile()
  const { account } = useAuth()
  const markSeen = useMarkExperimentsSeen()
  const seenAt = parseExperimentsSeenAt(
    (account as { prefs?: UserPrefs } | null | undefined)?.prefs,
  )
  const unseenCount = markSeen.isPending ? 0 : countUnseenExperiments(seenAt)
  const unseenBadgeLabel =
    unseenCount > 9 ? '9+' : unseenCount > 0 ? String(unseenCount) : null

  const handleOpenChange = (open: boolean) => {
    if (open && unseenCount > 0) {
      markSeen.mutate(new Date())
    }
  }

  return (
    <Popover onOpenChange={handleOpenChange}>
      <Tooltip>
        <TooltipTrigger asChild>
          <PopoverTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="relative h-9 w-9 text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={t('Previews')}
              data-analytics-track="false"
            >
              <FlaskConical className="h-4 w-4" />
              {unseenBadgeLabel ? (
                <span className="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold leading-none text-primary-foreground">
                  {unseenBadgeLabel}
                </span>
              ) : null}
            </Button>
          </PopoverTrigger>
        </TooltipTrigger>
        <TooltipContent>
          <p>{t('Previews')}</p>
        </TooltipContent>
      </Tooltip>
      <PopoverContent
        align="end"
        className="w-80 overflow-hidden p-0"
        data-analytics-track="false"
      >
        <div className="border-b border-border px-4 py-3 text-start">
          <p className="text-[13px] font-semibold text-foreground">
            {t('Previews')}
          </p>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {t('Early access. Saved in this browser.')}
          </p>
        </div>
        <ul className="divide-y divide-border">
          {EXPERIMENTS.map((experiment) => {
            const Icon = EXPERIMENT_ICONS[experiment.flag]
            return (
              <li
                key={experiment.flag}
                className="flex items-start gap-3 px-4 py-3"
              >
                <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                  <Icon className="h-4 w-4" aria-hidden />
                </div>
                <div className="min-w-0 flex-1 text-start">
                  <p className="text-[13px] font-medium text-foreground">
                    {t(experiment.name)}
                  </p>
                  <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
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
            )
          })}
        </ul>
      </PopoverContent>
    </Popover>
  )
}
