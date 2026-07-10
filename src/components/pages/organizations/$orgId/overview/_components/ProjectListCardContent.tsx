import { type ReactNode } from 'react'
import { PauseCircle } from '@/lib/icons'
import { Badge } from '@/components/ui/badge'
import { RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type {
  ProjectListItem,
  ProjectListPlatformsEntry,
} from '@/lib/react-query/hooks/projects'
import { ProjectListCardActionsMenu } from './ProjectListCardActionsMenu'
import { useT } from '@/lib/i18n/translate'
import { ProjectListName } from './ProjectListName'
import { ProjectListPlatformAvatars } from './ProjectListPlatformAvatars'

type ProjectListCardMainProps = {
  project: ProjectListItem
  failedInvoiceWarning?: ReactNode
  /** Org hit its budget cap; show Locked badge like paused projects */
  budgetLimitReached?: boolean
}

export function ProjectListCardMain({
  project,
  failedInvoiceWarning,
  budgetLimitReached = false,
}: ProjectListCardMainProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const showRegion =
    features.multiRegion &&
    !!project.region &&
    project.region !== 'unknown'
  const showLockedBadge = budgetLimitReached
  const showPausedBadge = !showLockedBadge && !!project.paused

  return (
    <div className="min-w-0 overflow-hidden">
      <div className="flex min-w-0 flex-wrap items-center gap-1.5">
        <ProjectListName name={project.name} as="h3" className="min-w-0" />
        {showLockedBadge ? (
          <Badge
            variant="error"
            className="text-[10px] font-medium shrink-0"
          >
            {t('Locked')}
          </Badge>
        ) : null}
        {showPausedBadge ? (
          <Badge
            variant="error"
            className="gap-1.5 text-[10px] font-medium shrink-0"
          >
            <PauseCircle className="h-3 w-3" />
            {t('Paused')}
          </Badge>
        ) : null}
        {failedInvoiceWarning ? (
          <div className="pointer-events-auto flex shrink-0 items-center">
            {failedInvoiceWarning}
          </div>
        ) : null}
      </div>
      {showRegion ? (
        <p className="mt-0.5 truncate font-mono text-[12px] uppercase text-muted-foreground">
          {project.region}
        </p>
      ) : null}
    </div>
  )
}

export function ProjectListCardFooter({
  project,
  showSettingsTab,
  platformsByProjectId,
}: {
  project: ProjectListItem
  showSettingsTab: boolean
  platformsByProjectId: Map<string, ProjectListPlatformsEntry>
}) {
  const platformsEntry = platformsByProjectId.get(project.$id)

  return (
    <div
      className={cn(
        RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME,
        'relative pointer-events-none',
      )}
    >
      <div className="relative flex min-w-0 items-center gap-2">
        <ProjectListPlatformAvatars
          projectId={project.$id}
          platforms={platformsEntry?.platforms ?? []}
          isLoading={platformsEntry?.isLoading ?? true}
          unavailable={platformsEntry?.unavailable === true}
          className="min-w-0 flex-1 pointer-events-auto"
        />
        <div className="relative z-10 shrink-0 pointer-events-auto">
          <ProjectListCardActionsMenu
            project={project}
            showSettingsTab={showSettingsTab}
          />
        </div>
      </div>
    </div>
  )
}

type ProjectListCardContentProps = ProjectListCardMainProps & {
  showSettingsTab: boolean
  platformsByProjectId: Map<string, ProjectListPlatformsEntry>
}

export function ProjectListCardContent({
  project,
  failedInvoiceWarning,
  budgetLimitReached,
  showSettingsTab,
  platformsByProjectId,
}: ProjectListCardContentProps) {
  return (
    <>
      <ProjectListCardMain
        project={project}
        failedInvoiceWarning={failedInvoiceWarning}
        budgetLimitReached={budgetLimitReached}
      />
      <ProjectListCardFooter
        project={project}
        showSettingsTab={showSettingsTab}
        platformsByProjectId={platformsByProjectId}
      />
    </>
  )
}
