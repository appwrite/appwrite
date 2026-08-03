import { cn } from '@/lib/utils'
import {
  getEnumOptions,
  getInlineFieldType,
  getInlineInputConfig,
  isColumnInlineEditable,
  isDateTimeInlineFieldType,
  isInlineColumnRequired,
  isNumericInlineFieldType,
  parseAndValidateInlineCellInput,
  parseAndValidateInlineCellValue,
  resolveInlineColumnInfo,
  rowCellValuesEqual,
  valueToInlineEditString,
  type RowCellValue,
} from '@/lib/database-row-inline-edits'
import { BlurredSensitiveText } from '@/components/global/shared/BlurredSensitiveText'
import { useTableRowsEditSession } from './TableRowsEditSession'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
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
import { useT } from '@/lib/i18n/translate'

type InlineTableCellProps = {
  tableId: string
  rowId: string
  columnKey: string
  columnInfo?: unknown
  onCancelDrawerOpen?: () => void
  onCellClick?: (event: MouseEvent) => void
  onCellMouseDown?: (event: MouseEvent) => void
  originalValue: RowCellValue
  canWrite?: boolean
  className?: string
  title?: string
  dir?: 'ltr' | 'rtl'
  display: string
  isNull: boolean
  /** When false, encrypted non-null values are blurred (header eye toggle). */
  revealEncrypted?: boolean
}

const CELL_SURFACE_CLASS =
  'absolute inset-0 flex items-center px-3 py-1.5 text-start'

const INLINE_INPUT_CLASS =
  'h-7 w-full border-amber-500/40 bg-background px-2 text-[12px] shadow-none'

