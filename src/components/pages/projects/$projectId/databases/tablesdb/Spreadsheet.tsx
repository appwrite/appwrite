// Table spreadsheet UI (rows, columns, indexes, security, settings) for this database product.
import { cn } from '@/lib/utils'
import {
  applyColumnResizeRailPosition,
  horizontalResizeDeltaPx,
  horizontalSplitHandleStyle,
  isRtlElement,
  setBodyResizeDragActive,
  RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X,
  RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
} from '@/lib/layout/horizontal-resize'
import {
  SPREADSHEET_FILLER_CELL_CLASS,
  SPREADSHEET_FILLER_HEADER_CLASS,
  SPREADSHEET_SCROLL_LAYER_CLASS,
  SPREADSHEET_STICKY_BODY_Z,
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
  SPREADSHEET_STICKY_START_EDGE_SHADOW,
  SPREADSHEET_STICKY_START_HEADER_SHADOW,
} from '@/lib/layout/spreadsheet-sticky'
import { getColumnIcon } from '@/lib/utils/column-icons'
import {
  columnTypeSupportsNumericRange,
  formatColumnNumericDisplay,
  formatColumnRangeDisplay,
  getTableColumnKey,
  getTableColumnStatusBadgeVariant,
  isTableColumnStatusPending,
  isTablesDbSystemColumnKey,
  isTextType,
  mergeTablesDbSystemColumnsIntoList,
  normalizeTableColumnStatus,
  type MappedTableColumnListItem,
} from '@/lib/utils/database-columns'
import { NumericValueTooltip } from '@/components/global/shared/NumericValueTooltip'
import { getErrorMessage, formatError } from '@/lib/utils/error-formatting'
import { captureExceptionWithContext } from '@/components/global/providers/SentryContext'
import { copyToClipboard, openInNewWindow } from '@/lib/utils/context-menu'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import {
  Plus,
  Key,
  Table2,
  CheckCircle2,
  Trash2,
  ArrowUpDown,
  ArrowUp,
  ArrowDown,
  Calendar,
  Link2,
  X,
  Check,
  BarChart3,
  Lightbulb,
  BookOpen,
  FileText,
  Copy,
  Pencil,
  Braces,
  Brackets,
  Redo2,
  Undo2,
  GripVertical,
  Columns3,
  Eye,
  EyeOff,
  AlertCircle,
} from 'lucide-react'
import {
  type Collection,
} from '@/lib/utils/mock-data'
import {
  useState,
  useEffect,
  useLayoutEffect,
  useRef,
  useCallback,
  useMemo,
  type CSSProperties,
  type MouseEvent,
  type ReactNode,
} from 'react'
import { flushSync } from 'react-dom'
import {
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useProjectTableRows,
  useProjectTableColumns,
  deleteProjectTableRow,
  createProjectTableRows,
  createProjectTableRow,
  updateProjectTableRow,
  fetchProjectTableRow,
  createProjectTableColumn,
  updateProjectTableColumn,
  deleteProjectTableColumn,
  patchProjectTableColumnsCache,
  refetchProjectTableRelatedQueries,
  useProjectTables as useTablesForColumns,
  useProjectTable,
  updateProjectTable,
  deleteProjectTable,
  createProjectTableIndex,
  deleteProjectTableIndex,
  useProjectTableIndexes,
  fetchConsoleAccount,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
  updateConsoleTeamPrefs,
} from '@/lib/react-query/hooks'
import {
  COLUMNS_INDEXES_DEFAULT_PAGE_SIZE,
  ROWS_DEFAULT_PAGE_SIZE,
} from '@/lib/react-query/hooks/constants'
import { ColumnDrawer, ColumnFormData, type ColumnType } from './Column'
import { IndexDrawer, IndexFormData } from './Index'
import { RowContextMenu } from '../_components/RowContextMenu'
import {
  clampSplitFirstPaneWidthPx,
  fitSplitFirstPaneWidthOnContainerResize,
} from '@/lib/resizable-layout'
import {
  DOCUMENTS_PREVIEW_PANE_MIN_PX,
  DOCUMENTS_TABLE_PANE_MAX_PX,
  DOCUMENTS_TABLE_PANE_MIN_PX,
  DOCUMENTS_TABLE_PANE_WIDTH_STORAGE_KEY,
  readStoredDocumentsTablePaneWidthPx,
} from '../../storage/_components/files-documents-layout'






import { useAuth } from '@/components/global/auth/RequireAuth'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useIsMobile } from '@/hooks/use-mobile'
import type { Models } from '@appwrite.io/console'
import type { editor } from 'monaco-editor'
import {
  dbNavLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { getLocalizedDatabaseConsoleLabels } from '@/lib/database-console-labels'
import { sdk } from '@/lib/appwrite/sdk'
import { Card } from '@/components/ui/card'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  CodeEditor,
  type CodeEditorRef,
} from '@/components/global/shared/CodeEditor'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { MenuItemContent, MenuItemIcon } from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { SampleDataModal } from './SampleData'
import { InlineTableCell } from './_components/InlineTableCell'
import { useTableRowsEditSession } from './_components/TableRowsEditSession'
import {
  getSystemDateColumnInfo,
  type SystemDateColumnKey,
} from '@/lib/database-row-inline-edits'
import { generateSampleRows, type Column } from '@/lib/utils/sample-data'
import { IdInput } from '@/components/ui/id-input'
import { Button } from '@/components/ui/button'
import { PermissionsEditor } from '../../auth/PermissionsEditor'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import {
  useNavigate,
  useParams,
  useLocation,
  useSearch,
} from '@tanstack/react-router'
import {
  getPage,
  getLimit,
  encodeSort,
  queryParamToMap,
  urlFromRouterLocation,
  searchParamsFromRouterLocation,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import {
  deleteDatabaseTableRowColumnWidthsFromPrefs,
  deleteTablesDbRowsListColumnsFromPrefs,
  getDatabaseTableRowColumnWidthsFromPrefs,
  mergeDatabaseTableRowColumnWidthsTableIntoPrefs,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PointEditor, LineEditor, PolygonEditor } from './spatial'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { translate, useT } from '@/lib/i18n/translate'
import {
  localizeResourceStatusLabel,
  localizeTableIndexTypeLabel,
} from '@/lib/i18n/resource-status-labels'

const DB_KIND = 'tablesdb' as const satisfies DatabaseRouteKind

/** Database list item: API may return extra backup/createdAt fields */
type DatabaseWithBackup = Models.Database & {
  hasBackupPolicy?: boolean
  backupPolicyCount?: number
  backupPolicy?: { name?: string }
  createdAt?: string
  updatedAt?: string
}

interface IndexColumnEntry {
  column: string
  order: 'ASC' | 'DESC' | null
  length: number | null
}

// Helper function for column type colors
const getColumnTypeColor = (type: string) => {
  const colors: Record<string, string> = {
    string:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    varchar:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    text: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    mediumtext:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    longtext:
      'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    integer:
      'bg-purple-500/10 text-purple-600 dark:text-purple-400',
    bigint:
      'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    float:
      'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    boolean:
      'bg-green-500/10 text-green-600 dark:text-green-400',
    datetime:
      'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    email: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
    ip: 'bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/20',
    url: 'bg-pink-500/10 text-pink-600 dark:text-pink-400 border-pink-500/20',
    enum: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    relationship:
      'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
  }
  return colors[type] || 'bg-muted text-muted-foreground'
}

const getColumnDisplayType = (column: Record<string, unknown>) => {
  const type = typeof column.type === 'string' ? column.type : 'string'
  const format = typeof column.format === 'string' ? column.format : null

  if (
    format &&
    (type === 'string' ||
      type === 'varchar' ||
      type === 'datetime' ||
      type === 'date')
  ) {
    return format
  }

  return type
}

// Helper function for index type colors
const getIndexTypeColor = (type: string) => {
  const colors: Record<string, string> = {
    key: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    unique:
      'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    fulltext:
      'bg-purple-500/10 text-purple-600 dark:text-purple-400',
  }
  return colors[type] || 'bg-muted text-muted-foreground'
}

// Reusable table styles for spreadsheet views
const stickyTheadClass = 'sticky top-0 z-20 bg-background'
const headerCellBorderClass =
  'border-e border-border shadow-[inset_0_1px_0_0_var(--border),inset_0_-1px_0_0_var(--border)]'
const bodyCellBorderClass = 'border-b border-e border-border'

/** Checkbox + row-actions column width; documents list uses `table-fixed` so edges stay this size. */
const ROWS_TABLE_EDGE_COL_PX = 40
const COLUMNS_GRID_MIN_WIDTH_PX =
  200 + 120 + 120 + 80 + 108 + 80 + 80 + 80 + 80 + 120 + ROWS_TABLE_EDGE_COL_PX
const ROWS_SEQUENCE_COL_PX = 72
const ROWS_DATA_COLUMN_DEFAULT_WIDTH_PX = 150
const ROWS_ID_COLUMN_DEFAULT_WIDTH_PX = 200
const ROWS_SYSTEM_DATE_COLUMN_DEFAULT_WIDTH_PX = 180
const ROWS_DATA_COLUMN_MIN_WIDTH_PX = 72
const ROWS_DATA_COLUMN_MAX_WIDTH_PX = 640
const ROW_GRID_DEFAULT_DATE_KEYS = ['$createdAt', '$updatedAt'] as const

function lockedColumnSizeStyle(widthPx: number): CSSProperties {
  return { width: widthPx, minWidth: widthPx, maxWidth: widthPx }
}

const spreadsheetActionsColStyle = lockedColumnSizeStyle(ROWS_TABLE_EDGE_COL_PX)
const spreadsheetSequenceColStyle = lockedColumnSizeStyle(ROWS_SEQUENCE_COL_PX)
const stickyActionsHeaderClass = cn(
  'relative sticky end-0 z-30 bg-background p-0',
  SPREADSHEET_STICKY_END_HEADER_SHADOW,
)
const stickyActionsCellBaseClass = cn(
  'sticky end-0 z-10 border-b border-border p-0',
  SPREADSHEET_STICKY_END_EDGE_SHADOW,
)

function applyLockedColumnSize(
  element: HTMLElement | null | undefined,
  widthPx: number,
  minWidthPx: number = widthPx,
): void {
  if (!element) return
  element.style.width = `${widthPx}px`
  element.style.minWidth = `${minWidthPx}px`
  element.style.maxWidth = `${widthPx}px`
}

function clampRowGridColumnWidthPx(widthPx: number): number {
  return Math.min(
    ROWS_DATA_COLUMN_MAX_WIDTH_PX,
    Math.max(ROWS_DATA_COLUMN_MIN_WIDTH_PX, widthPx),
  )
}

/** Sequence is a fixed index column; every other visible rows-grid column can resize. */
function isResizableRowGridColumn(key: string): boolean {
  return key.length > 0 && key !== '$sequence'
}

function defaultRowGridColumnWidthPx(key: string): number {
  if (key === '$id') return ROWS_ID_COLUMN_DEFAULT_WIDTH_PX
  if (key === '$createdAt' || key === '$updatedAt') {
    return ROWS_SYSTEM_DATE_COLUMN_DEFAULT_WIDTH_PX
  }
  return ROWS_DATA_COLUMN_DEFAULT_WIDTH_PX
}

function getRowGridDataColumnLayoutStyle(
  widthPx: number,
  isDragResize: boolean,
): CSSProperties | undefined {
  if (isDragResize) return undefined
  return lockedColumnSizeStyle(widthPx)
}

function computeRowsTableMinWidthPx(
  gridKeys: readonly string[],
  getColumnWidthPx: (key: string) => number,
): number {
  let total = ROWS_TABLE_EDGE_COL_PX * 2
  for (const key of gridKeys) {
    if (!key) continue
    total +=
      key === '$sequence' ? ROWS_SEQUENCE_COL_PX : getColumnWidthPx(key)
  }
  return total
}
/**
 * Same affordance as database `TableViewResizableLayout` / functions editor
 * `ResizableHandle`: hairline (`after`) + wider `before` strip on hover/drag.
 * `w-2` hit area so the spine stays thin but remains easy to grab.
 */
const DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS = cn(
  'group absolute top-0 bottom-0 z-[41] w-2 cursor-col-resize touch-none border-0 bg-transparent p-0 outline-none',
  'after:pointer-events-none after:absolute after:inset-y-0 after:w-[0.5px] after:bg-border',
  RESIZE_HANDLE_PSEUDO_AFTER_HAIRLINE_LOGICAL_X,
  'before:pointer-events-none before:absolute before:inset-y-0 before:z-10 before:w-2 before:bg-border before:opacity-0 before:transition-opacity',
  RESIZE_HANDLE_PSEUDO_BEFORE_LOGICAL_X,
  'hover:before:opacity-100',
)

// Row type for the spreadsheet
interface RowData {
  $id: string
  /** Server-provided sequence (stable); fallback to computed rowNumber when absent */
  $sequence?: number
  rowNumber: number
  data: Record<string, string | number | bigint | boolean | unknown[] | null>
  $createdAt?: string
  $updatedAt?: string
  $permissions?: string[]
}

/** Flat document object for the inline JSON editor (metadata + attributes). */
function buildInlineDocumentJsonObjectFromRow(row: RowData): Record<string, unknown> {
  const o: Record<string, unknown> = {
    $id: row.$id,
    $permissions: row.$permissions ?? [],
  }
  if (row.$sequence !== undefined && row.$sequence !== null) {
    o.$sequence = row.$sequence
  }
  if (row.$createdAt !== undefined) o.$createdAt = row.$createdAt
  if (row.$updatedAt !== undefined) o.$updatedAt = row.$updatedAt
  Object.assign(o, row.data)
  return o
}

function buildInlineDocumentJsonObjectForCreate(
  columns: unknown[],
): Record<string, unknown> {
  const now = new Date().toISOString()
  const o: Record<string, unknown> = {
    $id: '',
    $permissions: [],
    $createdAt: now,
    $updatedAt: now,
  }
  columns.forEach((col: unknown) => {
    const c = col as {
      key?: string
      name?: string
      $id?: string
      attribute?: string
      attributeId?: string
      default?: unknown
      type?: string
      array?: boolean
    }
    const colKey =
      c.key || c.name || c.$id || c.attribute || c.attributeId
    if (colKey && !String(colKey).startsWith('$')) {
      if (c.default !== undefined && c.default !== null) {
        o[colKey] = c.default
      } else if (c.type === 'boolean') {
        o[colKey] = false
      } else if (c.array) {
        o[colKey] = []
      } else {
        o[colKey] = ''
      }
    }
  })
  return o
}


function arrayFieldSortableId(fieldKey: string, index: number) {
  return `${fieldKey}::__arr__::${index}`
}

function RowEditArraySortableRow({
  id,
  index,
  children,
}: {
  id: string
  index: number
  children: ReactNode
}) {
  const t = useT()
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id,
    // Avoid post-drop “double motion”: default sortable tweens layout while React
    // already re-rendered the new order, which reads as items sliding twice.
    animateLayoutChanges: () => false,
    transition: null,
  })
  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  }
  return (
    <div
      ref={setNodeRef}
      style={style}
      className={cn(
        'group flex items-stretch',
        index % 2 === 0 ? 'bg-background' : 'bg-muted/20',
        isDragging && 'relative z-20 ring-1 ring-border/70',
      )}
    >
      <button
        type="button"
        className="flex w-8 shrink-0 cursor-grab touch-none items-center justify-center border-e border-foreground/10 bg-muted/30 text-muted-foreground hover:bg-muted/45 active:cursor-grabbing"
        aria-label={t('Drag to reorder')}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="h-4 w-4 shrink-0" />
      </button>
      {children}
    </div>
  )
}

// Row Update Drawer Component
interface RowEditDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  row: RowData | null
  tableName: string
  focusedField?: string | null
  /** When set, drawer opens with this tab selected (e.g. 'data' for Update row, 'permissions' from context menu) */
  initialTab?: 'data' | 'permissions'
  columns?: unknown[]
  onSave: (
    rowId: string | null,
    data: Record<string, string | number | bigint | boolean | unknown[] | null>,
    customId?: string | undefined,
    permissions?: string[],
  ) => void
  isSaving?: boolean
  /** Documents DB: render beside the table instead of a sheet */
  presentation?: 'drawer' | 'inline'
}

