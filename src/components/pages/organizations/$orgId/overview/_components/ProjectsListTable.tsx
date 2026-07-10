import { useMemo } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { PauseCircle, Pin, PinOff } from '@/lib/icons'
import { FailedInvoiceWarningIcon } from '@/components/global/shared/FailedInvoiceWarningIcon'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
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
  type ProjectListPlatformsEntry,
  PROJECT_NAME_DISPLAY_MAX_WIDE,
} from '@/lib/react-query/hooks/projects'
import type { ProjectListRequestsUsageEntry } from '@/lib/react-query/hooks/usage-events'
import { cn } from '@/lib/utils'
import { ProjectContextMenu } from './ProjectContextMenu'
import { ProjectListIdentities } from './ProjectListIdentities'
import { ProjectListName } from './ProjectListName'
import {
  PROJECT_LIST_TABLE_ROW_HEIGHT_CLASS,
  ProjectListPlatformAvatars,
} from './ProjectListPlatformAvatars'
import { ProjectListTableRequestsCell } from './ProjectListRequestsChart'
import { MAX_PINNED_PROJECTS } from '@/lib/team-prefs-keys'
import { useT } from '@/lib/i18n/translate'

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
  /** Org hit its budget cap; show Locked badge like paused projects */
  budgetLimitReached?: boolean
  showUsageCharts?: boolean
  projectRequestsUsageById: Map<string, ProjectListRequestsUsageEntry>
  projectPlatformsById: Map<string, ProjectListPlatformsEntry>
  canPinProjects?: boolean
  pinnedIds?: string[]
  onPinProject?: (projectId: string) => void
  isPinPending?: boolean
}

function getProjectColumnWidth(
  showRegionColumn: boolean,
  showUsageCharts: boolean,
): string {
  if (showUsageCharts && showRegionColumn) return 'w-[20%]'
  if (showUsageCharts) return 'w-[22%]'
  if (showRegionColumn) return 'w-[28%]'
  return 'w-[32%]'
}

function getRequestsColumnWidth(showRegionColumn: boolean): string {
  return showRegionColumn ? 'w-[30%]' : 'w-[34%]'
}

function getPlatformsColumnWidth(
  showRegionColumn: boolean,
  showUsageCharts: boolean,
): string {
  if (showUsageCharts && showRegionColumn) return 'w-[12%]'
  if (showUsageCharts) return 'w-[14%]'
  if (showRegionColumn) return 'w-[14%]'
  return 'w-[16%]'
}

function getActionsColumnWidth(
  showRegionColumn: boolean,
  showUsageCharts: boolean,
): string {
  if (showUsageCharts) return 'w-[30%]'
  if (showRegionColumn) return 'w-[50%]'
  return 'w-[52%]'
}

const listTableCellClassName = cn(
  PROJECT_LIST_TABLE_ROW_HEIGHT_CLASS,
  'overflow-hidden px-4',
)

