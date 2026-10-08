import { Link } from '@tanstack/react-router'
import { ArrowRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import {
  getUsageCategoryIdForOverviewChartTab,
  type OverviewChartTabId,
} from '@/lib/overview-chart-tabs'

type OverviewViewAllUsageLinkProps = {
  projectId: string
  tabId: OverviewChartTabId
}

export function OverviewViewAllUsageLink({
  projectId,
  tabId,
}: OverviewViewAllUsageLinkProps) {
  const t = useT()
  return (
    <Link
      to="/projects/$projectId/usage/$categoryId"
      params={{
        projectId,
        categoryId: getUsageCategoryIdForOverviewChartTab(tabId),
      }}
    >
      <Button
        variant="ghost"
        size="sm"
        className="h-7 gap-1.5 text-[11px] text-muted-foreground hover:text-foreground"
      >
        {t('View all usage')}
        <ArrowRight className="h-3 w-3" />
      </Button>
    </Link>
  )
}
