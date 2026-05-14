import { useParams } from '@tanstack/react-router'
import { useProjectSite } from '@/lib/react-query/hooks'
import { GitRepositoryCard } from './GitRepositoryCard'
import { GitSilentModeCard } from './GitSilentModeCard'

export function View() {
  const { projectId, siteId } = useParams({ strict: false })
  const { data: site, isLoading } = useProjectSite(projectId, siteId)

  if (isLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  if (!site) return null

  const hasRepository = Boolean(
    site.installationId && site.providerRepositoryId,
  )

  return (
    <>
      <GitRepositoryCard projectId={projectId} siteId={siteId} site={site} />
      {hasRepository ? (
        <GitSilentModeCard projectId={projectId} siteId={siteId} site={site} />
      ) : null}
    </>
  )
}
