import { Outlet, useParams } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { useMediaMinWidth } from '@/hooks/use-media-min-width'
import {
  bucketsQueryOptions,
  BUCKETS_DEFAULT_SORT_BY,
  BUCKETS_DEFAULT_SORT_ORDER,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { PlanLimitWarning } from '../../shared/PlanLimitWarning'
import { TableViewResizableLayout } from '../../databases/_components/TableViewResizableLayout'
import { BucketsSidebar } from './BucketsSidebar'
import { MobileBucketSelector } from './MobileBucketSelector'
import { useT } from '@/lib/i18n/translate'

function StorageBucketsPlanLimitBanner() {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)

  const { data: totalBucketsCount = 0 } = useQuery({
    ...bucketsQueryOptions(
      projectId,
      0,
      1,
      '',
      undefined,
      BUCKETS_DEFAULT_SORT_BY,
      BUCKETS_DEFAULT_SORT_ORDER,
    ),
    select: (d) => d.total ?? 0,
  })

  const bucketsLimit = organizationPlan?.buckets ?? 0

  if (!project) return null

  return (
    <PlanLimitWarning
      currentCount={totalBucketsCount}
      limit={bucketsLimit}
      planName={resolveOrganizationPlanDisplayLabel({
        planName: organizationPlan?.name ?? null,
        planId: organizationPlan?.$id,
      })}
      resourceName={t('buckets')}
      orgId={project.teamId}
    />
  )
}

/**
 * Database-style workspace: resizable bucket list + main outlet (files, settings, …).
 */
export function WorkspaceLayout() {
  const showDesktopSidebar = useMediaMinWidth(1024)

  return (
    <div className="@container flex h-full min-h-0 min-w-0 flex-col">
      <div className="shrink-0">
        <StorageBucketsPlanLimitBanner />
      </div>
      {showDesktopSidebar ? (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
          <TableViewResizableLayout
            sidebar={<BucketsSidebar />}
            sidebarWidthScope="storage"
          >
            <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden bg-background">
              <Outlet />
            </div>
          </TableViewResizableLayout>
        </div>
      ) : (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-background">
          <MobileBucketSelector />
          <div className="min-h-0 flex-1 overflow-hidden">
            <Outlet />
          </div>
        </div>
      )}
    </div>
  )
}
