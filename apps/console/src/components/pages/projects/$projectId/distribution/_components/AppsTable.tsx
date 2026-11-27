import { Link, useNavigate } from '@tanstack/react-router'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { AppLatestBuild, AppRepository } from './AppDetails'
import { AppFrameworkIcon } from './AppFrameworkIcon'
import { frameworkLabel } from './platform'

const HEAD_CLASSNAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function AppsTable({
  projectId,
  apps,
}: {
  projectId: string
  apps: DistributionApp[]
}) {
  const t = useT()
  const navigate = useNavigate()

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASSNAME}>{t('App')}</TableHead>
            <TableHead className={HEAD_CLASSNAME}>{t('Repository')}</TableHead>
            <TableHead className={HEAD_CLASSNAME}>
              {t('Latest build')}
            </TableHead>
            <TableHead className={`${HEAD_CLASSNAME} text-end`}>
              {t('Updated')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {apps.map((app) => (
            <TableRow
              key={app.$id}
              className="cursor-pointer border-b border-border/50 transition-colors hover:bg-muted/30"
              onClick={() =>
                navigate({
                  to: '/projects/$projectId/distribution/$appId',
                  params: { projectId, appId: app.$id },
                })
              }
            >
              <TableCell className="px-4 py-3">
                <div className="flex min-w-0 items-center gap-3">
                  <AppFrameworkIcon framework={app.framework} />
                  <div className="min-w-0 flex-1">
                    <Link
                      to="/projects/$projectId/distribution/$appId"
                      params={{ projectId, appId: app.$id }}
                      onClick={(event) => event.stopPropagation()}
                      className="block truncate text-[13px] font-medium text-foreground hover:underline"
                    >
                      {app.name}
                    </Link>
                    <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                      {frameworkLabel(app.framework)}
                    </p>
                  </div>
                </div>
              </TableCell>
              <TableCell className="px-4 py-3">
                <AppRepository app={app} />
              </TableCell>
              <TableCell className="px-4 py-3">
                <AppLatestBuild app={app} />
              </TableCell>
              <TableCell className="px-4 py-3 text-end">
                <DateTooltip
                  date={app.$updatedAt}
                  className="text-[12px] text-muted-foreground font-mono"
                />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
