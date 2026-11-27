import { useNavigate, useParams } from '@tanstack/react-router'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import { useDistributionApp } from '@/lib/react-query/hooks'
import { DangerZoneCard } from '../../_components/DangerZoneCard'
import { GitAutomationCard } from '../../_components/GitAutomationCard'
import { GitRepositoryCard } from '../../_components/GitRepositoryCard'
import { NameCard } from '../../_components/NameCard'
import { StatusCard } from '../../_components/StatusCard'
import { TriggersCard } from '../../_components/TriggersCard'

type ViewProps = {
  initialData?: { app: DistributionApp }
}

export function View({ initialData }: ViewProps = {}) {
  const { projectId, appId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { data } = useDistributionApp(projectId, appId)
  const app = data ?? initialData?.app

  if (!projectId || !app) return null

  const hasRepository = Boolean(app.installationId && app.providerRepositoryId)

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6 sm:py-6">
      <div className="space-y-6">
        <NameCard projectId={projectId} app={app} />
        <StatusCard projectId={projectId} app={app} />
        <GitRepositoryCard projectId={projectId} app={app} />
        {hasRepository && (
          <>
            <GitAutomationCard projectId={projectId} app={app} />
            <TriggersCard projectId={projectId} app={app} />
          </>
        )}
        <DangerZoneCard
          projectId={projectId}
          app={app}
          onDelete={() =>
            navigate({
              to: '/projects/$projectId/distribution',
              params: { projectId },
            })
          }
        />
      </div>
    </div>
  )
}