function formatDateTimeLocalForInput(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** Parse a comma-separated string into an array, with optional type coercion per element. */
function parseCommaSeparatedToArray(
  value: string,
  columnType?: string,
): unknown[] {
  if (typeof value !== 'string' || value.trim() === '') return []
  const parts = value.split(',').map((s) => s.trim())
  const type = (columnType || 'string').toLowerCase()
  if (type === 'integer' || type === 'int') {
    return parts.map((p) => (p === '' || p === 'null' ? null : parseInt(p, 10)))
  }
  if (type === 'bigint') {
    return parts.map((p) => {
      if (p === '' || p === 'null') return null
      try {
        return BigInt(p)
      } catch {
        return null
      }
    })
  }
  if (type === 'double' || type === 'float' || type === 'number') {
    return parts.map((p) => (p === '' || p === 'null' ? null : parseFloat(p)))
  }
  if (type === 'boolean' || type === 'bool') {
    return parts.map((p) =>
      p === '' || p === 'null' ? null : /^(true|1|yes)$/i.test(p),
    )
  }
  return parts.map((p) => (p === '' || p === 'null' ? null : p))
}

/** Normalize a value for a column: e.g. comma-separated string → array when column is array type. */
function normalizeValueForColumn(
  value: unknown,
  columnInfo?: unknown,
): string | number | bigint | boolean | unknown[] | null {
  if (value === null || value === undefined) return null
  const col = columnInfo as { array?: boolean; type?: string } | undefined
  if (!col?.array) return value as string | number | boolean | null
  if (Array.isArray(value)) return value
  if (typeof value === 'string') {
    return parseCommaSeparatedToArray(value, col.type)
  }
  return value as unknown[]
}

function findColumnForAttrKey(
  columns: unknown[],
  key: string,
): unknown | undefined {
  return columns.find((c) => {
    const col = c as Record<string, unknown>
    return (
      col.key === key ||
      col.name === key ||
      col.$id === key ||
      col.attribute === key
    )
  })
}

/** Map a full API row document into spreadsheet `RowData` (all non-$ attributes). */
function mapApiRowToRowData(
  apiRow: Record<string, unknown>,
  columns: unknown[],
  options?: { rowNumber?: number; fallbackId?: string },
): RowData {
  const data: Record<
    string,
    string | number | bigint | boolean | unknown[] | null
  > = {}
  Object.keys(apiRow).forEach((key) => {
    if (key.startsWith('$')) return
    data[key] = normalizeValueForColumn(
      apiRow[key],
      findColumnForAttrKey(columns, key),
    ) as string | number | boolean | unknown[] | null
  })
  return {
    $id: (apiRow.$id as string) ?? options?.fallbackId ?? '',
    $sequence: apiRow.$sequence as number | undefined,
    rowNumber: options?.rowNumber ?? 0,
    data,
    $createdAt: apiRow.$createdAt as string | undefined,
    $updatedAt: apiRow.$updatedAt as string | undefined,
    $permissions: (apiRow.$permissions as string[]) || [],
  }
}

function getTableColumnKey(col: unknown): string | null {
  const c = col as {
    key?: string
    name?: string
    $id?: string
    attribute?: string
    attributeId?: string
  }
  const colKey = c.key || c.name || c.$id || c.attribute || c.attributeId
  if (!colKey || String(colKey).startsWith('$')) return null
  return String(colKey)
}

function defaultFormValueForColumn(
  col: unknown,
): string | number | bigint | boolean | unknown[] | null {
  const c = col as {
    default?: unknown
    type?: string
    array?: boolean
  }
  if (c.default !== undefined && c.default !== null) {
    return c.default as string | number | bigint | boolean | unknown[] | null
  }
  if (c.type === 'boolean') return false
  if (c.array) return []
  return ''
}

function getRelationshipTableId(columnInfo?: unknown): string | undefined {
  const col = columnInfo as
    | { relatedTable?: string; relatedTableId?: string }
    | undefined

  return col?.relatedTableId || col?.relatedTable || undefined
}

function getRelationshipKind(columnInfo?: unknown): string | undefined {
  const col = columnInfo as
    | { relationType?: string; relationshipType?: string }
    | undefined

  return col?.relationshipType || col?.relationType || undefined
}

function getRelationshipRowLabel(
  row: Record<string, unknown>,
  columns: unknown[],
): string {
  const labelColumn = columns.find((column) => {
    const col = column as { type?: string } | undefined
    const key = getTableColumnKey(column)

    if (!key || key.startsWith('$')) return false

    return (
      col?.type === 'string' ||
      col?.type === 'varchar' ||
      col?.type === 'text' ||
      col?.type === 'mediumtext' ||
      col?.type === 'longtext' ||
      col?.type === 'email' ||
      col?.type === 'url'
    )
  })

  const labelKey = labelColumn ? getTableColumnKey(labelColumn) : null
  const labelValue =
    labelKey && typeof row[labelKey] === 'string' ? row[labelKey] : null

  if (labelValue && labelValue.trim().length > 0) {
    return `${labelValue} (${row.$id})`
  }

  return String(row.$id ?? translate('Unknown row'))
}

interface RelationshipFieldProps {
  columnInfo?: unknown
  currentValue: string | unknown[] | null
  isRequired: boolean
  isSaving: boolean
  onChange: (value: string | unknown[] | null) => void
}

function RelationshipField({
  columnInfo,
  currentValue,
  isRequired,
  isSaving,
  onChange,
}: RelationshipFieldProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string | undefined
  const databaseId = params.databaseId as string | undefined
  const relatedTableId = getRelationshipTableId(columnInfo)
  const relationType = getRelationshipKind(columnInfo)
  const isMulti =
    relationType === 'oneToMany' || relationType === 'manyToMany'

  const { rows: relatedRows, isLoading: relatedRowsLoading } =
    useProjectTableRows(
    projectId,
    databaseId,
    relatedTableId,
    DB_KIND,
    0,
      100,
      undefined,
      'desc',
      '$createdAt',
    )
  const { columns: relatedColumns } = useProjectTableColumns(
    projectId,
    databaseId,
    DB_KIND,
    relatedTableId,
    undefined,
    0,
    100,
  )

  const selectedValues = Array.isArray(currentValue)
    ? currentValue
        .map((value) => (typeof value === 'string' ? value : null))
        .filter((value): value is string => Boolean(value))
    : typeof currentValue === 'string' && currentValue.length > 0
      ? [currentValue]
      : []

  const options = relatedRows
    .map((row) => row as Record<string, unknown>)
    .filter((row) => typeof row.$id === 'string')
    .map((row) => ({
      value: row.$id as string,
      label: getRelationshipRowLabel(row, relatedColumns),
    }))

  if (!relatedTableId) {
    return (
      <div className="rounded-lg border border-border bg-muted/30 p-3">
        <p className="text-[12px] text-muted-foreground">
          {t('This relationship is missing its related table metadata.')}
        </p>
      </div>
    )
  }

  if (isMulti) {
    const availableItems = options.filter(
      (option) => !selectedValues.includes(option.value),
    )

    return (
      <div className="space-y-2">
        <SearchableSelect
          value=""
          onValueChange={(value) => {
            if (!selectedValues.includes(value)) {
              onChange([...selectedValues, value])
            }
          }}
          items={availableItems}
          placeholder={
            relatedRowsLoading ? t('Loading related rows…') : t('Add related row')
          }
          searchPlaceholder={t('Search related rows…')}
          emptyMessage={
            relatedRowsLoading ? t('Loading related rows…') : t('No related rows')
          }
          disabled={isSaving || relatedRowsLoading || availableItems.length === 0}
        />
        {selectedValues.length > 0 ? (
          <div className="space-y-2">
            {selectedValues.map((value) => {
              const option = options.find((item) => item.value === value)

              return (
                <div
                  key={value}
                  className="flex items-center justify-between rounded-lg border border-border bg-muted/30 px-3 py-2"
                >
                  <span className="truncate text-[12px] text-foreground">
                    {option?.label || value}
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2 text-[12px] text-muted-foreground hover:text-foreground"
                    disabled={isSaving}
                    onClick={() =>
                      onChange(selectedValues.filter((item) => item !== value))
                    }
                  >
                    {t('Remove')}
                  </Button>
                </div>
              )
            })}
          </div>
        ) : (
          <p className="text-[12px] text-muted-foreground">
            {t('No related rows selected.')}
          </p>
        )}
        {!isRequired && selectedValues.length > 0 && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            disabled={isSaving}
            onClick={() => onChange([])}
          >
            {t('Clear')}
          </Button>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <SearchableSelect
        value={selectedValues[0] || ''}
        onValueChange={(value) => onChange(value)}
        items={options}
        placeholder={
          relatedRowsLoading ? t('Loading related rows…') : t('Select related row')
        }
        searchPlaceholder={t('Search related rows…')}
        emptyMessage={
          relatedRowsLoading ? t('Loading related rows…') : t('No related rows')
        }
        disabled={isSaving || relatedRowsLoading}
      />
      {!isRequired && selectedValues[0] && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          disabled={isSaving}
          onClick={() => onChange(null)}
        >
          {t('Clear')}
        </Button>
      )}
    </div>
  )
}

function RowEditDrawer({
  open,
  onOpenChange,
  row,
  focusedField,
  initialTab,
  columns = [],
  onSave,
  isSaving = false,
  presentation = 'drawer',
}: RowEditDrawerProps) {
  const t = useT()
  const isMobileViewport = useIsMobile()
  const useCompactInlineJsonToolbar =
    presentation === 'inline' && isMobileViewport
  const jsonToolbarTooltipSide = useCompactInlineJsonToolbar
    ? ('bottom' as const)
    : ('left' as const)
  const params = useParams({ strict: false })
  const projectId = params.projectId as string | undefined
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, DB_KIND)
  const isCreateMode = !row
  const hideSequenceInEditor = false

  const dataTabFieldKeys = useMemo(() => {
    const sys = ['$createdAt', '$updatedAt'] as const
    if (isCreateMode) {
      const keys: string[] = [...sys]
      for (const col of columns) {
        const k = getTableColumnKey(col)
        if (k && !keys.includes(k)) keys.push(k)
      }
      return keys
    }
    if (!row) return [...sys]
    const keys: string[] = [...sys]
    const seen = new Set<string>([...sys])
    for (const k of Object.keys(row.data)) {
      if (k.startsWith('$')) continue
      keys.push(k)
      seen.add(k)
    }
    for (const col of columns) {
      const k = getTableColumnKey(col)
      if (k && !seen.has(k)) {
        keys.push(k)
        seen.add(k)
      }
    }
    return keys
  }, [isCreateMode, row, columns])

  const [formData, setFormData] = useState<
    Record<string, string | number | bigint | boolean | unknown[] | null>
  >({})
  const [customRowId, setCustomRowId] = useState<string | undefined>(undefined)
  const fieldRefs = useRef<
    Record<
      string,
      | HTMLInputElement
      | HTMLSelectElement
      | HTMLButtonElement
      | HTMLTextAreaElement
      | null
    >
  >({})
  const scrollContainerRef = useRef<HTMLDivElement | null>(null)
  const [newlyAddedItem, setNewlyAddedItem] = useState<{
    key: string
    index: number
  } | null>(null)
  const [linkCopied, setLinkCopied] = useState(false)
  const [documentViewLinkCopied, setDocumentViewLinkCopied] = useState(false)
  const [documentJsonCopied, setDocumentJsonCopied] = useState(false)
  const [activeTab, setActiveTab] = useState('data')
  const [rowPermissions, setRowPermissions] = useState<string[]>([])
  const [documentJsonText, setDocumentJsonText] = useState('{}')
  const [documentJsonBaseline, setDocumentJsonBaseline] = useState('{}')
  const documentJsonEditorRef = useRef<CodeEditorRef>(null)
  const [jsonEditorCanUndo, setJsonEditorCanUndo] = useState(false)
  const [jsonEditorCanRedo, setJsonEditorCanRedo] = useState(false)
  const documentJsonUndoRedoDisposeRef = useRef<(() => void) | null>(null)

  const arrayDragSensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: 8 },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  // When drawer opens with initialTab (e.g. from "Update permissions" context menu), switch to that tab
  useEffect(() => {
    if (open && initialTab) {
      setActiveTab(initialTab)
    }
  }, [open, initialTab])

  // Initialize row permissions from row data
  useEffect(() => {
    if (row) {
      setRowPermissions(row.$permissions || [])
    } else {
      // Reset permissions when drawer closes or in create mode
      setRowPermissions([])
    }
  }, [row])

  useEffect(() => {
    if (presentation !== 'inline' || !open) return
    setJsonEditorCanUndo(false)
    setJsonEditorCanRedo(false)
    const next = row
      ? JSON.stringify(buildInlineDocumentJsonObjectFromRow(row), null, 2)
      : JSON.stringify(buildInlineDocumentJsonObjectForCreate(columns), null, 2)
    setDocumentJsonText(next)
    setDocumentJsonBaseline(next)
  }, [presentation, open, row?.$id, columns, row])

  // Update form data when row changes - only when row actually changes
  useEffect(() => {
    if (row) {
      // Preserve null values explicitly; normalize array columns (e.g. comma-separated string → array)
      const initialData: Record<
        string,
        string | number | bigint | boolean | unknown[] | null
      > = {}
      columns.forEach((column) => {
        const key = getTableColumnKey(column)
        if (!key || key.startsWith('$')) return

        const value = row.data[key]
        const columnInfo = getColumnInfo(key)
        initialData[key] =
          value === null || value === undefined
            ? null
            : (normalizeValueForColumn(value, columnInfo) as
              | string
              | number
              | boolean
              | unknown[]
              | null)
      })
      initialData['$createdAt'] = row.$createdAt ?? null
      initialData['$updatedAt'] = row.$updatedAt ?? null
      for (const col of columns) {
        const colKey = getTableColumnKey(col)
        if (!colKey || colKey in initialData) continue
        initialData[colKey] = defaultFormValueForColumn(col)
      }
      setFormData(initialData)
      fieldRefs.current = {}
      // Reset custom row ID when editing existing row
      setCustomRowId(undefined)
    } else {
      // Initialize form data from columns when creating a new row
      const initialData: Record<
        string,
        string | number | bigint | boolean | unknown[] | null
      > = {}
      columns.forEach((col: unknown) => {
        const colKey = getTableColumnKey(col)
        if (!colKey) return
        initialData[colKey] = defaultFormValueForColumn(col)
      })
      initialData['$createdAt'] = new Date().toISOString()
      initialData['$updatedAt'] = new Date().toISOString()
      setFormData(initialData)
      fieldRefs.current = {}
      // Reset custom row ID when creating new row
      setCustomRowId(undefined)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row?.$id, columns]) // Re-run when row ID changes or columns change

  // Switch to data tab when a field is focused (cell clicked)
  // Also set initial tab when drawer opens (unless parent passed initialTab e.g. "Update permissions")
  useEffect(() => {
    if (focusedField) {
      setActiveTab('data')
    } else if (open && !initialTab) {
      setActiveTab('data')
    }
  }, [focusedField, open, initialTab])

  // Focus the requested field when drawer opens, then place the caret at the
  // end of the existing value. The input's value is hydrated by a separate
  // effect watching `row?.$id`, so the value may arrive a tick after mount -
  // we poll a few frames until it's there before placing the caret.
  const lastFocusedSessionRef = useRef<string | null>(null)
  useEffect(() => {
    if (!open || !focusedField) return
    // A "session" is one focus request: drawer-open + focusedField + row.
    // Once we've placed the caret for this session, don't re-run on re-renders.
    const sessionKey = `${row?.$id ?? 'create'}::${focusedField}`
    if (lastFocusedSessionRef.current === sessionKey) return

    let cancelled = false
    let attempt = 0
    const MAX_ATTEMPTS = 6

    const tryPlaceCaret = () => {
      if (cancelled) return
      const el = fieldRefs.current[focusedField]
      if (!el) {
        if (attempt++ < MAX_ATTEMPTS) requestAnimationFrame(tryPlaceCaret)
        return
      }
      el.focus({ preventScroll: true })
      if (
        el instanceof HTMLInputElement ||
        el instanceof HTMLTextAreaElement
      ) {
        const len = el.value.length
        if (len === 0 && attempt++ < MAX_ATTEMPTS) {
          // Value hasn't been hydrated by the `row?.$id` effect yet; retry.
          requestAnimationFrame(tryPlaceCaret)
          return
        }
        if (len > 0) {
          try {
            el.setSelectionRange(len, len)
          } catch {
            // Number/email/etc. may reject selection APIs; ignore.
          }
        }
      }
      lastFocusedSessionRef.current = sessionKey
    }

    const rafId = requestAnimationFrame(() => {
      requestAnimationFrame(tryPlaceCaret)
    })
    return () => {
      cancelled = true
      cancelAnimationFrame(rafId)
    }
  }, [open, focusedField, row?.$id])

  // Reset the session marker so reopening the same field places the caret again.
  useEffect(() => {
    if (!open) lastFocusedSessionRef.current = null
  }, [open])

  // Focus newly added array item
  useEffect(() => {
    if (!newlyAddedItem) return

    const itemKey = `${newlyAddedItem.key}-${newlyAddedItem.index}`
    const el = fieldRefs.current[itemKey] as HTMLTextAreaElement | null

    if (el) {
      // Defer to ensure the element is fully rendered
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          el.focus()
          // Move cursor to end
          el.setSelectionRange(el.value.length, el.value.length)
          setNewlyAddedItem(null) // Clear after focusing
        })
      })
    }
  }, [newlyAddedItem])

  // Handle drawer open/close
  const handleOpenChange = (newOpen: boolean) => {
    // Don't reset form data here - let the useEffect handle it based on row changes
    // This prevents overwriting user edits when drawer opens/closes
    onOpenChange(newOpen)
  }

  const handleFieldChange = (
    key: string,
    value: string | number | bigint | boolean | unknown[] | null,
  ) => {
    setFormData((prev) => ({ ...prev, [key]: value }))
  }

  const handleNullToggle = (key: string, isNull: boolean) => {
    if (isNull) {
      // Set to null explicitly
      setFormData((prev) => ({ ...prev, [key]: null }))
    } else {
      // Unset null - restore to empty string (user can then type)
      setFormData((prev) => ({ ...prev, [key]: '' }))
    }
  }

  // Get column info for a field
  const getColumnInfo = (key: string) => {
    return columns.find((col: unknown) => {
      const colKey = getTableColumnKey(col)
      return colKey === key
    })
  }

  const isColumnRequired = (
    columnInfo?: Record<string, unknown> | null,
  ): boolean => {
    return (
      columnInfo?.required === true ||
      columnInfo?.required === 'true' ||
      columnInfo?.isRequired === true ||
      columnInfo?.isRequired === 'true' ||
      columnInfo?.nullable === false ||
      columnInfo?.nullable === 'false'
    )
  }

  // Detect RTL content
  const isRTL = (text: string | null | undefined): boolean => {
    if (!text || typeof text !== 'string') return false
    // Check for RTL characters (Arabic, Hebrew, etc.)
    const rtlPattern =
      /[\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
    return rtlPattern.test(text)
  }

  const handleArrayItemChange = (
    key: string,
    index: number,
    value: string | number | boolean | null,
  ) => {
    const currentArray = (formData[key] as unknown[]) || []
    const newArray = [...currentArray]
    newArray[index] = value
    handleFieldChange(key, newArray)
  }

  const handleAddArrayItem = (key: string) => {
    const currentArray = (formData[key] as unknown[]) || []
    const newIndex = currentArray.length
    const col = getColumnInfo(key) as { type?: string } | undefined
    const defaultItem =
      col?.type === 'boolean' || col?.type === 'bool' ? false : ''
    handleFieldChange(key, [...currentArray, defaultItem])
    setNewlyAddedItem({ key, index: newIndex })
  }

  const handleRemoveArrayItem = (key: string, index: number) => {
    const currentArray = (formData[key] as unknown[]) || []
    const newArray = currentArray.filter((_, i) => i !== index)
    handleFieldChange(key, newArray)
  }

  const handleArrayItemsDragEnd = useCallback(
    (fieldKey: string, event: DragEndEvent) => {
      const { active, over } = event
      if (!over || active.id === over.id) return
      const activeId = String(active.id)
      const overId = String(over.id)
      setFormData((prev) => {
        const arr = (prev[fieldKey] as unknown[]) || []
        const sortIds = arr.map((_, i) => arrayFieldSortableId(fieldKey, i))
        const oldIndex = sortIds.indexOf(activeId)
        const newIndex = sortIds.indexOf(overId)
        if (oldIndex < 0 || newIndex < 0 || oldIndex === newIndex) return prev
        return {
          ...prev,
          [fieldKey]: arrayMove(arr, oldIndex, newIndex),
        }
      })
    },
    [],
  )

  const handlePrettifyDocumentJson = () => {
    try {
      const parsed = JSON.parse(documentJsonText.trim() || '{}')
      setDocumentJsonText(JSON.stringify(parsed, null, 2))
    } catch {
      toast.error(t('Invalid JSON'))
    }
  }

  const handleCopyDocumentViewLink = () => {
    if (!row) return
    const u = new URL(window.location.href)
    u.hash = `row-${row.$id}`
    void navigator.clipboard.writeText(u.toString())
    setDocumentViewLinkCopied(true)
    setTimeout(() => setDocumentViewLinkCopied(false), 2000)
    toast.success(t('Link copied'))
  }

  const handleCopyDocumentJson = async () => {
    try {
      await navigator.clipboard.writeText(documentJsonText)
      setDocumentJsonCopied(true)
      setTimeout(() => setDocumentJsonCopied(false), 2000)
      toast.success(t('JSON copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy JSON'))
    }
  }

  const handleDocumentJsonEditorMount = useCallback(
    (ed: editor.IStandaloneCodeEditor) => {
      documentJsonUndoRedoDisposeRef.current?.()
      documentJsonUndoRedoDisposeRef.current = null

      const refresh = () => {
        queueMicrotask(() => {
          const model = ed.getModel()
          setJsonEditorCanUndo(model?.canUndo() ?? false)
          setJsonEditorCanRedo(model?.canRedo() ?? false)
        })
      }

      refresh()
      const d1 = ed.onDidChangeModelContent(() => refresh())
      const d2 = ed.onDidChangeModel(() => refresh())

      documentJsonUndoRedoDisposeRef.current = () => {
        d1.dispose()
        d2.dispose()
        documentJsonUndoRedoDisposeRef.current = null
      }
    },
    [],
  )

  useEffect(() => {
    return () => documentJsonUndoRedoDisposeRef.current?.()
  }, [])

  const handleDocumentJsonUndo = useCallback(() => {
    const ed = documentJsonEditorRef.current?.getEditor()
    const model = ed?.getModel()
    if (!model?.canUndo()) return
    void model.undo()
  }, [])

  const handleDocumentJsonRedo = useCallback(() => {
    const ed = documentJsonEditorRef.current?.getEditor()
    const model = ed?.getModel()
    if (!model?.canRedo()) return
    void model.redo()
  }, [])

  const handleRevertInlineDocumentChanges = useCallback(() => {
    setDocumentJsonText(documentJsonBaseline)
    try {
      const parsed = JSON.parse(documentJsonBaseline.trim() || '{}') as unknown
      if (
        parsed !== null &&
        typeof parsed === 'object' &&
        !Array.isArray(parsed) &&
        '$permissions' in parsed
      ) {
        const p = (parsed as { $permissions: unknown }).$permissions
        setRowPermissions(
          Array.isArray(p)
            ? (p as unknown[]).filter((x): x is string => typeof x === 'string')
            : [],
        )
      } else {
        setRowPermissions(row?.$permissions ?? [])
      }
    } catch {
      setRowPermissions(row?.$permissions ?? [])
    }
  }, [documentJsonBaseline, row])

  const handlePermissionsChange = (next: string[]) => {
    setRowPermissions(next)
    if (presentation === 'inline') {
      setDocumentJsonText((prev) => {
        try {
          const o = JSON.parse(prev.trim() || '{}') as unknown
          if (o === null || typeof o !== 'object' || Array.isArray(o))
            return prev
          ;(o as Record<string, unknown>).$permissions = next
          return JSON.stringify(o, null, 2)
        } catch {
          return prev
        }
      })
    }
  }

  const handleSave = () => {
    // For create mode, pass customRowId if set, otherwise pass null to use auto-generated
    // For update mode, pass the existing row ID
    const idToSave = isCreateMode ? customRowId || null : row?.$id || null
    // Always pass permissions when updating (even if empty, to allow clearing permissions)
    // For create mode, only pass if permissions are set
    const permissionsToSave = isCreateMode
      ? rowPermissions.length > 0
        ? rowPermissions
        : undefined
      : rowPermissions // Always pass for updates, even if empty
    const now = new Date().toISOString()

    if (presentation === 'inline') {
      let parsed: unknown
      try {
        parsed = JSON.parse(documentJsonText.trim() || '{}')
      } catch {
        toast.error(t('Invalid JSON'))
        return
      }
      if (
        parsed === null ||
        typeof parsed !== 'object' ||
        Array.isArray(parsed)
      ) {
        toast.error(t('Document data must be a JSON object'))
        return
      }
      const fromJson = parsed as Record<string, unknown>
      const permsFromJson = Array.isArray(fromJson.$permissions)
        ? (fromJson.$permissions as string[])
        : rowPermissions
      const permissionsInline = isCreateMode
        ? permsFromJson.length > 0
          ? permsFromJson
          : undefined
        : permsFromJson
      const customIdFromJson =
        isCreateMode &&
        typeof fromJson.$id === 'string' &&
        fromJson.$id.trim() !== ''
          ? fromJson.$id.trim()
          : undefined
      const idToSaveInline = isCreateMode ? null : row?.$id || null
      const payload: Record<string, unknown> = { ...fromJson }
      delete payload.$id
      delete payload.$permissions
      delete payload.$sequence
      onSave(
        idToSaveInline,
        payload as Record<string, string | number | bigint | boolean | unknown[] | null>,
        customIdFromJson,
        permissionsInline,
      )
      return
    }

    const payload = { ...formData }
    const allowedFieldKeys = new Set(
      columns
        .map((column) => getTableColumnKey(column))
        .filter((key): key is string => Boolean(key && !key.startsWith('$'))),
    )
    const missingRequiredFields: string[] = []

    columns.forEach((col: unknown) => {
      const columnInfo = col as Record<string, unknown>
      const colKey =
        columnInfo.key ||
        columnInfo.name ||
        columnInfo.$id ||
        columnInfo.attribute ||
        columnInfo.attributeId

      if (!colKey || String(colKey).startsWith('$')) return

      const fieldKey = String(colKey)
      const currentValue = payload[fieldKey]
      const fieldType = getFieldType(
        fieldKey,
        currentValue as string | number | bigint | boolean | unknown[] | null,
        columnInfo,
      )
      const required = isColumnRequired(columnInfo)
      const isEmptyValue =
        currentValue === null ||
        currentValue === undefined ||
        (typeof currentValue === 'string' && currentValue.trim() === '') ||
        (Array.isArray(currentValue) && currentValue.length === 0)

      if (required && fieldType !== 'boolean' && isEmptyValue) {
        missingRequiredFields.push(fieldKey)
        return
      }

      if (!required && isEmptyValue) {
        payload[fieldKey] = null
      }
    })

    Object.keys(payload).forEach((key) => {
      if (key.startsWith('$')) return
      if (!allowedFieldKeys.has(key)) {
        delete payload[key]
      }
    })

    if (missingRequiredFields.length > 0) {
      toast.error(`${t('Required fields')}: ${missingRequiredFields.join(', ')}`)
      return
    }

    if (
      payload['$createdAt'] === null ||
      payload['$createdAt'] === undefined ||
      payload['$createdAt'] === ''
    ) {
      payload['$createdAt'] = now
    }
    if (
      payload['$updatedAt'] === null ||
      payload['$updatedAt'] === undefined ||
      payload['$updatedAt'] === ''
    ) {
      payload['$updatedAt'] = now
    }
    onSave(idToSave, payload, customRowId, permissionsToSave)
    // Don't close drawer here - wait for mutation to complete
  }

  const getFieldType = (
    key: string,
    value: string | number | bigint | boolean | unknown[] | null,
    columnInfo?: unknown,
  ): string => {
    if (key === '$createdAt' || key === '$updatedAt') return 'datetime'
    const col = columnInfo as { array?: boolean; type?: string } | undefined
    // Array columns always use the array UI (add/remove items per element)
    if (col?.array) return 'array'
    if (
      col?.type === 'array' ||
      (typeof col?.type === 'string' && col.type.endsWith('[]'))
    )
      return 'array'
    // Use column type from metadata if available (element type for arrays)
    if (col?.type) return col.type
    // Fallback to value-based inference (e.g. API returned actual array)
    if (Array.isArray(value)) return 'array'
    if (typeof value === 'boolean') return 'boolean'
    if (typeof value === 'number') return 'number'
    return 'string'
  }

  const getEnumOptions = (columnInfo?: unknown): string[] => {
    if (columnInfo?.elements && Array.isArray(columnInfo.elements)) {
      return columnInfo.elements
    }
    // Fallback for legacy support
    return []
  }

  const rowEditorTitle =
    isCreateMode ? dbLabels.createRecord : dbLabels.updateRecord

  const inlineDocumentDirty =
    presentation === 'inline' && documentJsonText !== documentJsonBaseline

  const rowEditorHeaderActions = !isCreateMode ? (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="sm"
            className="h-8 w-8 p-0 cursor-pointer"
            onClick={() => {
              const currentUrl = window.location.href
              navigator.clipboard.writeText(currentUrl)
              setLinkCopied(true)
              setTimeout(() => setLinkCopied(false), 2000)
            }}
          >
            {linkCopied ? (
              <Check className="h-4 w-4 text-emerald-500" />
            ) : (
              <Link2 className="h-4 w-4 text-muted-foreground" />
            )}
          </Button>
        </TooltipTrigger>
        <TooltipContent>
          <p>{linkCopied ? t('Link copied!') : t('Copy link')}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ) : undefined

  const rowEditorBody = (
    <>
      {presentation !== 'inline' ? (
        <div className="border-t border-border shrink-0" />
      ) : null}

      <Tabs
          value={activeTab}
          onValueChange={(v) => setActiveTab(v as 'data' | 'permissions')}
          className="flex min-h-0 flex-1 flex-col gap-0"
        >
          <div className="shrink-0 border-b border-border px-3 pb-3 pt-3 sm:px-6 sm:pb-4 sm:pt-4">
            <TabsList className="w-full grid grid-cols-2 h-9">
              <TabsTrigger value="data" className="text-[13px]">
                {t('Data')}
              </TabsTrigger>
              <TabsTrigger value="permissions" className="text-[13px]">
                {t('Permissions')}
              </TabsTrigger>
            </TabsList>
          </div>

          <div
            ref={scrollContainerRef}
            className={cn(
              'min-h-0 flex-1',
              presentation === 'inline' && 'relative',
              presentation === 'inline' && activeTab === 'data'
                ? 'flex flex-col overflow-hidden'
                : 'overflow-y-auto',
            )}
          >
            <TabsContent
              value="data"
              className={cn(
                'mt-0 flex-1 outline-none data-[state=inactive]:hidden',
                presentation === 'inline' && 'flex min-h-0 flex-col',
              )}
            >
              {presentation === 'inline' ? (
                <div
                  className={cn(
                    'relative flex min-h-0 min-w-0 flex-1 flex-col',
                    (inlineDocumentDirty || isCreateMode) &&
                      useCompactInlineJsonToolbar &&
                      'pb-[4.5rem]',
                  )}
                >
                  <div
                    className={cn(
                      'z-10 flex gap-1.5 sm:gap-2',
                      useCompactInlineJsonToolbar
                        ? 'pointer-events-auto relative shrink-0 flex-row flex-wrap justify-end border-b border-border bg-background/95 px-2 py-2 backdrop-blur-sm'
                        : 'pointer-events-none absolute end-3 top-4 flex-col p-1 sm:end-6 sm:top-5',
                    )}
                  >
                    <TooltipProvider delayDuration={0}>
                      <div className="pointer-events-auto overflow-hidden rounded-lg border border-border bg-background/90 shadow-sm backdrop-blur-sm">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 cursor-pointer rounded-none p-0"
                              disabled={isSaving || !jsonEditorCanUndo}
                              aria-label={t('Undo')}
                              onMouseDown={(e) => e.preventDefault()}
                              onClick={handleDocumentJsonUndo}
                            >
                              <Undo2 className="h-4 w-4 text-muted-foreground" />
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent side={jsonToolbarTooltipSide}>
                            <p>{t('Undo')}</p>
                          </TooltipContent>
                        </Tooltip>
                      </div>
                      <div className="pointer-events-auto overflow-hidden rounded-lg border border-border bg-background/90 shadow-sm backdrop-blur-sm">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 cursor-pointer rounded-none p-0"
                              disabled={isSaving || !jsonEditorCanRedo}
                              aria-label={t('Redo')}
                              onMouseDown={(e) => e.preventDefault()}
                              onClick={handleDocumentJsonRedo}
                            >
                              <Redo2 className="h-4 w-4 text-muted-foreground" />
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent side={jsonToolbarTooltipSide}>
                            <p>{t('Redo')}</p>
                          </TooltipContent>
                        </Tooltip>
                      </div>
                      <div className="pointer-events-auto overflow-hidden rounded-lg border border-border bg-background/90 shadow-sm backdrop-blur-sm">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 cursor-pointer rounded-none p-0"
                              onClick={handlePrettifyDocumentJson}
                              disabled={isSaving}
                              aria-label={t('Prettify JSON')}
                            >
                              <Braces className="h-4 w-4 text-muted-foreground" />
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent side={jsonToolbarTooltipSide}>
                            <p>{t('Prettify JSON')}</p>
                          </TooltipContent>
                        </Tooltip>
                      </div>
                      <div className="pointer-events-auto overflow-hidden rounded-lg border border-border bg-background/90 shadow-sm backdrop-blur-sm">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 cursor-pointer rounded-none p-0"
                              onClick={() => void handleCopyDocumentJson()}
                              disabled={isSaving}
                              aria-label={t('Copy JSON')}
                            >
                              {documentJsonCopied ? (
                                <Check className="h-4 w-4 text-emerald-500" />
                              ) : (
                                <Copy className="h-4 w-4 text-muted-foreground" />
                              )}
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent side={jsonToolbarTooltipSide}>
                            <p>
                              {documentJsonCopied
                                ? t('Copied!')
                                : t('Copy JSON')}
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      </div>
                      <div className="pointer-events-auto overflow-hidden rounded-lg border border-border bg-background/90 shadow-sm backdrop-blur-sm">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 cursor-pointer rounded-none p-0"
                              onClick={handleCopyDocumentViewLink}
                              disabled={isSaving || !row}
                              aria-label={t('Copy link to document')}
                            >
                              {documentViewLinkCopied ? (
                                <Check className="h-4 w-4 text-emerald-500" />
                              ) : (
                                <Link2 className="h-4 w-4 text-muted-foreground" />
                              )}
                            </Button>
                          </TooltipTrigger>
                          <TooltipContent side={jsonToolbarTooltipSide}>
                            <p>
                              {documentViewLinkCopied
                                ? t('Link copied!')
                                : t('Copy link to document')}
                            </p>
                          </TooltipContent>
                        </Tooltip>
                      </div>
                    </TooltipProvider>
                  </div>
                  <CodeEditor
                    key={row?.$id ?? 'new'}
                    ref={documentJsonEditorRef}
                    modelPath={
                      row
                        ? `inmemory://console-document-json/${row.$id}`
                        : 'inmemory://console-document-json/new'
                    }
                    value={documentJsonText}
                    onChange={setDocumentJsonText}
                    language="json"
                    lineNumbers={useCompactInlineJsonToolbar ? 'off' : 'on'}
                    height="100%"
                    onEditorMount={handleDocumentJsonEditorMount}
                    className="h-full min-h-0 flex-1 rounded-none border-0 shadow-none"
                  />
                </div>
              ) : (
              <div className="px-6 py-6">
                <div className="space-y-5">
                  {/* System fields (read-only) - only when updating a row */}
                  {!isCreateMode && row && (
                    <div className="space-y-3">
                      <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-3">
                        <div>
                          <Label className="text-[11px] text-muted-foreground">
                            $id
                          </Label>
                          <div className="mt-1">
                            <CopyableId id={row.$id} size="sm" />
                          </div>
                        </div>
                        {!hideSequenceInEditor ? (
                          <div>
                            <Label className="text-[11px] text-muted-foreground">
                              {t('Row #')}
                            </Label>
                            <div className="mt-1">
                              <CopyableId
                                id={String(row.$sequence ?? row.rowNumber ?? '')}
                                size="sm"
                              />
                            </div>
                          </div>
                        ) : null}
                      </div>
                    </div>
                  )}

                  {/* ID Input - Only shown in create mode */}
                  {isCreateMode && (
                    <div className="space-y-2">
                      <Label
                        className="text-[12px] font-medium text-foreground"
                        htmlFor="row-id"
                      >
                        {dbLabels.recordIdLabel}
                      </Label>
                      <IdInput
                        id="row-id"
                        value={customRowId}
                        onChange={setCustomRowId}
                        maxLength={36}
                        disabled={isSaving}
                        placeholder={t('Leave blank to auto-generate')}
                      />
                    </div>
                  )}

                  {/* Editable fields */}
                  <div className="space-y-3">
                    <h4 className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      {t('Row Data')}
                    </h4>
                    <div className="space-y-4">
                      {dataTabFieldKeys.map((key) => {
                        const value = isCreateMode
                          ? formData[key]
                          : key === '$createdAt' || key === '$updatedAt'
                            ? (row as RowData)[key as keyof RowData]
                            : row.data[key]
                        const columnInfo = getColumnInfo(key)
                        const currentValue =
                          key in formData
                            ? formData[key]
                            : key === '$createdAt' || key === '$updatedAt'
                              ? value
                              : columnInfo !== undefined
                                ? defaultFormValueForColumn(columnInfo)
                                : value
                        const fieldType = getFieldType(
                          key,
                          currentValue as string | number | bigint | boolean | unknown[] | null,
                          columnInfo,
                        )
                        const shouldFocus = focusedField === key
                        // Check multiple possible properties for required status
                        const isRequired = isColumnRequired(
                          columnInfo as Record<string, unknown> | undefined,
                        )

                        const arrayLength =
                          fieldType === 'array'
                            ? ((currentValue as unknown[]) || []).length
                            : 0

                        return (
                          <div key={key} className="space-y-1.5">
                            <Label
                              htmlFor={key}
                              className="text-[12px] font-medium text-foreground flex items-center gap-1.5"
                            >
                              <span>{key}</span>
                              {fieldType === 'array' && (
                                <span className="inline-flex items-center gap-1 rounded border border-border bg-muted/50 px-1.5 py-px text-[10px] font-medium text-muted-foreground">
                                  <Brackets className="h-3.5 w-3.5 shrink-0 opacity-70" />
                                  Array
                                  {arrayLength > 0 && (
                                    <span className="tabular-nums">
                                      · {arrayLength}
                                    </span>
                                  )}
                                </span>
                              )}
                              {isRequired && (
                                <span
                                  className="text-destructive text-[12px] font-semibold ms-0.5"
                                  aria-label={t('Required field')}
                                >
                                  *
                                </span>
                              )}
                            </Label>

                            {fieldType === 'boolean' ? (
                              <div className="flex items-center gap-2">
                                <Switch
                                  id={key}
                                  checked={currentValue as boolean}
                                  onCheckedChange={(checked) =>
                                    handleFieldChange(key, checked)
                                  }
                                />
                                <span className="text-[12px] text-muted-foreground">
                                  {currentValue ? t('True') : t('False')}
                                </span>
                              </div>
                            ) : fieldType === 'enum' ? (
                              <Select
                                value={currentValue ? String(currentValue) : ''}
                                onValueChange={(val) =>
                                  handleFieldChange(
                                    key,
                                    val === 'null' ? null : val,
                                  )
                                }
                              >
                                <SelectTrigger
                                  className="h-9 text-[13px]"
                                  ref={(el) => {
                                    fieldRefs.current[key] = el
                                  }}
                                  autoFocus={shouldFocus}
                                >
                                  <SelectValue
                                    placeholder={
                                      isRequired ? undefined : 'NULL'
                                    }
                                  />
                                </SelectTrigger>
                                <SelectContent>
                                  {!isRequired && (
                                    <SelectItem value="null">NULL</SelectItem>
                                  )}
                                  {getEnumOptions(columnInfo).map((option) => (
                                    <SelectItem key={option} value={option}>
                                      {option}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            ) : fieldType === 'integer' ||
                              fieldType === 'bigint' ||
                              fieldType === 'double' ||
                              fieldType === 'number' ? (
                              <div className="space-y-1.5">
                                <Input
                                  id={key}
                                  type={
                                    fieldType === 'bigint' ? 'text' : 'number'
                                  }
                                  inputMode="numeric"
                                  value={
                                    currentValue !== null &&
                                      currentValue !== undefined
                                      ? String(currentValue)
                                      : ''
                                  }
                                  ref={(el) => {
                                    fieldRefs.current[key] = el
                                  }}
                                  autoFocus={shouldFocus}
                                  onChange={(e) => {
                                    const val =
                                      e.target.value === ''
                                        ? null
                                        : fieldType === 'bigint'
                                          ? (() => {
                                              try {
                                                return BigInt(e.target.value)
                                              } catch {
                                                return null
                                              }
                                            })()
                                          : fieldType === 'integer'
                                            ? parseInt(e.target.value, 10) ||
                                              null
                                            : parseFloat(e.target.value) ||
                                              null
                                    handleFieldChange(key, val)
                                  }}
                                  min={
                                    fieldType === 'bigint'
                                      ? undefined
                                      : columnInfo?.min
                                  }
                                  max={
                                    fieldType === 'bigint'
                                      ? undefined
                                      : columnInfo?.max
                                  }
                                  step={fieldType === 'double' ? 0.1 : 1}
                                  placeholder={isRequired ? undefined : 'NULL'}
                                  className="h-9 text-[13px]"
                                />
                                {!isRequired && currentValue === null && (
                                  <p className="text-[11px] text-muted-foreground">
                                    NULL
                                  </p>
                                )}
                              </div>
                            ) : fieldType === 'array' ? (
                              <div className="overflow-hidden rounded-md border border-border bg-card">
                                {((currentValue as unknown[]) || []).length >
                                  0 ? (
                                  <DndContext
                                    sensors={arrayDragSensors}
                                    collisionDetection={closestCenter}
                                    onDragEnd={(e) =>
                                      handleArrayItemsDragEnd(key, e)
                                    }
                                  >
                                    <SortableContext
                                      items={(
                                        (currentValue as unknown[]) || []
                                      ).map((_, i) =>
                                        arrayFieldSortableId(key, i),
                                      )}
                                      strategy={
                                        verticalListSortingStrategy
                                      }
                                    >
                                      <div className="divide-y divide-foreground/10">
                                        {(
                                          (currentValue as unknown[]) || []
                                        ).map((item, index) => (
                                        <RowEditArraySortableRow
                                          key={arrayFieldSortableId(
                                            key,
                                            index,
                                          )}
                                          id={arrayFieldSortableId(
                                            key,
                                            index,
                                          )}
                                          index={index}
                                        >
                                          {(() => {
                                        const columnInfo = getColumnInfo(key)
                                        const size = columnInfo?.size || null
                                        const rawType = ((
                                          columnInfo as { type?: string }
                                        )?.type || 'string') as string
                                        const colType = rawType.endsWith('[]')
                                          ? rawType.slice(0, -2)
                                          : rawType
                                        const isRequired = isColumnRequired(
                                          columnInfo as
                                            | Record<string, unknown>
                                            | undefined,
                                        )
                                        const isNull = item === null
                                        const stringValue = isNull
                                          ? ''
                                          : String(item ?? '')
                                        const charCount = stringValue.length
                                        const hasLimit =
                                          (colType === 'string' ||
                                            colType === 'varchar') &&
                                          size !== null &&
                                          size > 0
                                        const isRTLContent = isRTL(stringValue)
                                        const showNullCheckbox = !isRequired
                                        const setRef = (
                                          el:
                                            | HTMLInputElement
                                            | HTMLSelectElement
                                            | HTMLButtonElement
                                            | HTMLTextAreaElement
                                            | null,
                                        ) => {
                                          const itemKey = `${key}-${index}`
                                          if (el) {
                                            fieldRefs.current[itemKey] = el
                                            if (index === 0)
                                              fieldRefs.current[key] = el
                                          } else {
                                            delete fieldRefs.current[itemKey]
                                            if (index === 0)
                                              delete fieldRefs.current[key]
                                          }
                                        }
                                        const focusGuard = () => {
                                          const scrollContainer =
                                            scrollContainerRef.current
                                          if (scrollContainer) {
                                            const scrollTop =
                                              scrollContainer.scrollTop
                                            const scrollLeft =
                                              scrollContainer.scrollLeft
                                            requestAnimationFrame(() => {
                                              scrollContainer.scrollTop =
                                                scrollTop
                                              scrollContainer.scrollLeft =
                                                scrollLeft
                                            })
                                          }
                                        }

                                        const isNumericType =
                                          colType === 'integer' ||
                                          colType === 'int' ||
                                          colType === 'bigint' ||
                                          colType === 'double' ||
                                          colType === 'float' ||
                                          colType === 'number'
                                        const isBoolType =
                                          colType === 'boolean' ||
                                          colType === 'bool'
                                        const isEnumType = colType === 'enum'
                                        const isDateTimeType =
                                          colType === 'datetime' ||
                                          colType === 'date'
                                        const showFooter =
                                          !isBoolType &&
                                          !isEnumType &&
                                          (hasLimit || showNullCheckbox)

                                            return (
                                              <>
                                            <div className="flex w-9 shrink-0 select-none items-center justify-center border-e border-foreground/10 bg-muted/40 text-[11px] font-mono tabular-nums text-muted-foreground">
                                              {index + 1}
                                            </div>
                                            <div className="flex min-w-0 flex-1 flex-col">
                                              <div className="min-w-0 flex-1">
                                                {isNumericType ? (
                                                  <Input
                                                    type={
                                                      colType === 'bigint'
                                                        ? 'text'
                                                        : 'number'
                                                    }
                                                    inputMode="numeric"
                                                    value={
                                                      isNull
                                                        ? ''
                                                        : String(item ?? '')
                                                    }
                                                    ref={(el) => setRef(el)}
                                                    autoFocus={
                                                      shouldFocus && index === 0
                                                    }
                                                    disabled={isNull}
                                                    onFocus={focusGuard}
                                                    onChange={(e) => {
                                                      const v = e.target.value
                                                      handleArrayItemChange(
                                                        key,
                                                        index,
                                                        v === ''
                                                          ? null
                                                          : colType ===
                                                              'bigint'
                                                            ? (() => {
                                                                try {
                                                                  return BigInt(
                                                                    v,
                                                                  )
                                                                } catch {
                                                                  return null
                                                                }
                                                              })()
                                                            : colType ===
                                                                'integer' ||
                                                                colType ===
                                                                  'int'
                                                              ? parseInt(v, 10)
                                                              : parseFloat(v),
                                                      )
                                                    }}
                                                    step={
                                                      colType === 'double' ||
                                                        colType === 'float'
                                                        ? 0.1
                                                        : 1
                                                    }
                                                    placeholder={`Item ${index + 1}`}
                                                    className="h-9 rounded-none border-0 bg-transparent px-3 text-[13px] focus-visible:ring-0 focus-visible:ring-offset-0"
                                                  />
                                                ) : isBoolType ? (
                                                  <div className="flex h-9 items-center gap-2 px-3">
                                                    <Switch
                                                      checked={item === true}
                                                      onCheckedChange={(
                                                        checked,
                                                      ) =>
                                                        handleArrayItemChange(
                                                          key,
                                                          index,
                                                          checked,
                                                        )
                                                      }
                                                    />
                                                    <span className="text-[12px] text-muted-foreground">
                                                      {item === true
                                                        ? t('True')
                                                        : t('False')}
                                                    </span>
                                                  </div>
                                                ) : isEnumType ? (
                                                  <Select
                                                    value={
                                                      isNull
                                                        ? 'null'
                                                        : String(item ?? '')
                                                    }
                                                    onValueChange={(val) =>
                                                      handleArrayItemChange(
                                                        key,
                                                        index,
                                                        val === 'null'
                                                          ? null
                                                          : val,
                                                      )
                                                    }
                                                  >
                                                    <SelectTrigger
                                                      className="h-9 rounded-none border-0 bg-transparent px-3 text-[13px] focus:ring-0 focus:ring-offset-0"
                                                      ref={(el) => setRef(el)}
                                                    >
                                                      <SelectValue
                                                        placeholder={
                                                          isRequired
                                                            ? undefined
                                                            : 'NULL'
                                                        }
                                                      />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                      {!isRequired && (
                                                        <SelectItem value="null">
                                                          NULL
                                                        </SelectItem>
                                                      )}
                                                      {getEnumOptions(
                                                        columnInfo,
                                                      ).map((option) => (
                                                        <SelectItem
                                                          key={option}
                                                          value={option}
                                                        >
                                                          {option}
                                                        </SelectItem>
                                                      ))}
                                                    </SelectContent>
                                                  </Select>
                                                ) : isDateTimeType ? (
                                                  <DateTimePicker
                                                    value={
                                                      isNull
                                                        ? null
                                                        : (item as
                                                          | string
                                                          | null)
                                                    }
                                                    onChange={(val) =>
                                                      handleArrayItemChange(
                                                        key,
                                                        index,
                                                        val,
                                                      )
                                                    }
                                                    triggerRef={(el) =>
                                                      setRef(el)
                                                    }
                                                    autoFocus={
                                                      shouldFocus && index === 0
                                                    }
                                                    onFocus={focusGuard}
                                                    disabled={isNull}
                                                    clearable={!isRequired}
                                                    placeholder={`Item ${index + 1}`}
                                                    className="h-9 rounded-none border-0 bg-transparent px-3 text-[13px] hover:bg-transparent focus-visible:ring-0 focus-visible:ring-offset-0"
                                                  />
                                                ) : (
                                                  <Textarea
                                                    value={stringValue}
                                                    onChange={(e) => {
                                                      e.stopPropagation()
                                                      handleArrayItemChange(
                                                        key,
                                                        index,
                                                        e.target.value,
                                                      )
                                                    }}
                                                    onFocus={focusGuard}
                                                    ref={(el) => setRef(el)}
                                                    autoFocus={
                                                      shouldFocus && index === 0
                                                    }
                                                    disabled={isNull}
                                                    dir={
                                                      isRTLContent ? 'rtl' : 'ltr'
                                                    }
                                                    maxLength={
                                                      hasLimit ? size : undefined
                                                    }
                                                    className={cn(
                                                      'min-h-[36px] max-h-[600px] resize-none rounded-none border-0 bg-transparent px-3 py-2 text-[13px] focus-visible:ring-0 focus-visible:ring-offset-0',
                                                      isNull &&
                                                      'cursor-not-allowed opacity-50',
                                                    )}
                                                    placeholder={`Item ${index + 1}`}
                                                    rows={1}
                                                  />
                                                )}
                                              </div>
                                              {showFooter && (
                                                <div className="flex items-center justify-end gap-3 border-t border-foreground/10 bg-muted/30 px-3 py-1">
                                                  {hasLimit && (
                                                    <span
                                                      className={cn(
                                                        'text-[10px] tabular-nums whitespace-nowrap',
                                                        charCount > size
                                                          ? 'font-medium text-destructive'
                                                          : 'text-muted-foreground',
                                                      )}
                                                    >
                                                      {charCount}/{size}
                                                    </span>
                                                  )}
                                                  {showNullCheckbox && (
                                                    <label
                                                      htmlFor={`${key}-${index}-null`}
                                                      className="flex cursor-pointer select-none items-center gap-1.5 text-[10px] text-muted-foreground"
                                                    >
                                                      <Checkbox
                                                        id={`${key}-${index}-null`}
                                                        checked={isNull}
                                                        onCheckedChange={(
                                                          checked,
                                                        ) => {
                                                          const newArray = [
                                                            ...((currentValue as unknown[]) ||
                                                              []),
                                                          ]
                                                          newArray[index] =
                                                            checked ? null : ''
                                                          handleFieldChange(
                                                            key,
                                                            newArray,
                                                          )
                                                        }}
                                                        onClick={(e) =>
                                                          e.stopPropagation()
                                                        }
                                                        className="h-3 w-3 cursor-pointer"
                                                        disabled={false}
                                                      />
                                                      {t('Null')}
                                                    </label>
                                                  )}
                                                </div>
                                              )}
                                            </div>
                                            <button
                                              type="button"
                                              aria-label={`Remove item ${index + 1}`}
                                              className="flex w-9 shrink-0 cursor-pointer items-center justify-center border-s border-foreground/10 text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground"
                                              onClick={() =>
                                                handleRemoveArrayItem(
                                                  key,
                                                  index,
                                                )
                                              }
                                            >
                                              <X className="h-3.5 w-3.5" />
                                            </button>
                                              </>
                                            )
                                          })()}
                                        </RowEditArraySortableRow>
                                        ))}
                                      </div>
                                    </SortableContext>
                                  </DndContext>
                                ) : (
                                  <div className="flex flex-col items-center justify-center gap-1.5 px-3 py-6 text-center">
                                    <Brackets className="h-4 w-4 text-muted-foreground/60" />
                                    <p className="text-[12px] text-muted-foreground">
                                      {t('No items in this array yet')}
                                    </p>
                                  </div>
                                )}
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => handleAddArrayItem(key)}
                                  className="h-9 w-full cursor-pointer justify-center rounded-none border-t border-foreground/10 bg-muted/30 text-[12px] text-muted-foreground hover:bg-muted/50 hover:text-foreground"
                                >
                                  <Plus className="me-1.5 h-3.5 w-3.5" />
                                  {t('Add item')}
                                </Button>
                              </div>
                            ) : fieldType === 'datetime' ? (
                              <DateTimePicker
                                id={key}
                                value={(currentValue as string | null) ?? null}
                                onChange={(val) => handleFieldChange(key, val)}
                                triggerRef={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                clearable={!isRequired}
                                placeholder={
                                  isRequired
                                    ? t('Select date & time')
                                    : 'NULL'
                                }
                              />
                            ) : fieldType === 'email' ? (
                              <Input
                                id={key}
                                type="email"
                                value={currentValue ? String(currentValue) : ''}
                                ref={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                onChange={(e) => {
                                  const val = e.target.value || null
                                  handleFieldChange(key, val)
                                }}
                                placeholder={isRequired ? undefined : 'NULL'}
                                className="h-9 text-[13px]"
                              />
                            ) : fieldType === 'url' ? (
                              <Input
                                id={key}
                                type="url"
                                value={currentValue ? String(currentValue) : ''}
                                ref={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                onChange={(e) => {
                                  const val = e.target.value || null
                                  handleFieldChange(key, val)
                                }}
                                placeholder={isRequired ? undefined : 'NULL'}
                                className="h-9 text-[13px]"
                              />
                            ) : fieldType === 'ip' ? (
                              <Input
                                id={key}
                                type="text"
                                value={currentValue ? String(currentValue) : ''}
                                ref={(el) => {
                                  fieldRefs.current[key] = el
                                }}
                                autoFocus={shouldFocus}
                                onChange={(e) => {
                                  const val = e.target.value || null
                                  handleFieldChange(key, val)
                                }}
                                placeholder={isRequired ? undefined : 'NULL'}
                                className="h-9 text-[13px]"
                              />
                            ) : fieldType === 'relationship' ? (
                              <RelationshipField
                                columnInfo={columnInfo}
                                currentValue={
                                  (currentValue as string | unknown[] | null) ??
                                  null
                                }
                                isRequired={isRequired}
                                isSaving={isSaving}
                                onChange={(val) => handleFieldChange(key, val)}
                              />
                            ) : fieldType === 'point' ? (
                              <PointEditor
                                value={currentValue as [number, number] | null}
                                onChange={(val: [number, number] | null) =>
                                  handleFieldChange(key, val)
                                }
                                isRequired={isRequired}
                                disabled={isSaving}
                              />
                            ) : fieldType === 'linestring' ? (
                              <LineEditor
                                value={currentValue as number[][] | null}
                                onChange={(val: number[][] | null) =>
                                  handleFieldChange(key, val)
                                }
                                isRequired={isRequired}
                                disabled={isSaving}
                              />
                            ) : fieldType === 'polygon' ? (
                              <PolygonEditor
                                value={currentValue as number[][][] | null}
                                onChange={(val: number[][][] | null) =>
                                  handleFieldChange(key, val)
                                }
                                isRequired={isRequired}
                                disabled={isSaving}
                              />
                            ) : (
                              (() => {
                                const size = columnInfo?.size || null
                                // Explicitly check for null - handle both null and undefined
                                const isNull =
                                  currentValue === null ||
                                  currentValue === undefined
                                const stringValue = isNull
                                  ? ''
                                  : String(currentValue || '')
                                const charCount = stringValue.length
                                // Only string and varchar have maxlength in UI
                                const hasLimit =
                                  (fieldType === 'string' ||
                                    fieldType === 'varchar') &&
                                  size !== null &&
                                  size > 0
                                const isRTLContent = isRTL(stringValue)
                                const showNullCheckbox = !isRequired
                                const useTextarea =
                                  (size && size >= 50) ||
                                  fieldType === 'text' ||
                                  fieldType === 'mediumtext' ||
                                  fieldType === 'longtext'
                                const needsCounterSpace =
                                  hasLimit || showNullCheckbox
                                const counterPadding = needsCounterSpace
                                  ? isRTLContent
                                    ? 'ps-28'
                                    : 'pe-28'
                                  : ''

                                return (
                                  <div className="space-y-1.5">
                                    <div className="relative">
                                      {useTextarea ? (
                                        <Textarea
                                          id={key}
                                          value={stringValue}
                                          ref={(el) => {
                                            if (el) {
                                              fieldRefs.current[key] = el
                                            }
                                          }}
                                          autoFocus={shouldFocus}
                                          onChange={(e) => {
                                            e.stopPropagation()
                                            const newValue = e.target.value
                                            handleFieldChange(key, newValue)
                                          }}
                                          onFocus={() => {
                                            const scrollContainer =
                                              scrollContainerRef.current
                                            if (scrollContainer) {
                                              const scrollTop =
                                                scrollContainer.scrollTop
                                              const scrollLeft =
                                                scrollContainer.scrollLeft
                                              requestAnimationFrame(() => {
                                                scrollContainer.scrollTop =
                                                  scrollTop
                                                scrollContainer.scrollLeft =
                                                  scrollLeft
                                              })
                                            }
                                          }}
                                          disabled={isNull}
                                          dir={isRTLContent ? 'rtl' : 'ltr'}
                                          maxLength={
                                            hasLimit ? size : undefined
                                          }
                                          className={cn(
                                            'min-h-[36px] max-h-[600px] text-[13px] resize-none',
                                            isNull &&
                                            'opacity-50 cursor-not-allowed',
                                            showNullCheckbox ? 'pb-8' : 'pb-2',
                                            counterPadding,
                                          )}
                                          rows={1}
                                        />
                                      ) : (
                                        <Input
                                          id={key}
                                          type="text"
                                          value={stringValue}
                                          ref={(el) => {
                                            if (el) {
                                              fieldRefs.current[key] = el
                                            }
                                          }}
                                          autoFocus={shouldFocus}
                                          onChange={(e) => {
                                            e.stopPropagation()
                                            const newValue = e.target.value
                                            handleFieldChange(key, newValue)
                                          }}
                                          disabled={isNull}
                                          dir={isRTLContent ? 'rtl' : 'ltr'}
                                          maxLength={
                                            hasLimit ? size : undefined
                                          }
                                          placeholder={
                                            isRequired ? undefined : 'NULL'
                                          }
                                          className={cn(
                                            'h-9 text-[13px]',
                                            isNull &&
                                            'opacity-50 cursor-not-allowed',
                                            counterPadding,
                                          )}
                                        />
                                      )}
                                      <div
                                        className={cn(
                                          'absolute flex items-center gap-2 pointer-events-none',
                                          useTextarea
                                            ? isRTLContent
                                              ? 'bottom-2 start-2'
                                              : 'bottom-2 end-2'
                                            : isRTLContent
                                              ? 'top-1/2 -translate-y-1/2 start-2'
                                              : 'top-1/2 -translate-y-1/2 end-2',
                                        )}
                                      >
                                        {hasLimit && (
                                          <span
                                            className={cn(
                                              'text-[11px] px-1.5 py-0.5 rounded pointer-events-auto whitespace-nowrap',
                                              charCount > size
                                                ? 'text-destructive bg-destructive/10'
                                                : 'text-muted-foreground bg-muted/80',
                                            )}
                                          >
                                            {charCount}/{size}
                                          </span>
                                        )}
                                        {showNullCheckbox && (
                                          <div className="pointer-events-auto flex items-center gap-1.5">
                                            <Checkbox
                                              id={`${key}-null`}
                                              checked={isNull}
                                              onCheckedChange={(checked) => {
                                                handleNullToggle(
                                                  key,
                                                  checked as boolean,
                                                )
                                              }}
                                              onClick={(e) =>
                                                e.stopPropagation()
                                              }
                                              className="h-4 w-4"
                                              disabled={false}
                                            />
                                            <label
                                              htmlFor={`${key}-null`}
                                              className="text-[11px] text-muted-foreground cursor-pointer select-none"
                                            >
                                              {t('Null')}
                                            </label>
                                          </div>
                                        )}
                                      </div>
                                    </div>
                                  </div>
                                )
                              })()
                            )}
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </div>
              </div>
              )}
            </TabsContent>

            <TabsContent
              value="permissions"
              className="mt-0 flex-1 outline-none data-[state=inactive]:hidden"
            >
              <div className="px-6 py-6">
                <div className="space-y-5">
                  <div className="space-y-3">
                    <h4 className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      {t('Permissions')}
                    </h4>
                    <div className="rounded-lg border border-border bg-muted/30 p-4">
                      <p className="text-[13px] text-muted-foreground">
                        {t('Configure row-level access permissions to control who can read, write, and delete this row.')}
                      </p>
                    </div>
                  </div>
                  <PermissionsEditor
                    permissions={rowPermissions}
                    onPermissionsChange={handlePermissionsChange}
                    withCreate={false}
                    projectId={projectId}
                  />
                </div>
              </div>
            </TabsContent>

            {presentation === 'inline' &&
            (inlineDocumentDirty || isCreateMode) ? (
              <div className="pointer-events-none absolute bottom-3 start-0 end-0 z-20 flex justify-center px-2 sm:bottom-4 sm:px-4">
                <div className="pointer-events-auto flex w-full max-w-[520px] min-w-0 flex-col gap-2 rounded-lg border border-border bg-background px-3 py-2.5 shadow-sm sm:min-w-[min(100%,400px)] sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-6 sm:py-3">
                  <Badge variant="secondary" className="h-6 w-fit shrink-0 px-2.5">
                    {isCreateMode
                      ? inlineDocumentDirty
                        ? t('Unsaved changes')
                        : t('New document')
                      : t('Unsaved changes')}
                  </Badge>
                  <div className="flex shrink-0 items-center justify-end gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={handleRevertInlineDocumentChanges}
                      disabled={isSaving}
                      className="h-8 text-xs"
                    >
                      {t('Cancel')}
                    </Button>
                    <Button
                      size="sm"
                      onClick={handleSave}
                      disabled={isSaving}
                      className="h-8 text-xs"
                    >
                      {isCreateMode ? dbLabels.createRecord : t('Update')}
                    </Button>
                  </div>
                </div>
              </div>
            ) : null}
          </div>
        </Tabs>

        {presentation !== 'inline' ? (
          <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            <Button onClick={handleSave} disabled={isSaving}>
              {isCreateMode ? dbLabels.createRecord : t('Update')}
            </Button>
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSaving}
            >
              {t('Cancel')}
            </Button>
          </div>
        ) : null}
    </>
  )

  if (presentation === 'inline') {
    if (!open) return null
    return (
      <div className="flex h-full min-h-0 w-full flex-col overflow-hidden bg-background">
        {rowEditorBody}
      </div>
    )
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={rowEditorTitle}
      maxWidth="sm:max-w-2xl"
      headerActions={rowEditorHeaderActions}
    >
      {rowEditorBody}
    </BaseDrawer>
  )
}

/** Row create/update drawer for document JSON list views (grid has its own drawer). */
export function DocumentsRowCreateBridge({
  table,
  onCreateRowReady,
}: {
  table: Collection,
  onCreateRowReady?: (openCreateDrawer: () => void) => void
}) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const routeSearch = useSearch({ strict: false }) as
    | Record<string, unknown>
    | undefined
  const openRowCreateFlag = routeSearch?.openRowCreate === '1'
  const openRowCreateConsumedRef = useRef(false)

  const [editDrawerOpen, setEditDrawerOpen] = useState(false)
  const [selectedRowForEdit, setSelectedRowForEdit] = useState<RowData | null>(
    null,
  )
  const [focusedField, setFocusedField] = useState<string | null>(null)
  const [drawerInitialTab, setDrawerInitialTab] = useState<
    'data' | 'permissions' | null
  >(null)

  const { columns: apiColumns } = useProjectTableColumns(
    projectId,
    databaseId,
    DB_KIND,
    table.$id,
  )

  const saveRowMutation = useMutation({
    mutationFn: async ({
      rowId,
      data,
      customId,
      permissions,
    }: {
      rowId: string | null
      data: Record<string, string | number | bigint | boolean | unknown[] | null>
      customId?: string | undefined
      permissions?: string[]
    }) => {
      if (rowId) {
        return await updateProjectTableRow(
          projectId,
          databaseId,
          DB_KIND,
          table.$id,
          rowId,
          data,
          permissions,
        )
      }
      return await createProjectTableRow(
        projectId,
        databaseId,
        DB_KIND,
        table.$id,
        data,
        customId,
        permissions,
      )
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, table.$id],
      })
      toast.success(
        variables.rowId
          ? t('Row updated successfully')
          : t('Row created successfully'),
      )
      setEditDrawerOpen(false)
      setSelectedRowForEdit(null)
    },
    onError: (error: Error, variables) => {
      toast.error(
        error.message ||
          (variables.rowId ? 'Failed to update row' : 'Failed to create row'),
      )
    },
  })

  const handleSaveRow = (
    rowId: string | null,
    data: Record<string, string | number | bigint | boolean | unknown[] | null>,
    customId?: string | undefined,
    permissions?: string[],
  ) => {
    saveRowMutation.mutate({ rowId, data, customId, permissions })
  }

  useEffect(() => {
    if (!onCreateRowReady) return
    const openFn = () => {
      setSelectedRowForEdit(null)
      setFocusedField(null)
      setDrawerInitialTab(null)
      setEditDrawerOpen(true)
    }
    onCreateRowReady(openFn)
  }, [onCreateRowReady])

  useEffect(() => {
    if (!openRowCreateFlag) {
      openRowCreateConsumedRef.current = false
      return
    }
    if (openRowCreateConsumedRef.current) return
    openRowCreateConsumedRef.current = true
    setSelectedRowForEdit(null)
    setFocusedField(null)
    setDrawerInitialTab(null)
    setEditDrawerOpen(true)
    navigate({
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev }
        delete next.openRowCreate
        return next
      },
      replace: true,
    })
  }, [openRowCreateFlag, navigate])

  return (
    <RowEditDrawer
      open={editDrawerOpen}
      onOpenChange={(open) => {
        setEditDrawerOpen(open)
        if (!open) setDrawerInitialTab(null)
      }}
      row={selectedRowForEdit}
      tableName={table.name}
      focusedField={focusedField}
      initialTab={drawerInitialTab ?? undefined}
      columns={apiColumns}
      onSave={handleSaveRow}
      isSaving={saveRowMutation.isPending}
    />
  )
}

// Spreadsheet-like view for Rows
export interface SpreadsheetProps {
  table: Collection
  onRefetchReady?: (refetch: () => Promise<unknown>) => void
  onCreateRowReady?: (openCreateDrawer: () => void) => void
  onCreateColumnReady?: (() => void) | null
  onCreateReady?: (openDialog: () => void) => void
  onSuggestReady?: (openDialog: () => void) => void
  onRowsCountChange?: (count: number) => void
  /** When false, create row/column and suggest actions are disabled (e.g. read-only roles) */
  canWriteRows?: boolean
  canWriteTables?: boolean
  /** URL-driven rows list (when set, search/page/limit/filters/sort come from URL) */
  rowsUrlSearch?: string
  rowsUrlPage?: number
  rowsUrlLimit?: number
  rowsFilterQueries?: string[]
  rowsFilterQueryString?: string
  rowsSortBy?: string
  rowsSortOrder?: 'asc' | 'desc'
  onNavigateToRowsList?: (params: {
    search?: string
    query?: string
    page?: number
    limit?: number
    sort?: string
  }) => void
  /** Account-persisted column order and visibility (system keys + attributes). Null = default. */
  rowsListSelectAttrKeys?: string[] | null
  /** When provided (e.g. from table view header), used for client-side filtering instead of URL */
  filterMap?: Map<CompactFilterKey, string>
}

export function RowsSpreadsheet({
  table,
  onRefetchReady,
  onCreateRowReady,
  onCreateColumnReady,
  onRowsCountChange,
  canWriteRows = true,
  canWriteTables = true,
  rowsUrlSearch,
  rowsUrlPage = 1,
  rowsUrlLimit = ROWS_DEFAULT_PAGE_SIZE,
  rowsFilterQueries,
  rowsFilterQueryString,
  rowsSortBy = '$createdAt',
  rowsSortOrder = 'desc',
  onNavigateToRowsList,
  rowsListSelectAttrKeys,
}: SpreadsheetProps) {
  const t = useT()
  const dbLabels = getLocalizedDatabaseConsoleLabels(t, DB_KIND)
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const { features } = useConsoleProfile()
  const hideSampleData = false
  const hideSequenceColumn = false
  const useInlineDocumentPane = false
  const isMobileViewport = useIsMobile()
  const isDocumentsStackedLayout = useInlineDocumentPane && isMobileViewport
  const rsNav = useMemo(() => dbNavLink(DB_KIND), [])
  const tableId = table.$id

  const editSession = useTableRowsEditSession()

  const urlDriven = onNavigateToRowsList != null

  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set())
  const rowSelectionAnchorRef = useRef<string | null>(null)
  const [rowMultiSelectModifierActive, setRowMultiSelectModifierActive] =
    useState(false)
  const [requestedPage, setRequestedPage] = useState(
    urlDriven ? rowsUrlPage : 1,
  )
  const [displayedPage, setDisplayedPage] = useState(
    urlDriven ? rowsUrlPage : 1,
  )
  const [displayedSearch, setDisplayedSearch] = useState<string>(
    urlDriven ? (rowsUrlSearch ?? '') : '',
  )
  const [displayedFilterQueryString, setDisplayedFilterQueryString] =
    useState<string>(urlDriven ? (rowsFilterQueryString ?? '') : '')
  const displayedFilterQueries = useMemo(() => {
    if (!displayedFilterQueryString) return undefined
    const map = queryParamToMap(displayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [displayedFilterQueryString])
  const documentsPaneHasFilters = useMemo(
    () =>
      Boolean(
        (displayedSearch ?? '').trim() ||
          (displayedFilterQueryString ?? '').length,
      ),
    [displayedSearch, displayedFilterQueryString],
  )
  const hasInitedDisplayedRef = useRef(false)
  const [displayedSortBy, setDisplayedSortBy] = useState<string>(
    urlDriven ? rowsSortBy : '$createdAt',
  )
  const [displayedSortOrder, setDisplayedSortOrder] = useState<'asc' | 'desc'>(
    urlDriven ? rowsSortOrder : 'desc',
  )
  const [pageSize, setPageSize] = useState(
    urlDriven ? rowsUrlLimit : ROWS_DEFAULT_PAGE_SIZE,
  )
  const [sortBy, setSortBy] = useState<string>(
    urlDriven ? rowsSortBy : '$createdAt',
  )
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>(
    urlDriven ? rowsSortOrder : 'desc',
  )
  const [editDrawerOpen, setEditDrawerOpen] = useState(false)
  const [drawerInitialTab, setDrawerInitialTab] = useState<
    'data' | 'permissions' | null
  >(null)
  const [selectedRowForEdit, setSelectedRowForEdit] = useState<RowData | null>(
    null,
  )
  const selectedRowForEditRef = useRef<RowData | null>(null)
  selectedRowForEditRef.current = selectedRowForEdit
  const [focusedField, setFocusedField] = useState<string | null>(null)
  const [documentTablePaneWidthPx, setDocumentTablePaneWidthPx] = useState(
    readStoredDocumentsTablePaneWidthPx,
  )
  const [isDocumentsSplitResizing, setIsDocumentsSplitResizing] =
    useState(false)
  const isDocumentsSplitResizingRef = useRef(false)
  const documentSplitContainerRef = useRef<HTMLDivElement>(null)
  const documentTablePaneWidthRef = useRef(documentTablePaneWidthPx)
  documentTablePaneWidthRef.current = documentTablePaneWidthPx

  const handleDocumentsSplitPointerDown = useCallback(
    (e: React.PointerEvent<HTMLButtonElement>) => {
      e.preventDefault()
      setBodyResizeDragActive(true)
      isDocumentsSplitResizingRef.current = true
      setIsDocumentsSplitResizing(true)
      const btn = e.currentTarget
      btn.setPointerCapture(e.pointerId)
      const startX = e.clientX
      const startW = documentTablePaneWidthRef.current
      const splitEl = documentSplitContainerRef.current
      const onMove = (ev: PointerEvent) => {
        if (!splitEl) return
        const next = clampSplitFirstPaneWidthPx(
          startW +
            horizontalResizeDeltaPx(
              startX,
              ev.clientX,
              isRtlElement(splitEl),
            ),
          splitEl.clientWidth,
          DOCUMENTS_TABLE_PANE_MIN_PX,
          DOCUMENTS_TABLE_PANE_MAX_PX,
          DOCUMENTS_PREVIEW_PANE_MIN_PX,
        )
        setDocumentTablePaneWidthPx(next)
        documentTablePaneWidthRef.current = next
      }
      const onUp = () => {
        setBodyResizeDragActive(false)
        isDocumentsSplitResizingRef.current = false
        setIsDocumentsSplitResizing(false)
        try {
          btn.releasePointerCapture(e.pointerId)
        } catch {
          /* already released */
        }
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        window.removeEventListener('pointercancel', onUp)
        localStorage.setItem(
          DOCUMENTS_TABLE_PANE_WIDTH_STORAGE_KEY,
          String(documentTablePaneWidthRef.current),
        )
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
      window.addEventListener('pointercancel', onUp)
    },
    [],
  )

  useEffect(() => {
    if (!useInlineDocumentPane || isDocumentsStackedLayout) return
    const el = documentSplitContainerRef.current
    if (!el) return
    const fitOnContainerResize = () => {
      if (isDocumentsSplitResizingRef.current) return
      setDocumentTablePaneWidthPx((w) =>
        fitSplitFirstPaneWidthOnContainerResize(
          w,
          el.clientWidth,
          DOCUMENTS_TABLE_PANE_MIN_PX,
          DOCUMENTS_TABLE_PANE_MAX_PX,
          DOCUMENTS_PREVIEW_PANE_MIN_PX,
        ),
      )
    }
    const ro = new ResizeObserver(fitOnContainerResize)
    ro.observe(el)
    fitOnContainerResize()
    return () => ro.disconnect()
  }, [useInlineDocumentPane, isDocumentsStackedLayout])

  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [sampleDataModalOpen, setSampleDataModalOpen] = useState(false)
  const [columnDialogOpen, setColumnDialogOpen] = useState(false)
  const [selectedColumn, setSelectedColumn] = useState<unknown>(null)
  const [contextCellColumnKey, setContextCellColumnKey] = useState<
    string | null
  >(null)
  const openCreateRowFnRef = useRef<(() => void) | null>(null)
  const openCreateColumnFnRef = useRef<(() => void) | null>(null)
  const [copiedColumnHeaderKey, setCopiedColumnHeaderKey] = useState<
    string | null
  >(null)
  const copiedColumnHeaderClearRef = useRef<ReturnType<
    typeof setTimeout
  > | null>(null)
  const [revealedEncryptedColumns, setRevealedEncryptedColumns] = useState(
    () => new Set<string>(),
  )

  const toggleEncryptedColumnReveal = useCallback((columnKey: string) => {
    setRevealedEncryptedColumns((prev) => {
      const next = new Set(prev)
      if (next.has(columnKey)) {
        next.delete(columnKey)
      } else {
        next.add(columnKey)
      }
      return next
    })
  }, [])

  const copyColumnHeaderName = useCallback(async (name: string) => {
    const ok = await copyToClipboard('Column name', name, {
      showToast: false,
    })
    if (!ok) return
    if (copiedColumnHeaderClearRef.current) {
      clearTimeout(copiedColumnHeaderClearRef.current)
    }
    setCopiedColumnHeaderKey(name)
    copiedColumnHeaderClearRef.current = setTimeout(() => {
      setCopiedColumnHeaderKey(null)
      copiedColumnHeaderClearRef.current = null
    }, 2000)
  }, [])

  const SequenceHeaderIcon = getColumnIcon('integer')
  const IdHeaderIcon = getColumnIcon('$id')

  useEffect(() => {
    return () => {
      if (copiedColumnHeaderClearRef.current) {
        clearTimeout(copiedColumnHeaderClearRef.current)
      }
    }
  }, [])

  const queryClient = useQueryClient()
  const { account } = useAuth()
  const organizationId = account?.prefs?.organization as string | undefined
  const navigate = useNavigate()
  const location = useLocation()
  const routeSearch = useSearch({ strict: false }) as
    | Record<string, unknown>
    | undefined
  const openRowCreateFlag = routeSearch?.openRowCreate === '1'
  const openRowCreateConsumedRef = useRef(false)

  useEffect(() => {
    if (urlDriven && rowsUrlPage != null && rowsUrlLimit != null) {
      setRequestedPage((p) => (p === rowsUrlPage ? p : rowsUrlPage))
      setPageSize((s) => (s === rowsUrlLimit ? s : rowsUrlLimit))
    }
  }, [urlDriven, rowsUrlPage, rowsUrlLimit])

  useEffect(() => {
    if (!urlDriven || rowsUrlPage == null || rowsUrlLimit == null) return
    if (!hasInitedDisplayedRef.current) {
      setDisplayedPage(rowsUrlPage)
      setDisplayedSearch(rowsUrlSearch ?? '')
      setDisplayedFilterQueryString(rowsFilterQueryString ?? '')
      setDisplayedSortBy(rowsSortBy)
      setDisplayedSortOrder(rowsSortOrder)
      setSortBy(rowsSortBy)
      setSortOrder(rowsSortOrder)
      hasInitedDisplayedRef.current = true
    }
  }, [
    urlDriven,
    rowsUrlPage,
    rowsUrlLimit,
    rowsUrlSearch,
    rowsFilterQueryString,
    rowsSortBy,
    rowsSortOrder,
  ])

  useEffect(() => {
    if (urlDriven && (rowsSortBy !== sortBy || rowsSortOrder !== sortOrder)) {
      setSortBy(rowsSortBy)
      setSortOrder(rowsSortOrder)
      setDisplayedSortBy(rowsSortBy)
      setDisplayedSortOrder(rowsSortOrder)
    }
  }, [urlDriven, rowsSortBy, rowsSortOrder, sortBy, sortOrder])

  useEffect(() => {
    setSelectedRows(new Set())
    setDeleteDialogOpen(false)
    // Reset requested + displayed list state together so table switches do not
    // keep the previous table's page/search/filters and fire a second listRows.
    // Depend only on table identity (not page/search) so in-table pagination
    // can still keep the previous page visible until the next page loads.
    if (!urlDriven) {
      setRequestedPage(1)
      setDisplayedPage(1)
      setDisplayedSearch('')
      setDisplayedFilterQueryString('')
      setDisplayedSortBy('$createdAt')
      setDisplayedSortOrder('desc')
      setSortBy('$createdAt')
      setSortOrder('desc')
      setPageSize(ROWS_DEFAULT_PAGE_SIZE)
    } else {
      const nextPage = rowsUrlPage ?? 1
      const nextSearch = rowsUrlSearch ?? ''
      const nextFilters = rowsFilterQueryString ?? ''
      setRequestedPage(nextPage)
      setDisplayedPage(nextPage)
      setDisplayedSearch(nextSearch)
      setDisplayedFilterQueryString(nextFilters)
      setDisplayedSortBy(rowsSortBy)
      setDisplayedSortOrder(rowsSortOrder)
      setSortBy(rowsSortBy)
      setSortOrder(rowsSortOrder)
      if (rowsUrlLimit != null) setPageSize(rowsUrlLimit)
      hasInitedDisplayedRef.current = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only on table/route identity change
  }, [location.pathname, projectId, databaseId, tableId, urlDriven])

  const effectiveSearch = urlDriven ? (rowsUrlSearch ?? '') : ''
  const effectivePageSize = urlDriven ? rowsUrlLimit : pageSize
  const effectiveRequestedPage = urlDriven ? requestedPage : requestedPage
  const effectiveDisplayedPage = urlDriven ? displayedPage : displayedPage
  const effectiveDisplayedSearch = urlDriven ? (displayedSearch ?? '') : ''
  const effectiveFilterQueries = urlDriven ? rowsFilterQueries : undefined
  const effectiveDisplayedFilterQueries = urlDriven
    ? displayedFilterQueries
    : undefined

  const {
    total: rowsTotal,
    isLoading: rowsLoading,
    refetch,
    isFetching: rowsFetching,
    error: rowsError,
    isPlaceholderData: rowsIsPlaceholderData,
  } = useProjectTableRows(
    projectId,
    databaseId,
    tableId,
    DB_KIND,
    effectiveRequestedPage - 1,
    effectivePageSize,
    effectiveSearch,
    sortOrder,
    sortBy,
    effectiveFilterQueries,
    rowsListSelectAttrKeys,
  )

  const {
    rows: apiRows,
    total: displayedRowsTotal,
    isLoading: displayedRowsLoading,
    isFetching: displayedRowsFetching,
    error: displayedRowsError,
    isPlaceholderData: displayedRowsIsPlaceholderData,
    refetch: refetchDisplayedRows,
  } = useProjectTableRows(
    projectId,
    databaseId,
    tableId,
    DB_KIND,
    effectiveDisplayedPage - 1,
    effectivePageSize,
    effectiveDisplayedSearch,
    displayedSortOrder,
    displayedSortBy,
    effectiveDisplayedFilterQueries,
    rowsListSelectAttrKeys,
  )

  const rowsLoadError = displayedRowsError ?? rowsError
  const rowsLoadFormatted = rowsLoadError
    ? formatError(rowsLoadError, dbLabels.failedToLoadRecordsTitle)
    : null
  // keepPreviousData can leave prior-table rows in `apiRows` after a failed
  // fetch (e.g. HTTP 408 database_timeout). Still show the error UI whenever
  // the active query failed and we are not mid-load.
  const showRowsLoadError =
    !!rowsLoadError &&
    !displayedRowsLoading &&
    (apiRows.length === 0 ||
      displayedRowsIsPlaceholderData ||
      rowsIsPlaceholderData)

  useEffect(() => {
    if (!showRowsLoadError || !rowsLoadError) return
    const err = rowsLoadError as {
      code?: number
      status?: number
      type?: string
    }
    const status = Number(err.code ?? err.status)
    captureExceptionWithContext(rowsLoadError, {
      source: 'tablesdb-spreadsheet-rows-load',
      projectId,
      databaseId,
      tableId,
      httpStatus: Number.isFinite(status) ? status : undefined,
      errorType: typeof err.type === 'string' ? err.type : undefined,
    })
  }, [showRowsLoadError, rowsLoadError, projectId, databaseId, tableId])

  const handleRetryRowsLoad = () => {
    void refetch()
    if (refetchDisplayedRows !== refetch) {
      void refetchDisplayedRows()
    }
  }

  useEffect(() => {
    if (!urlDriven || rowsFetching || rowsLoading) return
    const urlSearchMatch = (rowsUrlSearch ?? '') === (displayedSearch ?? '')
    const filterMatch =
      (rowsFilterQueryString ?? '') === (displayedFilterQueryString ?? '')
    const match =
      requestedPage === displayedPage &&
      sortBy === displayedSortBy &&
      sortOrder === displayedSortOrder &&
      urlSearchMatch &&
      filterMatch
    if (!match) {
      setDisplayedPage(requestedPage)
      setDisplayedSortBy(sortBy)
      setDisplayedSortOrder(sortOrder)
      setDisplayedSearch(rowsUrlSearch ?? '')
      setDisplayedFilterQueryString(rowsFilterQueryString ?? '')
    }
  }, [
    urlDriven,
    rowsFetching,
    rowsLoading,
    requestedPage,
    displayedPage,
    sortBy,
    sortOrder,
    displayedSortBy,
    displayedSortOrder,
    rowsUrlSearch,
    displayedSearch,
    rowsFilterQueryString,
    displayedFilterQueryString,
  ])

  // Only show full loading when we have no data to display (initial load)
  const showRowsLoading = displayedRowsLoading && apiRows.length === 0

  const currentPageIndexed = displayedPage - 1

  // Notify parent of row count changes (only when count actually changes)
  const prevRowsTotalRef = useRef<number | null>(null)
  useEffect(() => {
    const total = displayedRowsTotal ?? rowsTotal
    if (onRowsCountChange && prevRowsTotalRef.current !== total) {
      prevRowsTotalRef.current = total
      onRowsCountChange(total)
    }
  }, [displayedRowsTotal, rowsTotal, onRowsCountChange])

  // Expose refetch function to parent component
  useEffect(() => {
    if (onRefetchReady) {
      onRefetchReady(refetch)
    }
  }, [refetch, onRefetchReady])

  // Expose create drawer open function to parent component
  useEffect(() => {
    if (onCreateRowReady) {
      const openFn = () => {
        setSelectedRowForEdit(null)
        setFocusedField(null)
        setDrawerInitialTab(null)
        setEditDrawerOpen(true)
      }
      onCreateRowReady(openFn)
      openCreateRowFnRef.current = openFn
    }
  }, [onCreateRowReady])

  useEffect(() => {
    if (!urlDriven) return
    if (!openRowCreateFlag) {
      openRowCreateConsumedRef.current = false
      return
    }
    if (openRowCreateConsumedRef.current) return
    openRowCreateConsumedRef.current = true
    setSelectedRowForEdit(null)
    setFocusedField(null)
    setDrawerInitialTab(null)
    setEditDrawerOpen(true)
    navigate({
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev }
        delete next.openRowCreate
        return next
      },
      replace: true,
    })
  }, [urlDriven, openRowCreateFlag, navigate])

  // Store create column function from parent
  useEffect(() => {
    if (onCreateColumnReady) {
      openCreateColumnFnRef.current = onCreateColumnReady
    }
  }, [onCreateColumnReady])

  // Create column mutation for empty state
  const createColumnMutationForEmptyState = useMutation({
    mutationFn: async (data: ColumnFormData) => {
      return await createProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        data,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      setColumnDialogOpen(false)
      setSelectedColumn(null)
      toast.success(t('Column created successfully'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create column'))
    },
  })

  const handleColumnSubmit = async (data: ColumnFormData) => {
    await createColumnMutationForEmptyState.mutateAsync(data)
  }

  const handleCreateColumn = () => {
    // Always use the local column dialog in the rows view
    setSelectedColumn(null)
    setColumnDialogOpen(true)
  }

  // Fetch columns from the project SDK
  const { columns: apiColumns, isLoading: columnsLoading } =
    useProjectTableColumns(projectId, databaseId, DB_KIND, tableId)

  const findColumnInfo = useCallback(
    (columnKey: string) =>
      apiColumns.find((c: unknown) => {
        const rec = c as Record<string, unknown>
        const colKey =
          rec.key ||
          rec.name ||
          rec.$id ||
          rec.attribute ||
          rec.attributeId
        return colKey === columnKey
      }),
    [apiColumns],
  )

  // Fetch tables for relationship columns (needed for column creation)
  const { tables: availableTablesForColumns } = useTablesForColumns(
    projectId,
    databaseId,
    DB_KIND,
    0,
    100,
    undefined,
  )

  // Check if table has relationship columns
  const hasRelationshipColumns = apiColumns.some(
    (col: unknown) => (col as { type?: string }).type === 'relationship',
  )

  const rowDataKeysFallback = useMemo((): string[] => {
    const first = apiRows[0] as Record<string, unknown> | undefined
    if (!first) return []
    return Object.keys(first).filter((k) => !k.startsWith('$'))
  }, [apiRows])

  // Map API rows to RowData format (normalize array columns: comma-separated string → array)
  const rows: RowData[] = apiRows.map((row: unknown, index: number) => {
    const rowObj = row as Record<string, unknown>
    const data: Record<string, string | number | bigint | boolean | unknown[] | null> =
      {}
    Object.keys(rowObj).forEach((key) => {
      if (!key.startsWith('$')) {
        const value = rowObj[key]
        const col = apiColumns.find(
          (c: Record<string, unknown>) =>
            c.key === key ||
            c.name === key ||
            c.$id === key ||
            c.attribute === key,
        )
        data[key] = normalizeValueForColumn(value, col) as
          | string
          | number
          | boolean
          | unknown[]
          | null
      }
    })

    return {
      $id: rowObj.$id,
      $sequence: rowObj.$sequence,
      rowNumber:
        (displayedRowsTotal ?? rowsTotal) -
        (currentPageIndexed * effectivePageSize + index),
      data,
      $createdAt: rowObj.$createdAt,
      $updatedAt: rowObj.$updatedAt,
      $permissions: (rowObj.$permissions as string[]) || [],
    }
  })

  const schemaColumnKeys = useMemo((): string[] => {
    if (useInlineDocumentPane) return []
    if (apiColumns.length > 0) {
      return apiColumns
        .map((col: unknown) => {
          const c = col as { key?: string; name?: string; $id?: string }
          return c.key || c.name || c.$id
        })
        .filter(
          (k): k is string =>
            typeof k === 'string' && k.length > 0 && !k.startsWith('$'),
        )
    }
    return rowDataKeysFallback
  }, [useInlineDocumentPane, apiColumns, rowDataKeysFallback])

  const defaultLeadingSystemKeys = useMemo((): string[] => {
    if (hideSequenceColumn) return ['$id']
    return ['$sequence', '$id']
  }, [hideSequenceColumn])

  const visibleRowGridKeys = useMemo((): string[] => {
    if (useInlineDocumentPane) return []
    const schemaSet = new Set(schemaColumnKeys)
    const isAllowedKey = (k: string) =>
      defaultLeadingSystemKeys.includes(k) ||
      k === '$createdAt' ||
      k === '$updatedAt' ||
      schemaSet.has(k)

    if (!rowsListSelectAttrKeys?.length) {
      return [
        ...defaultLeadingSystemKeys,
        ...schemaColumnKeys,
        ...ROW_GRID_DEFAULT_DATE_KEYS,
      ]
    }

    const hasSystemInPrefs = rowsListSelectAttrKeys.some((k) =>
      k.startsWith('$'),
    )
    if (!hasSystemInPrefs) {
      return [
        ...defaultLeadingSystemKeys,
        ...rowsListSelectAttrKeys.filter((k) => schemaSet.has(k)),
        ...ROW_GRID_DEFAULT_DATE_KEYS,
      ]
    }

    const seen = new Set<string>()
    return rowsListSelectAttrKeys.filter((k) => {
      if (!isAllowedKey(k) || seen.has(k)) return false
      seen.add(k)
      return true
    })
  }, [
    useInlineDocumentPane,
    rowsListSelectAttrKeys,
    schemaColumnKeys,
    defaultLeadingSystemKeys,
  ])

  const columns = useMemo((): string[] => {
    if (useInlineDocumentPane) return []
    return visibleRowGridKeys.filter((k) => isResizableRowGridColumn(k))
  }, [useInlineDocumentPane, visibleRowGridKeys])

  const rowGridLayoutKey = useMemo(
    () => visibleRowGridKeys.join('\u0001'),
    [visibleRowGridKeys],
  )

  const columnsRef = useRef(columns)
  columnsRef.current = columns

  const [rowColumnWidths, setRowColumnWidths] = useState<Record<string, number>>(
    {},
  )
  const [resizingDataColumnKey, setResizingDataColumnKey] = useState<
    string | null
  >(null)
  const rowColumnWidthsRef = useRef<Record<string, number>>({})
  if (resizingDataColumnKey == null) {
    rowColumnWidthsRef.current = rowColumnWidths
  }
  const rowsTableScrollRef = useRef<HTMLDivElement | null>(null)
  const rowsTableLayerRef = useRef<HTMLDivElement | null>(null)
  const dataColumnHeaderThRefs = useRef<Map<string, HTMLTableCellElement>>(
    new Map(),
  )
  const dataColumnColRefs = useRef<Map<string, HTMLTableColElement>>(new Map())
  const dataColumnRailRefs = useRef<Map<string, HTMLButtonElement>>(new Map())

  const getDataColumnWidthPx = useCallback(
    (colKey: string) => {
      const w = rowColumnWidths[colKey]
      if (typeof w === 'number' && Number.isFinite(w)) {
        return clampRowGridColumnWidthPx(w)
      }
      return defaultRowGridColumnWidthPx(colKey)
    },
    [rowColumnWidths],
  )

  const tableMinWidthPx = useMemo(
    () => computeRowsTableMinWidthPx(visibleRowGridKeys, getDataColumnWidthPx),
    [visibleRowGridKeys, getDataColumnWidthPx],
  )

  const repositionDataColumnRailsOnly = useCallback(() => {
    const layer = rowsTableLayerRef.current
    if (!layer) return
    const layerWidthPx = layer.getBoundingClientRect().width
    for (const col of columnsRef.current) {
      const th = dataColumnHeaderThRefs.current.get(col)
      const rail = dataColumnRailRefs.current.get(col)
      if (!th || !rail) continue
      applyColumnResizeRailPosition(rail, layer, th, {
        maxInsetInlineStartPx: layerWidthPx,
      })
    }
  }, [])

  const applyDraggedDataColumnWidthPx = useCallback(
    (columnKey: string, widthPx: number) => {
      const next = clampRowGridColumnWidthPx(Math.round(widthPx))
      rowColumnWidthsRef.current = {
        ...rowColumnWidthsRef.current,
        [columnKey]: next,
      }
      applyLockedColumnSize(dataColumnColRefs.current.get(columnKey), next)
      applyLockedColumnSize(
        dataColumnHeaderThRefs.current.get(columnKey),
        next,
        ROWS_DATA_COLUMN_MIN_WIDTH_PX,
      )
      repositionDataColumnRailsOnly()
    },
    [repositionDataColumnRailsOnly],
  )

  /** From cached account prefs (same source as loaders) - avoids default-width flash + extra account.get on navigation. */
  const rowColumnWidthsFromPrefs = useMemo((): Record<string, number> => {
    if (!databaseId || !tableId) return {}
    const prefs = (account as { prefs?: UserPrefs } | undefined)?.prefs
    const raw = getDatabaseTableRowColumnWidthsFromPrefs(
      prefs,
      databaseId,
      tableId,
    )
    const next: Record<string, number> = {}
    for (const [k, v] of Object.entries(raw)) {
      if (!isResizableRowGridColumn(k)) continue
      const n = typeof v === 'number' ? v : Number(v)
      if (!Number.isFinite(n)) continue
      next[k] = clampRowGridColumnWidthPx(n)
    }
    return next
  }, [databaseId, tableId, account])

  useLayoutEffect(() => {
    setRowColumnWidths(rowColumnWidthsFromPrefs)
  }, [databaseId, tableId, rowColumnWidthsFromPrefs])

  const persistRowColumnWidths = useCallback(
    async (
      widths: Record<string, number>,
      allowedKeys: readonly string[],
    ) => {
      if (!databaseId) return
      const allowed = new Set(allowedKeys.filter(isResizableRowGridColumn))
      const pruned: Record<string, number> = {}
      for (const k of allowed) {
        const w = widths[k]
        if (typeof w === 'number' && Number.isFinite(w)) {
          pruned[k] = clampRowGridColumnWidthPx(w)
        }
      }
      try {
        const acct = await fetchConsoleAccount()
        const prefs = mergeDatabaseTableRowColumnWidthsTableIntoPrefs(
          (acct.prefs || {}) as UserPrefs,
          databaseId,
          tableId,
          pruned,
        )
        const updatedAccount = await updateAccountPrefs(prefs)
        syncConsoleAccountAfterMutation(queryClient, {
          apiResult: updatedAccount,
        })
      } catch {
        /* preference save is best-effort */
      }
    },
    [databaseId, tableId, queryClient],
  )

  const handleDataColumnResizePointerDown = useCallback(
    (columnKey: string) => (e: React.PointerEvent<HTMLButtonElement>) => {
      if (!columnKey || !isResizableRowGridColumn(columnKey)) return
      e.preventDefault()
      e.stopPropagation()
      setBodyResizeDragActive(true)
      const btn = e.currentTarget
      btn.setPointerCapture(e.pointerId)
      const startX = e.clientX
      const initialWidth = getDataColumnWidthPx(columnKey)
      flushSync(() => {
        setResizingDataColumnKey(columnKey)
      })
      applyDraggedDataColumnWidthPx(columnKey, initialWidth)
      const onMove = (ev: PointerEvent) => {
        applyDraggedDataColumnWidthPx(
          columnKey,
          initialWidth + horizontalResizeDeltaPx(startX, ev.clientX),
        )
      }
      const onUp = () => {
        setBodyResizeDragActive(false)
        try {
          btn.releasePointerCapture(e.pointerId)
        } catch {
          /* already released */
        }
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        window.removeEventListener('pointercancel', onUp)
        flushSync(() => {
          setResizingDataColumnKey(null)
          setRowColumnWidths({ ...rowColumnWidthsRef.current })
        })
        void persistRowColumnWidths(
          rowColumnWidthsRef.current,
          columnsRef.current,
        )
      }
      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
      window.addEventListener('pointercancel', onUp)
    },
    [
      applyDraggedDataColumnWidthPx,
      getDataColumnWidthPx,
      persistRowColumnWidths,
    ],
  )

  const columnResizeLayoutKey = columns.join('\u0001')

  useLayoutEffect(() => {
    const scroll = rowsTableScrollRef.current
    const layer = rowsTableLayerRef.current
    if (!scroll || !layer || columns.length === 0) {
      return
    }

    const measure = () => {
      repositionDataColumnRailsOnly()
    }

    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(scroll)
    ro.observe(layer)
    scroll.addEventListener('scroll', measure, { passive: true })
    window.addEventListener('resize', measure)
    return () => {
      ro.disconnect()
      scroll.removeEventListener('scroll', measure)
      window.removeEventListener('resize', measure)
    }
    // columnResizeLayoutKey tracks column set; rowColumnWidths / scroll / resize drive layout.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- columns array identity changes every render
  }, [
    columnResizeLayoutKey,
    rowGridLayoutKey,
    rowColumnWidths,
    useInlineDocumentPane,
    apiColumns.length,
    repositionDataColumnRailsOnly,
  ])

  const toggleRow = (id: string) => {
    const newSelected = new Set(selectedRows)
    if (newSelected.has(id)) {
      newSelected.delete(id)
    } else {
      newSelected.add(id)
    }
    rowSelectionAnchorRef.current = id
    setSelectedRows(newSelected)
  }

  useEffect(() => {
    const syncModifierActive = (event: KeyboardEvent) => {
      setRowMultiSelectModifierActive(
        event.shiftKey || event.ctrlKey || event.metaKey,
      )
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (
        event.key === 'Shift' ||
        event.key === 'Control' ||
        event.key === 'Meta'
      ) {
        setRowMultiSelectModifierActive(true)
      }
    }
    const onBlur = () => setRowMultiSelectModifierActive(false)
    window.addEventListener('keydown', onKeyDown)
    window.addEventListener('keyup', syncModifierActive)
    window.addEventListener('blur', onBlur)
    return () => {
      window.removeEventListener('keydown', onKeyDown)
      window.removeEventListener('keyup', syncModifierActive)
      window.removeEventListener('blur', onBlur)
    }
  }, [])

  const isRowMultiSelectModifierClick = (event: MouseEvent) =>
    event.shiftKey || event.ctrlKey || event.metaKey

  const preventRowTextSelectionOnPointer = (event: MouseEvent) => {
    if (isRowMultiSelectModifierClick(event)) {
      event.preventDefault()
    }
  }

  const selectRowsWithShift = useCallback(
    (rowId: string) => {
      const anchorId =
        rowSelectionAnchorRef.current ??
        (selectedRows.size > 0 ? Array.from(selectedRows)[0] : rowId)
      const anchorIndex = rows.findIndex((r) => r.$id === anchorId)
      const clickIndex = rows.findIndex((r) => r.$id === rowId)
      if (anchorIndex === -1 || clickIndex === -1) {
        const next = new Set(selectedRows)
        if (next.has(rowId)) next.delete(rowId)
        else next.add(rowId)
        rowSelectionAnchorRef.current = rowId
        setSelectedRows(next)
        return
      }
      const start = Math.min(anchorIndex, clickIndex)
      const end = Math.max(anchorIndex, clickIndex)
      const next = new Set(selectedRows)
      for (let i = start; i <= end; i++) {
        next.add(rows[i].$id)
      }
      setSelectedRows(next)
    },
    [rows, selectedRows],
  )

  const toggleAll = () => {
    // Rows are already paginated by the API
    const paginatedRows = rows
    if (selectedRows.size === paginatedRows.length) {
      setSelectedRows(new Set())
    } else {
      setSelectedRows(new Set(paginatedRows.map((r) => r.$id)))
    }
  }

  const handlePageChange = (page: number) => {
    setRequestedPage(page)
    setSelectedRows(new Set())
    if (urlDriven && onNavigateToRowsList) {
      onNavigateToRowsList({ page })
    }
  }

  const handlePageSizeChange = (newPageSize: number) => {
    setPageSize(newPageSize)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedRows(new Set())
    if (urlDriven && onNavigateToRowsList) {
      onNavigateToRowsList({ page: 1, limit: newPageSize })
    }
  }

  const handleSortColumn = (columnKey: string) => {
    let nextSortBy: string
    let nextSortOrder: 'asc' | 'desc'
    if (sortBy === columnKey) {
      if (sortOrder === 'asc') {
        nextSortBy = columnKey
        nextSortOrder = 'desc'
      } else {
        if (columnKey === '$createdAt') {
          nextSortBy = '$createdAt'
          nextSortOrder = 'asc'
        } else {
          nextSortBy = '$createdAt'
          nextSortOrder = 'desc'
        }
      }
    } else {
      nextSortBy = columnKey
      nextSortOrder = 'asc'
    }
    setSortBy(nextSortBy)
    setSortOrder(nextSortOrder)
    setRequestedPage(1)
    setDisplayedPage(1)
    setSelectedRows(new Set())
    if (urlDriven && onNavigateToRowsList) {
      onNavigateToRowsList({
        page: 1,
        sort:
          nextSortBy !== '$createdAt' || nextSortOrder !== 'desc'
            ? encodeSort(nextSortBy, nextSortOrder)
            : undefined,
      })
    }
  }

  // List rows may be projected via Query.select (visible spreadsheet columns only).
  // Always load the full row before opening the update drawer so hidden attrs are present.
  const openRowInDrawerRequestRef = useRef(0)
  const openRowInDrawer = useCallback(
    (
      row: RowData,
      options?: {
        focusedField?: string | null
        initialTab?: 'data' | 'permissions' | null
      },
    ) => {
      rowSelectionAnchorRef.current = row.$id
      setFocusedField(options?.focusedField ?? null)
      setDrawerInitialTab(options?.initialTab ?? 'data')

      const applyRow = (fullRow: RowData) => {
        setSelectedRowForEdit(fullRow)
        setEditDrawerOpen(true)
      }

      if (!projectId || !databaseId || !tableId) {
        applyRow(row)
        return
      }

      const requestId = ++openRowInDrawerRequestRef.current
      fetchProjectTableRow(projectId, databaseId, DB_KIND, tableId, row.$id)
        .then((apiRow: unknown) => {
          if (requestId !== openRowInDrawerRequestRef.current) return
          if (!apiRow || typeof apiRow !== 'object') {
            applyRow(row)
            return
          }
          applyRow(
            mapApiRowToRowData(apiRow as Record<string, unknown>, apiColumns, {
              rowNumber: row.rowNumber,
              fallbackId: row.$id,
            }),
          )
        })
        .catch(() => {
          if (requestId !== openRowInDrawerRequestRef.current) return
          applyRow(row)
        })
    },
    [projectId, databaseId, tableId, apiColumns],
  )

  const handleRowMultiSelectPointer = (rowId: string, event: MouseEvent) => {
    if (!isRowMultiSelectModifierClick(event)) return false
    event.preventDefault()
    if (event.shiftKey) {
      selectRowsWithShift(rowId)
    } else {
      toggleRow(rowId)
    }
    return true
  }

  const pendingCellDrawerTimersRef = useRef<
    Map<string, ReturnType<typeof setTimeout>>
  >(new Map())

  const cancelPendingCellDrawer = useCallback((rowId: string, columnKey: string) => {
    const timerKey = `${rowId}:${columnKey}`
    const timer = pendingCellDrawerTimersRef.current.get(timerKey)
    if (!timer) return
    clearTimeout(timer)
    pendingCellDrawerTimersRef.current.delete(timerKey)
  }, [])

  const cancelAllPendingCellDrawers = useCallback(() => {
    for (const timer of pendingCellDrawerTimersRef.current.values()) {
      clearTimeout(timer)
    }
    pendingCellDrawerTimersRef.current.clear()
  }, [])

  useEffect(() => {
    const timers = pendingCellDrawerTimersRef.current
    return () => {
      for (const timer of timers.values()) clearTimeout(timer)
      timers.clear()
    }
  }, [])

  const handleRowClick = (row: RowData, event: MouseEvent) => {
    if (handleRowMultiSelectPointer(row.$id, event)) return
    if (editSession?.consumeSuppressNextDrawerOpen()) {
      cancelAllPendingCellDrawers()
      return
    }
    if (editSession?.commitActiveInlineEdit()) {
      cancelAllPendingCellDrawers()
      return
    }
    openRowInDrawer(row, { focusedField: null, initialTab: 'data' })
  }

  const handleOpenPermissionsRow = (row: RowData) => {
    openRowInDrawer(row, { focusedField: null, initialTab: 'permissions' })
  }

  const handleCellClick = (row: RowData, key: string, event: MouseEvent) => {
    if (handleRowMultiSelectPointer(row.$id, event)) return
    if (editSession?.consumeSuppressNextDrawerOpen()) {
      cancelAllPendingCellDrawers()
      return
    }
    if (editSession?.commitActiveInlineEdit()) {
      cancelAllPendingCellDrawers()
      return
    }
    const timerKey = `${row.$id}:${key}`
    const existing = pendingCellDrawerTimersRef.current.get(timerKey)
    if (existing) clearTimeout(existing)
    const timer = setTimeout(() => {
      pendingCellDrawerTimersRef.current.delete(timerKey)
      openRowInDrawer(row, { focusedField: key, initialTab: null })
    }, 200)
    pendingCellDrawerTimersRef.current.set(timerKey, timer)
  }

  // Open row drawer when URL has #row-<id> or #row-<id>-permissions (e.g. from copied link).
  // Use window.location.hash and hashchange so it works on new-tab load and when hash is set after load.
  const prevTableIdForPreviewRef = useRef<string | null>(null)
  const lastProcessedHashRef = useRef<string | null>(null)

  useEffect(() => {
    const prev = prevTableIdForPreviewRef.current
    prevTableIdForPreviewRef.current = tableId
    if (prev !== null && prev !== tableId) {
      setSelectedRowForEdit(null)
      setEditDrawerOpen(false)
      setDrawerInitialTab(null)
      setFocusedField(null)
      setSelectedRows(new Set())
      lastProcessedHashRef.current = null
      if (typeof window !== 'undefined') {
        const { hash, pathname, search } = window.location
        if (hash && /^#row-/.test(hash)) {
          window.history.replaceState(
            window.history.state,
            '',
            `${pathname}${search}`,
          )
        }
      }
    }
  }, [tableId])
  const openRowDrawerFromHash = useCallback(
    (
      hash: string,
      currentRows: RowData[],
      columnsForNormalize: unknown[] = [],
    ) => {
      const rawHash = hash.replace(/^#/, '')
      const match = rawHash.match(/^row-(.+?)(-permissions)?$/)
      if (!match || !projectId || !databaseId || !tableId) {
        lastProcessedHashRef.current = null
        return
      }
      if (lastProcessedHashRef.current === hash) return
      lastProcessedHashRef.current = hash
      const rowId = match[1]
      const openToPermissions = !!match[2]

      // Always fetch the full row. List rows may omit hidden spreadsheet columns
      // via Query.select, so reusing `fromCurrentPage` would open an incomplete form.
      const fromCurrentPage = currentRows.find((r) => r.$id === rowId)
      fetchProjectTableRow(projectId, databaseId, DB_KIND, tableId, rowId)
        .then((apiRow: unknown) => {
          if (!apiRow || typeof apiRow !== 'object') {
            if (fromCurrentPage) {
              setSelectedRowForEdit(fromCurrentPage)
              setFocusedField(null)
              setDrawerInitialTab(openToPermissions ? 'permissions' : 'data')
              setEditDrawerOpen(true)
            }
            return
          }
          setSelectedRowForEdit(
            mapApiRowToRowData(
              apiRow as Record<string, unknown>,
              columnsForNormalize,
              {
                rowNumber: fromCurrentPage?.rowNumber ?? 0,
                fallbackId: rowId,
              },
            ),
          )
          setFocusedField(null)
          setDrawerInitialTab(openToPermissions ? 'permissions' : 'data')
          setEditDrawerOpen(true)
        })
        .catch(() => {
          if (!fromCurrentPage) return
          setSelectedRowForEdit(fromCurrentPage)
          setFocusedField(null)
          setDrawerInitialTab(openToPermissions ? 'permissions' : 'data')
          setEditDrawerOpen(true)
        })
    },
    [projectId, databaseId, tableId],
  )

  useEffect(() => {
    const hash = window.location.hash
    if (hash) openRowDrawerFromHash(hash, rows, apiColumns)
  }, [rows, openRowDrawerFromHash])

  useEffect(() => {
    const onHashChange = () => {
      const hash = window.location.hash
      if (!hash) {
        lastProcessedHashRef.current = null
        return
      }
      openRowDrawerFromHash(hash, rows, apiColumns)
    }
    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [rows, openRowDrawerFromHash])

  // Create/Update row mutation
  const saveRowMutation = useMutation({
    mutationFn: async ({
      rowId,
      data,
      customId,
      permissions,
    }: {
      rowId: string | null
      data: Record<string, string | number | bigint | boolean | unknown[] | null>
      customId?: string | undefined
      permissions?: string[]
    }) => {
      if (rowId) {
        // Update existing row
        return await updateProjectTableRow(
          projectId,
          databaseId,
          DB_KIND,
          tableId,
          rowId,
          data,
          permissions,
        )
      } else {
        // Create new row - use customId if provided, otherwise will auto-generate
        return await createProjectTableRow(
          projectId,
          databaseId,
          DB_KIND,
          tableId,
          data,
          customId,
          permissions,
        )
      }
    },
    onSuccess: (result, variables) => {
      // Invalidate and refetch rows
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(
        variables.rowId
          ? dbLabels.recordUpdatedSuccess
          : dbLabels.recordCreatedSuccess,
      )
      if (useInlineDocumentPane && result && typeof result === 'object') {
        const rowObj = result as Record<string, unknown>
        setSelectedRowForEdit((prev) => {
          const data: Record<
            string,
            string | number | bigint | boolean | unknown[] | null
          > = {}
          Object.keys(rowObj).forEach((key) => {
            if (!key.startsWith('$')) {
              const value = rowObj[key]
              const col = apiColumns.find(
                (c: Record<string, unknown>) =>
                  c.key === key ||
                  c.name === key ||
                  c.$id === key ||
                  c.attribute === key,
              )
              data[key] = normalizeValueForColumn(value, col) as
                | string
                | number
                | boolean
                | unknown[]
                | null
            }
          })
          return {
            $id:
              (rowObj.$id as string) ||
              (variables.rowId as string | null) ||
              '',
            $sequence: rowObj.$sequence as number | undefined,
            rowNumber: prev?.rowNumber ?? 0,
            data,
            $createdAt: rowObj.$createdAt as string | undefined,
            $updatedAt: rowObj.$updatedAt as string | undefined,
            $permissions: (rowObj.$permissions as string[]) || [],
          }
        })
        setEditDrawerOpen(true)
        return
      }
      setEditDrawerOpen(false)
      setSelectedRowForEdit(null)
    },
    onError: (error: Error, variables) => {
      toast.error(
        error.message ||
          (variables.rowId
            ? dbLabels.failedToUpdateRecord
            : dbLabels.failedToCreateRecord),
      )
    },
  })

  const handleSaveRow = (
    rowId: string | null,
    data: Record<string, string | number | bigint | boolean | unknown[] | null>,
    customId?: string | undefined,
    permissions?: string[],
  ) => {
    saveRowMutation.mutate({ rowId, data, customId, permissions })
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (rowIds: string[]) => {
      // Delete all rows in parallel
      await Promise.all(
        rowIds.map((rowId) =>
          deleteProjectTableRow(projectId, databaseId, DB_KIND, tableId, rowId),
        ),
      )
    },
    onSuccess: async (_data, rowIds) => {
      // Refetch rows list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(
        `${t('Successfully deleted')} ${selectedRows.size} ${
          selectedRows.size === 1
            ? dbLabels.recordSingular
            : dbLabels.recordPlural
        }`,
      )
      if (useInlineDocumentPane) {
        const sid = selectedRowForEditRef.current?.$id
        if (sid && rowIds.includes(sid)) {
          setSelectedRowForEdit(null)
          setEditDrawerOpen(false)
          setDrawerInitialTab(null)
        }
      }
      setSelectedRows(new Set())
    },
    onError: (error: Error) => {
      toast.error(
        error.message || dbLabels.failedToDeleteRecords,
      )
    },
  })

  const handleBulkDelete = () => {
    if (selectedRows.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedRows.size === 0) return
    const rowIds = Array.from(selectedRows)
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    bulkDeleteMutation.mutate(rowIds)
  }

  const duplicateRowMutation = useMutation({
    mutationFn: async (row: RowData) => {
      const data = { ...row.data } as Record<string, unknown>
      if (Object.prototype.hasOwnProperty.call(data, '$id')) delete data.$id
      return createProjectTableRow(projectId, databaseId, DB_KIND, tableId, data)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      toast.success(dbLabels.recordDuplicatedSuccess)
    },
    onError: (error: Error) => {
      toast.error(
        error.message ??
          dbLabels.failedToDuplicateRecord,
      )
    },
  })

  // Sample data generation mutation (requires at least one non-relationship column; no auto-scaffold)
  const sampleDataMutation = useMutation({
    mutationFn: async (rowCount: number) => {
      const columns: Column[] = apiColumns
        .filter((col: unknown) => {
          const colKey =
            (col as Record<string, unknown>).key ||
            (col as Record<string, unknown>).name ||
            (col as Record<string, unknown>).$id
          return colKey && !String(colKey).startsWith('$')
        })
        .map((col: unknown) => {
          const c = col as Record<string, unknown>
          return {
            key: c.key || c.name || c.$id,
            type: c.type || 'string',
            size: c.size ?? null,
            required: c.required || false,
            array: c.array || false,
            default: c.default ?? null,
            format: c.format ?? null,
            elements: c.elements ?? null,
            min: c.min ?? null,
            max: c.max ?? null,
            status: (c.status as string) || 'available',
          }
        }) as Column[]

      const dataProducingColumns = columns.filter(
        (c) =>
          c.type !== 'relationship' && (!c.status || c.status === 'available'),
      )
      if (dataProducingColumns.length === 0) {
        throw new Error(dbLabels.addSchemaForSampleDataHint)
      }

      const sampleRows = generateSampleRows(columns, rowCount)
      const result = await createProjectTableRows(
        projectId,
        databaseId,
        DB_KIND,
        tableId,
        sampleRows,
        hasRelationshipColumns,
      )

      return result
    },
    onSuccess: (result) => {
      queryClient.invalidateQueries({
        queryKey: ['rows', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(
        `Sample data added successfully. ${result.created} row${result.created !== 1 ? 's' : ''} created.`,
      )
      setSampleDataModalOpen(false)
    },
    onError: (error: Error) => {
      const isCanceled =
        error?.name === 'CanceledError' ||
        (typeof error?.message === 'string' &&
          error.message.toLowerCase().includes('cancel'))
      if (!isCanceled) {
        toast.error(error.message || t('Failed to generate sample data'))
      }
    },
  })

  const handleGenerateSampleData = (rowCount: number) => {
    sampleDataMutation.mutate(rowCount)
  }

  // Rows are already paginated by the API
  const paginatedRows = rows

  const stringifyStructuredValue = (input: unknown): string => {
    if (input === null || input === undefined) return 'null'
    if (Array.isArray(input)) {
      return `[${input.map((item) => stringifyStructuredValue(item)).join(', ')}]`
    }
    if (typeof input === 'object') {
      return JSON.stringify(input)
    }
    return String(input)
  }

  const documentsListFullyEmpty =
    useInlineDocumentPane &&
    paginatedRows.length === 0 &&
    !editDrawerOpen &&
    selectedRowForEdit === null

  const formatCellValue = (
    value:
      | string
      | number
      | boolean
      | unknown[]
      | Record<string, unknown>
      | null
      | undefined,
  ) => {
    if (value === null || value === undefined)
      return { full: 'null', display: 'null', isNull: true }
      const stringValue = stringifyStructuredValue(value)
    const trimmed =
      stringValue.length > 80 ? `${stringValue.slice(0, 77)}…` : stringValue
    return { full: stringValue, display: trimmed, isNull: false }
  }

  const formatSystemDateCellDisplay = (
    value: string | null | undefined,
  ): { full: string; display: string; isNull: boolean } => {
    if (!value) return { full: 'null', display: 'N/A', isNull: true }
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) {
      return { full: value, display: value, isNull: false }
    }
    return {
      full: value,
      display: d.toLocaleString(undefined, {
        dateStyle: 'short',
        timeStyle: 'short',
      }),
      isNull: false,
    }
  }

  // Detect RTL content
  const isRTL = (text: string | null | undefined): boolean => {
    if (!text || typeof text !== 'string') return false
    // Check for RTL characters (Arabic, Hebrew, etc.)
    const rtlPattern =
      /[\u0590-\u05FF\u0600-\u06FF\u0700-\u074F\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/
    return rtlPattern.test(text)
  }

  // The parent container constrains height with overflow-hidden
  // This component fills available space and handles its own scrolling
  // Only show loading if we don't have data yet (data is prefetched in route loader)
  // This prevents showing loading when switching tables since data is already cached
  if (showRowsLoadError && rowsLoadFormatted) {
    return (
      <div className="flex h-full flex-col items-center justify-center px-6 py-12">
        <AlertCircle className="h-9 w-9 text-destructive" />
        <h3 className="mt-4 text-[15px] font-semibold text-foreground">
          {rowsLoadFormatted.title}
        </h3>
        <p className="mt-2 max-w-md text-center text-[13px] text-muted-foreground">
          {rowsLoadFormatted.message}
        </p>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="mt-6"
          onClick={handleRetryRowsLoad}
          disabled={rowsFetching || displayedRowsFetching}
        >
          {t('Try again')}
        </Button>
      </div>
    )
  }

  if (showRowsLoading || (columnsLoading && apiColumns.length === 0)) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">
          {dbLabels.loadingRecordsLabel}
        </div>
      </div>
    )
  }

  // Check if table has custom columns (non-system columns)
  const hasCustomColumns = apiColumns.some((col: unknown) => {
    const colKey =
      (col as Record<string, unknown>).key ||
      (col as Record<string, unknown>).name ||
      (col as Record<string, unknown>).$id
    return colKey && !String(colKey).startsWith('$')
  })

  // Check if table has any columns at all
  const hasColumns = apiColumns.length > 0

  // Sample data requires at least one non-relationship column (API rejects empty row data)
  const hasDataProducingColumns = apiColumns.some((col: unknown) => {
    const c = col as Record<string, unknown>
    const colKey = c.key || c.name || c.$id
    const type = (c.type as string) || 'string'
    const status = (c.status as string) || 'available'
    return (
      colKey &&
      !String(colKey).startsWith('$') &&
      type !== 'relationship' &&
      (status === 'available' || !status)
    )
  })

  // Show empty state outside the table when there are no rows (tables / grid).
  // Documents DB inline view: full-width empty when idle; split when creating/editing.
  if (paginatedRows.length === 0 && !useInlineDocumentPane) {
    const handleCreateRow = () => {
      if (openCreateRowFnRef.current) {
        openCreateRowFnRef.current()
      } else {
        // Fallback: directly open the drawer
        setSelectedRowForEdit(null)
        setFocusedField(null)
        setDrawerInitialTab(null)
        setEditDrawerOpen(true)
      }
    }

    const handleSuggestColumns = () => {
      navigate({
        ...rsNav.columns({
          projectId,
          dbKind: DB_KIND,
          databaseId,
          resourceId: tableId,
        }),
        search: { openSuggest: 'true' },
      })
    }

    const handleDocumentation = () => {
      openInNewWindow(
        getDocsPageUrl('/docs/products/databases', features.marketing),
      )
    }

    const handleOpenSampleDataModal = () => {
      if (
        !sampleDataMutation.isPending &&
        !columnsLoading &&
        hasDataProducingColumns
      ) {
        setSampleDataModalOpen(true)
      }
    }

    return (
      <div className="flex flex-col">
        <div className="flex flex-1 items-start justify-center pt-32">
          <div className="flex flex-col items-center justify-center gap-6">
            <Table2 className="h-12 w-12 text-muted-foreground/50" />
            <div className="space-y-2 text-center">
              <p className="text-sm font-medium text-foreground">
                {hasColumns
                  ? dbLabels.emptyGridNoRecordsTitle
                  : dbLabels.emptyGridNoSchemaTitle}
              </p>
              <p className="text-xs text-muted-foreground max-w-sm">
                {hasColumns
                  ? hideSampleData
                    ? dbLabels.emptyGridHasSchemaHint
                    : dbLabels.emptyGridHasSchemaWithSampleHint
                  : dbLabels.emptyGridNoSchemaHint}
              </p>
            </div>
            <div className="grid grid-cols-2 gap-3 w-full max-w-2xl">
              {/* Row 1 */}
              {features.agent &&
                (!canWriteTables ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Card className="cursor-not-allowed opacity-60 p-0 gap-0 shadow-none">
                        <div className="flex items-start gap-3 p-4">
                          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <Lightbulb className="h-5 w-5 text-muted-foreground" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <h3 className="text-sm font-medium text-foreground">
                              {dbLabels.suggestSchemaCardTitle}
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                              {dbLabels.suggestSchemaCardHint}
                            </p>
                          </div>
                        </div>
                      </Card>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      {t("You don't have permission to perform this action.")}
                    </TooltipContent>
                  </Tooltip>
                ) : (
                  <Card
                    onClick={handleSuggestColumns}
                    className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
                  >
                    <div className="flex items-start gap-3 p-4">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <Lightbulb className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="text-sm font-medium text-foreground">
                          {dbLabels.suggestSchemaCardTitle}
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                          {dbLabels.suggestSchemaCardHint}
                        </p>
                      </div>
                    </div>
                  </Card>
                ))}
              {hasCustomColumns ? (
                !canWriteRows ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Card className="cursor-not-allowed opacity-60 p-0 gap-0 shadow-none">
                        <div className="flex items-start gap-3 p-4">
                          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <Plus className="h-5 w-5 text-muted-foreground" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <h3 className="text-sm font-medium text-foreground">
                              {dbLabels.createRecord}
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                              {dbLabels.createRecordCardHint}
                            </p>
                          </div>
                        </div>
                      </Card>
                    </TooltipTrigger>
                    <TooltipContent side="bottom">
                      {t("You don't have permission to perform this action.")}
                    </TooltipContent>
                  </Tooltip>
                ) : (
                  <Card
                    onClick={handleCreateRow}
                    className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
                  >
                    <div className="flex items-start gap-3 p-4">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <Plus className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="text-sm font-medium text-foreground">
                          {dbLabels.createRecord}
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                          {dbLabels.createRecordCardHint}
                        </p>
                      </div>
                    </div>
                  </Card>
                )
              ) : !canWriteTables ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Card className="cursor-not-allowed opacity-60 p-0 gap-0 shadow-none">
                      <div className="flex items-start gap-3 p-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                          <Plus className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div className="min-w-0 flex-1">
                          <h3 className="text-sm font-medium text-foreground">
                            {dbLabels.createSchema}
                          </h3>
                          <p className="mt-1 text-xs text-muted-foreground">
                            {dbLabels.createSchemaCardHint}
                          </p>
                        </div>
                      </div>
                    </Card>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    {t("You don't have permission to perform this action.")}
                  </TooltipContent>
                </Tooltip>
              ) : (
                <Card
                  onClick={handleCreateColumn}
                  className="cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none"
                >
                  <div className="flex items-start gap-3 p-4">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                      <Plus className="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <h3 className="text-sm font-medium text-foreground">
                        {dbLabels.createSchema}
                      </h3>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {dbLabels.createSchemaCardHint}
                      </p>
                    </div>
                  </div>
                </Card>
              )}
              {/* Row 2 */}
              {!hideSampleData &&
                (!hasDataProducingColumns ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Card className="cursor-not-allowed opacity-60 p-0 gap-0 shadow-none">
                        <div className="flex items-start gap-3 p-4">
                          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                            <BarChart3 className="h-5 w-5 text-muted-foreground" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <h3 className="text-sm font-medium text-foreground">
                              {t('Generate sample data')}
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                              {t('Generate data for testing')}
                            </p>
                          </div>
                        </div>
                      </Card>
                    </TooltipTrigger>
                    <TooltipContent>{t('Create columns first')}</TooltipContent>
                  </Tooltip>
                ) : (
                  <Card
                    onClick={handleOpenSampleDataModal}
                    className={cn(
                      'transition-colors p-0 gap-0 shadow-none',
                      sampleDataMutation.isPending || columnsLoading
                        ? 'opacity-50 cursor-not-allowed'
                        : 'cursor-pointer hover:bg-accent/50',
                    )}
                  >
                    <div className="flex items-start gap-3 p-4">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                        <BarChart3 className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="text-sm font-medium text-foreground">
                          {t('Generate sample data')}
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                          {t('Generate data for testing')}
                        </p>
                      </div>
                    </div>
                  </Card>
                ))}
              <Card
                onClick={handleDocumentation}
                className={cn(
                  'cursor-pointer transition-colors hover:bg-accent/50 p-0 gap-0 shadow-none',
                  hideSampleData && 'col-span-2',
                )}
              >
                <div className="flex items-start gap-3 p-4">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <BookOpen className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-medium text-foreground">
                      {t('Documentation')}
                    </h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {t('Read the Appwrite docs')} {/* pragma: allowlist secret */}
                    </p>
                  </div>
                </div>
              </Card>
            </div>
          </div>
        </div>

        {/* Row Update Drawer */}
        <RowEditDrawer
          open={editDrawerOpen}
          onOpenChange={setEditDrawerOpen}
          row={selectedRowForEdit}
          tableName={table.name}
          focusedField={focusedField}
          columns={apiColumns}
          onSave={handleSaveRow}
          isSaving={saveRowMutation.isPending}
        />

        {!hideSampleData && (
          <SampleDataModal
            open={sampleDataModalOpen}
            onOpenChange={setSampleDataModalOpen}
            onConfirm={handleGenerateSampleData}
            isLoading={sampleDataMutation.isPending}
          />
        )}

        {/* Column Form Dialog for empty state */}
        <ColumnDrawer
          open={columnDialogOpen}
          onOpenChange={setColumnDialogOpen}
          onSubmit={handleColumnSubmit}
          column={selectedColumn}
          currentTableId={tableId}
          availableTables={
            availableTablesForColumns?.map((t: unknown) => ({
              $id: t.$id,
              name: t.name,
            })) || []
          }
          existingColumns={apiColumns.map((c: unknown) => ({
            key: c.key || c.name || c.$id,
          }))}
          isLoading={createColumnMutationForEmptyState.isPending}
        />
      </div>
    )
  }

  return (
    <div
      className={cn(
        'flex min-h-0 flex-col',
        useInlineDocumentPane ? 'min-h-0 flex-1' : 'h-full',
      )}
    >
      {documentsListFullyEmpty ? (
        <div className="flex min-h-0 flex-1 flex-col items-center justify-center border-t border-border px-6 py-12">
          <EmptyState
            icon={FileText}
            title={
              documentsPaneHasFilters
                ? dbLabels.noRecordsFilteredTitle
                : dbLabels.noRecordsYetTitle
            }
            description={
              documentsPaneHasFilters
                ? t('Try adjusting or clearing filters.')
                : dbLabels.addFirstRecordHint
            }
            isEmpty={!documentsPaneHasFilters}
            hasFilters={documentsPaneHasFilters}
            variant="centered"
            iconSize="md"
          />
        </div>
      ) : (
      <div
        ref={documentSplitContainerRef}
        className={cn(
          'flex min-h-0 flex-1 overflow-hidden',
          useInlineDocumentPane
            ? isDocumentsStackedLayout
              ? 'flex-col'
              : 'flex-row'
            : 'flex-row',
          useInlineDocumentPane && 'relative',
        )}
      >
        <div
          ref={rowsTableScrollRef}
          style={
            useInlineDocumentPane && !isDocumentsStackedLayout
              ? {
                  width: documentTablePaneWidthPx,
                  minWidth: DOCUMENTS_TABLE_PANE_MIN_PX,
                }
              : undefined
          }
          className={cn(
            'min-h-0 min-w-0 overflow-auto overscroll-contain',
            rowMultiSelectModifierActive && 'select-none',
            useInlineDocumentPane
              ? cn(
                  'shrink-0 border-border',
                  isDocumentsStackedLayout
                    ? 'max-h-[min(42dvh,320px)] w-full border-b'
                    : 'border-e',
                  paginatedRows.length === 0 && 'border-t border-border',
                )
              : 'min-w-0 flex-1',
          )}
        >
          {useInlineDocumentPane && paginatedRows.length === 0 ? (
            <div className="flex min-h-[min(240px,40dvh)] flex-col items-stretch justify-center px-4 py-8">
              <EmptyState
                icon={FileText}
                title={
                  documentsPaneHasFilters
                    ? dbLabels.noRecordsFilteredTitle
                    : dbLabels.noRecordsYetTitle
                }
                description={
                  documentsPaneHasFilters
                    ? t('Try adjusting or clearing filters.')
                    : dbLabels.addFirstRecordHint
                }
                isEmpty={!documentsPaneHasFilters}
                hasFilters={documentsPaneHasFilters}
                variant="centered"
                iconSize="md"
              />
            </div>
          ) : (
          <div
            ref={rowsTableLayerRef}
            className={SPREADSHEET_SCROLL_LAYER_CLASS}
            style={{ minWidth: tableMinWidthPx }}
          >
          <table className="relative z-0 w-full table-fixed border-collapse">
          <colgroup>
            <col style={spreadsheetActionsColStyle} />
            {visibleRowGridKeys.map((gridKey) => {
              if (gridKey === '$sequence') {
                return (
                  <col key="col-$sequence" style={spreadsheetSequenceColStyle} />
                )
              }
              const w = getDataColumnWidthPx(gridKey)
              const isDragResize = resizingDataColumnKey === gridKey
              return (
                <col
                  key={`col-${gridKey}`}
                  ref={(node) => {
                    if (node) dataColumnColRefs.current.set(gridKey, node)
                    else dataColumnColRefs.current.delete(gridKey)
                  }}
                  style={getRowGridDataColumnLayoutStyle(w, isDragResize)}
                />
              )
            })}
            <col />
            <col style={spreadsheetActionsColStyle} />
          </colgroup>
          <thead className={stickyTheadClass}>
            <tr>
              <th
                className={cn(
                  'sticky start-0 z-40 w-10 bg-background px-2 py-2 text-center',
                  useInlineDocumentPane &&
                    'min-w-[40px] max-w-[40px] shrink-0 box-border',
                  SPREADSHEET_STICKY_START_HEADER_SHADOW,
                )}
                style={spreadsheetActionsColStyle}
              >
                <div className="flex justify-center">
                  <Checkbox
                    checked={
                      selectedRows.size === paginatedRows.length &&
                      paginatedRows.length > 0
                    }
                    onCheckedChange={toggleAll}
                  />
                </div>
              </th>
              {visibleRowGridKeys.map((gridKey) => {
                if (gridKey === '$sequence') {
                  return (
                    <th
                      key="th-$sequence"
                      className={cn(
                        'px-2 py-2 text-start',
                        headerCellBorderClass,
                      )}
                      style={spreadsheetSequenceColStyle}
                    >
                      <div className="flex min-w-0 items-center gap-1">
                        <SequenceHeaderIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <button
                          type="button"
                          aria-label={t('Copy column name: $sequence')}
                          onClick={() => void copyColumnHeaderName('$sequence')}
                          className="group inline-flex shrink-0 items-center rounded p-0.5 text-muted-foreground cursor-pointer transition-colors hover:bg-muted/80 hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                          {copiedColumnHeaderKey === '$sequence' ? (
                            <Check
                              className="h-3 w-3 shrink-0 text-green-600"
                              aria-hidden
                            />
                          ) : (
                            <Copy
                              className="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-70 group-focus-visible:opacity-70"
                              aria-hidden
                            />
                          )}
                        </button>
                      </div>
                    </th>
                  )
                }

                if (gridKey === '$id') {
                  const isDragResize = resizingDataColumnKey === '$id'
                  return (
                    <th
                      key="th-$id"
                      ref={(node) => {
                        if (node) dataColumnHeaderThRefs.current.set('$id', node)
                        else dataColumnHeaderThRefs.current.delete('$id')
                      }}
                      className={cn('px-3 py-2', headerCellBorderClass)}
                      style={getRowGridDataColumnLayoutStyle(
                        getDataColumnWidthPx('$id'),
                        isDragResize,
                      )}
                    >
                      <div className="flex min-w-0 items-center gap-2 pe-1.5">
                        <IdHeaderIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <button
                          type="button"
                          aria-label={t('Copy column name: $id')}
                          onClick={() => void copyColumnHeaderName('$id')}
                          className="group inline-flex min-w-0 max-w-full items-center gap-1 truncate rounded px-0.5 -mx-0.5 py-0 text-start text-[12px] font-medium text-foreground cursor-pointer transition-colors hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                          <span className="min-w-0 truncate">$id</span>
                          {copiedColumnHeaderKey === '$id' ? (
                            <Check
                              className="h-3 w-3 shrink-0 text-green-600"
                              aria-hidden
                            />
                          ) : (
                            <Copy
                              className="h-3 w-3 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-70 group-focus-visible:opacity-70"
                              aria-hidden
                            />
                          )}
                        </button>
                        <button
                          type="button"
                          onClick={() => handleSortColumn('$id')}
                          className="ms-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                          {sortBy === '$id' ? (
                            sortOrder === 'asc' ? (
                              <ArrowUp className="h-3 w-3 shrink-0 text-foreground" />
                            ) : (
                              <ArrowDown className="h-3 w-3 shrink-0 text-foreground" />
                            )
                          ) : (
                            <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                          )}
                        </button>
                      </div>
                    </th>
                  )
                }

                if (gridKey === '$createdAt' || gridKey === '$updatedAt') {
                  const isDragResize = resizingDataColumnKey === gridKey
                  return (
                    <th
                      key={`th-${gridKey}`}
                      ref={(node) => {
                        if (node) {
                          dataColumnHeaderThRefs.current.set(gridKey, node)
                        } else {
                          dataColumnHeaderThRefs.current.delete(gridKey)
                        }
                      }}
                      className={cn('px-3 py-2', headerCellBorderClass)}
                      style={getRowGridDataColumnLayoutStyle(
                        getDataColumnWidthPx(gridKey),
                        isDragResize,
                      )}
                    >
                      <div className="flex min-w-0 items-center gap-2 pe-1.5">
                        <Calendar className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <button
                          type="button"
                          aria-label={`Copy column name: ${gridKey}`}
                          onClick={() => void copyColumnHeaderName(gridKey)}
                          className="group inline-flex min-w-0 max-w-full items-center gap-1 truncate rounded px-0.5 -mx-0.5 py-0 text-start text-[12px] font-medium text-foreground cursor-pointer transition-colors hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                          <span className="min-w-0 truncate">{gridKey}</span>
                          {copiedColumnHeaderKey === gridKey ? (
                            <Check
                              className="h-3 w-3 shrink-0 text-green-600"
                              aria-hidden
                            />
                          ) : (
                            <Copy
                              className="h-3 w-3 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-70 group-focus-visible:opacity-70"
                              aria-hidden
                            />
                          )}
                        </button>
                        <button
                          type="button"
                          onClick={() => handleSortColumn(gridKey)}
                          className="ms-auto cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        >
                          {sortBy === gridKey ? (
                            sortOrder === 'asc' ? (
                              <ArrowUp className="h-3 w-3 shrink-0 text-chart-brand" />
                            ) : (
                              <ArrowDown className="h-3 w-3 shrink-0 text-chart-brand" />
                            )
                          ) : (
                            <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                          )}
                        </button>
                      </div>
                    </th>
                  )
                }

                const col = gridKey
                const columnInfo = apiColumns.find((c: unknown) => {
                  const rec = c as Record<string, unknown>
                  const colKey =
                    rec.key ||
                    rec.name ||
                    rec.$id ||
                    rec.attribute ||
                    rec.attributeId
                  return colKey === col
                })
                const columnType =
                  (columnInfo as { type?: string } | undefined)?.type ||
                  'string'
                const isEncryptedColumn = Boolean(
                  (columnInfo as { encrypt?: boolean } | undefined)?.encrypt,
                )
                const encryptedValuesRevealed =
                  isEncryptedColumn && revealedEncryptedColumns.has(col)
                const ColumnIcon = getColumnIcon(columnType)
                const isDragResize = resizingDataColumnKey === col
                const isCopiedHeader = copiedColumnHeaderKey === col
                return (
                  <th
                    key={col}
                    ref={(node) => {
                      if (node) {
                        dataColumnHeaderThRefs.current.set(col, node)
                      } else {
                        dataColumnHeaderThRefs.current.delete(col)
                      }
                    }}
                    className={cn('px-3 py-2', headerCellBorderClass)}
                    style={getRowGridDataColumnLayoutStyle(
                      getDataColumnWidthPx(col),
                      isDragResize,
                    )}
                  >
                    <div className="flex min-w-0 items-center gap-2 pe-1.5">
                      <ColumnIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <button
                        type="button"
                        aria-label={`Copy column name: ${col}`}
                        onClick={() => void copyColumnHeaderName(col)}
                        className="group inline-flex max-w-full min-w-0 shrink items-center gap-1 overflow-hidden rounded px-0.5 -mx-0.5 py-0 text-start text-[12px] font-medium text-foreground cursor-pointer transition-colors hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                      >
                        <span className="min-w-0 truncate">{col}</span>
                        {isCopiedHeader ? (
                          <Check
                            className="h-3 w-3 shrink-0 text-green-600"
                            aria-hidden
                          />
                        ) : (
                          <Copy
                            className="h-3 w-3 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-70 group-focus-visible:opacity-70"
                            aria-hidden
                          />
                        )}
                      </button>
                      <span className="min-w-0 flex-1 shrink" aria-hidden />
                      {isEncryptedColumn ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <button
                              type="button"
                              aria-label={
                                encryptedValuesRevealed
                                  ? t('Hide encrypted values')
                                  : t('Show encrypted values')
                              }
                              aria-pressed={encryptedValuesRevealed}
                              onClick={(e) => {
                                e.stopPropagation()
                                toggleEncryptedColumnReveal(col)
                              }}
                              className="shrink-0 cursor-pointer rounded p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                              {encryptedValuesRevealed ? (
                                <EyeOff className="h-3 w-3 shrink-0" aria-hidden />
                              ) : (
                                <Eye className="h-3 w-3 shrink-0" aria-hidden />
                              )}
                            </button>
                          </TooltipTrigger>
                          <TooltipContent side="bottom">
                            {encryptedValuesRevealed
                              ? t('Hide encrypted values')
                              : t('Show encrypted values')}
                          </TooltipContent>
                        </Tooltip>
                      ) : null}
                      <button
                        type="button"
                        onClick={() => handleSortColumn(col)}
                        className="shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                      >
                        {sortBy === col ? (
                          sortOrder === 'asc' ? (
                            <ArrowUp className="h-3 w-3 shrink-0 text-chart-brand" />
                          ) : (
                            <ArrowDown className="h-3 w-3 shrink-0 text-chart-brand" />
                          )
                        ) : (
                          <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
                        )}
                      </button>
                    </div>
                  </th>
                )
              })}
              <th aria-hidden className={SPREADSHEET_FILLER_HEADER_CLASS} />
              <th
                className={cn(
                  stickyActionsHeaderClass,
                  useInlineDocumentPane && 'shrink-0 box-border',
                )}
                style={spreadsheetActionsColStyle}
              >
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="absolute inset-0 flex items-center justify-center">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          if (canWriteTables) handleCreateColumn()
                        }}
                        disabled={!canWriteTables}
                        className={cn(
                          'flex items-center justify-center transition-colors',
                          canWriteTables
                            ? 'cursor-pointer hover:bg-muted/50'
                            : 'cursor-not-allowed opacity-50',
                        )}
                      >
                        <Plus className="h-4 w-4 text-muted-foreground" />
                      </button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent side="bottom">
                    {canWriteTables
                      ? dbLabels.createSchema
                      : t("You don't have permission to perform this action.")}
                  </TooltipContent>
                </Tooltip>
              </th>
            </tr>
          </thead>
          <tbody>
            {paginatedRows.map((row) => {
              const isInlinePreviewRow =
                useInlineDocumentPane &&
                selectedRowForEdit !== null &&
                selectedRowForEdit.$id === row.$id
              const hasPendingEdits =
                editSession?.isRowEdited(tableId, row.$id) ?? false
              return (
              <RowContextMenu
                key={row.$id}
                projectId={projectId}
                databaseId={databaseId}
                dbKind={DB_KIND}
                tableId={tableId}
                row={row}
                contextColumnKey={contextCellColumnKey}
                queryKey={['rows', 'project', projectId, databaseId, tableId]}
                onRowDeleted={
                  useInlineDocumentPane
                    ? (deletedId) => {
                        if (
                          selectedRowForEditRef.current?.$id === deletedId
                        ) {
                          setSelectedRowForEdit(null)
                          setEditDrawerOpen(false)
                          setDrawerInitialTab(null)
                        }
                      }
                    : undefined
                }
              >
                <tr
                  className={cn(
                    'group cursor-pointer transition-colors',
                    hasPendingEdits &&
                      'bg-amber-500/10 ring-1 ring-inset ring-amber-500/20 hover:bg-amber-500/15',
                    isInlinePreviewRow
                      ? 'bg-muted/25 ring-1 ring-inset ring-border/20 hover:bg-muted/35'
                      : !hasPendingEdits &&
                          (selectedRows.has(row.$id)
                            ? 'bg-muted'
                            : 'hover:bg-muted/50'),
                  )}
                  onMouseDown={preventRowTextSelectionOnPointer}
                  onClick={(e) => handleRowClick(row, e)}
                  onContextMenu={(e) => {
                    const td = (e.target as HTMLElement).closest('td')
                    const key = td?.getAttribute('data-column') ?? null
                    setContextCellColumnKey(key)
                  }}
                >
                  <td
                    className={cn(
                      'sticky start-0 w-10 border-b border-border px-2 py-1.5 text-center',
                      SPREADSHEET_STICKY_BODY_Z,
                      useInlineDocumentPane &&
                        'min-w-[40px] max-w-[40px] shrink-0 box-border',
                      SPREADSHEET_STICKY_START_EDGE_SHADOW,
                      !isInlinePreviewRow
                        ? 'bg-background'
                        : 'bg-muted/25 group-hover:bg-muted/35',
                      !isInlinePreviewRow && selectedRows.has(row.$id) && 'bg-muted',
                    )}
                    style={spreadsheetActionsColStyle}
                  >
                    <div className="flex justify-center">
                      <Checkbox
                        checked={selectedRows.has(row.$id)}
                        onCheckedChange={() => toggleRow(row.$id)}
                        onClick={(e) => e.stopPropagation()}
                      />
                    </div>
                  </td>
                  {visibleRowGridKeys.map((gridKey) => {
                    if (gridKey === '$sequence') {
                      return (
                        <td
                          key="td-$sequence"
                          className={cn('px-3 py-1.5', bodyCellBorderClass)}
                          data-column="$sequence"
                        >
                          <span className="text-[12px] text-muted-foreground">
                            {row.$sequence ?? row.rowNumber}
                          </span>
                        </td>
                      )
                    }
                    if (gridKey === '$id') {
                      return (
                        <td
                          key="td-$id"
                          className={cn('px-3 py-1.5', bodyCellBorderClass)}
                          data-column="$id"
                        >
                          <div className="min-w-0 max-w-full overflow-hidden">
                            <CopyableId
                              id={row.$id}
                              size="xs"
                              constrainToContainer
                            />
                          </div>
                        </td>
                      )
                    }
                    if (gridKey === '$createdAt' || gridKey === '$updatedAt') {
                      const systemKey = gridKey as SystemDateColumnKey
                      const originalValue = row[systemKey] ?? null
                      const displayValue =
                        editSession?.getCellDisplayValue(
                          tableId,
                          row.$id,
                          systemKey,
                          originalValue,
                        ) ?? originalValue
                      const { full, display, isNull } =
                        formatSystemDateCellDisplay(
                          displayValue as string | null,
                        )
                      return (
                        <td
                          key={`td-${systemKey}`}
                          className={cn(
                            'relative h-px p-0',
                            bodyCellBorderClass,
                          )}
                          data-column={systemKey}
                        >
                          <InlineTableCell
                            tableId={tableId}
                            rowId={row.$id}
                            columnKey={systemKey}
                            columnInfo={getSystemDateColumnInfo(systemKey)}
                            onCancelDrawerOpen={() =>
                              cancelPendingCellDrawer(row.$id, systemKey)
                            }
                            onCellClick={(event) =>
                              handleCellClick(row, systemKey, event)
                            }
                            onCellMouseDown={preventRowTextSelectionOnPointer}
                            originalValue={originalValue}
                            canWrite={canWriteRows}
                            title={full}
                            display={display}
                            isNull={isNull}
                          />
                        </td>
                      )
                    }

                    const col = gridKey
                    const columnInfo = findColumnInfo(col)
                    return (
                      <td
                        key={col}
                        className={cn('relative h-px p-0', bodyCellBorderClass)}
                        data-column={col}
                      >
                        {(() => {
                          const originalValue = row.data[
                            col as keyof typeof row.data
                          ] as
                            | string
                            | number
                            | boolean
                            | unknown[]
                            | Record<string, unknown>
                            | null
                            | undefined
                          const displayValue =
                            editSession?.getCellDisplayValue(
                              tableId,
                              row.$id,
                              col,
                              (originalValue ?? null) as
                                | string
                                | number
                                | bigint
                                | boolean
                                | unknown[]
                                | null,
                            ) ?? originalValue
                          const { full, display, isNull } = formatCellValue(
                            displayValue as
                              | string
                              | number
                              | boolean
                              | unknown[]
                              | Record<string, unknown>
                              | null
                              | undefined,
                          )
                          const cellValue = displayValue
                          const isRTLContent =
                            typeof cellValue === 'string'
                              ? isRTL(cellValue)
                              : false
                          const revealEncrypted =
                            revealedEncryptedColumns.has(col)
                          return (
                            <InlineTableCell
                              tableId={tableId}
                              rowId={row.$id}
                              columnKey={col}
                              columnInfo={columnInfo}
                              onCancelDrawerOpen={() =>
                                cancelPendingCellDrawer(row.$id, col)
                              }
                              onCellClick={(event) =>
                                handleCellClick(row, col, event)
                              }
                              onCellMouseDown={preventRowTextSelectionOnPointer}
                              originalValue={
                                (originalValue ?? null) as
                                  | string
                                  | number
                                  | bigint
                                  | boolean
                                  | unknown[]
                                  | null
                              }
                              canWrite={canWriteRows}
                              title={full}
                              dir={isRTLContent ? 'rtl' : 'ltr'}
                              display={display}
                              isNull={isNull}
                              revealEncrypted={revealEncrypted}
                            />
                          )
                        })()}
                      </td>
                    )
                  })}
                  <td aria-hidden className={SPREADSHEET_FILLER_CELL_CLASS} />
                  <td
                    className={cn(
                      stickyActionsCellBaseClass,
                      useInlineDocumentPane && 'shrink-0 box-border',
                      !isInlinePreviewRow
                        ? 'bg-background'
                        : 'bg-muted/25 group-hover:bg-muted/35',
                      !isInlinePreviewRow && selectedRows.has(row.$id) && 'bg-muted',
                    )}
                    style={spreadsheetActionsColStyle}
                  >
                    <div
                      className="flex h-full items-center justify-center py-1.5"
                      style={{ width: ROWS_TABLE_EDGE_COL_PX }}
                    >
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            onClick={(e) => e.stopPropagation()}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem
                            onClick={(e) => {
                              e.stopPropagation()
                              openRowInDrawer(row, {
                                focusedField: null,
                                initialTab: 'data',
                              })
                            }}
                          >
                            <MenuItemContent icon={Pencil}>{t('Update')}</MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            onClick={(e) => {
                              e.stopPropagation()
                              duplicateRowMutation.mutate(row)
                            }}
                            disabled={duplicateRowMutation.isPending}
                          >
                            <MenuItemContent icon={Copy}>{t('Duplicate')}</MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            onClick={(e) => {
                              e.stopPropagation()
                              setSelectedRows(new Set([row.$id]))
                              openDialogAfterOverlayCloses(() =>
                                setDeleteDialogOpen(true),
                              )
                            }}
                          >
                            <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>
              </RowContextMenu>
              )
            })}
          </tbody>
        </table>
            {columns.map((col) => {
                return (
                  <button
                    key={`col-resize-rail-${col}`}
                    ref={(node) => {
                      if (node) dataColumnRailRefs.current.set(col, node)
                      else dataColumnRailRefs.current.delete(col)
                    }}
                    type="button"
                    aria-label={`Resize ${col} column width`}
                    aria-orientation="vertical"
                    role="separator"
                    tabIndex={0}
                    onPointerDown={handleDataColumnResizePointerDown(col)}
                    className={cn(
                      DATA_COLUMN_RESIZE_RAIL_HANDLE_CLASS,
                      resizingDataColumnKey === col && 'before:opacity-100',
                      'focus-visible:ring-1 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
                    )}
                  />
                )
              })}
          </div>
          )}
        </div>
        {useInlineDocumentPane ? (
          <div
            className={cn(
              'flex min-h-0 min-w-0 flex-1 flex-col bg-muted/10',
              isDocumentsStackedLayout
                ? 'min-h-[min(46dvh,360px)] border-t-0'
                : 'min-w-[280px] border-t border-border',
            )}
          >
            {selectedRowForEdit !== null || editDrawerOpen ? (
              <RowEditDrawer
                presentation="inline"
                open={selectedRowForEdit !== null || editDrawerOpen}
                onOpenChange={(open) => {
                  setEditDrawerOpen(open)
                  if (!open) {
                    setDrawerInitialTab(null)
                    setSelectedRowForEdit(null)
                  }
                }}
                row={selectedRowForEdit}
                tableName={table.name}
                focusedField={focusedField}
                initialTab={drawerInitialTab ?? undefined}
                columns={apiColumns}
                onSave={handleSaveRow}
                isSaving={saveRowMutation.isPending}
              />
            ) : (
              <div className="flex h-full min-h-0 flex-col items-center justify-center gap-2 px-6 text-center">
                <FileText className="h-10 w-10 text-muted-foreground/45" />
                <p className="text-[14px] font-medium text-foreground">
                  {dbLabels.noRecordSelectedTitle}
                </p>
                <p className="max-w-sm text-[13px] text-muted-foreground">
                  {dbLabels.noRecordSelectedHint}
                </p>
              </div>
            )}
          </div>
        ) : null}
        {useInlineDocumentPane && !isDocumentsStackedLayout ? (
          <button
            type="button"
            aria-label={t('Resize table and document preview')}
            aria-orientation="vertical"
            role="separator"
            tabIndex={0}
            style={horizontalSplitHandleStyle(documentTablePaneWidthPx)}
            onKeyDown={(e) => {
              const splitEl = documentSplitContainerRef.current
              if (!splitEl) return
              const step = 24
              if (e.key === 'ArrowLeft') {
                e.preventDefault()
                setDocumentTablePaneWidthPx((w) => {
                  const next = clampSplitFirstPaneWidthPx(
                    w - step,
                    splitEl.clientWidth,
                    DOCUMENTS_TABLE_PANE_MIN_PX,
                    DOCUMENTS_TABLE_PANE_MAX_PX,
                    DOCUMENTS_PREVIEW_PANE_MIN_PX,
                  )
                  documentTablePaneWidthRef.current = next
                  localStorage.setItem(
                    DOCUMENTS_TABLE_PANE_WIDTH_STORAGE_KEY,
                    String(next),
                  )
                  return next
                })
              } else if (e.key === 'ArrowRight') {
                e.preventDefault()
                setDocumentTablePaneWidthPx((w) => {
                  const next = clampSplitFirstPaneWidthPx(
                    w + step,
                    splitEl.clientWidth,
                    DOCUMENTS_TABLE_PANE_MIN_PX,
                    DOCUMENTS_TABLE_PANE_MAX_PX,
                    DOCUMENTS_PREVIEW_PANE_MIN_PX,
                  )
                  documentTablePaneWidthRef.current = next
                  localStorage.setItem(
                    DOCUMENTS_TABLE_PANE_WIDTH_STORAGE_KEY,
                    String(next),
                  )
                  return next
                })
              }
            }}
            className={cn(
              'absolute top-0 bottom-0 z-30 w-1.5 cursor-col-resize border-0 bg-transparent p-0 outline-none transition-colors hover:bg-primary/20 dark:hover:bg-sidebar-accent/60',
              isDocumentsSplitResizing &&
                'bg-primary/30 dark:bg-sidebar-accent/70',
              'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
            )}
            onPointerDown={handleDocumentsSplitPointerDown}
          />
        ) : null}
      </div>
      )}

      {/* Bulk Delete Action Bar */}
      {selectedRows.size > 0 && (
        <div className="fixed bottom-4 start-1/2 z-50 w-[min(100%,calc(100vw-2rem))] max-w-md -translate-x-1/2 px-2 sm:px-0 sm:w-auto sm:max-w-none">
          <div className="mx-auto flex min-w-0 items-center justify-between gap-2 rounded-lg border border-border bg-background px-4 py-3 sm:min-w-[400px] sm:gap-3 sm:px-6">
            <Badge variant="secondary" className="h-6 px-2.5">
              {selectedRows.size === 1
                ? dbLabels.oneRecordSelectedLabel
                : `${selectedRows.size} ${dbLabels.recordsSelectedSuffix}`}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSelectedRows(new Set())}
                className="h-8 text-xs"
              >
                {t('Cancel')}
              </Button>
              <Button
                variant="destructive"
                size="sm"
                onClick={handleBulkDelete}
                disabled={bulkDeleteMutation.isPending}
                className="h-8 gap-2"
              >
                {t('Delete')}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Sticky Pagination Footer */}
      <div className="h-[54px] shrink-0 border-t border-border bg-background">
        <div className="@container flex h-full items-center justify-between gap-4 px-4">
          <div className="flex-1 min-w-0">
            <Pagination
              currentPage={displayedPage}
              totalItems={displayedRowsTotal ?? rowsTotal}
              pageSize={effectivePageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={handlePageChange}
              onPageSizeChange={handlePageSizeChange}
              itemLabel={dbLabels.recordPlural}
              className="h-full min-h-0 border-0 mt-0 py-0"
            />
          </div>
          {!hideSampleData && (
            <div className="flex-shrink-0">
              {!hasDataProducingColumns ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-block">
                      <Button
                        size="sm"
                        variant="outline"
                        disabled
                        className="h-8 gap-2 text-[12px] font-medium"
                      >
                        <Plus className="h-3.5 w-3.5" />
                        <span className="hidden @[500px]:inline">
                          {t('Sample data')}
                        </span>
                        <span className="@[500px]:hidden">{t('Sample')}</span>
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>{t('Create columns first')}</TooltipContent>
                </Tooltip>
              ) : (
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setSampleDataModalOpen(true)}
                  disabled={sampleDataMutation.isPending || columnsLoading}
                  className="h-8 gap-2 text-[12px] font-medium"
                >
                  <Plus className="h-3.5 w-3.5" />
                  <span className="hidden @[500px]:inline">{t('Sample data')}</span>
                  <span className="@[500px]:hidden">{t('Sample')}</span>
                </Button>
              )}
            </div>
          )}
        </div>
      </div>

      {!useInlineDocumentPane ? (
        <RowEditDrawer
          open={editDrawerOpen}
          onOpenChange={(open) => {
            setEditDrawerOpen(open)
            if (!open) {
              setDrawerInitialTab(null)
              setSelectedRowForEdit(null)
            }
          }}
          row={selectedRowForEdit}
          tableName={table.name}
          focusedField={focusedField}
          initialTab={drawerInitialTab ?? undefined}
          columns={apiColumns}
          onSave={handleSaveRow}
          isSaving={saveRowMutation.isPending}
        />
      ) : null}

      {/* Bulk Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{dbLabels.deleteRecordsTitle}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              {selectedRows.size}{' '}
              {selectedRows.size === 1
                ? dbLabels.recordSingular
                : dbLabels.recordPlural}
              ? {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={bulkDeleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmBulkDelete}
              disabled={bulkDeleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {!hideSampleData && (
        <SampleDataModal
          open={sampleDataModalOpen}
          onOpenChange={setSampleDataModalOpen}
          onConfirm={handleGenerateSampleData}
          isLoading={sampleDataMutation.isPending}
        />
      )}

      {/* Column Form Dialog for empty state */}
      <ColumnDrawer
        open={columnDialogOpen}
        onOpenChange={setColumnDialogOpen}
        onSubmit={handleColumnSubmit}
        column={selectedColumn}
        currentTableId={tableId}
        availableTables={
          availableTablesForColumns?.map((t: unknown) => ({
            $id: t.$id,
            name: t.name,
          })) || []
        }
        existingColumns={apiColumns.map((c: unknown) => ({
          key: c.key || c.name || c.$id,
        }))}
        isLoading={createColumnMutationForEmptyState.isPending}
      />
    </div>
  )
}

