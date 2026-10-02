import type { Models } from '@appwrite.io/console'
import { Copy, FileJson, Pencil, Trash2 } from 'lucide-react'
import { SpreadsheetColumnHeader } from './SpreadsheetColumnHeader'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
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
import { cn } from '@/lib/utils'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import {
  SPREADSHEET_FILLER_CELL_CLASS,
  SPREADSHEET_FILLER_HEADER_CLASS,
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import { useT } from '@/lib/i18n/translate'
import type { ProfileSortColumn } from '@/lib/videos/profile-list-filters'

const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const bodyCellBorderClass = 'border-b border-e border-border'

const ROWS_TABLE_EDGE_COL_PX = 40
const PROFILES_COLUMN_MIN_PX = {
  name: 220,
  resolution: 112,
  videoBitRate: 128,
  audioBitRate: 128,
  created: 120,
} as const
const PROFILES_COLUMN_ORDER = [
  'name',
  'resolution',
  'videoBitRate',
  'audioBitRate',
  'created',
] as const satisfies ReadonlyArray<keyof typeof PROFILES_COLUMN_MIN_PX>

const PROFILES_GRID_MIN_WIDTH_PX =
  Object.values(PROFILES_COLUMN_MIN_PX).reduce((sum, w) => sum + w, 0) +
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

const SORTABLE_COLUMNS: Array<{
  key: ProfileSortColumn
  labelKey: string
  term?: 'bitrate'
}> = [
  { key: 'name', labelKey: 'Name' },
  { key: 'resolution', labelKey: 'Resolution' },
  { key: 'videoBitRate', labelKey: 'Video bitrate', term: 'bitrate' },
  { key: 'audioBitRate', labelKey: 'Audio bitrate' },
  { key: '$createdAt', labelKey: 'Created' },
]

export function ProfilesSpreadsheet({
  profiles,
  canWrite,
  onUpdate,
  onDelete,
  sortBy,
  sortOrder,
  onSortColumn,
  className,
}: {
  profiles: Models.VideoProfile[]
  canWrite: boolean
  onUpdate: (profile: Models.VideoProfile) => void
  onDelete: (profile: Models.VideoProfile) => void
  sortBy: string
  sortOrder: 'asc' | 'desc'
  onSortColumn?: (columnKey: ProfileSortColumn) => void
  className?: string
}) {
  const t = useT()

  return (
    <div className={cn('relative flex h-full flex-col', className)}>
      <div className="flex-1 overflow-auto overscroll-contain">
        <div
          className={SPREADSHEET_SCROLL_LAYER_CLASS}
          style={{ minWidth: PROFILES_GRID_MIN_WIDTH_PX }}
        >
          <table className="w-full table-fixed border-collapse">
            <colgroup>
              {PROFILES_COLUMN_ORDER.map((columnId) => {
                const widthPx = PROFILES_COLUMN_MIN_PX[columnId]
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
                {SORTABLE_COLUMNS.map((col) => (
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
                          ? (key) => onSortColumn(key as ProfileSortColumn)
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
              {profiles.map((profile) => (
                <tr
                  key={profile.$id}
                  className="group transition-colors hover:bg-muted/50"
                >
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <div className="flex min-w-0 flex-col gap-1">
                      <span className="truncate text-[13px] font-medium">
                        {profile.name}
                      </span>
                      <div className="min-w-0 w-fit max-w-full overflow-hidden">
                        <CopyableId
                          id={profile.$id}
                          size="xs"
                          constrainToContainer
                        />
                      </div>
                    </div>
                  </td>
                  <td
                    className={cn(
                      'px-3 py-2 font-mono text-[12px]',
                      bodyCellBorderClass,
                    )}
                  >
                    {formatResolution(profile.width, profile.height)}
                  </td>
                  <td
                    className={cn(
                      'px-3 py-2 font-mono text-[12px] text-muted-foreground',
                      bodyCellBorderClass,
                    )}
                  >
                    {formatBitrate(profile.videoBitRate, 'kbps')}
                  </td>
                  <td
                    className={cn(
                      'px-3 py-2 font-mono text-[12px] text-muted-foreground',
                      bodyCellBorderClass,
                    )}
                  >
                    {formatBitrate(profile.audioBitRate, 'kbps')}
                  </td>
                  <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                    <DateTooltip
                      date={profile.$createdAt}
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
                            aria-label={`${t('Actions for')} ${profile.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          <DropdownMenuItem
                            disabled={!canWrite}
                            onClick={() => onUpdate(profile)}
                          >
                            <MenuItemContent icon={Pencil}>
                              {t('Update')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                              <MenuItemIcon icon={Copy} />
                              {t('Copy')}
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent className="w-44">
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('ID'), profile.$id)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy ID')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(t('Name'), profile.name)
                                }
                              >
                                <MenuItemContent icon={Copy}>
                                  {t('Copy name')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() =>
                                  copyResourceAsJson(() => profile)
                                }
                              >
                                <MenuItemContent icon={FileJson}>
                                  {t('Copy as JSON')}
                                </MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuSubContent>
                          </DropdownMenuSub>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            disabled={!canWrite}
                            onClick={() => onDelete(profile)}
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
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
