import { useEffect } from 'react'
import { useParams } from '@tanstack/react-router'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { UpgradePlanLink } from '@/components/global/shared/UpgradePlanLink'
import { useT } from '@/lib/i18n/translate'
import { useAnalyticsPropertiesPlanLimit } from '../../analytics/_components/useAnalyticsPropertiesPlanLimit'

interface AnalyticsToggleProps {
  checked: boolean
  onCheckedChange: (checked: boolean) => void
}

export function AnalyticsToggle({
  checked,
  onCheckedChange,
}: AnalyticsToggleProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const { isAtLimit, limit, orgId } = useAnalyticsPropertiesPlanLimit(projectId)

  // A new site always needs a new property, so the toggle starts on but cannot stay on at the cap.
  useEffect(() => {
    if (isAtLimit && checked) onCheckedChange(false)
  }, [isAtLimit, checked, onCheckedChange])

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
        {isAtLimit && (
          <p className="text-[12px] text-amber-600 dark:text-amber-400 mt-1">
            {t('Your current plan includes up to')} {limit}{' '}
            {limit === 1 ? t('analytics property') : t('analytics properties')}{' '}
            {t('per project.')} <UpgradePlanLink orgId={orgId} />{' '}
            {t('to enable analytics on this site.')}
          </p>
        )}
      </div>
      <Switch
        id="site-analytics"
        checked={checked && !isAtLimit}
        onCheckedChange={onCheckedChange}
        disabled={isAtLimit}
        className="shrink-0"
      />
    </div>
  )
}
