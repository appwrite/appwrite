import { Link } from '@tanstack/react-router'
import { PauseCircle } from '@/lib/icons'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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
import {
  getProjectListItemEndpoint,
  type ProjectListItem,
} from '@/lib/react-query/hooks/projects'
import { ProjectContextMenu } from './ProjectContextMenu'
import {
  getProjectListEndpointDisplay,
} from './ProjectListIdentities'
import { ProjectListName } from './ProjectListName'

type ProjectsListTableProps = {
  projects: ProjectListItem[]
  showProjectSettingsTab: boolean
  canDeleteProject: boolean
  onProjectDeleted: (projectId: string) => void | Promise<void>
  showFailedInvoiceOrgAlert: boolean
  orgBillingReadonlyForFailedInvoice: boolean
}

export function ProjectsListTable({
  projects,
  showProjectSettingsTab,
  canDeleteProject,
  onProjectDeleted,
  showFailedInvoiceOrgAlert,
  orgBillingReadonlyForFailedInvoice,
}: ProjectsListTableProps) {
  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="w-full table-fixed">
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead className="w-[28%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Project
            </TableHead>
            <TableHead className="w-[36%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Endpoint
            </TableHead>
            <TableHead className="w-[22%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              ID
            </TableHead>
            <TableHead className="w-[14%] px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Created
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {projects.map((project) => {
            const endpoint = getProjectListItemEndpoint(project)

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
                  <TableCell className="max-w-0 px-4 py-3">
                    <CopyableId
                      id={endpoint}
                      displayText={getProjectListEndpointDisplay(endpoint)}
                      size="md"
                      copyToastLabel="Endpoint"
                      className="w-fit max-w-full"
                    />
                  </TableCell>
                  <TableCell className="max-w-0 px-4 py-3">
                    <CopyableId
                      id={project.$id}
                      size="md"
                      maxWidth={140}
                      copyToastLabel="Project ID"
                      className="w-fit max-w-full"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-right">
                    <DateTooltip
                      date={project.createdAt}
                      className="text-[12px] text-muted-foreground"
                    />
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
