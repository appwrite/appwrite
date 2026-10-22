import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import {
  Copy,
  ExternalLink,
  FileJson,
  ListVideo,
  RefreshCw,
  Trash2,
} from 'lucide-react'
import { VideoOutputBadge } from './VideoOutputBadge'
import { VideoStatusBadge } from './VideoStatusBadge'
import { SpreadsheetColumnHeader } from './SpreadsheetColumnHeader'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { ProgressBarRow } from '@/components/global/shared/ProgressBarRow'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import {
  isVideoRenditionActive,
  parseVideoProgress,
} from '@/lib/react-query/hooks/videos'
import { cn } from '@/lib/utils'
import {
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
} from '@/lib/utils/context-menu'
import {
  formatBitrate,
  formatElapsed,
  formatResolution,
} from '@/lib/utils/video-format'
import { getVideoRenditionPlaylistUrl } from '@/lib/videos/urls'
import {
  SPREADSHEET_FILLER_CELL_CLASS,
  SPREADSHEET_FILLER_HEADER_CLASS,
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import { useT } from '@/lib/i18n/translate'
import type { RenditionSortColumn } from '@/lib/videos/rendition-list-filters'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'

const RETRYABLE_STATUSES = new Set(['error', 'aborted'])

const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const bodyCellBorderClass = 'border-b border-e border-border'

const ROWS_TABLE_EDGE_COL_PX = 40
const RENDITIONS_COLUMN_MIN_PX = {
  rendition: 200,
  profile: 140,
  output: 100,
  resolution: 112,
  bitrate: 160,
  segmentLength: 152,
  status: 170,
  encodingTime: 132,
  created: 120,
} as const
const RENDITIONS_COLUMN_ORDER = [
  'rendition',
  'profile',
  'output',
  'resolution',
  'bitrate',
  'segmentLength',
  'status',
  'encodingTime',
  'created',
] as const satisfies ReadonlyArray<keyof typeof RENDITIONS_COLUMN_MIN_PX>

const RENDITIONS_GRID_MIN_WIDTH_PX =
  Object.values(RENDITIONS_COLUMN_MIN_PX).reduce((sum, w) => sum + w, 0) +
  ROWS_TABLE_EDGE_COL_PX
const spreadsheetActionsColStyle = {
  width: ROWS_TABLE_EDGE_COL_PX,
  minWidth: ROWS_TABLE_EDGE_COL_PX,
  maxWidth: ROWS_TABLE_EDGE_COL_PX,
}
const stickyActionsHeaderClass = cn(
  'relative sticky end-0 z-30 bg-background p-0',
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
)
const stickyActionsCellBaseClass = cn(
  'sticky end-0 z-10 border-b border-border p-0',
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
)

const RENDITION_SORTABLE_COLUMNS: Array<{
  key: RenditionSortColumn
  labelKey: string
  term?: VideoGlossaryTerm
}> = [
  { key: 'rendition', labelKey: 'Rendition' },
  { key: 'profile', labelKey: 'Profile', term: 'profile' },
  { key: 'output', labelKey: 'Output', term: 'output' },
  { key: 'resolution', labelKey: 'Resolution' },
  { key: 'bitrate', labelKey: 'Bitrate', term: 'bitrate' },
  { key: 'segmentLength', labelKey: 'Segment length', term: 'targetDuration' },
  { key: 'status', labelKey: 'Status' },
  { key: 'encodingTime', labelKey: 'Encoding time' },
  { key: 'created', labelKey: 'Created' },
]

export function RenditionsSpreadsheet({
  projectId,
  videoId,
  renditions,
  profileNames,
  canWrite,
  retryPending,
  onRetry,
  onDelete,
  sortBy,
  sortOrder,
  onSortColumn,
  className,
}: {
  projectId: string
  videoId: string
  renditions: Models.VideoRendition[]
  profileNames: Map<string, string>
  canWrite: boolean
  retryPending: boolean
  onRetry: (rendition: Models.VideoRendition) => void
  onDelete: (rendition: Models.VideoRendition) => void
  sortBy: string
  sortOrder: 'asc' | 'desc'
  onSortColumn?: (columnKey: RenditionSortColumn) => void
  className?: string
}) {
  const t = useT()

  return (
    <div className={cn('relative flex h-full flex-col', className)}>
      <div className="flex-1 overflow-auto overscroll-contain">
        <div
          className={SPREADSHEET_SCROLL_LAYER_CLASS}
          style={{ minWidth: RENDITIONS_GRID_MIN_WIDTH_PX }}
        >
          <table className="w-full table-fixed border-collapse">
            <colgroup>
              {RENDITIONS_COLUMN_ORDER.map((columnId) => {
                const widthPx = RENDITIONS_COLUMN_MIN_PX[columnId]
                return (
                  <col
                    key={columnId}
                    style={{
                      width: widthPx,
                      minWidth: widthPx,
                    }}
                  />
                )
              })}
              <col />
              <col style={spreadsheetActionsColStyle} />
            </colgroup>
            <thead className={stickyTheadClass}>
              <tr>
                {RENDITION_SORTABLE_COLUMNS.map((col) => (
                  <th
                    key={col.key}
                    className={cn(
                      'max-w-0 overflow-hidden px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                    scope="col"
                  >
                    <SpreadsheetColumnHeader
                      label={t(col.labelKey)}
                      term={col.term}
                      sortColumnKey={col.key}
                      sortBy={sortBy}
                      sortOrder={sortOrder}
                      onSortColumn={
                        onSortColumn
                          ? (key) => onSortColumn(key as RenditionSortColumn)
                          : undefined
                      }
                    />
                  </th>
                ))}
                <th aria-hidden className={SPREADSHEET_FILLER_HEADER_CLASS} />
                <th
                  className={stickyActionsHeaderClass}
                  scope="col"
                  style={spreadsheetActionsColStyle}
                />
              </tr>
            </thead>
            <tbody>
              {renditions.map((rendition) => {
                const active = isVideoRenditionActive(rendition.status)
                const playlistUrl =
                  rendition.status === 'ready'
                    ? getVideoRenditionPlaylistUrl(
                        projectId,
                        videoId,
                        rendition.$id,
                        rendition.output,
                      )
                    : null
                const profileName = profileNames.get(rendition.profileId)

                return (
                  <tr
                    key={rendition.$id}
                    className="group transition-colors hover:bg-muted/50"
                  >
                    <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                      <div className="flex min-w-0 flex-col gap-1">
                        <span className="truncate text-[13px] font-medium">
                          {rendition.name}
                        </span>
                        <div className="min-w-0 w-fit max-w-full overflow-hidden">
                          <CopyableId
                            id={rendition.$id}
                            size="xs"
                            constrainToContainer
                          />
                        </div>
                      </div>
                    </td>
                    <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                      {profileName ? (
                        <Link
                          to="/projects/$projectId/videos/profiles"
                          params={{ projectId }}
                          className="block min-w-0 truncate text-[13px] hover:underline"
                        >
                          {profileName}
                        </Link>
                      ) : (
                        <div className="min-w-0 w-fit max-w-full overflow-hidden">
                          <CopyableId
                            id={rendition.profileId}
                            size="xs"
                            constrainToContainer
                          />
                        </div>
                      )}
                    </td>
                    <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                      <VideoOutputBadge output={rendition.output} />
                    </td>
                    <td
                      className={cn(
                        'px-3 py-2 font-mono text-[12px]',
                        bodyCellBorderClass,
                      )}
                    >
                      {formatResolution(rendition.width, rendition.height)}
                    </td>
                    <td
                      className={cn(
                        'px-3 py-2 font-mono text-[12px] text-muted-foreground',
                        bodyCellBorderClass,
                      )}
                    >
                      {formatBitrate(rendition.videoBitRate, 'kbps')} /{' '}
                      {formatBitrate(rendition.audioBitRate, 'kbps')}
                    </td>
                    <td
                      className={cn(
                        'px-3 py-2 font-mono text-[12px] text-muted-foreground',
                        bodyCellBorderClass,
                      )}
                    >
                      {Number(rendition.targetDuration) > 0
                        ? `${rendition.targetDuration}s`
                        : '-'}
                    </td>
                    <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                      <div className="flex flex-col gap-1.5">
                        <VideoStatusBadge
                          status={rendition.status}
                          kind="rendition"
                        />
                        {active ? (
                          <ProgressBarRow
                            value={parseVideoProgress(rendition.progress)}
                            className="mb-0 max-w-[160px]"
                          />
                        ) : null}
                      </div>
                    </td>
                    <td
                      className={cn(
                        'px-3 py-2 font-mono text-[12px] text-muted-foreground',
                        bodyCellBorderClass,
                      )}
                    >
                      {rendition.startedAt
                        ? formatElapsed(rendition.startedAt, rendition.endedAt)
                        : '-'}
                    </td>
                    <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                      <DateTooltip
                        date={rendition.$createdAt}
                        className="whitespace-nowrap text-[12px] font-normal text-muted-foreground"
                      />
                    </td>
                    <td aria-hidden className={SPREADSHEET_FILLER_CELL_CLASS} />
                    <td
                      className={cn(
                        stickyActionsCellBaseClass,
                        'bg-background group-hover:bg-muted/50',
                      )}
                      style={spreadsheetActionsColStyle}
                    >
                      <div
                        className="flex h-full items-center justify-center py-1.5"
                        style={spreadsheetActionsColStyle}
                      >
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger
                              aria-label={`${t('Actions for')} ${rendition.name}`}
                            />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-60">
                            {RETRYABLE_STATUSES.has(rendition.status) ? (
                              <>
                                <DropdownMenuItem
                                  disabled={!canWrite || retryPending}
                                  onClick={() => onRetry(rendition)}
                                >
                                  <MenuItemContent icon={RefreshCw}>
                                    {t('Retry')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                              </>
                            ) : null}
                            <DropdownMenuSub>
                              <DropdownMenuSubTrigger>
                                <MenuItemIcon icon={Copy} />
                                {t('Copy')}
                              </DropdownMenuSubTrigger>
                              <DropdownMenuSubContent className="w-56">
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard(t('ID'), rendition.$id)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy ID')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard(t('Name'), rendition.name)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy name')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                {playlistUrl ? (
                                  <DropdownMenuItem
                                    onClick={() =>
                                      copyToClipboard(
                                        t('Media playlist URL'),
                                        playlistUrl,
                                      )
                                    }
                                  >
                                    <MenuItemContent icon={ListVideo}>
                                      {t('Copy media playlist URL')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                ) : null}
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyResourceAsJson(
                                      () =>
                                        sdk
                                          .forProject(projectId)
                                          .videos.getRendition({
                                            videoId,
                                            renditionId: rendition.$id,
                                          }),
                                      { fallback: rendition },
                                    )
                                  }
                                >
                                  <MenuItemContent icon={FileJson}>
                                    {t('Copy as JSON')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            {playlistUrl ? (
                              <DropdownMenuItem
                                onClick={() =>
                                  openInNewTab(withAdminMode(playlistUrl))
                                }
                              >
                                <MenuItemContent icon={ExternalLink}>
                                  {t('Open media playlist')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            ) : null}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              disabled={!canWrite}
                              onClick={() => onDelete(rendition)}
                            >
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
