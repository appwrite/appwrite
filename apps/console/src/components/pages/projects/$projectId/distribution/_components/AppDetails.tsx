import { GitBranch } from 'lucide-react'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { BuildStatusBadge } from './status-badges'

export function AppRepository({
  app,
  className,
}: {
  app: DistributionApp
  className?: string
}) {
  const t = useT()
  if (!app.providerRepositoryId) {
    return (
      <span className={cn('text-[12px] text-muted-foreground', className)}>
        {t('No repository')}
      </span>
    )
  }
  return (
    <span
      className={cn(
        'flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground',
        className,
      )}
    >
      <GitBranch className="h-3.5 w-3.5 shrink-0" />
      <span dir="ltr" className="truncate font-mono">
        {app.providerBranch || t('Connected')}
      </span>
    </span>
  )
}

export function AppLatestBuild({ app }: { app: DistributionApp }) {
  const t = useT()
  if (!app.latestBuildStatus) {
    return (
      <span className="text-[12px] text-muted-foreground">
        {t('No builds yet')}
      </span>
    )
  }
  return <BuildStatusBadge status={app.latestBuildStatus} />
}
