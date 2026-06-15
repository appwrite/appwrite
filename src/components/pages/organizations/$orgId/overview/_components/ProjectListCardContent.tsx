import { type ReactNode } from 'react'
import { PauseCircle } from '@/lib/icons'
import { Badge } from '@/components/ui/badge'
import { RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { ProjectListItem } from '@/lib/react-query/hooks/projects'
import { ProjectListIdentities } from './ProjectListIdentities'
import { ProjectListName } from './ProjectListName'

type ProjectListCardMainProps = {
  project: ProjectListItem
  failedInvoiceWarning?: ReactNode
}

export function ProjectListCardMain({
  project,
  failedInvoiceWarning,
}: ProjectListCardMainProps) {
  const { features } = useConsoleProfile()
  const showRegion =
    features.multiRegion &&
    !!project.region &&
    project.region !== 'unknown'

  return (
    <div className="min-w-0 overflow-hidden">
      <div className="flex min-w-0 flex-wrap items-center gap-1.5">
        <ProjectListName name={project.name} as="h3" className="min-w-0" />
        {project.paused ? (
          <Badge
            variant="error"
            className="gap-1.5 text-[10px] font-medium shrink-0"
          >
            <PauseCircle className="h-3 w-3" />
            Paused
          </Badge>
        ) : null}
        {failedInvoiceWarning ? (
          <div className="flex shrink-0 items-center">
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
}: {
  project: ProjectListItem
}) {
  return (
    <div className={RESOURCE_CARD_METADATA_DIVIDER_CLASSNAME}>
      <ProjectListIdentities project={project} />
    </div>
  )
}

type ProjectListCardContentProps = ProjectListCardMainProps

export function ProjectListCardContent({
  project,
  failedInvoiceWarning,
}: ProjectListCardContentProps) {
  return (
    <>
      <ProjectListCardMain
        project={project}
        failedInvoiceWarning={failedInvoiceWarning}
      />
      <ProjectListCardFooter project={project} />
    </>
  )
}
