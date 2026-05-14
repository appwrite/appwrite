import { useParams } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import { SiteBuildFrameworkCard } from './SiteBuildFrameworkCard'
import { SiteBuildCommandsCard } from './SiteBuildCommandsCard'
import { SiteBuildSpecificationCard } from './SiteBuildSpecificationCard'

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading } = useProjectSite(projectId, siteId)
  const isCloud = true

  if (isLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  if (!site) return null

  return (
    <>
      <SiteBuildFrameworkCard
        projectId={projectId}
        siteId={siteId}
        site={site}
      />
      <SiteBuildCommandsCard
        projectId={projectId}
        siteId={siteId}
        site={site}
      />
      <SiteBuildSpecificationCard
        projectId={projectId}
        siteId={siteId}
        site={site}
        isCloud={isCloud}
      />
    </>
  )
}
