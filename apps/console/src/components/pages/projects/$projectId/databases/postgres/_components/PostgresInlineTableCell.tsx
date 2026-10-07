import { cn } from '@/lib/utils'
import {
  getPostgresColumnEditMeta,
  getPostgresInlineFieldType,
  isPostgresColumnInlineEditable,
  isPostgresColumnRequired,
  parseAndValidatePostgresCellInput,
  valueToPostgresEditString,
} from '@/lib/postgres-row-edits'
import type { PostgresTableColumnRow } from '@/lib/postgres-sql'
import type { PostgresRowIdentity } from '@/lib/postgres-row-sql'
import {
  isDateTimeInlineFieldType,
  isNumericInlineFieldType,
  type RowCellValue,
} from '@/lib/database-row-inline-edits'
import {
  formatSpreadsheetCellValue,
  isSpreadsheetRtlText,
} from '@/lib/spreadsheet-cell-formatting'
import { usePostgresRowsEditSession } from './PostgresRowsEditSession'
import { POSTGRES_BODY_CELL_BORDER_CLASS } from './postgres-spreadsheet-chrome'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
  type MouseEvent,
} from 'react'
import { toast } from 'sonner'

type PostgresInlineTableCellProps = {
  tableId: string
  rowKey: string
  column: PostgresTableColumnRow
  identity: PostgresRowIdentity
  originalValue: RowCellValue
  canWrite?: boolean
  className?: string
  onCancelDrawerOpen?: () => void
  onCellClick?: (event: MouseEvent) => void
}

const CELL_SURFACE_CLASS =
  'absolute inset-0 flex items-center px-3 py-1.5 text-start'

const INLINE_INPUT_CLASS =
  'h-7 w-full border-amber-500/40 bg-background px-2 text-[12px] shadow-none'