export function InlineTableCell({
  tableId,
  rowId,
  columnKey,
  columnInfo,
  onCancelDrawerOpen,
  onCellClick,
  onCellMouseDown,
  originalValue,
  canWrite = true,
  className,
  title,
  dir,
  display,
  isNull,
  revealEncrypted = false,
}: InlineTableCellProps) {
  const t = useT()
  const editSession = useTableRowsEditSession()
  const [isEditing, setIsEditing] = useState(false)
  const [draft, setDraft] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const editContainerRef = useRef<HTMLDivElement>(null)
  const commitLocalEditRef = useRef<() => boolean>(() => false)

  const resolvedColumnInfo = resolveInlineColumnInfo(columnKey, columnInfo)

  const editable =
    canWrite &&
    (editSession?.canWrite ?? true) &&
    isColumnInlineEditable(resolvedColumnInfo, columnKey)
  const fieldType = getInlineFieldType(resolvedColumnInfo, originalValue, columnKey)
  const isRequired = isInlineColumnRequired(resolvedColumnInfo)
  const inputConfig = getInlineInputConfig(resolvedColumnInfo, columnKey)
  const displayValue =
    editSession?.getCellDisplayValue(
      tableId,
      rowId,
      columnKey,
      originalValue,
    ) ?? originalValue
  const isEdited =
    editSession?.isCellEdited(tableId, rowId, columnKey) ?? false
  const blurEncryptedValue =
    Boolean(resolvedColumnInfo.encrypt) && !isNull && !revealEncrypted

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
          rowId,
          columnKey,
          commit: () => commitLocalEditRef.current(),
        }) ?? true
      if (!started) return
      setDraft(valueToInlineEditString(displayValue, resolvedColumnInfo, columnKey))
      setIsEditing(true)
    },
    [
      columnInfo,
      columnKey,
      displayValue,
      editable,
      editSession,
      onCancelDrawerOpen,
      rowId,
      tableId,
    ],
  )

  const commitLocalEdit = useCallback((): boolean => {
    if (!editSession) {
      setIsEditing(false)
      return true
    }

    let result
    if (fieldType === 'boolean' || fieldType === 'bool') {
      const boolValue = draft === 'true'
      result = parseAndValidateInlineCellValue(boolValue, resolvedColumnInfo, columnKey)
    } else if (isDateTimeInlineFieldType(fieldType)) {
      result = parseAndValidateInlineCellValue(
        draft ? draft : null,
        resolvedColumnInfo,
        columnKey,
      )
    } else {
      result = parseAndValidateInlineCellInput(draft, resolvedColumnInfo, columnKey)
    }

    if (!result.ok) {
      setValidationError(result.error)
      toast.error(result.error)
      return false
    }

    setValidationError(null)
    editSession.setCellEdit({
      tableId,
      rowId,
      columnKey,
      originalValue,
      value: result.value,
    })
    setIsEditing(false)
    editSession.endInlineEdit(tableId, rowId, columnKey)
    return true
  }, [
    columnInfo,
    columnKey,
    draft,
    editSession,
    fieldType,
    originalValue,
    rowId,
    tableId,
  ])

  commitLocalEditRef.current = commitLocalEdit

  const cancelEditing = useCallback(() => {
    setValidationError(null)
    setIsEditing(false)
    setDraft(
      valueToInlineEditString(
        displayValue,
        resolveInlineColumnInfo(columnKey, columnInfo),
        columnKey,
      ),
    )
    editSession?.endInlineEdit(tableId, rowId, columnKey)
  }, [columnInfo, columnKey, displayValue, editSession, rowId, tableId])

  useEffect(() => {
    if (!isEditing) return
    const onPointerDown = (event: PointerEvent) => {
      const target = event.target as HTMLElement
      if (editContainerRef.current?.contains(target)) return
      if (target.closest('[data-radix-popper-content-wrapper]')) return
      const committed = commitLocalEditRef.current()
      if (committed) {
        editSession?.markSuppressNextDrawerOpen()
      }
    }
    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [editSession, isEditing])

  useEffect(() => {
    if (!isEditing) return
    const el = inputRef.current
    if (!el) return
    el.focus()
    el.select()
  }, [isEditing, fieldType])

  const handleKeyDown = (event: KeyboardEvent) => {
    event.stopPropagation()
    if (event.key === 'Enter') {
      event.preventDefault()
      commitLocalEdit()
    } else if (event.key === 'Escape') {
      event.preventDefault()
      if (
        editSession &&
        !rowCellValuesEqual(displayValue, originalValue)
      ) {
        editSession.clearCellEdit(tableId, rowId, columnKey)
      }
      cancelEditing()
    }
  }

  const renderTextInput = () => (
    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
      <Input
        ref={inputRef}
        value={draft}
        onChange={(e) => {
          setDraft(e.target.value)
          if (validationError) setValidationError(null)
        }}
        onBlur={() => commitLocalEdit()}
        onKeyDown={handleKeyDown}
        onClick={(e) => e.stopPropagation()}
        onMouseDown={(e) => e.stopPropagation()}
        type={inputConfig.htmlType}
        inputMode={inputConfig.inputMode}
        min={inputConfig.min}
        max={inputConfig.max}
        step={inputConfig.step}
        maxLength={inputConfig.maxLength}
        placeholder={inputConfig.placeholder ? t(inputConfig.placeholder) : undefined}
        aria-invalid={validationError ? true : undefined}
        className={cn(
          INLINE_INPUT_CLASS,
          validationError && 'border-destructive',
        )}
      />
      {validationError ? (
        <span className="truncate text-[10px] text-destructive">
          {validationError}
        </span>
      ) : null}
    </div>
  )

  if (!editable) {
    return (
      <div
        className={cn(CELL_SURFACE_CLASS, className)}
        title={blurEncryptedValue ? undefined : title}
        onClick={(event) => {
          event.stopPropagation()
          onCellClick?.(event)
        }}
        onMouseDown={(event) => {
          event.stopPropagation()
          onCellMouseDown?.(event)
        }}
      >
        <BlurredSensitiveText
          value={display}
          blurred={blurEncryptedValue}
          className={cn(
            'block min-w-0 max-w-full truncate whitespace-nowrap text-[12px]',
            isNull ? 'text-foreground/60' : 'text-foreground',
          )}
          dir={dir}
        />
      </div>
    )
  }

  if (isEditing) {
    if (fieldType === 'boolean' || fieldType === 'bool') {
      const boolValue = draft === 'true'
      return (
        <div
          ref={editContainerRef}
          className={cn(CELL_SURFACE_CLASS, 'gap-2')}
          onClick={(e) => e.stopPropagation()}
          onMouseDown={(e) => e.stopPropagation()}
        >
          <Switch
            checked={boolValue}
            onCheckedChange={(checked) => {
              setDraft(checked ? 'true' : 'false')
              if (validationError) setValidationError(null)
            }}
          />
          <span className="text-[11px] text-muted-foreground">
            {boolValue ? 'true' : 'false'}
          </span>
          <button
            type="button"
            className="ms-auto text-[11px] font-medium text-primary"
            onClick={commitLocalEdit}
          >
            {t('Done')}
          </button>
        </div>
      )
    }

    if (fieldType === 'enum') {
      const options = getEnumOptions(resolvedColumnInfo)
      return (
        <div ref={editContainerRef} className={CELL_SURFACE_CLASS}>
          <Select
            value={draft || (isRequired ? options[0] ?? '' : '__null__')}
            onValueChange={(value) => {
              const next = value === '__null__' ? '' : value
              setDraft(next)
              const result = parseAndValidateInlineCellInput(
                next,
                resolvedColumnInfo,
                columnKey,
              )
              if (!result.ok) {
                setValidationError(result.error)
                toast.error(result.error)
                return
              }
              if (!editSession) return
              editSession.setCellEdit({
                tableId,
                rowId,
                columnKey,
                originalValue,
                value: result.value,
              })
              setIsEditing(false)
              editSession.endInlineEdit(tableId, rowId, columnKey)
            }}
          >
            <SelectTrigger
              className={cn(INLINE_INPUT_CLASS, 'h-7')}
              onClick={(e) => e.stopPropagation()}
              onMouseDown={(e) => e.stopPropagation()}
            >
              <SelectValue placeholder={isRequired ? undefined : 'NULL'} />
            </SelectTrigger>
            <SelectContent>
              {!isRequired ? <SelectItem value="__null__">NULL</SelectItem> : null}
              {options.map((option) => (
                <SelectItem key={option} value={option}>
                  {option}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )
    }

    if (isDateTimeInlineFieldType(fieldType)) {
      return (
        <div
          ref={editContainerRef}
          className={cn(CELL_SURFACE_CLASS, 'flex-col items-stretch gap-0.5')}
          onClick={(e) => e.stopPropagation()}
          onMouseDown={(e) => e.stopPropagation()}
        >
          <DateTimePicker
            value={draft || null}
            onChange={(val) => {
              setDraft(val ?? '')
              if (validationError) setValidationError(null)
            }}
            clearable={!isRequired}
            placeholder={inputConfig.placeholder ? t(inputConfig.placeholder) : undefined}
            size="sm"
            hideIcon
            className={cn(
              'h-7 w-full justify-start border border-amber-500/40 bg-background px-2 text-[12px] font-normal shadow-none',
              validationError && 'border-destructive',
            )}
          />
          {validationError ? (
            <span className="truncate text-[10px] text-destructive">
              {validationError}
            </span>
          ) : null}
        </div>
      )
    }

    if (isNumericInlineFieldType(fieldType)) {
      return (
        <div ref={editContainerRef} className={CELL_SURFACE_CLASS}>
          {renderTextInput()}
        </div>
      )
    }

    return (
      <div ref={editContainerRef} className={CELL_SURFACE_CLASS}>
        {renderTextInput()}
      </div>
    )
  }

  return (
    <div
      className={cn(
        CELL_SURFACE_CLASS,
        isEdited && 'bg-amber-500/20',
        !isEdited && 'hover:bg-muted/60',
        className,
      )}
      title={
        blurEncryptedValue
          ? undefined
          : (title ??
            (editable ? `${display} · Double-click to edit inline` : display))
      }
      onClick={(event) => {
        event.stopPropagation()
        onCellClick?.(event)
      }}
      onDoubleClick={startEditing}
      onMouseDown={(event) => {
        event.stopPropagation()
        onCellMouseDown?.(event)
      }}
    >
      <BlurredSensitiveText
        value={display}
        blurred={blurEncryptedValue}
        className={cn(
          'block min-w-0 max-w-full truncate whitespace-nowrap text-[12px]',
          isNull ? 'text-foreground/60' : 'text-foreground',
          isEdited && 'font-medium text-amber-950 dark:text-amber-50',
        )}
        dir={dir}
      />
    </div>
  )
}
