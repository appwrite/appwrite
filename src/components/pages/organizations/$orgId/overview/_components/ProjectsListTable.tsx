import { Link } from '@tanstack/react-router'
import { PauseCircle } from '@/lib/icons'
import { FailedInvoiceWarningIcon } from '@/components/global/shared/FailedInvoiceWarningIcon'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  type ProjectListItem,
} from '@/lib/react-query/hooks/projects'
import type { ProjectListRequestsUsageEntry } from '@/lib/react-query/hooks/usage-events'
import { ProjectContextMenu } from './ProjectContextMenu'
import { ProjectListIdentities } from './ProjectListIdentities'
import { ProjectListName } from './ProjectListName'
import { ProjectListTableRequestsCell } from './ProjectListRequestsChart'

function getProjectListRegionLabel(project: ProjectListItem): string | null {
  if (!project.region || project.region === 'unknown') return null
  return project.region
}

type ProjectsListTableProps = {
  projects: ProjectListItem[]
  showProjectSettingsTab: boolean
  canDeleteProject: boolean
  onProjectDeleted: (projectId: string) => void | Promise<void>
  showFailedInvoiceOrgAlert: boolean
  orgBillingReadonlyForFailedInvoice: boolean
  showUsageCharts?: boolean
  projectRequestsUsageById?: Map<string, ProjectListRequestsUsageEntry>
}

export function ProjectsListTable({
  projects,
  showProjectSettingsTab,
  canDeleteProject,
  onProjectDeleted,
  showFailedInvoiceOrgAlert,
  orgBillingReadonlyForFailedInvoice,
  showUsageCharts = false,
  projectRequestsUsageById,
}: ProjectsListTableProps) {
  const { features } = useConsoleProfile()
  const showRegionColumn = features.multiRegion

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="w-full table-fixed">
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead
              className={
                showUsageCharts
                  ? showRegionColumn
                    ? 'w-[22%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
                    : 'w-[28%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
                  : showRegionColumn
                    ? 'w-[32%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
                    : 'w-[40%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
              }
            >
              Project
            </TableHead>
            {showRegionColumn ? (
              <TableHead className="w-[10%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                Region
              </TableHead>
            ) : null}
            {showUsageCharts ? (
              <TableHead
                className={
                  showRegionColumn
                    ? 'w-[36%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
                    : 'w-[42%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'
                }
              >
                Requests
              </TableHead>
            ) : null}
            <TableHead
              className={
                showUsageCharts
                  ? showRegionColumn
                    ? 'w-[32%] px-4 py-3 text-right'
                    : 'w-[30%] px-4 py-3 text-right'
                  : showRegionColumn
                    ? 'w-[58%] px-4 py-3 text-right'
                    : 'w-[60%] px-4 py-3 text-right'
              }
            />
          </TableRow>
        </TableHeader>
        <TableBody>
          {projects.map((project) => {
            const regionLabel = getProjectListRegionLabel(project)

            return (
              <ProjectContextMenu
                key={project.$id}
                project={project}
                showSettingsTab={showProjectSettingsTab}
                canDeleteProject={canDeleteProject}
                onProjectDeleted={onProjectDeleted}
              >
                <TableRow className="cursor-pointer border-b border-border/50 transition-colors hover:bg-muted/30">
                  <TableCell className="max-w-0 px-4 py-3">
                    <Link
                      to="/projects/$projectId"
                      params={{ projectId: project.$id }}
                      className="block min-w-0"
                    >
                      <div className="flex min-w-0 items-center gap-1.5">
                        <ProjectListName
                          name={project.name}
                          className="min-w-0 flex-1"
                          maxLength={36}
                        />
                        <FailedInvoiceWarningIcon
                          show={showFailedInvoiceOrgAlert}
                          orgBillingReadonly={
                            orgBillingReadonlyForFailedInvoice
                          }
                          className="shrink-0"
                        />
                        {project.paused ? (
                          <Badge
                            variant="error"
                            className="gap-1 text-[10px] font-medium shrink-0"
                          >
                            <PauseCircle className="h-3 w-3" />
                            Paused
                          </Badge>
                        ) : null}
                      </div>
                    </Link>
                  </TableCell>
                  {showRegionColumn ? (
                    <TableCell className="max-w-0 px-4 py-3">
                      {regionLabel ? (
                        <span className="truncate font-mono text-[12px] uppercase text-muted-foreground">
                          {regionLabel}
                        </span>
                      ) : null}
                    </TableCell>
                  ) : null}
                  {showUsageCharts && projectRequestsUsageById ? (
                    <TableCell className="max-w-0 px-4 py-3">
                      <ProjectListTableRequestsCell
                        projectId={project.$id}
                        usageByProjectId={projectRequestsUsageById}
                      />
                    </TableCell>
                  ) : null}
                  <TableCell
                    className="px-4 py-3 text-right"
                    onClick={(event) => event.stopPropagation()}
                  >
                    <div className="flex justify-end">
                      <ProjectListIdentities project={project} />
                    </div>
                  </TableCell>
                </TableRow>
              </ProjectContextMenu>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}
