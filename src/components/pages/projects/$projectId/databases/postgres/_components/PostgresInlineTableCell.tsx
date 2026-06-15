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
import { usePostgresRowsEditSession } from './PostgresRowsEditSession'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  useCallback,
  useEffect,
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
  title?: string
  dir?: 'ltr' | 'rtl'
  display: string
  isNull: boolean
  onCancelDrawerOpen?: () => void
  onCellClick?: (event: MouseEvent) => void
}

const CELL_SURFACE_CLASS =
  'absolute inset-0 flex items-center px-3 py-1.5 text-left'

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
  title,
  dir,
  display,
  isNull,
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
    const handlePointerDown = (event: PointerEvent) => {
      if (
        editContainerRef.current &&
        !editContainerRef.current.contains(event.target as Node)
      ) {
        commitLocalEdit()
      }
    }
    document.addEventListener('pointerdown', handlePointerDown)
    return () => document.removeEventListener('pointerdown', handlePointerDown)
  }, [commitLocalEdit, isEditing])

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

  return (
    <td
      data-column={column.column_name}
      className={cn(
        'relative px-4 py-2.5 border-b border-r border-border',
        isEdited && 'bg-amber-500/20',
        className,
      )}
      onClick={onCellClick}
      onDoubleClick={startEditing}
    >
      {isEditing ? (
        <div ref={editContainerRef} className={CELL_SURFACE_CLASS}>
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
      ) : (
        <span
          className={cn(
            'block max-w-[320px] truncate whitespace-nowrap text-[12px] font-mono',
            isNull ? 'text-foreground/60' : 'text-foreground',
          )}
          title={title}
          dir={dir}
        >
          {display}
        </span>
      )}
    </td>
  )
}
