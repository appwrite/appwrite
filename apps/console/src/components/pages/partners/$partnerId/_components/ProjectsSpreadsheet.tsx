import { ArrowDown, ArrowUp, ArrowUpDown, type LucideIcon } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { useMemo, useRef, type CSSProperties } from 'react'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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
  ProjectContextMenu,
  ProjectRowActionsMenu,
} from '@/components/pages/organizations/$orgId/overview/_components/ProjectContextMenu'
import { ProjectListName } from '@/components/pages/organizations/$orgId/overview/_components/ProjectListName'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getProjectListItemEndpoint,
  PROJECT_NAME_DISPLAY_MAX_WIDE,
  type ActiveProjectsSortBy,
  type ProjectListItem,
} from '@/lib/react-query/hooks/projects'
import type {
  ProjectListAuthMauUsageEntry,
  ProjectListBandwidthUsageEntry,
  ProjectListComputeUsageEntry,
  ProjectListExecutionsUsageEntry,
  ProjectListRequestsUsageEntry,
  ProjectListStorageUsageEntry,
} from '@/lib/react-query/hooks/usage-events'
import { useT } from '@/lib/i18n/translate'
import { buildConsoleUrl, openInNewTab } from '@/lib/utils/context-menu'
import { formatAuthMauTotal } from '@/lib/usage/auth-usage'
import { formatBandwidthTotal } from '@/lib/usage/bandwidth-events'
import { getUsageChartLatestValue } from '@/lib/usage/database-usage'
import { formatExecutionsTotal } from '@/lib/usage/executions-events'
import { formatGbHoursTotal } from '@/lib/usage/gb-hours-events'
import { formatRequestsTotal } from '@/lib/usage/requests-events'
import { formatStorageTotal } from '@/lib/usage/storage-gauges'
import { sumUsageChartPoints, type UsageChartPoint } from '@/lib/usage/usage-events-common'
import { cn } from '@/lib/utils'
import {
  COLUMN_RESIZE_RAILS_LAYER_CLASS,
  columnResizeRailHandleClass,
} from '@/lib/layout/horizontal-resize'
import {
  SPREADSHEET_FILLER_CELL_CLASS,
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import type { PartnerProjectsSpreadsheetColumnWidthKey } from '@/lib/user-prefs-keys'
import { MAX_PINNED_PROJECTS } from '@/lib/team-prefs-keys'
import {
  PARTNER_PROJECTS_ACTIONS_COLUMN_WIDTH_PX,
  usePartnerProjectsSpreadsheetColumnResize,
} from './usePartnerProjectsSpreadsheetColumnResize'

const stickyTheadClass = 'sticky top-0 z-20 bg-background'

const headerCellClassName =
  'h-9 bg-background px-3 py-2 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground border-e border-border shadow-[inset_0_-1px_0_0_var(--border)]'

const fillerHeaderClassName =
  'bg-background p-0 shadow-[inset_0_-1px_0_0_var(--border)]'

const stickyActionsHeaderClass = cn(
  'relative sticky end-0 z-30 bg-background p-0',
  'shadow-[inset_0_-1px_0_0_var(--border),inset_1px_0_0_0_var(--border)] rtl:shadow-[inset_0_-1px_0_0_var(--border),inset_-1px_0_0_0_var(--border)]',
)

const bodyCellClassName =
  'h-11 overflow-hidden border-b border-e border-border px-3 py-0 align-middle'

const dataColumnResizeRailHandleClass = columnResizeRailHandleClass()

const spreadsheetActionsColStyle: CSSProperties = {
  width: PARTNER_PROJECTS_ACTIONS_COLUMN_WIDTH_PX,
  minWidth: PARTNER_PROJECTS_ACTIONS_COLUMN_WIDTH_PX,
  maxWidth: PARTNER_PROJECTS_ACTIONS_COLUMN_WIDTH_PX,
}

const stickyActionsCellBaseClass = cn(
  'sticky end-0 z-10 border-b border-border bg-background p-0',
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
)

function columnBoxStyle(
  widthPx: number,
  isDragResize: boolean,
): CSSProperties | undefined {
  if (isDragResize) return undefined
  return { width: widthPx, minWidth: widthPx, maxWidth: widthPx }
}

function PartnerProjectsColGroup({
  visibleKeys,
  getColumnWidthPx,
  resizingColumnKey,
  colRefs,
}: {
  visibleKeys: PartnerProjectsSpreadsheetColumnWidthKey[]
  getColumnWidthPx: (
    columnKey: PartnerProjectsSpreadsheetColumnWidthKey,
  ) => number
  resizingColumnKey: PartnerProjectsSpreadsheetColumnWidthKey | null
  colRefs: {
    current: Map<PartnerProjectsSpreadsheetColumnWidthKey, HTMLTableColElement>
  }
}) {
  return (
    <colgroup>
      {visibleKeys.map((key) => (
        <col
          key={key}
          ref={(node) => {
            if (node) colRefs.current.set(key, node)
            else colRefs.current.delete(key)
          }}
          style={columnBoxStyle(
            getColumnWidthPx(key),
            resizingColumnKey === key,
          )}
        />
      ))}
      <col />
      <col style={spreadsheetActionsColStyle} />
    </colgroup>
  )
}

function endpointHost(endpoint: string): string {
  try {
    return new URL(endpoint).host
  } catch {
    return endpoint
  }
}

type UsageChartEntry = {
  isLoading: boolean
  isError: boolean
  data?: {
    changePercent?: number
    chartPoints?: UsageChartPoint[]
    latestValue?: number
  }
}

function UsageStatCell({
  entry,
  formatTotal,
  mode = 'sum',
}: {
  entry: UsageChartEntry | undefined
  formatTotal: (value: number) => string
  mode?: 'sum' | 'latest'
}) {
  const t = useT()
  if (!entry || entry.isLoading || entry.isError || !entry.data) {
    return <span className="text-[12px] text-muted-foreground">-</span>
  }

  const total =
    mode === 'latest'
      ? (entry.data.latestValue ??
        getUsageChartLatestValue(entry.data.chartPoints ?? []))
      : sumUsageChartPoints(entry.data.chartPoints ?? [])
  const changePercent = entry.data.changePercent ?? 0
  const showChange = total > 0
  const isPositive = changePercent > 0
  const isNegative = changePercent < 0

  return (
    <div className="flex min-w-0 items-center gap-1.5">
      <span className="text-[12px] font-medium tabular-nums text-foreground">
        {formatTotal(total)}
      </span>
      {showChange ? (
        <span
          className={cn(
            'inline-flex shrink-0 items-center gap-0.5 text-[11px] font-medium tabular-nums',
            isPositive && 'text-emerald-600 dark:text-emerald-400',
            isNegative && 'text-red-500 dark:text-red-400',
            !isPositive && !isNegative && 'text-muted-foreground',
          )}
          title={t('vs previous period')}
        >
          {isPositive ? (
            <ArrowUp className="h-3 w-3" aria-hidden />
          ) : isNegative ? (
            <ArrowDown className="h-3 w-3" aria-hidden />
          ) : null}
          {isPositive ? '+' : ''}
          {changePercent}%
        </span>
      ) : null}
    </div>
  )
}

function SortableHead({
  label,
  columnKey,
  sortBy,
  sortOrder,
  onSortColumn,
  className,
  style,
  thRef,
}: {
  label: string
  columnKey: ActiveProjectsSortBy
  sortBy: string
  sortOrder: 'asc' | 'desc'
  onSortColumn: (columnKey: string) => void
  className?: string
  style?: CSSProperties
  thRef?: (node: HTMLTableCellElement | null) => void
}) {
  const t = useT()
  const isActive = sortBy === columnKey
  return (
    <TableHead
      ref={thRef}
      className={className}
      style={style}
      aria-sort={
        isActive ? (sortOrder === 'asc' ? 'ascending' : 'descending') : 'none'
      }
    >
      <div className="flex h-full min-w-0 items-center gap-1.5">
        <span className="min-w-0 truncate">{label}</span>
        <button
          type="button"
          aria-label={t('Sort by attribute and direction')}
          onClick={() => onSortColumn(columnKey)}
          className="ms-auto shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
        >
          {isActive ? (
            sortOrder === 'asc' ? (
              <ArrowUp className="h-3 w-3 shrink-0 text-foreground" aria-hidden />
            ) : (
              <ArrowDown
                className="h-3 w-3 shrink-0 text-foreground"
                aria-hidden
              />
            )
          ) : (
            <ArrowUpDown
              className="h-3 w-3 shrink-0 text-muted-foreground"
              aria-hidden
            />
          )}
        </button>
      </div>
    </TableHead>
  )
}

type PartnerProjectsSpreadsheetProps = {
  projects: ProjectListItem[]
  pinnedProjects?: ProjectListItem[]
  showSettingsTab: boolean
  canDeleteProject: boolean
  onProjectDeleted: (projectId: string) => void | Promise<void>
  onHandoff?: (projectId: string) => void
  handoffIcon?: LucideIcon
  showUsage?: boolean
  projectRequestsUsageById: Map<string, ProjectListRequestsUsageEntry>
  projectBandwidthUsageById: Map<string, ProjectListBandwidthUsageEntry>
  projectExecutionsUsageById: Map<string, ProjectListExecutionsUsageEntry>
  projectStorageUsageById: Map<string, ProjectListStorageUsageEntry>
  projectComputeUsageById: Map<string, ProjectListComputeUsageEntry>
  projectAuthMauUsageById: Map<string, ProjectListAuthMauUsageEntry>
  sortBy: string
  sortOrder: 'asc' | 'desc'
  onSortColumn: (columnKey: string) => void
  canPinProjects?: boolean
  pinnedIds?: string[]
  onPinProject?: (projectId: string) => void
  isPinPending?: boolean
}

function SpreadsheetSectionRow({
  label,
  colSpan,
  withTopSeparator = false,
}: {
  label: string
  colSpan: number
  withTopSeparator?: boolean
}) {
  return (
    <TableRow className="border-b-0 hover:bg-transparent">
      <TableCell
        colSpan={colSpan}
        className={cn(
          'h-7 border-b border-border bg-muted/20 px-3 py-0 align-middle',
          withTopSeparator && 'border-t border-border',
        )}
      >
        <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {label}
        </span>
      </TableCell>
    </TableRow>
  )
}

function SpreadsheetProjectRow({
  project,
  showRegion,
  showUsage,
  showSettingsTab,
  canDeleteProject,
  onProjectDeleted,
  onHandoff,
  handoffIcon,
  projectRequestsUsageById,
  projectBandwidthUsageById,
  projectExecutionsUsageById,
  projectStorageUsageById,
  projectComputeUsageById,
  projectAuthMauUsageById,
  canPinProjects,
  isPinned,
  canPinMore,
  onPinProject,
  isPinPending,
}: {
  project: ProjectListItem
  showRegion: boolean
  showUsage: boolean
  showSettingsTab: boolean
  canDeleteProject: boolean
  onProjectDeleted: (projectId: string) => void | Promise<void>
  onHandoff?: (projectId: string) => void
  handoffIcon?: LucideIcon
  projectRequestsUsageById: Map<string, ProjectListRequestsUsageEntry>
  projectBandwidthUsageById: Map<string, ProjectListBandwidthUsageEntry>
  projectExecutionsUsageById: Map<string, ProjectListExecutionsUsageEntry>
  projectStorageUsageById: Map<string, ProjectListStorageUsageEntry>
  projectComputeUsageById: Map<string, ProjectListComputeUsageEntry>
  projectAuthMauUsageById: Map<string, ProjectListAuthMauUsageEntry>
  canPinProjects: boolean
  isPinned: boolean
  canPinMore: boolean
  onPinProject?: (projectId: string) => void
  isPinPending: boolean
}) {
  const t = useT()
  const regionLabel =
    showRegion && project.region && project.region !== 'unknown'
      ? project.region
      : null
  const endpoint = getProjectListItemEndpoint(project)

  return (
    <ProjectContextMenu
      project={project}
      showSettingsTab={showSettingsTab}
      canDeleteProject={canDeleteProject}
      onProjectDeleted={onProjectDeleted}
      openResourceInNewTab
      onHandoff={onHandoff}
      handoffIcon={handoffIcon}
      canPinProjects={canPinProjects}
      isPinned={isPinned}
      canPinMore={canPinMore}
      onPinProject={onPinProject}
      isPinPending={isPinPending}
    >
      <TableRow
        className="group cursor-pointer border-b-0 hover:bg-muted/50"
        onClick={() =>
          openInNewTab(buildConsoleUrl(`/projects/${project.$id}`))
        }
      >
        <TableCell className={bodyCellClassName}>
          {project.paused ? (
            <Badge
              variant="error"
              className="text-[10px] font-medium shrink-0"
            >
              {t('Paused')}
            </Badge>
          ) : (
            <Badge
              variant="success"
              className="text-[10px] font-medium shrink-0"
            >
              {t('Active')}
            </Badge>
          )}
        </TableCell>
        <TableCell className={cn(bodyCellClassName, 'max-w-0')}>
          <Link
            to="/projects/$projectId"
            params={{ projectId: project.$id }}
            target="_blank"
            rel="noreferrer"
            className="block min-w-0"
            onClick={(event) => event.stopPropagation()}
          >
            <ProjectListName
              name={project.name}
              className="min-w-0"
              maxLength={PROJECT_NAME_DISPLAY_MAX_WIDE}
            />
          </Link>
        </TableCell>
        <TableCell
          className={bodyCellClassName}
          onClick={(event) => event.stopPropagation()}
        >
          <CopyableId
            id={project.$id}
            variant="inline"
            size="xs"
            constrainToContainer
            copyToastLabel="ID"
          />
        </TableCell>
        <TableCell
          className={bodyCellClassName}
          onClick={(event) => event.stopPropagation()}
        >
          <div className="flex min-w-0 flex-col justify-center gap-0.5">
            <CopyableId
              id={endpoint}
              displayText={endpointHost(endpoint)}
              variant="inline"
              size="xs"
              constrainToContainer
              className="w-fit max-w-full"
              copyToastLabel="Endpoint"
            />
            {regionLabel ? (
              <span className="ps-1.5 font-mono text-[11px] uppercase leading-none text-muted-foreground">
                {regionLabel}
              </span>
            ) : null}
          </div>
        </TableCell>
        <TableCell
          className={bodyCellClassName}
          onClick={(event) => event.stopPropagation()}
        >
          <DateTooltip
            date={project.createdAt}
            className="text-[12px] text-muted-foreground"
          />
        </TableCell>
        {showUsage ? (
          <>
            <TableCell className={bodyCellClassName}>
              <UsageStatCell
                entry={projectRequestsUsageById.get(project.$id)}
                formatTotal={formatRequestsTotal}
              />
            </TableCell>
            <TableCell className={bodyCellClassName}>
              <UsageStatCell
                entry={projectBandwidthUsageById.get(project.$id)}
                formatTotal={formatBandwidthTotal}
              />
            </TableCell>
            <TableCell className={bodyCellClassName}>
              <UsageStatCell
                entry={projectExecutionsUsageById.get(project.$id)}
                formatTotal={formatExecutionsTotal}
              />
            </TableCell>
            <TableCell className={bodyCellClassName}>
              <UsageStatCell
                entry={projectStorageUsageById.get(project.$id)}
                formatTotal={formatStorageTotal}
                mode="latest"
              />
            </TableCell>
            <TableCell className={bodyCellClassName}>
              <UsageStatCell
                entry={projectComputeUsageById.get(project.$id)}
                formatTotal={formatGbHoursTotal}
              />
            </TableCell>
            <TableCell className={bodyCellClassName}>
              <UsageStatCell
                entry={projectAuthMauUsageById.get(project.$id)}
                formatTotal={formatAuthMauTotal}
                mode="latest"
              />
            </TableCell>
          </>
        ) : null}
        <TableCell aria-hidden className={SPREADSHEET_FILLER_CELL_CLASS} />
        <TableCell
          className={stickyActionsCellBaseClass}
          style={spreadsheetActionsColStyle}
          onClick={(event) => event.stopPropagation()}
        >
          <div
            className="flex h-full items-center justify-center py-1.5"
            style={spreadsheetActionsColStyle}
          >
            <ProjectRowActionsMenu />
          </div>
        </TableCell>
      </TableRow>
    </ProjectContextMenu>
  )
}

export function PartnerProjectsSpreadsheet({
  projects,
  pinnedProjects = [],
  showSettingsTab,
  canDeleteProject,
  onProjectDeleted,
  onHandoff,
  handoffIcon,
  showUsage = false,
  projectRequestsUsageById,
  projectBandwidthUsageById,
  projectExecutionsUsageById,
  projectStorageUsageById,
  projectComputeUsageById,
  projectAuthMauUsageById,
  sortBy,
  sortOrder,
  onSortColumn,
  canPinProjects = false,
  pinnedIds = [],
  onPinProject,
  isPinPending = false,
}: PartnerProjectsSpreadsheetProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const showRegion = features.multiRegion
  const scrollRef = useRef<HTMLDivElement | null>(null)
  const {
    visibleKeys,
    tableLayerRef,
    tableRef,
    headerThRefs,
    colRefs,
    railRefs,
    getColumnWidthPx,
    tableWidthPx,
    resizingColumnKey,
    handleResizePointerDown,
  } = usePartnerProjectsSpreadsheetColumnResize(showUsage, scrollRef)

  const setHeaderThRef =
    (key: PartnerProjectsSpreadsheetColumnWidthKey) =>
    (node: HTMLTableCellElement | null) => {
      if (node) headerThRefs.current.set(key, node)
      else headerThRefs.current.delete(key)
    }

  const headerStyle = (key: PartnerProjectsSpreadsheetColumnWidthKey) =>
    columnBoxStyle(getColumnWidthPx(key), resizingColumnKey === key)

  const columnResizeLabels: Record<
    PartnerProjectsSpreadsheetColumnWidthKey,
    string
  > = {
    status: t('Status'),
    project: t('Project'),
    projectId: t('Project ID'),
    endpoint: t('Endpoint'),
    created: t('Created'),
    requests: t('Requests'),
    bandwidth: t('Bandwidth'),
    executions: t('Executions'),
    storage: t('Storage'),
    compute: t('Compute'),
    users: t('Users'),
  }

  const resizeRailLabel = (key: PartnerProjectsSpreadsheetColumnWidthKey) =>
    `${t('Resize')} ${columnResizeLabels[key]} ${t('column width')}`

  const pinnedSet = useMemo(() => new Set(pinnedIds), [pinnedIds])
  const canPinMore = pinnedIds.length < MAX_PINNED_PROJECTS
  const showPinnedSection = pinnedProjects.length > 0
  const sectionColSpan = visibleKeys.length + 2

  const renderProjectRow = (project: ProjectListItem) => (
    <SpreadsheetProjectRow
      key={project.$id}
      project={project}
      showRegion={showRegion}
      showUsage={showUsage}
      showSettingsTab={showSettingsTab}
      canDeleteProject={canDeleteProject}
      onProjectDeleted={onProjectDeleted}
      onHandoff={onHandoff}
      handoffIcon={handoffIcon}
      projectRequestsUsageById={projectRequestsUsageById}
      projectBandwidthUsageById={projectBandwidthUsageById}
      projectExecutionsUsageById={projectExecutionsUsageById}
      projectStorageUsageById={projectStorageUsageById}
      projectComputeUsageById={projectComputeUsageById}
      projectAuthMauUsageById={projectAuthMauUsageById}
      canPinProjects={canPinProjects}
      isPinned={pinnedSet.has(project.$id)}
      canPinMore={canPinMore}
      onPinProject={onPinProject}
      isPinPending={isPinPending}
    />
  )

  return (
    <div
      ref={scrollRef}
      className="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain"
    >
      <div
        ref={tableLayerRef}
        className={SPREADSHEET_SCROLL_LAYER_CLASS}
        style={{ minWidth: tableWidthPx }}
      >
        <Table
          ref={tableRef}
          withScrollContainer={false}
          className="relative z-0 w-full table-fixed border-collapse"
          style={{ minWidth: tableWidthPx }}
        >
          <PartnerProjectsColGroup
            visibleKeys={visibleKeys}
            getColumnWidthPx={getColumnWidthPx}
            resizingColumnKey={resizingColumnKey}
            colRefs={colRefs}
          />
          <TableHeader className={cn(stickyTheadClass, '[&_tr]:border-b-0')}>
            <TableRow className="border-b-0 hover:bg-transparent">
              <SortableHead
                label={t('Status')}
                columnKey="status"
                sortBy={sortBy}
                sortOrder={sortOrder}
                onSortColumn={onSortColumn}
                className={headerCellClassName}
                style={headerStyle('status')}
                thRef={setHeaderThRef('status')}
              />
              <SortableHead
                label={t('Project')}
                columnKey="name"
                sortBy={sortBy}
                sortOrder={sortOrder}
                onSortColumn={onSortColumn}
                className={headerCellClassName}
                style={headerStyle('project')}
                thRef={setHeaderThRef('project')}
              />
              <SortableHead
                label={t('Project ID')}
                columnKey="$id"
                sortBy={sortBy}
                sortOrder={sortOrder}
                onSortColumn={onSortColumn}
                className={headerCellClassName}
                style={headerStyle('projectId')}
                thRef={setHeaderThRef('projectId')}
              />
              <SortableHead
                label={t('Endpoint')}
                columnKey="region"
                sortBy={sortBy}
                sortOrder={sortOrder}
                onSortColumn={onSortColumn}
                className={headerCellClassName}
                style={headerStyle('endpoint')}
                thRef={setHeaderThRef('endpoint')}
              />
              <SortableHead
                label={t('Created')}
                columnKey="$createdAt"
                sortBy={sortBy}
                sortOrder={sortOrder}
                onSortColumn={onSortColumn}
                className={headerCellClassName}
                style={headerStyle('created')}
                thRef={setHeaderThRef('created')}
              />
              {showUsage ? (
                <>
                  <TableHead
                    ref={setHeaderThRef('requests')}
                    className={headerCellClassName}
                    style={headerStyle('requests')}
                  >
                    {t('Requests')}
                  </TableHead>
                  <TableHead
                    ref={setHeaderThRef('bandwidth')}
                    className={headerCellClassName}
                    style={headerStyle('bandwidth')}
                  >
                    {t('Bandwidth')}
                  </TableHead>
                  <TableHead
                    ref={setHeaderThRef('executions')}
                    className={headerCellClassName}
                    style={headerStyle('executions')}
                  >
                    {t('Executions')}
                  </TableHead>
                  <TableHead
                    ref={setHeaderThRef('storage')}
                    className={headerCellClassName}
                    style={headerStyle('storage')}
                  >
                    {t('Storage')}
                  </TableHead>
                  <TableHead
                    ref={setHeaderThRef('compute')}
                    className={headerCellClassName}
                    style={headerStyle('compute')}
                  >
                    {t('Compute')}
                  </TableHead>
                  <TableHead
                    ref={setHeaderThRef('users')}
                    className={headerCellClassName}
                    style={headerStyle('users')}
                  >
                    {t('Users')}
                  </TableHead>
                </>
              ) : null}
              <TableHead aria-hidden className={fillerHeaderClassName} />
              <TableHead
                className={stickyActionsHeaderClass}
                style={spreadsheetActionsColStyle}
              />
            </TableRow>
          </TableHeader>
          <TableBody>
            {showPinnedSection ? (
              <SpreadsheetSectionRow
                label={t('Pinned')}
                colSpan={sectionColSpan}
              />
            ) : null}
            {pinnedProjects.map((project) => renderProjectRow(project))}
            {showPinnedSection && projects.length > 0 ? (
              <SpreadsheetSectionRow
                label={t('All projects')}
                colSpan={sectionColSpan}
                withTopSeparator
              />
            ) : null}
            {projects.map((project) => renderProjectRow(project))}
          </TableBody>
        </Table>
        <div className={COLUMN_RESIZE_RAILS_LAYER_CLASS} aria-hidden>
          {visibleKeys.map((key) => (
            <button
              key={`col-resize-rail-${key}`}
              ref={(node) => {
                if (node) railRefs.current.set(key, node)
                else railRefs.current.delete(key)
              }}
              type="button"
              aria-label={resizeRailLabel(key)}
              aria-orientation="vertical"
              role="separator"
              tabIndex={0}
              onPointerDown={handleResizePointerDown(key)}
              className={cn(
                dataColumnResizeRailHandleClass,
                resizingColumnKey === key && 'before:opacity-100',
                'focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
              )}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
