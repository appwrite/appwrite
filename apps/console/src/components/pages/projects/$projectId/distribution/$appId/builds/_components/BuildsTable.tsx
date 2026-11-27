import { useNavigate } from '@tanstack/react-router'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { DistributionBuild } from '@/lib/appwrite/distribution'
import {
  buildDuration,
  buildVersion,
  formatDuration,
  type BuildPermissions,
} from '@/lib/distribution/builds'
import { useT } from '@/lib/i18n/translate'
import { PlatformIcon, platformLabel } from '../../../_components/platform'
import { BuildStatusBadge } from '../../../_components/status-badges'
import {
  BuildContextMenu,
  BuildRowMenu,
  type BuildActionHandlers,
} from './BuildMenu'
import { BuildSource } from './BuildSource'

const HEAD_CLASSNAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

type BuildsTableProps = BuildActionHandlers & {
  projectId: string
  appId: string
  builds: DistributionBuild[]
  permissions: BuildPermissions
}

export function BuildsTable({
  projectId,
  appId,
  builds,
  permissions,
  ...handlers
}: BuildsTableProps) {
  const t = useT()
  const navigate = useNavigate()
  const now = Date.now()

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASSNAME}>{t('Build')}</TableHead>
            <TableHead className={HEAD_CLASSNAME}>{t('Platform')}</TableHead>
            <TableHead className={HEAD_CLASSNAME}>{t('Status')}</TableHead>
            <TableHead className={HEAD_CLASSNAME}>{t('Source')}</TableHead>
            <TableHead className={HEAD_CLASSNAME}>{t('Created')}</TableHead>
            <TableHead className={HEAD_CLASSNAME}>{t('Duration')}</TableHead>
            <TableHead className={`${HEAD_CLASSNAME} text-end w-[60px]`} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {builds.map((build) => {
            const version = buildVersion(build)
            const duration = buildDuration(build, now)
            const menu = { projectId, appId, build, permissions, ...handlers }
            return (
              <BuildContextMenu key={build.$id} {...menu}>
                <TableRow
                  data-testid={`build-row-${build.$id}`}
                  className="cursor-pointer border-b border-border/50 transition-colors hover:bg-muted/30"
                  onClick={() =>
                    navigate({
                      to: '/projects/$projectId/distribution/$appId/builds/$buildId',
                      params: { projectId, appId, buildId: build.$id },
                    })
                  }
                >
                  <TableCell className="px-4 py-3">
                    <div className="min-w-0">
                      <p className="truncate text-[13px] font-medium text-foreground">
                        {version ? (
                          <span dir="ltr">{version}</span>
                        ) : (
                          t('No version')
                        )}
                      </p>
                      <p className="mt-0.5 truncate font-mono text-[12px] text-muted-foreground">
                        {build.$id}
                      </p>
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <span className="flex items-center gap-1.5 text-[13px] text-foreground">
                      <PlatformIcon
                        platform={build.platform}
                        className="text-muted-foreground"
                      />
                      {platformLabel(build.platform)}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <BuildStatusBadge status={build.status} />
                  </TableCell>
                  <TableCell className="px-4 py-3 max-w-[260px]">
                    <BuildSource build={build} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DateTooltip
                      date={build.$createdAt}
                      className="text-[12px] text-muted-foreground font-mono"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-[12px] text-muted-foreground font-mono">
                    {duration > 0 ? formatDuration(duration) : '-'}
                  </TableCell>
                  <TableCell
                    className="px-4 py-3 text-end"
                    onClick={(event) => event.stopPropagation()}
                  >
                    <BuildRowMenu {...menu} />
                  </TableCell>
                </TableRow>
              </BuildContextMenu>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}
