import { type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { overviewChartPanelErrorClass } from './chart-panel'

interface OverviewChartPanelErrorProps {
  title: string
  message: ReactNode
  onRetry?: () => void
  /** When set (and billing is enabled), show an Upgrade plan CTA. */
  upgradeOrgId?: string | null
}

export function OverviewChartPanelError({
  title,
  message,
  onRetry,
  upgradeOrgId,
}: OverviewChartPanelErrorProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const showUpgradeCta = features.billing && !!upgradeOrgId

  return (
    <div className={overviewChartPanelErrorClass}>
      <AlertCircle className="h-8 w-8 shrink-0 text-muted-foreground" />
      <div className="max-w-sm">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
          {message}
        </p>
      </div>
      {showUpgradeCta ? (
        <Button asChild size="sm">
          <Link
            to="/upgrade"
            search={{ orgId: upgradeOrgId! }}
            {...analyticsAttrs('upgrade-clicked')}
          >
            {t('Upgrade plan')}
          </Link>
        </Button>
      ) : onRetry ? (
        <Button variant="outline" size="sm" onClick={onRetry}>
          {t('Try again')}
        </Button>
      ) : null}
    </div>
  )
}
