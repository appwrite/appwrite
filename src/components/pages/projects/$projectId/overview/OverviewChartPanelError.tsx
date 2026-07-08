import { type ReactNode } from 'react'
import { AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { overviewChartPanelErrorClass } from './chart-panel'

interface OverviewChartPanelErrorProps {
  title: string
  message: ReactNode
  onRetry?: () => void
}

export function OverviewChartPanelError({
  title,
  message,
  onRetry,
}: OverviewChartPanelErrorProps) {
  const t = useT()
  return (
    <div className={overviewChartPanelErrorClass}>
      <AlertCircle className="h-8 w-8 shrink-0 text-muted-foreground" />
      <div className="max-w-sm">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
          {message}
        </p>
      </div>
      {onRetry ? (
        <Button variant="outline" size="sm" onClick={onRetry}>
          {t('Try again')}
        </Button>
      ) : null}
    </div>
  )
}
