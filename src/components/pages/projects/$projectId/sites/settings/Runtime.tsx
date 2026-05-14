import { useParams } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import { SiteRuntimeImageCard } from './SiteRuntimeImageCard'
import { SiteRuntimeTimeoutCard } from './SiteRuntimeTimeoutCard'
import { SiteRuntimeLoggingCard } from './SiteRuntimeLoggingCard'
import { SiteRuntimeSpecificationCard } from './SiteRuntimeSpecificationCard'

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading } = useProjectSite(projectId, siteId)
  // TODO: align with project/org settings (same as SiteSettingsView)
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
      <SiteRuntimeImageCard projectId={projectId} siteId={siteId} site={site} />
      <SiteRuntimeTimeoutCard
        projectId={projectId}
        siteId={siteId}
        site={site}
      />
      <SiteRuntimeLoggingCard
        projectId={projectId}
        siteId={siteId}
        site={site}
      />
      <SiteRuntimeSpecificationCard
        projectId={projectId}
        siteId={siteId}
        site={site}
        isCloud={isCloud}
      />
    </>
  )
}
