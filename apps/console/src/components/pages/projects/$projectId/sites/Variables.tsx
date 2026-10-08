import { useParams } from '@tanstack/react-router'
import { SiteVariablesCard } from './settings/SiteVariablesCard'

export function View() {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const siteId = params.siteId as string

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6 sm:py-6">
      <div className="space-y-6">
        <SiteVariablesCard projectId={projectId} siteId={siteId} />
      </div>
    </div>
  )
}