export function PostgresInlineTableCell({
  tableId,
  rowKey,
  column,
  identity,
  originalValue,
  canWrite = true,
  className,
  onCancelDrawerOpen,
  onCellClick,
}: PostgresInlineTableCellProps) {
  const editSession = usePostgresRowsEditSession()
  const [isEditing, setIsEditing] = useState(false)
  const [draft, setDraft] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const editContainerRef = useRef<HTMLDivElement>(null)
  const commitLocalEditRef = useRef<() => boolean>(() => false)

  const meta = getPostgresColumnEditMeta(column)
  const editable =
    canWrite &&
    (editSession?.canWrite ?? true) &&
    isPostgresColumnInlineEditable(column)
  const fieldType = getPostgresInlineFieldType(meta, originalValue)
  const isRequired = isPostgresColumnRequired(column)
  const displayValue =
    editSession?.getCellDisplayValue(
      tableId,
      rowKey,
      column.column_name,
      originalValue,
    ) ?? originalValue
  const isEdited =
    editSession?.isCellEdited(tableId, rowKey, column.column_name) ?? false

  const formattedCell = useMemo(
    () => formatSpreadsheetCellValue(displayValue),
    [displayValue],
  )
  const shownDisplay = formattedCell.display
  const shownIsNull = formattedCell.isNull
  const shownTitle = formattedCell.full
  const shownDir = isSpreadsheetRtlText(shownTitle) ? 'rtl' : undefined
  const cellTitle =
    editable
      ? `${shownTitle} · Double-click to edit inline`
      : shownTitle

  const startEditing = useCallback(
    (event?: MouseEvent) => {
      event?.stopPropagation()
      event?.preventDefault()
      if (!editable) return
      onCancelDrawerOpen?.()
      setValidationError(null)
      const started =
        editSession?.beginInlineEdit({
          tableId,
          rowKey,
          columnKey: column.column_name,
          commit: () => commitLocalEditRef.current(),
        }) ?? true
      if (!started) return
      setDraft(valueToPostgresEditString(displayValue, meta))
      setIsEditing(true)
    },
    [
      column.column_name,
      displayValue,
      editable,
      editSession,
      meta,
      onCancelDrawerOpen,
      rowKey,
      tableId,
    ],
  )

  const commitLocalEdit = useCallback((): boolean => {
    if (!editSession) {
      setIsEditing(false)
      return true
    }

    const result = parseAndValidatePostgresCellInput(draft, column)
    if (!result.ok) {
      setValidationError(result.error)
      toast.error(result.error)
      return false
    }

    editSession.setCellEdit({
      tableId,
      rowKey,
      columnKey: column.column_name,
      originalValue,
      value: result.value,
      identity,
    })

    setIsEditing(false)
    editSession.endInlineEdit(tableId, rowKey, column.column_name)
    return true
  }, [column, draft, editSession, identity, originalValue, rowKey, tableId])

  commitLocalEditRef.current = commitLocalEdit

  useEffect(() => {
    if (!isEditing) return
    const onPointerDown = (event: PointerEvent) => {
      const target = event.target as HTMLElement
      if (editContainerRef.current?.contains(target)) return
      if (target.closest('[data-radix-popper-content-wrapper]')) return
      if (target.closest('[data-slot="select-trigger"]')) return
      if (target.closest('[data-slot="select-content"]')) return
      if (target.closest('[data-slot="dropdown-menu-trigger"]')) return
      if (target.closest('[data-slot="dropdown-menu-content"]')) return
      const committed = commitLocalEditRef.current()
      if (committed) {
        editSession?.markSuppressNextDrawerOpen()
      }
    }
    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [editSession, isEditing])

  useEffect(() => {
    if (isEditing && inputRef.current) {
      inputRef.current.focus()
      inputRef.current.select()
    }
  }, [isEditing])

  const handleKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'Enter') {
      event.preventDefault()
      commitLocalEdit()
    }
    if (event.key === 'Escape') {
      event.preventDefault()
      setIsEditing(false)
      editSession?.endInlineEdit(tableId, rowKey, column.column_name)
    }
  }

  const renderEditingSurface = () => (
    <div
      ref={editContainerRef}
      className={CELL_SURFACE_CLASS}
      onClick={(event) => event.stopPropagation()}
      onMouseDown={(event) => event.stopPropagation()}
    >
      {fieldType === 'boolean' ? (
        <Switch
          checked={draft === 'true'}
          onCheckedChange={(checked) => {
            setDraft(checked ? 'true' : isRequired ? 'false' : '')
          }}
        />
      ) : isDateTimeInlineFieldType(fieldType) ? (
        <DateTimePicker
          value={draft || null}
          onChange={(value) => setDraft(value ?? '')}
          className="h-7 w-full text-[12px]"
        />
      ) : (
        <Input
          ref={inputRef}
          type={isNumericInlineFieldType(fieldType) ? 'number' : 'text'}
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={handleKeyDown}
          className={INLINE_INPUT_CLASS}
          aria-invalid={validationError ? true : undefined}
        />
      )}
    </div>
  )

  const renderReadOnlySurface = () => (
    <div
      className={CELL_SURFACE_CLASS}
      title={cellTitle}
      onClick={(event) => {
        event.stopPropagation()
        onCellClick?.(event)
      }}
      onMouseDown={(event) => event.stopPropagation()}
    >
      <span
        className={cn(
          'block min-w-0 max-w-full truncate whitespace-nowrap text-[12px]',
          shownIsNull ? 'text-foreground/60' : 'text-foreground',
        )}
        dir={shownDir}
      >
        {shownDisplay}
      </span>
    </div>
  )

  const renderEditableSurface = () => {
    if (isEditing) {
      return renderEditingSurface()
    }

    return (
      <div
        className={cn(
          CELL_SURFACE_CLASS,
          isEdited && 'bg-amber-500/20',
          !isEdited && 'hover:bg-muted/60',
        )}
        title={cellTitle}
        onClick={(event) => {
          event.stopPropagation()
          onCellClick?.(event)
        }}
        onDoubleClick={startEditing}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <span
          className={cn(
            'block min-w-0 max-w-full truncate whitespace-nowrap text-[12px]',
            shownIsNull ? 'text-foreground/60' : 'text-foreground',
            isEdited && 'font-medium text-amber-950 dark:text-amber-50',
          )}
          dir={shownDir}
        >
          {shownDisplay}
        </span>
      </div>
    )
  }

  return (
    <td
      data-column={column.column_name}
      className={cn(
        'relative h-px p-0',
        POSTGRES_BODY_CELL_BORDER_CLASS,
        className,
      )}
    >
      {editable ? renderEditableSurface() : renderReadOnlySurface()}
    </td>
  )
}
