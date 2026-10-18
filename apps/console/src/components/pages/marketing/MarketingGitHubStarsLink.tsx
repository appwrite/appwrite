import { lazy, Suspense, useState } from 'react'
import { ArrowUpRight } from 'lucide-react'
import { SheetClose } from '@/components/ui/sheet'
import {
  HoverCard,
  HoverCardContent,
  HoverCardTrigger,
} from '@/components/ui/hover-card'
import { Button } from '@/components/ui/button'
import { GitHubSolidIcon } from '@/components/pages/marketing/GitHubSolidIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  GITHUB_STARS_HISTORY,
  MARKETING_SOCIAL_STATS,
} from '@/lib/marketing/social-stats'
import { starHistoryGrowthSince } from '@/lib/marketing/github-stars-history'
import { ANALYTICS_ACTIONS, analyticsAttrs } from '@/lib/analytics-actions'
import { useAnalytics } from '@/hooks/use-analytics'
import { formatCompactCount } from '@/lib/usage/format-metric'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'

const GITHUB_REPO_LABEL = 'appwrite/appwrite'

const GitHubStarsGrowthChart = lazy(() =>
  import('@/components/pages/marketing/GitHubStarsGrowthChart').then(
    (module) => ({
      default: module.GitHubStarsGrowthChart,
    }),
  ),
)

type MarketingGitHubStarsLinkProps = {
  className?: string
  mobile?: boolean
}

export function MarketingGitHubStarsLink({
  className,
  mobile = false,
}: MarketingGitHubStarsLinkProps) {
  const t = useT()
  const { track } = useAnalytics()
  const [chartReady, setChartReady] = useState(false)
  const { link, stat } = MARKETING_SOCIAL_STATS.github
  const chartData = GITHUB_STARS_HISTORY.filter(
    (point) => point.date && Number.isFinite(point.stars),
  )
  const yearlyGrowth = starHistoryGrowthSince(chartData, 365)

  const anchor = (
    <a
      href={link}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={
        `${t('Appwrite on GitHub')}, ${stat} ${t('stars')}` /* pragma: allowlist secret */
      }
      {...analyticsAttrs('marketing-nav-github')}
      className={cn(
        mobile
          ? 'flex h-10 w-full items-center justify-start gap-1.5 rounded-md px-3 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground'
          : 'inline-flex h-9 items-center gap-1.5 rounded-md px-2.5 text-start text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground',
        className,
      )}
    >
      <GitHubSolidIcon className="h-4 w-4 shrink-0" />
      <span>{stat}</span>
    </a>
  )

  if (mobile) {
    return <SheetClose asChild>{anchor}</SheetClose>
  }

  return (
    <HoverCard
      openDelay={200}
      closeDelay={160}
      onOpenChange={(open) => {
        if (open) {
          setChartReady(true)
          track(ANALYTICS_ACTIONS['marketing-nav-github-hover'])
        }
      }}
    >
      <HoverCardTrigger asChild>{anchor}</HoverCardTrigger>
      <HoverCardContent
        align="center"
        side="bottom"
        sideOffset={10}
        className="w-[22rem] overflow-hidden rounded-xl p-0 shadow-lg"
      >
        <div className="flex flex-col text-start">
          <div className="flex flex-col gap-3 px-4 pt-4 pb-3">
            <div className="flex items-center gap-2">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                <GitHubSolidIcon className="size-3.5 text-muted-foreground" />
              </span>
              <p className="truncate text-[12px] font-medium text-muted-foreground">
                {GITHUB_REPO_LABEL}
              </p>
            </div>

            <div className="flex items-end justify-between gap-3">
              <div className="min-w-0">
                <p
                  className={cn(
                    'font-aeonik-pro text-[28px] leading-none tabular-nums tracking-tight text-foreground',
                    FORCE_LTR_CLASS,
                  )}
                >
                  {stat}
                </p>
                <p className="mt-1.5 text-[12px] text-muted-foreground">
                  {t('stars')}
                </p>
              </div>
              {yearlyGrowth ? (
                <div className="text-end">
                  <p
                    className={cn(
                      'text-[13px] font-medium tabular-nums text-emerald-600 dark:text-emerald-400',
                      FORCE_LTR_CLASS,
                    )}
                  >
                    +{formatCompactCount(yearlyGrowth)}
                  </p>
                  <p className="mt-1.5 text-[12px] text-muted-foreground">
                    {t('in the last year')}
                  </p>
                </div>
              ) : null}
            </div>

            {chartReady && chartData.length >= 2 ? (
              <Suspense
                fallback={
                  <div className="h-[7.25rem] overflow-hidden rounded-lg border border-border/80 bg-muted/30" />
                }
              >
                <GitHubStarsGrowthChart data={chartData} />
              </Suspense>
            ) : null}

            <p className="text-[12px] leading-5 text-muted-foreground">
              {t(
                'A GitHub star is the easiest way to support the work we do in the open.',
              )}
            </p>
          </div>

          <div className="border-t border-border bg-muted/30 px-4 py-3">
            <Button asChild variant="outline" size="sm" className="h-9 w-full">
              <a
                href={link}
                target="_blank"
                rel="noopener noreferrer"
                {...analyticsAttrs('marketing-nav-github-star')}
              >
                {t('Star on GitHub')}
                <ArrowUpRight />
              </a>
            </Button>
          </div>
        </div>
      </HoverCardContent>
    </HoverCard>
  )
}
