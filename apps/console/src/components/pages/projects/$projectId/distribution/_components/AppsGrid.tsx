import { Link } from '@tanstack/react-router'
import {
  RESOURCE_CARD_GRID_4_COL_CLASSNAME,
  RESOURCE_CARD_MEDIA_SHELL_CLASSNAME,
  RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
} from '../../shared/ResourceCard'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import { cn } from '@/lib/utils'
import { AppLatestBuild, AppRepository } from './AppDetails'
import { AppFrameworkIcon } from './AppFrameworkIcon'
import { frameworkLabel } from './platform'

export function AppsGrid({
  projectId,
  apps,
}: {
  projectId: string
  apps: DistributionApp[]
}) {
  return (
    <div className={RESOURCE_CARD_GRID_4_COL_CLASSNAME}>
      {apps.map((app) => (
        <Link
          key={app.$id}
          to="/projects/$projectId/distribution/$appId"
          params={{ projectId, appId: app.$id }}
          className="group block min-w-0"
        >
          <div className={cn(RESOURCE_CARD_MEDIA_SHELL_CLASSNAME, 'h-auto')}>
            <div className="px-4 pt-4 pb-0">
              <div className="flex min-w-0 items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                  <AppFrameworkIcon framework={app.framework} />
                  <div className="min-w-0">
                    <h3 className="truncate text-[14px] font-medium text-foreground">
                      {app.name}
                    </h3>
                    <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                      {frameworkLabel(app.framework)}
                    </p>
                  </div>
                </div>
                <div className="shrink-0 pt-0.5">
                  <AppLatestBuild app={app} />
                </div>
              </div>
              <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
                <div className="flex min-w-0 items-center justify-between gap-2">
                  <AppRepository app={app} />
                  <DateTooltip
                    date={app.$updatedAt}
                    className="shrink-0 text-[12px] text-muted-foreground"
                  />
                </div>
              </div>
            </div>
          </div>
        </Link>
      ))}
    </div>
  )
}