export function ProjectsListTable({
  projects,
  showProjectSettingsTab,
  canDeleteProject,
  onProjectDeleted,
  showFailedInvoiceOrgAlert,
  orgBillingReadonlyForFailedInvoice,
  budgetLimitReached = false,
  showUsageCharts = false,
  projectRequestsUsageById,
  projectPlatformsById,
  canPinProjects = false,
  pinnedIds = [],
  onPinProject,
  isPinPending = false,
}: ProjectsListTableProps) {
  const t = useT()
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const showRegionColumn = features.multiRegion
  const pinnedSet = useMemo(() => new Set(pinnedIds), [pinnedIds])
  const canPinMore = pinnedIds.length < MAX_PINNED_PROJECTS

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table className="w-full table-fixed">
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            <TableHead
              className={`${getProjectColumnWidth(showRegionColumn, showUsageCharts)} px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground`}
            >
              {t('Project')}
            </TableHead>
            {showRegionColumn ? (
              <TableHead className="w-[8%] px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Region')}
              </TableHead>
            ) : null}
            {showUsageCharts ? (
              <TableHead
                className={`${getRequestsColumnWidth(showRegionColumn)} px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground`}
              >
                {t('Requests')}
              </TableHead>
            ) : null}
            <TableHead
              className={`${getPlatformsColumnWidth(showRegionColumn, showUsageCharts)} px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground`}
            >
              {t('Platforms')}
            </TableHead>
            <TableHead
              className={`${getActionsColumnWidth(showRegionColumn, showUsageCharts)} px-4 py-3 text-end`}
            />
          </TableRow>
        </TableHeader>
        <TableBody>
          {projects.map((project) => {
            const regionLabel = getProjectListRegionLabel(project)
            const platformsEntry = projectPlatformsById.get(project.$id)
            const isPinned = pinnedSet.has(project.$id)
            const showPinControl =
              canPinProjects && onPinProject && (isPinned || canPinMore)

            return (
              <ProjectContextMenu
                key={project.$id}
                project={project}
                showSettingsTab={showProjectSettingsTab}
                canDeleteProject={canDeleteProject}
                onProjectDeleted={onProjectDeleted}
                canPinProjects={canPinProjects}
                isPinned={isPinned}
                canPinMore={canPinMore}
                onPinProject={onPinProject}
                isPinPending={isPinPending}
              >
                <TableRow
                  className="cursor-pointer border-b border-border/50 transition-colors hover:bg-muted/30"
                  onClick={() =>
                    navigate({
                      to: '/projects/$projectId',
                      params: { projectId: project.$id },
                    })
                  }
                >
                  <TableCell className={cn(listTableCellClassName, 'max-w-0')}>
                    <div className="flex h-full min-w-0 items-center">
                      <div className="flex min-w-0 items-center gap-1.5">
                        <ProjectListName
                          name={project.name}
                          className="min-w-0 flex-1"
                          maxLength={PROJECT_NAME_DISPLAY_MAX_WIDE}
                        />
                        <FailedInvoiceWarningIcon
                          show={showFailedInvoiceOrgAlert}
                          orgBillingReadonly={
                            orgBillingReadonlyForFailedInvoice
                          }
                          className="shrink-0"
                        />
                        {budgetLimitReached ? (
                          <Badge
                            variant="error"
                            className="text-[10px] font-medium shrink-0"
                          >
                            {t('Locked')}
                          </Badge>
                        ) : project.paused ? (
                          <Badge
                            variant="error"
                            className="gap-1 text-[10px] font-medium shrink-0"
                          >
                            <PauseCircle className="h-3 w-3" />
                            {t('Paused')}
                          </Badge>
                        ) : null}
                      </div>
                    </div>
                  </TableCell>
                  {showRegionColumn ? (
                    <TableCell className={cn(listTableCellClassName, 'max-w-0')}>
                      <div className="flex h-full min-w-0 items-center">
                        {regionLabel ? (
                          <span className="truncate font-mono text-[12px] uppercase text-muted-foreground">
                            {regionLabel}
                          </span>
                        ) : null}
                      </div>
                    </TableCell>
                  ) : null}
                  {showUsageCharts ? (
                    <TableCell className={cn(listTableCellClassName, 'max-w-0')}>
                      <ProjectListTableRequestsCell
                        projectId={project.$id}
                        usageByProjectId={projectRequestsUsageById}
                      />
                    </TableCell>
                  ) : null}
                  <TableCell className={listTableCellClassName}>
                    <div className="flex h-full items-center">
                      <ProjectListPlatformAvatars
                        projectId={project.$id}
                        platforms={platformsEntry?.platforms ?? []}
                        isLoading={platformsEntry?.isLoading ?? true}
                        unavailable={platformsEntry?.unavailable === true}
                        variant="table"
                      />
                    </div>
                  </TableCell>
                  <TableCell
                    className={cn(listTableCellClassName, 'text-end')}
                    onClick={(event) => event.stopPropagation()}
                  >
                    <div className="flex h-full items-center justify-end gap-1">
                      <ProjectListIdentities project={project} />
                      {showPinControl ? (
                        <TooltipProvider delayDuration={0}>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 shrink-0 rounded-md"
                                aria-label={
                                  isPinned
                                    ? t('Unpin project')
                                    : t('Pin project')
                                }
                                onClick={() => onPinProject(project.$id)}
                                disabled={isPinPending}
                              >
                                {isPinned ? (
                                  <PinOff className="h-4 w-4" />
                                ) : (
                                  <Pin className="h-4 w-4" />
                                )}
                              </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                              <p>{isPinned ? t('Unpin project') : t('Pin project')}</p>
                            </TooltipContent>
                          </Tooltip>
                        </TooltipProvider>
                      ) : null}
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
