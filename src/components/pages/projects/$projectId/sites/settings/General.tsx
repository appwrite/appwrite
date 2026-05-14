import { useParams, useNavigate } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import { NameCard } from './NameCard'
import { SiteDetailsCard } from './SiteDetailsCard'
import { DangerZoneCard } from './DangerZoneCard'

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { data: site, isLoading: siteLoading } = useProjectSite(
    projectId,
    siteId,
  )

  const handleDelete = () => {
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
    })
  }

  if (siteLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  if (!site) return null

  return (
    <>
      <SiteDetailsCard site={site} />
      <NameCard projectId={projectId} siteId={siteId} site={site} />
      <DangerZoneCard
        projectId={projectId}
        siteId={siteId}
        site={site}
        onDelete={handleDelete}
      />
    </>
  )
}