// Spreadsheet-like view for Columns
export function ColumnsSpreadsheet({
  table,
  onCreateReady,
  onSuggestReady,
  canWriteTables = true,
  filterMap: filterMapProp,
}: SpreadsheetProps) {
  const t = useT()
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id

  const [columnDialogOpen, setColumnDialogOpen] = useState(false)
  const [selectedColumn, setSelectedColumn] = useState<unknown>(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [columnToDelete, setColumnToDelete] = useState<string | null>(null)
  const [contextDialogOpen, setContextDialogOpen] = useState(false)
  const [suggestedColumns, setSuggestedColumns] = useState<unknown[]>([])
  const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false)

  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as
    | { query?: string; page?: number; limit?: number }
    | undefined
  const columnsFilterMapFromUrl = useMemo(
    () => queryParamToMap(search?.query ?? null),
    [search?.query],
  )
  const columnsFilterMap = filterMapProp ?? columnsFilterMapFromUrl
  const columnsFilterQueries =
    columnsFilterMap.size > 0
      ? Array.from(columnsFilterMap.values())
      : undefined

  const columnsListParams = useMemo(() => {
    const url = urlFromRouterLocation(location, window.location.origin)
    // Prefer router search state over URL so page size change takes effect even if URL lags
    const pageFromSearch =
      search?.page != null
        ? typeof search.page === 'number'
          ? search.page
          : Number(search.page)
        : undefined
    const limitFromSearch =
      search?.limit != null
        ? typeof search.limit === 'number'
          ? search.limit
          : Number(search.limit)
        : undefined
    const page =
      Number.isInteger(pageFromSearch) && (pageFromSearch ?? 0) >= 1
        ? pageFromSearch!
        : getPage(url, 1)
    const limit =
      Number.isInteger(limitFromSearch) && (limitFromSearch ?? 0) >= 1
        ? limitFromSearch!
        : getLimit(url, COLUMNS_INDEXES_DEFAULT_PAGE_SIZE)
    return { page, limit }
  }, [location.pathname, location.search, search?.page, search?.limit])
  const columnsPage = columnsListParams.page
  const columnsLimit = columnsListParams.limit
  const columnsPageIndexed = Math.max(0, columnsPage - 1)

  // Keep showing previous results while fetching new filter results (no empty state flash)
  const lastDisplayColumnsRef = useRef<
    Array<{
      key: string
      type: string
      [key: string]: unknown
    }>
  >([])

  // Fetch columns from the project SDK (listColumns with optional filter queries, ordered by $createdAt asc, paginated)
  const {
    columns: apiColumns,
    total: columnsTotal,
    isLoading: columnsLoading,
    isFetching: columnsFetching,
    refetch: refetchColumns,
  } = useProjectTableColumns(
    projectId,
    databaseId,
    DB_KIND,
    tableId,
    columnsFilterQueries,
    columnsPageIndexed,
    columnsLimit,
  )

  // Reuses indexes prefetched by the columns route / Workspace (same query key, no extra fetch)
  const { indexes: tableIndexes } = useProjectTableIndexes(
    projectId,
    databaseId,
    DB_KIND,
    tableId,
  )

  const indexedColumnKeys = useMemo(() => {
    const keys = new Set<string>(['$id'])
    for (const index of tableIndexes) {
      const columns = (index as { columns?: string[] }).columns
      if (!columns?.length) continue
      for (const columnKey of columns) {
        if (columnKey) keys.add(columnKey)
      }
    }
    return keys
  }, [tableIndexes])

  const hasPendingColumnStatuses = useMemo(
    () =>
      apiColumns.some((col) =>
        isTableColumnStatusPending(
          (col as { status?: string }).status,
        ),
      ),
    [apiColumns],
  )

  useEffect(() => {
    if (!hasPendingColumnStatuses) return
    const intervalId = window.setInterval(() => {
      void refetchColumns()
    }, 2000)
    return () => window.clearInterval(intervalId)
  }, [hasPendingColumnStatuses, refetchColumns])

  const navigateColumnsList = (updates: { page?: number; limit?: number }) => {
    navigate({
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...(typeof prev === 'object' && prev !== null ? prev : {}),
          ...(updates.page != null && { page: updates.page }),
          ...(updates.limit != null && { limit: updates.limit }),
        }
        return next
      },
      replace: true,
    })
  }

  // Fetch full table for row size metadata (bytesUsed, bytesMax) when creating varchar columns
  const { table: fullTable } = useProjectTable(
    projectId,
    databaseId,
    DB_KIND,
    tableId,
  )

  // Fetch tables for relationship columns
  const { tables: availableTables } = useTablesForColumns(
    projectId,
    databaseId,
    DB_KIND,
    0,
    100,
    undefined,
  )

  // Create column mutation
  const createColumnMutation = useMutation({
    mutationFn: async (data: ColumnFormData) => {
      return await createProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        data,
      )
    },
    onSuccess: async () => {
      await refetchProjectTableRelatedQueries(
        queryClient,
        projectId,
        databaseId,
        tableId,
      )
      setColumnDialogOpen(false)
      setSelectedColumn(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to create column'))
    },
  })

  // Update column mutation
  const updateColumnMutation = useMutation({
    mutationFn: async ({
      columnKey,
      data,
    }: {
      columnKey: string
      data: Partial<ColumnFormData>
    }) => {
      return await updateProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        columnKey,
        data,
      )
    },
    onSuccess: async () => {
      await refetchProjectTableRelatedQueries(
        queryClient,
        projectId,
        databaseId,
        tableId,
      )
      setColumnDialogOpen(false)
      setSelectedColumn(null)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update column'))
    },
  })

  // Delete column mutation
  const deleteColumnMutation = useMutation({
    mutationFn: async (columnKey: string) => {
      return await deleteProjectTableColumn(
        projectId,
        databaseId,
        tableId,
        columnKey,
      )
    },
    onMutate: async (columnKey: string) => {
      lastDisplayColumnsRef.current = []
      await queryClient.cancelQueries({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
      })
      patchProjectTableColumnsCache(
        queryClient,
        projectId,
        databaseId,
        tableId,
        (columns) =>
          columns.map((col) => {
            const c = col as Record<string, unknown>
            const key = String(c.key ?? c.name ?? c.$id ?? '')
            if (key === columnKey) {
              return { ...c, status: 'deleting' }
            }
            return col
          }),
      )
    },
    onSuccess: async () => {
      await refetchProjectTableRelatedQueries(
        queryClient,
        projectId,
        databaseId,
        tableId,
      )
      setDeleteDialogOpen(false)
      setColumnToDelete(null)
      toast.success(t('Column deleted'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete column'))
    },
  })

  const handleCreateColumn = () => {
    setSelectedColumn(null)
    setColumnDialogOpen(true)
  }

  const handleGenerateSuggestions = async (context: string) => {
    setIsLoadingSuggestions(true)
    try {
      const projectSdk = sdk.forProject(projectId)
      const result = await projectSdk.console.suggestColumns({
        databaseId,
        tableId,
        context: context || undefined,
        min: 3,
        max: 7,
      })

      // Map API suggestions to display format with suggestion flag
      const mapped = result.columns.map((col: unknown) => ({
        key: col.key || col.name || col.$id || 'unnamed',
        type: col.type || 'string',
        // String fields
        size: col.size,
        encrypt: col.encrypt,
        // Number fields
        min: col.min,
        max: col.max,
        // Enum fields
        elements: col.elements,
        // Relationship fields
        relatedTableId: col.relatedTableId ?? col.relatedTable,
        relationshipType: col.relationshipType ?? col.relationType,
        twoWay: col.twoWay,
        twoWayKey: col.twoWayKey,
        onDelete: col.onDelete,
        // Common fields
        required: col.required ?? false,
        array: col.array ?? false,
        xdefault: col.default ?? col.xdefault,
        default: col.default, // Keep for backward compatibility
        isSuggestion: true,
        originalData: col,
      }))

      setSuggestedColumns(mapped)
      setContextDialogOpen(false)
      toast.success(
        `${t('Generated')} ${mapped.length} ${t('column suggestions')}`,
      )

      // Scroll to first suggestion after DOM updates
      setTimeout(() => {
        const firstSuggestionRow = document.querySelector(
          '[data-suggestion-row="true"]',
        )
        if (firstSuggestionRow) {
          firstSuggestionRow.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
          })
        }
      }, 100)
    } catch (error) {
      toast.error(getErrorMessage(error))
      setContextDialogOpen(false)
    } finally {
      setIsLoadingSuggestions(false)
    }
  }

  const handleApproveSuggestion = async (suggestionKey: string) => {
    try {
      // Get the current suggestion data from state (in case it was edited)
      const suggestion = suggestedColumns.find((s) => s.key === suggestionKey)
      if (!suggestion) {
        toast.error(t('Suggestion not found'))
        return
      }

      // Extract all relevant fields, ensuring we get updated values
      // Only set size for string and varchar (index/suggestions payload)
      const suggestionType = suggestion.type as string
      const columnData: ColumnFormData = {
        key: suggestion.key,
        type: suggestion.type as ColumnType,
        required: suggestion.required ?? false,
        array: suggestion.array ?? false,
        // Type-specific fields
        size:
          suggestionType === 'string' || suggestionType === 'varchar'
            ? suggestion.size
            : undefined,
        encrypt: [
          'string',
          'text',
          'mediumtext',
          'longtext',
          'varchar',
        ].includes(suggestionType)
          ? suggestion.encrypt
          : undefined,
        min: suggestion.min,
        max: suggestion.max,
        elements: suggestion.elements,
        xdefault: suggestion.xdefault ?? suggestion.default,
        // Relationship fields
        relatedTableId: suggestion.relatedTableId ?? suggestion.relatedTable,
        relationshipType:
          suggestion.relationshipType ?? suggestion.relationType,
        twoWay: suggestion.twoWay,
        twoWayKey: suggestion.twoWayKey,
        onDelete: suggestion.onDelete,
      }

      await createColumnMutation.mutateAsync(columnData)

      // Remove from suggestions
      setSuggestedColumns((prev) => prev.filter((s) => s.key !== suggestionKey))

      toast.success(
        `${t('Column')} "${suggestion.key}" ${t('created successfully')}`,
      )
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleRemoveSuggestion = (key: string) => {
    setSuggestedColumns((prev) => prev.filter((s) => s.key !== key))
  }

  const handleEditSuggestion = (suggestion: unknown) => {
    setSelectedColumn({ ...suggestion, isSuggestion: true })
    setColumnDialogOpen(true)
  }

  const handleSuggestionSubmit = (key: string, data: ColumnFormData) => {
    // Update the suggestion in the list, ensuring all fields are properly merged
    setSuggestedColumns((prev) =>
      prev.map((s) =>
        s.key === key ? { ...s, ...data, isSuggestion: true } : s,
      ),
    )
    setColumnDialogOpen(false)
    setSelectedColumn(null)
    toast.success(t('Suggestion updated'))
  }

  // Expose create function to parent
  useEffect(() => {
    if (onCreateReady) {
      onCreateReady(handleCreateColumn)
    }
  }, [onCreateReady])

  // Expose suggest function to parent
  useEffect(() => {
    if (onSuggestReady) {
      onSuggestReady(() => setContextDialogOpen(true))
    }
  }, [onSuggestReady])

  // Check for openSuggest URL parameter and auto-open modal
  useEffect(() => {
    const searchParams = searchParamsFromRouterLocation(location)
    if (searchParams.get('openSuggest') === 'true') {
      setContextDialogOpen(true)
      // Clean up URL parameter
      searchParams.delete('openSuggest')
      const newSearch = searchParams.toString()
      navigate({
        to: location.pathname,
        search: newSearch ? `?${newSearch}` : '',
        replace: true,
      })
    }
  }, [location.search, location.pathname, navigate])

  const handleEditColumn = (column: unknown) => {
    setSelectedColumn(column)
    setColumnDialogOpen(true)
  }

  const handleDeleteColumn = (columnKey: string) => {
    setColumnToDelete(columnKey)
    setDeleteDialogOpen(true)
  }

  const handleColumnSubmit = async (data: ColumnFormData) => {
    if (selectedColumn?.isSuggestion) {
      // Update suggestion in place
      handleSuggestionSubmit(selectedColumn.key, data)
    } else if (selectedColumn) {
      await updateColumnMutation.mutateAsync({
        columnKey:
          selectedColumn.key || selectedColumn.name || selectedColumn.$id,
        data,
      })
    } else {
      await createColumnMutation.mutateAsync(data)
    }
  }

  const handleConfirmDelete = () => {
    if (columnToDelete) {
      deleteColumnMutation.mutate(columnToDelete)
    }
  }

  const clearAllColumnsFilters = () => {
    navigate({
      to: location.pathname,
      search: (prev) => ({
        ...(typeof prev === 'object' && prev !== null ? prev : {}),
        query: undefined,
      }),
      replace: true,
    })
  }

  // Map API columns and prepend built-in system columns ($id, $createdAt, $updatedAt).
  const columns = mergeTablesDbSystemColumnsIntoList(
    apiColumns
      .filter((col) => !isTablesDbSystemColumnKey(getTableColumnKey(col)))
      .map(
        (col: unknown): MappedTableColumnListItem => ({
          key: getTableColumnKey(col),
          type: (col as { type?: string }).type || 'string',
          format: (col as { format?: string | null }).format || null,
          // String fields
          size: (col as { size?: number | null }).size || null,
          encrypt: (col as { encrypt?: boolean }).encrypt || false,
          // Number fields
          min: (col as { min?: unknown }).min,
          max: (col as { max?: unknown }).max,
          // Enum fields
          elements: (col as { elements?: string[] | null }).elements || null,
          // Relationship fields
          relatedTableId:
            (col as { relatedTableId?: unknown }).relatedTableId ??
            (col as { relatedTable?: unknown }).relatedTable,
          relationshipType:
            (col as { relationshipType?: unknown }).relationshipType ??
            (col as { relationType?: unknown }).relationType,
          twoWay: (col as { twoWay?: unknown }).twoWay,
          twoWayKey: (col as { twoWayKey?: unknown }).twoWayKey,
          onDelete: (col as { onDelete?: unknown }).onDelete,
          // Common fields
          required: (col as { required?: boolean }).required || false,
          array: (col as { array?: boolean }).array || false,
          default: (col as { default?: unknown }).default || null,
          xdefault:
            (col as { xdefault?: unknown }).xdefault ??
            (col as { default?: unknown }).default ??
            null,
          status: normalizeTableColumnStatus(
            (col as { status?: string }).status,
          ),
          error: (col as { error?: string }).error || '',
          $id: String((col as { $id?: string }).$id ?? getTableColumnKey(col)),
        }),
      ),
  )

  const displayColumns = columns

  useEffect(() => {
    if (!columnsFetching && displayColumns.length > 0) {
      lastDisplayColumnsRef.current = displayColumns
    }
  }, [columnsFetching, displayColumns])
  const columnsToShow =
    columnsFilterMap.size > 0 &&
      columnsFetching &&
      lastDisplayColumnsRef.current.length > 0
      ? lastDisplayColumnsRef.current
      : displayColumns

  // Combine regular columns with suggestions
  const allColumns = [...columnsToShow, ...suggestedColumns]

  if (columnsLoading && lastDisplayColumnsRef.current.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Loading columns...')}</div>
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col relative">
      <>
          <div
            className={cn(
              'flex-1 overflow-auto overscroll-contain',
              suggestedColumns.length > 0 && 'pb-24',
            )}
          >
            <div
              className={SPREADSHEET_SCROLL_LAYER_CLASS}
              style={{ minWidth: COLUMNS_GRID_MIN_WIDTH_PX }}
            >
            <table className="w-full border-collapse">
              <thead className={stickyTheadClass}>
                <tr>
                  <th
                    className={cn(
                      'min-w-[200px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Key')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[120px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Type')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'w-[120px] min-w-[120px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Status')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[80px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Size')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[108px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Range')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[80px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Required')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[80px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Array')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[80px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Encrypted')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[80px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Index')}
                    </span>
                  </th>
                  <th
                    className={cn(
                      'min-w-[120px] px-3 py-2 text-start',
                      headerCellBorderClass,
                    )}
                  >
                    <span className="text-[12px] font-medium text-foreground">
                      {t('Default')}
                    </span>
                  </th>
                  <th aria-hidden className={SPREADSHEET_FILLER_HEADER_CLASS} />
                  <th
                    className={stickyActionsHeaderClass}
                    style={spreadsheetActionsColStyle}
                  />
                </tr>
              </thead>
              <tbody>
                {allColumns.map((col: unknown) => {
                  const Icon = getColumnIcon(col.type)
                  const isSystem = col.key
                    ? isTablesDbSystemColumnKey(col.key)
                    : false
                  const isSuggestion = col.isSuggestion
                  const columnStatus = normalizeTableColumnStatus(col.status)
                  const isStatusPending = isTableColumnStatusPending(columnStatus)
                  const rangeDisplay =
                    !isSuggestion && columnTypeSupportsNumericRange(col.type)
                      ? formatColumnRangeDisplay(col.min, col.max, col.type)
                      : null
                  const defaultDisplay = !isSuggestion
                    ? formatColumnNumericDisplay(col.default, col.type)
                    : null
                  return (
                    <tr
                      key={col.key}
                      data-suggestion-row={isSuggestion ? 'true' : undefined}
                      className={cn(
                        'group transition-colors hover:bg-muted/50',
                        isSystem && 'bg-muted/30',
                        isSuggestion && 'bg-amber-500/5',
                        isStatusPending && 'opacity-80',
                      )}
                    >
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        <div className="flex items-center justify-between gap-2">
                          <div className="flex items-center gap-2">
                            <Icon className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                            <code
                              className={cn(
                                'font-mono text-[12px]',
                                isSystem
                                  ? 'text-muted-foreground'
                                  : 'text-foreground',
                              )}
                            >
                              {col.key || 'unnamed'}
                            </code>
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <button
                                  type="button"
                                  onClick={(e) => {
                                    e.stopPropagation()
                                    const name = col.key || 'unnamed'
                                    navigator.clipboard.writeText(name)
                                    toast.success(t('Column name copied'))
                                  }}
                                  className="flex h-6 w-6 shrink-0 items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground group-hover:opacity-100"
                                >
                                  <Copy className="h-3 w-3" />
                                </button>
                              </TooltipTrigger>
                              <TooltipContent side="top">
                                <p>{t('Copy column name')}</p>
                              </TooltipContent>
                            </Tooltip>
                          </div>
                          {isSuggestion && (
                            <div className="flex items-center gap-1">
                              <button
                                onClick={() => handleApproveSuggestion(col.key)}
                                className="flex h-6 w-6 items-center justify-center rounded bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors cursor-pointer"
                              >
                                <Check className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                              </button>
                              <button
                                onClick={() => handleRemoveSuggestion(col.key)}
                                className="flex h-6 w-6 items-center justify-center rounded bg-destructive/10 hover:bg-destructive/20 transition-colors cursor-pointer"
                              >
                                <X className="h-3.5 w-3.5 text-destructive" />
                              </button>
                              <div className="h-4 w-px bg-border mx-0.5" />
                              <button
                                onClick={() => handleEditSuggestion(col)}
                                className="flex h-6 w-6 items-center justify-center rounded bg-blue-500/10 hover:bg-blue-500/20 transition-colors cursor-pointer"
                              >
                                <Pencil className="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                              </button>
                            </div>
                          )}
                        </div>
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        <Badge
                          variant="outline"
                          className={cn(
                            'text-[11px] font-medium border',
                            getColumnTypeColor(
                              getColumnDisplayType(
                                col as Record<string, unknown>,
                              ),
                            ),
                          )}
                        >
                          {getColumnDisplayType(col as Record<string, unknown>)}
                        </Badge>
                      </td>
                      <td
                        className={cn(
                          'w-[120px] min-w-[120px] px-3 py-2',
                          bodyCellBorderClass,
                        )}
                      >
                        {isSuggestion ? (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        ) : (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="inline-flex w-full min-w-0">
                                <Badge
                                  variant={getTableColumnStatusBadgeVariant(
                                    columnStatus,
                                  )}
                                  className="max-w-full truncate text-[11px] font-medium capitalize"
                                >
                                  {localizeResourceStatusLabel(columnStatus, t)}
                                </Badge>
                              </span>
                            </TooltipTrigger>
                            {col.error ? (
                              <TooltipContent side="top" className="max-w-xs">
                                <p className="text-[12px]">{col.error}</p>
                              </TooltipContent>
                            ) : null}
                          </Tooltip>
                        )}
                      </td>
                      <td
                        className={cn(
                          'px-3 py-2 text-[12px] text-muted-foreground',
                          bodyCellBorderClass,
                        )}
                      >
                        {col.key !== '$id' &&
                          (col.type === 'string' || col.type === 'varchar')
                          ? (col.size ?? '-')
                          : '-'}
                      </td>
                      <td
                        className={cn(
                          'px-3 py-2 text-[12px] text-muted-foreground',
                          bodyCellBorderClass,
                        )}
                      >
                        {rangeDisplay ? (
                          <NumericValueTooltip display={rangeDisplay} />
                        ) : (
                          '-'
                        )}
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        {col.required ? (
                          <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                             - 
                          </span>
                        )}
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        {col.array ? (
                          <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                             - 
                          </span>
                        )}
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        {col.encrypt ? (
                          <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                             - 
                          </span>
                        )}
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        {isSuggestion ? (
                          <span className="text-[12px] text-muted-foreground">
                            -
                          </span>
                        ) : indexedColumnKeys.has(col.key) ? (
                          <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                        ) : (
                          <span className="text-[12px] text-muted-foreground">
                             - 
                          </span>
                        )}
                      </td>
                      <td className={cn('px-3 py-2', bodyCellBorderClass)}>
                        {defaultDisplay ? (
                          <NumericValueTooltip
                            display={defaultDisplay}
                            className="text-muted-foreground"
                          />
                        ) : (
                          <code className="font-mono text-[11px] text-muted-foreground">
                            {col.default === null || col.default === undefined
                              ? 'NULL'
                              : String(col.default)}
                          </code>
                        )}
                      </td>
                      <td aria-hidden className={SPREADSHEET_FILLER_CELL_CLASS} />
                      <td
                        className={cn(
                          stickyActionsCellBaseClass,
                          isSystem
                            ? 'bg-muted/30'
                            : isSuggestion
                              ? 'bg-amber-500/5'
                              : 'bg-background group-hover:bg-muted/50',
                        )}
                        style={spreadsheetActionsColStyle}
                      >
                        <div
                          className="flex h-full items-center justify-center py-1.5"
                          style={spreadsheetActionsColStyle}
                        >
                        {!isSuggestion && !isSystem && !isStatusPending && (
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <RowActionsMenuTrigger />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() => handleEditColumn(col)}
                              >
                                <MenuItemContent icon={Pencil}>{t('Update')}</MenuItemContent>
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                onClick={() => handleDeleteColumn(col.key)}
                              >
                                <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            </div>
          </div>

          {/* Sticky Pagination Footer */}
          <div className="h-[54px] shrink-0 border-t border-border bg-background">
            <div className="@container flex h-full items-center justify-between gap-4 px-4">
              <div className="flex-1 min-w-0">
                <Pagination
                  currentPage={columnsPage}
                  totalItems={columnsTotal ?? 0}
                  pageSize={columnsLimit}
                  pageSizeOptions={[10, 25, 50, 100]}
                  onPageChange={(page) => navigateColumnsList({ page })}
                  onPageSizeChange={(newLimit) =>
                    navigateColumnsList({ page: 1, limit: newLimit })
                  }
                  itemLabel="columns"
                />
              </div>
              {suggestedColumns.length > 0 && (
                <div className="flex-shrink-0 text-[12px] text-amber-600 dark:text-amber-400">
                  {suggestedColumns.length} suggestion
                  {suggestedColumns.length !== 1 ? 's' : ''}
                </div>
              )}
            </div>
          </div>
        </>

      {/* Bulk Action Bar for Suggestions */}
      {suggestedColumns.length > 0 && (
        <div className="absolute bottom-4 start-1/2 z-50 -translate-x-1/2">
          <div className="flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
            <Badge variant="secondary" className="h-6 px-2.5">
              <Lightbulb className="h-3 w-3 me-1.5" />
              {suggestedColumns.length} suggestion
              {suggestedColumns.length !== 1 ? 's' : ''}
            </Badge>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setSuggestedColumns([])}
                className="h-8"
              >
                {t('Clear all')}
              </Button>
              <Button
                size="sm"
                onClick={async () => {
                  for (const suggestion of suggestedColumns) {
                    await handleApproveSuggestion(suggestion.key)
                  }
                }}
                disabled={createColumnMutation.isPending}
                className="h-8"
              >
                <Check className="h-3.5 w-3.5 me-1.5" />
                {t('Approve all')}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Column Form Dialog */}
      <ColumnDrawer
        open={columnDialogOpen}
        onOpenChange={(open) => {
          setColumnDialogOpen(open)
          if (!open && selectedColumn?.isSuggestion) {
            setSelectedColumn(null)
          }
        }}
        onSubmit={handleColumnSubmit}
        column={selectedColumn}
        currentTableId={tableId}
        availableTables={availableTables.map((t) => ({
          $id: t.$id,
          name: t.name,
        }))}
        existingColumns={apiColumns.map((c: unknown) => ({
          key:
            (c as { key?: string }).key ||
            (c as { name?: string }).name ||
            (c as { $id?: string }).$id,
        }))}
        isLoading={
          createColumnMutation.isPending || updateColumnMutation.isPending
        }
        table={(() => {
          const t = fullTable ?? table
          const bytesUsed = (t as { bytesUsed?: number })?.bytesUsed
          const bytesMax = (t as { bytesMax?: number })?.bytesMax
          return bytesUsed !== undefined && bytesMax !== undefined
            ? { bytesUsed, bytesMax }
            : undefined
        })()}
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete Column')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete the column')}{' '}
              &quot;{columnToDelete}&quot;?{' '}
              {t('This action cannot be undone and may affect existing rows.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteColumnMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={handleConfirmDelete}
              disabled={deleteColumnMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Context Input Dialog */}
      <Dialog
        open={contextDialogOpen}
        onOpenChange={(open) => {
          if (!isLoadingSuggestions) {
            setContextDialogOpen(open)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          {isLoadingSuggestions ? (
            <>
              <DialogHeader className="px-6 pt-6 pb-4 text-start">
                <DialogTitle>{t('Generating suggestions')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t('AI is analyzing your table structure and generating column suggestions...')}
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0 mt-8 mb-4 flex flex-col items-center justify-center gap-4">
                <div className="relative">
                  <Lightbulb className="h-12 w-12 text-amber-500 animate-pulse" />
                  <div className="absolute inset-0 bg-amber-500/20 rounded-full animate-ping" />
                </div>
                <p className="text-[13px] text-muted-foreground text-center">
                  {t('This may take a few seconds...')}
                </p>
              </div>
            </>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                const formData = new FormData(e.currentTarget)
                const context = formData.get('context') as string
                handleGenerateSuggestions(context)
              }}
            >
              <DialogHeader className="px-6 pt-6 pb-4 text-start">
                <DialogTitle>{t('AI column suggestions')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t('Provide optional context or instructions to help generate better column suggestions for this table.')}
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-0">
                <div className="space-y-2 mt-4">
                  <Label htmlFor="context" className="text-[12px] font-medium">
                    {t('Context (Optional)')}
                  </Label>
                  <Textarea
                    id="context"
                    name="context"
                    placeholder={t('E.g., This is a social media app with user posts and comments...')}
                    className="min-h-[100px] text-[13px]"
                  />
                  <p className="text-[11px] text-muted-foreground">
                    {t('The AI will analyze your table name and existing database structure to suggest relevant columns.')}
                  </p>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setContextDialogOpen(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button type="submit">{t('Generate suggestions')}</Button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}


// Table Security
export function TableSecurity({ table }: SpreadsheetProps) {
  const t = useT()
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const tableId = table.$id
  const queryClient = useQueryClient()

  // Fetch full table data
  const { table: tableData, isLoading: tableLoading } = useProjectTable(
    projectId,
    databaseId,
    DB_KIND,
    tableId,
  )

  // State for Permissions
  const [tablePermissions, setTablePermissions] = useState<string[]>([])

  // State for Security
  const [tableRowSecurity, setTableRowSecurity] = useState<boolean | null>(null)

  // Initialize state from table data
  useEffect(() => {
    if (tableData) {
      // Always sync permissions from table data to ensure we have the latest
      const tablePerms = tableData.$permissions || []
      // Only update if permissions actually changed (avoid unnecessary re-renders)
      const currentPermsStr = JSON.stringify([...tablePermissions].sort())
      const newPermsStr = JSON.stringify([...tablePerms].sort())
      if (currentPermsStr !== newPermsStr) {
        setTablePermissions(tablePerms)
      }
      if (tableRowSecurity === null) setTableRowSecurity(tableData.rowSecurity)
    }
  }, [tableData, tablePermissions, tableRowSecurity])

  // Helper to check if arrays are different
  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    const sortedA = [...a].sort()
    const sortedB = [...b].sort()
    return sortedA.every((val, idx) => val === sortedB[idx])
  }

  // Handle permissions change from PermissionsEditor
  const handlePermissionsChange = (newPermissions: string[]) => {
    setTablePermissions(newPermissions)
  }

  // Update Permissions mutation
  const updatePermissionsMutation = useMutation({
    mutationFn: async (newPermissions: string[]) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, DB_KIND, tableId, {
        name: tableData.name,
        permissions: newPermissions,
        rowSecurity: tableData.rowSecurity,
        enabled: tableData.enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      toast.success(t('Permissions have been updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update permissions'))
    },
  })

  // Update Security mutation
  const updateSecurityMutation = useMutation({
    mutationFn: async (newRowSecurity: boolean) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, DB_KIND, tableId, {
        name: tableData.name,
        permissions: tableData.$permissions || [],
        rowSecurity: newRowSecurity,
        enabled: tableData.enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      toast.success(t('Security has been updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update security'))
    },
  })

  if (tableLoading || !tableData) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Loading...')}</div>
      </div>
    )
  }

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Update Permissions */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Permissions')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              Choose who can access your tables and rows.{' '}
              <DocsRouteLink className="link-neutral" href="/docs/products/databases/permissions">
                {t('Learn more')}
              </DocsRouteLink>
              .
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <PermissionsEditor
              permissions={tablePermissions}
              onPermissionsChange={handlePermissionsChange}
              withCreate={true}
              projectId={projectId}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                arraysEqual(tablePermissions, tableData.$permissions || []) ||
                updatePermissionsMutation.isPending
              }
              onClick={() => {
                updatePermissionsMutation.mutate(tablePermissions)
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Row Level Security (RLS) */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Row level security (RLS)')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Switch
                  id="security"
                  checked={tableRowSecurity ?? false}
                  onCheckedChange={(checked) => setTableRowSecurity(checked)}
                />
                <Label
                  htmlFor="security"
                  className="text-[13px] text-foreground"
                >
                  {t('Row level security (RLS)')}
                </Label>
              </div>
            </div>
            <div className="mt-4 space-y-2">
              <p className="text-[13px] text-muted-foreground">
                {t('When row security is enabled, users need')}{' '}
                <strong>{t('both table permissions and row permissions')}</strong>{' '}
                {t('to access rows. Row permissions are an additional layer, not an alternative to table permissions.')}
              </p>
              <p className="text-[13px] text-muted-foreground">
                <strong>{t('Create operations')}</strong>{' '}
                {t('always require table-level permissions, regardless of row security settings.')}
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t('If row security is disabled, users can access rows')}{' '}
                <strong>{t('only if they have table permissions')}</strong>.{' '}
                {t('Row permissions will be ignored.')}
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                tableRowSecurity === tableData.rowSecurity ||
                updateSecurityMutation.isPending
              }
              onClick={() => {
                if (
                  tableRowSecurity !== null &&
                  tableRowSecurity !== tableData.rowSecurity
                ) {
                  updateSecurityMutation.mutate(tableRowSecurity)
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

// Table Settings
export function TableSettings({
  table,
}: Pick<SpreadsheetProps, 'table'>) {
  const t = useT()
  const params = useParams({
    strict: false,
  })
  const projectId = params.projectId as string
  const databaseId = params.databaseId as string
  const settingsNav = useMemo(() => dbNavLink(DB_KIND), [])
  const tableId = table.$id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { account } = useAuth()
  const organizationId = account?.prefs?.organization as string | undefined

  // Fetch full table data
  const { table: tableData, isLoading: tableLoading } = useProjectTable(
    projectId,
    databaseId,
    DB_KIND,
    tableId,
  )
  const { columns: tableColumns } = useProjectTableColumns(
    projectId,
    databaseId,
    DB_KIND,
    tableId,
  )

  // State for Update Status
  const [enabled, setEnabled] = useState<boolean | null>(null)

  // State for Update Name
  const [tableName, setTableName] = useState('')

  // State for Display Names
  const [displayNames, setDisplayNames] = useState<string[]>(['$id'])

  // State for Delete
  const [showDelete, setShowDelete] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  // Initialize state from table data
  useEffect(() => {
    if (tableData) {
      if (enabled === null) setEnabled(tableData.enabled)
      if (!tableName) setTableName(tableData.name)
    }
  }, [tableData, enabled, tableName])

  // Load display names from preferences
  useEffect(() => {
    if (organizationId && tableId) {
      const loadDisplayNames = async () => {
        try {
          const team = await sdk.forConsole.teams.get({
            teamId: organizationId,
          })
          const prefs = team.prefs || {}
          const savedNames = prefs.displayNames?.[tableId] as
            | string[]
            | undefined
          if (savedNames && savedNames.length > 0) {
            setDisplayNames(savedNames)
          }
        } catch {
          // Silently handle display names loading error
        }
      }
      loadDisplayNames()
    }
  }, [organizationId, tableId])

  // Helper to check if arrays are different
  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    const sortedA = [...a].sort()
    const sortedB = [...b].sort()
    return sortedA.every((val, idx) => val === sortedB[idx])
  }

  // Update Status mutation
  const toggleTableMutation = useMutation({
    mutationFn: async (newEnabled: boolean) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, DB_KIND, tableId, {
        name: tableData.name,
        permissions: tableData.$permissions || [],
        rowSecurity: tableData.rowSecurity,
        enabled: newEnabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(`${tableData?.name || dbLabels.containerSingularTitle} ${t('has been updated')}`)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update table'))
    },
  })

  // Update Name mutation
  const updateNameMutation = useMutation({
    mutationFn: async (newName: string) => {
      if (!tableData) throw new Error('Table data not available')
      return await updateProjectTable(projectId, databaseId, DB_KIND, tableId, {
        name: newName,
        permissions: tableData.$permissions || [],
        rowSecurity: tableData.rowSecurity,
        enabled: tableData.enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
      })
      queryClient.invalidateQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(t('Name has been updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update name'))
    },
  })

  // Update Display Names mutation
  const updateDisplayNamesMutation = useMutation({
    mutationFn: async (names: string[]) => {
      if (!organizationId) throw new Error('Organization ID not available')
      await updateConsoleTeamPrefs(organizationId, (freshPrefs) => ({
        displayNames: {
          ...((freshPrefs.displayNames as Record<string, string[]>) || {}),
          [tableId]: names,
        },
      }))
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'console', organizationId],
      })
      toast.success(t('Display names have been updated'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update display names'))
    },
  })

  // Delete mutation
  const deleteTableMutation = useMutation({
    mutationFn: async () => {
      return await deleteProjectTable(projectId, databaseId, DB_KIND, tableId)
    },
    onSuccess: async () => {
      try {
        const acct = await fetchConsoleAccount()
        const prefsAfterWidths = deleteDatabaseTableRowColumnWidthsFromPrefs(
          (acct.prefs || {}) as UserPrefs,
          databaseId,
          tableId,
        )
        const updatedAccount = await updateAccountPrefs(
          deleteTablesDbRowsListColumnsFromPrefs(
            prefsAfterWidths,
            databaseId,
            tableId,
          ),
        )
        syncConsoleAccountAfterMutation(queryClient, {
          apiResult: updatedAccount,
        })
      } catch {
        // Silently handle preference deletion error
      }

      // Delete table preferences (team)
      if (organizationId) {
        try {
          await updateConsoleTeamPrefs(
            organizationId,
            (freshPrefs) => {
              const updatedPrefs = { ...freshPrefs }
              if (updatedPrefs.displayNames) {
                const displayNames = {
                  ...(updatedPrefs.displayNames as Record<string, unknown>),
                }
                delete displayNames[tableId]
                updatedPrefs.displayNames = displayNames
              }
              if (updatedPrefs.tables) {
                const tables = {
                  ...(updatedPrefs.tables as Record<string, unknown>),
                }
                delete tables[tableId]
                updatedPrefs.tables = tables
              }
              if (updatedPrefs.columnOrder) {
                const columnOrder = {
                  ...(updatedPrefs.columnOrder as Record<string, unknown>),
                }
                delete columnOrder[tableId]
                updatedPrefs.columnOrder = columnOrder
              }
              if (updatedPrefs.columnWidths) {
                const columnWidths = {
                  ...(updatedPrefs.columnWidths as Record<string, unknown>),
                }
                delete columnWidths[tableId]
                delete columnWidths[`${tableId}#columns`]
                delete columnWidths[`${tableId}#indexes`]
                updatedPrefs.columnWidths = columnWidths
              }
              return updatedPrefs
            },
            { mode: 'replace' },
          )
          queryClient.invalidateQueries({
            queryKey: ['team', 'console', organizationId],
          })
        } catch {
          // Silently handle preference deletion error
        }
      }

      await queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId, databaseId],
      })
      toast.success(`${tableData?.name || dbLabels.containerSingularTitle} ${t('has been deleted')}`)
      setShowDelete(false)
      setDeleteError(null)

      navigate({
        ...settingsNav.dataGrid({
          projectId,
          dbKind: DB_KIND,
          databaseId,
          resourceId: '-',
        }),
        replace: true,
      })
    },
    onError: (error: Error) => {
      setDeleteError(error.message || t('Failed to delete table'))
    },
  })

  if (tableLoading || !tableData) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Loading...')}</div>
      </div>
    )
  }

  // Get valid text-type columns for display names (string, varchar, text, mediumtext, longtext; not array)
  const validStringColumns = tableColumns.filter(
    (col: unknown) => isTextType(col.type) && col.array === false,
  )

  // Filter display name options (exclude already selected except current)
  const getDisplayNameOptions = (index: number) => {
    return validStringColumns
      .filter((col: unknown) => {
        const key = col.key
        // Include if not selected elsewhere, or if it's the current selection
        return !displayNames.some((name, idx) => name === key && idx !== index)
      })
      .map((col: unknown) => ({
        value: col.key,
        label: col.key,
      }))
  }

  const handleAddDisplayNameColumn = () => {
    if (displayNames.length < 5) {
      setDisplayNames([...displayNames, ''])
    }
  }

  const handleRemoveDisplayNameColumn = (index: number) => {
    if (displayNames.length > 1 && index > 0) {
      const newNames = [...displayNames]
      newNames.splice(index, 1)
      setDisplayNames(newNames)
    }
  }

  const handleDisplayNameChange = (index: number, value: string) => {
    const newNames = [...displayNames]
    newNames[index] = value
    setDisplayNames(newNames)
  }

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Update Status */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {tableData.name}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Switch
                  id="toggle"
                  checked={enabled ?? false}
                  onCheckedChange={(checked) => setEnabled(checked)}
                />
                <Label htmlFor="toggle" className="text-[13px] text-foreground">
                  {enabled ? 'Enabled' : 'Disabled'}
                </Label>
              </div>
            </div>
            <div className="mt-4 space-y-1">
              <p className="text-[13px] text-muted-foreground">
                Created:{' '}
                <DateTooltip
                  date={tableData.$createdAt}
                  showFormattedDate
                  className="text-foreground"
                />
              </p>
              <p className="text-[13px] text-muted-foreground">
                Last updated:{' '}
                <DateTooltip
                  date={tableData.$updatedAt}
                  showFormattedDate
                  className="text-foreground"
                />
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                enabled === tableData.enabled || toggleTableMutation.isPending
              }
              onClick={() => {
                if (enabled !== null && enabled !== tableData.enabled) {
                  toggleTableMutation.mutate(enabled)
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Update Name */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">{t('Name')}</h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Input
              id="name"
              value={tableName}
              onChange={(e) => setTableName(e.target.value)}
              placeholder={t('Enter name')}
              autoComplete="off"
              className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                tableName === tableData.name ||
                !tableName.trim() ||
                updateNameMutation.isPending
              }
              onClick={() => {
                if (tableName.trim() && tableName !== tableData.name) {
                  updateNameMutation.mutate(tableName.trim())
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Display Name */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Display name')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Select up to 5 string columns to display as row names in the Appwrite console. These help identify rows in places like relationships.')} {/* pragma: allowlist secret */}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-3">
            {displayNames.map((name, index) => (
              <div key={index} className="flex items-center gap-2">
                {index === 0 ? (
                  <>
                    <Input
                      value="Row ID"
                      readOnly
                      className="h-9 flex-1 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                    />
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-9 w-9 p-0 invisible"
                      disabled
                      aria-hidden="true"
                      tabIndex={-1}
                    >
                      <X className="h-4 w-4" aria-hidden />
                    </Button>
                  </>
                ) : (
                  <>
                    <Select
                      value={name || ''}
                      onValueChange={(value) =>
                        handleDisplayNameChange(index, value)
                      }
                    >
                      <SelectTrigger className="h-9 w-[200px] border-border bg-background text-[13px]">
                        <SelectValue placeholder={t('Select column')} />
                      </SelectTrigger>
                      <SelectContent>
                        {getDisplayNameOptions(index).map(
                          (option: { value: string; label: string }) => (
                            <SelectItem key={option.value} value={option.value}>
                              {option.label}
                            </SelectItem>
                          ),
                        )}
                      </SelectContent>
                    </Select>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-9 w-9 p-0"
                      onClick={() => handleRemoveDisplayNameColumn(index)}
                      disabled={displayNames.length === 1}
                      aria-label={t('Remove display name column')}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </>
                )}
              </div>
            ))}
            {displayNames.length < 5 &&
              validStringColumns.length >
              displayNames.filter((n) => n && n !== '$id').length && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={handleAddDisplayNameColumn}
                  disabled={displayNames[displayNames.length - 1] === ''}
                >
                  <Plus className="h-4 w-4 me-1.5" />
                  {t('Add column')}
                </Button>
              )}
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                (() => {
                  try {
                    const saved = (account?.prefs as unknown)?.displayNames?.[
                      tableId
                    ] as string[] | undefined
                    const savedNames =
                      saved && saved.length > 0 ? saved : ['$id']
                    const currentNames = displayNames.filter(Boolean)
                    return arraysEqual(currentNames, savedNames)
                  } catch {
                    return arraysEqual(displayNames.filter(Boolean), ['$id'])
                  }
                })() ||
                displayNames[displayNames.length - 1] === '' ||
                updateDisplayNamesMutation.isPending
              }
              onClick={() => {
                const namesToSave = displayNames.filter(Boolean)
                if (namesToSave.length > 0) {
                  updateDisplayNamesMutation.mutate(namesToSave)
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Danger Zone */}
        <div className="rounded-xl border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-red-600 dark:text-red-400">
              {t('Delete table')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('The table will be permanently deleted, including all the rows within it. This action is irreversible.')}
            </p>
          </div>
          <div className="border-t" />
          <div className="px-6 py-4">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                <Table2 className="h-5 w-5 text-muted-foreground" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-[14px] font-medium text-foreground truncate">
                  {tableData.name}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  Last updated:{' '}
                  <DateTooltip
                    date={tableData.$updatedAt}
                    showFormattedDate
                    className="text-foreground"
                  />
                </p>
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t bg-red-500/5">
            <Dialog open={showDelete} onOpenChange={setShowDelete}>
              <DialogTrigger asChild>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                >
                  {t('Delete')}
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-md p-0">
                <DialogHeader className="px-6 pt-6 text-start">
                  <DialogTitle>{t('Delete table')}</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    Are you sure you want to delete{' '}
                    <strong>{tableData.name}</strong>? This action cannot be
                    undone.
                  </DialogDescription>
                </DialogHeader>
                {deleteError && (
                  <div className="px-6 pt-4">
                    <p className="text-[13px] text-destructive">
                      {deleteError}
                    </p>
                  </div>
                )}
                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => {
                      setShowDelete(false)
                      setDeleteError(null)
                    }}
                    disabled={deleteTableMutation.isPending}
                  >
                    {t('Cancel')}
                  </Button>
                  <Button
                    variant="destructive"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => {
                      deleteTableMutation.mutate()
                    }}
                    disabled={deleteTableMutation.isPending}
                  >
                    {t('Delete')}
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </div>
        </div>
      </div>
    </div>
  )
}
