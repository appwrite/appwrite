import { Link, useParams } from '@tanstack/react-router'
import { Target } from 'lucide-react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type {
  DistributionConnection,
  DistributionSigningIdentity,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { canShowDistributionSubmissions } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  useDistributionTargets,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { PLATFORMS } from '../../_components/platform'
import { StoreTasks } from './_components/StoreTasks'
import { TargetReadiness } from './_components/TargetReadiness'

export type OverviewInitialData = {
  targets: DistributionTarget[]
  connections: DistributionConnection[]
  signingIdentities: DistributionSigningIdentity[]
}

type ViewProps = {
  initialData?: OverviewInitialData
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId = '', appId = '' } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access, isLoading: accessLoading } = useOrganizationScopes(
    project?.teamId,
  )
  const showSubmissions =
    !!project &&
    !accessLoading &&
    canShowDistributionSubmissions(access, features)

  const { targets: targetsFromHook, isLoading } = useDistributionTargets(
    projectId,
    appId,
  )
  const targets =
    isLoading && initialData ? initialData.targets : targetsFromHook
  const ordered = PLATFORMS.flatMap((platform) =>
    targets.filter((target) => target.platform === platform),
  )

  if (isLoading && !initialData) return null

  if (ordered.length === 0) {
    return (
      <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6 sm:py-6">
        <EmptyState
          icon={Target}
          title={t('No targets yet')}
          description={t(
            'Add an iOS or Android target to check whether this app is ready to submit to the stores.',
          )}
          variant="card"
          action={
            <Button asChild size="sm" className="h-9 text-[13px]">
              <Link
                to="/projects/$projectId/distribution/$appId/targets"
                params={{ projectId, appId }}
              >
                {t('Go to targets')}
              </Link>
            </Button>
          }
        />
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 space-y-6 px-4 py-4 sm:px-6 sm:py-6">
      {ordered.map((target) => (
        <TargetReadiness
          key={target.$id}
          projectId={projectId}
          target={target}
          showSubmissions={showSubmissions}
          initialConnections={initialData?.connections}
          initialSigningIdentities={initialData?.signingIdentities}
        />
      ))}
      <StoreTasks
        platforms={PLATFORMS.filter((platform) =>
          ordered.some((target) => target.platform === platform),
        )}
      />
    </div>
  )
}
