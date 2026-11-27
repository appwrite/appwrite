import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type {
  DistributionConnection,
  DistributionPlatform,
  DistributionSigningIdentity,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { canWriteDistributionApp } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  useDistributionApp,
  useDistributionTargets,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { CreateTarget } from '../../_components/CreateTarget'
import { DisabledReasonTooltip } from '../../_components/DisabledReasonTooltip'
import {
  PLATFORMS,
  PlatformIcon,
  frameworkPlatforms,
} from '../../_components/platform'
import { TargetCard } from '../../_components/TargetCard'

export type TargetsInitialData = {
  targets: DistributionTarget[]
  connections: DistributionConnection[]
  signingIdentities: DistributionSigningIdentity[]
}

type ViewProps = {
  initialData?: TargetsInitialData
}

const MISSING_TITLES: Record<DistributionPlatform, string> = {
  ios: 'No iOS target',
  android: 'No Android target',
}

const MISSING_DESCRIPTIONS: Record<DistributionPlatform, string> = {
  ios: 'Add an iOS target to build this app and submit it to the App Store.',
  android:
    'Add an Android target to build this app and submit it to Google Play.',
}

export function View({ initialData }: ViewProps = {}) {
  const { projectId = '', appId = '' } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const { data: app } = useDistributionApp(projectId, appId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canEdit = canWriteDistributionApp(access, features)

  const { targets: targetsFromHook, isLoading } = useDistributionTargets(
    projectId,
    appId,
  )
  const targets =
    isLoading && initialData ? initialData.targets : targetsFromHook
  const supported = app ? frameworkPlatforms(app.framework) : []
  const missing = supported.filter(
    (platform) => !targets.some((target) => target.platform === platform),
  )

  const [createOpen, setCreateOpen] = useState(false)
  const [createPlatform, setCreatePlatform] = useState<DistributionPlatform>(
    missing[0] ?? 'ios',
  )

  const openCreate = (platform: DistributionPlatform) => {
    setCreatePlatform(platform)
    setCreateOpen(true)
  }

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 space-y-6 px-4 py-4 sm:px-6 sm:py-6">
      {PLATFORMS.map((platform) => {
        const target = targets.find(
          (candidate) => candidate.platform === platform,
        )
        if (target) {
          return (
            <TargetCard
              key={target.$id}
              projectId={projectId}
              target={target}
              canEdit={canEdit}
              initialConnections={initialData?.connections}
              initialSigningIdentities={initialData?.signingIdentities}
            />
          )
        }
        return missing.includes(platform) ? (
          <MissingTarget
            key={platform}
            platform={platform}
            canEdit={canEdit}
            onAdd={() => openCreate(platform)}
          />
        ) : null
      })}
      {missing.length > 0 ? (
        <CreateTarget
          key={createPlatform}
          open={createOpen}
          onOpenChange={setCreateOpen}
          projectId={projectId}
          appId={appId}
          platforms={missing}
          platform={createPlatform}
        />
      ) : null}
    </div>
  )
}

function MissingTarget({
  platform,
  canEdit,
  onAdd,
}: {
  platform: DistributionPlatform
  canEdit: boolean
  onAdd: () => void
}) {
  const t = useT()
  return (
    <section
      data-testid={`target-${platform}-missing`}
      className="flex flex-col gap-4 rounded-xl border border-dashed border-border px-6 py-5 sm:flex-row sm:items-center sm:justify-between"
    >
      <div className="flex min-w-0 items-start gap-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <PlatformIcon platform={platform} />
        </div>
        <div className="min-w-0">
          <h3 className="text-[14px] font-semibold text-foreground">
            {t(MISSING_TITLES[platform])}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t(MISSING_DESCRIPTIONS[platform])}
          </p>
        </div>
      </div>
      <DisabledReasonTooltip
        reason={
          canEdit
            ? null
            : t("You don't have permission to perform this action.")
        }
      >
        <Button
          variant="outline"
          size="sm"
          className="h-9 shrink-0 text-[13px]"
          disabled={!canEdit}
          onClick={onAdd}
        >
          {t('Add target')}
        </Button>
      </DisabledReasonTooltip>
    </section>
  )
}
