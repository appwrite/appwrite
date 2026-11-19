import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useT } from '@/lib/i18n/translate'

interface AnalyticsToggleProps {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
}

export function AnalyticsToggle({
  checked,
  onCheckedChange,
}: AnalyticsToggleProps) {
  const t = useT()

  return (
    <div className="flex items-center justify-between gap-4">
      <div className="min-w-0">
        <Label htmlFor="site-analytics" className="text-[13px]">
          {t('Enable analytics')}
        </Label>
        <p className="text-[12px] text-muted-foreground mt-1">
          {t('Production domains only. Preview deployments are not tracked.')}
        </p>
        <p className="text-[12px] text-muted-foreground mt-1">
          {t(
            'Tracked pageviews and events are billed as analytics usage, which adds up quickly on a high-traffic site.',
          )}
        </p>
      </div>
      <Switch
        id="site-analytics"
        checked={checked}
        onCheckedChange={onCheckedChange}
        className="shrink-0"
      />
    </div>
  )
}
