import { useParams } from '@tanstack/react-router'
import { useProjectFunction } from '@/lib/react-query/hooks'
import { GitSettingsCard } from '../GitSettingsCard'
import { GitSilentModeCard } from '../GitSilentModeCard'

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const { data: func, isLoading } = useProjectFunction(projectId, functionId)

  if (isLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  if (!func) return null

  const hasRepository = Boolean(
    func.installationId && func.providerRepositoryId,
  )

  return (
    <>
      <GitSettingsCard func={func} />
      {hasRepository ? <GitSilentModeCard func={func} /> : null}
    </>
  )
}
