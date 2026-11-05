import { useMemo, type ReactNode, type Ref } from 'react'
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { Brackets, GripVertical, Plus, X } from 'lucide-react'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import type { DatabaseArrayElementType } from '@/lib/database-array-field'
import { isSpreadsheetRtlText } from '@/lib/spreadsheet-cell-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { DatabaseArrayItemTextField } from './DatabaseArrayItemTextField'

export type { DatabaseArrayElementType } from '@/lib/database-array-field'

export type DatabaseArrayFieldEditorProps = {
  idPrefix: string
  items: RowCellValue[]
  onChange: (items: RowCellValue[]) => void
  elementType: DatabaseArrayElementType
  required?: boolean
  maxLength?: number
  enumOptions?: string[]
  autoFocus?: boolean
  /** Focus target for drawer open (first item control, or Add when empty). */
  focusRef?: Ref<
    HTMLInputElement | HTMLTextAreaElement | HTMLButtonElement | null
  >
  disabled?: boolean
}

function assignFocusRef(
  focusRef:
    | Ref<HTMLInputElement | HTMLTextAreaElement | HTMLButtonElement | null>
    | undefined,
  el: HTMLInputElement | HTMLTextAreaElement | HTMLButtonElement | null,
) {
  if (!focusRef) return
  if (typeof focusRef === 'function') {
    focusRef(el)
    return
  }
  focusRef.current = el
}

function arrayItemSortableId(idPrefix: string, index: number) {
  return `${idPrefix}::__arr__::${index}`
}

function ArraySortableRow({
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
    animateLayoutChanges: () => false,
    transition: null,
  })

  return (
    <div
      ref={setNodeRef}
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
      }}
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

function coerceArrayItemValue(
  elementType: DatabaseArrayElementType,
  raw: string,
): RowCellValue {
  if (raw === '') return null
  if (elementType === 'integer') {
    const parsed = Number.parseInt(raw, 10)
    return Number.isFinite(parsed) ? parsed : null
  }
  if (elementType === 'bigint') {
    try {
      return BigInt(raw)
    } catch {
      return null
    }
  }
  if (elementType === 'double') {
    const parsed = Number.parseFloat(raw)
    return Number.isFinite(parsed) ? parsed : null
  }
  return raw
}

export function DatabaseArrayFieldEditor({
  idPrefix,
  items,
  onChange,
  elementType,
  required = false,
  maxLength,
  enumOptions = [],
  autoFocus = false,
  focusRef,
  disabled = false,
}: DatabaseArrayFieldEditorProps) {
  const t = useT()
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  const sortableIds = useMemo(
    () => items.map((_, index) => arrayItemSortableId(idPrefix, index)),
    [idPrefix, items],
  )

  const updateItem = (index: number, value: RowCellValue) => {
    const next = [...items]
    next[index] = value
    onChange(next)
  }

  const removeItem = (index: number) => {
    onChange(items.filter((_, itemIndex) => itemIndex !== index))
  }

  const addItem = () => {
    const defaultValue: RowCellValue =
      elementType === 'boolean' ? false : elementType === 'integer' || elementType === 'double' ? 0 : ''
    onChange([...items, defaultValue])
  }

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event
    if (!over || active.id === over.id) return
    const oldIndex = sortableIds.indexOf(String(active.id))
    const newIndex = sortableIds.indexOf(String(over.id))
    if (oldIndex < 0 || newIndex < 0) return
    const next = [...items]
    const [moved] = next.splice(oldIndex, 1)
    next.splice(newIndex, 0, moved)
    onChange(next)
  }

  const isNumericType =
    elementType === 'integer' ||
    elementType === 'bigint' ||
    elementType === 'double'
  const isBoolType = elementType === 'boolean'
  const isEnumType = elementType === 'enum'
  const isDateTimeType = elementType === 'datetime'
  const useTextarea =
    elementType === 'text' ||
    elementType === 'string' ||
    (maxLength != null && maxLength >= 50)
  const hasLimit =
    maxLength != null &&
    maxLength > 0 &&
    (elementType === 'string' || elementType === 'text')
  const showNullCheckbox = !required
  const usesIntegratedTextField =
    useTextarea &&
    !isNumericType &&
    !isBoolType &&
    !isEnumType &&
    !isDateTimeType

  const renderItemControl = (item: RowCellValue, index: number) => {
    const isNull = item === null
    const stringValue = isNull ? '' : String(item ?? '')
    const isRTLContent = isSpreadsheetRtlText(stringValue)
    const placeholder = `Item ${index + 1}`
    const setFirstControlRef = (
      el: HTMLInputElement | HTMLTextAreaElement | HTMLButtonElement | null,
    ) => {
      if (index === 0) assignFocusRef(focusRef, el)
    }

    if (isNumericType) {
      return (
        <Input
          type={elementType === 'bigint' ? 'text' : 'number'}
          inputMode="numeric"
          value={isNull ? '' : String(item ?? '')}
          ref={setFirstControlRef}
          autoFocus={autoFocus && index === 0}
          disabled={disabled || isNull}
          onChange={(event) => {
            updateItem(index, coerceArrayItemValue(elementType, event.target.value))
          }}
          step={elementType === 'double' ? 0.1 : 1}
          placeholder={placeholder}
          className="h-9 rounded-none border-0 bg-transparent px-3 text-[13px] focus-visible:ring-0 focus-visible:ring-offset-0"
        />
      )
    }

    if (isBoolType) {
      return (
        <div className="flex h-9 items-center gap-2 px-3">
          <Switch
            checked={item === true}
            disabled={disabled}
            ref={setFirstControlRef}
            onCheckedChange={(checked) => updateItem(index, checked)}
          />
          <span className="text-[12px] text-muted-foreground">
            {item === true ? t('True') : t('False')}
          </span>
        </div>
      )
    }

    if (isEnumType) {
      return (
        <Select
          value={isNull ? 'null' : String(item ?? '')}
          disabled={disabled}
          onValueChange={(value) =>
            updateItem(index, value === 'null' ? null : value)
          }
        >
          <SelectTrigger
            ref={setFirstControlRef}
            autoFocus={autoFocus && index === 0}
            className="h-9 rounded-none border-0 bg-transparent px-3 text-[13px] focus:ring-0 focus:ring-offset-0"
          >
            <SelectValue placeholder={required ? undefined : 'NULL'} />
          </SelectTrigger>
          <SelectContent>
            {!required ? <SelectItem value="null">NULL</SelectItem> : null}
            {enumOptions.map((option) => (
              <SelectItem key={option} value={option}>
                {option}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )
    }

    if (isDateTimeType) {
      return (
        <DateTimePicker
          value={isNull ? null : (item as string | null)}
          onChange={(value) => updateItem(index, value)}
          triggerRef={setFirstControlRef}
          autoFocus={autoFocus && index === 0}
          disabled={disabled || isNull}
          clearable={!required}
          placeholder={placeholder}
          className="h-9 rounded-none border-0 bg-transparent px-3 text-[13px] hover:bg-transparent focus-visible:ring-0 focus-visible:ring-offset-0"
        />
      )
    }

    if (usesIntegratedTextField) {
      return (
        <DatabaseArrayItemTextField
          value={stringValue}
          onChange={(next) => updateItem(index, next)}
          disabled={disabled}
          inputRef={setFirstControlRef}
          autoFocus={autoFocus && index === 0}
          maxLength={hasLimit ? maxLength : undefined}
          placeholder={placeholder}
          isNull={isNull}
          showNullCheckbox={showNullCheckbox}
          nullCheckboxId={`${idPrefix}-${index}-null`}
          onNullChange={(checked) => updateItem(index, checked ? null : '')}
        />
      )
    }

    return (
      <Input
        value={stringValue}
        disabled={disabled || isNull}
        ref={setFirstControlRef}
        autoFocus={autoFocus && index === 0}
        dir={isRTLContent ? 'rtl' : 'ltr'}
        maxLength={hasLimit ? maxLength : undefined}
        onChange={(event) => updateItem(index, event.target.value)}
        className={cn(
          'field-sizing-fixed h-9 w-full rounded-none border-0 bg-transparent px-3 text-[13px] text-start focus-visible:ring-0 focus-visible:ring-offset-0',
          isNull && 'cursor-not-allowed opacity-50',
        )}
        placeholder={placeholder}
      />
    )
  }

  const renderItemRow = (item: RowCellValue, index: number) => {
    const isNull = item === null
    const stringValue = isNull ? '' : String(item ?? '')
    const charCount = stringValue.length
    const isRTLContent = isSpreadsheetRtlText(stringValue)
    const showFooter =
      !usesIntegratedTextField &&
      !isBoolType &&
      !isEnumType &&
      (hasLimit || showNullCheckbox)
    const control = renderItemControl(item, index)

    return (
      <>
        <div className="flex w-9 shrink-0 select-none items-center justify-center border-e border-foreground/10 bg-muted/40 text-[11px] font-mono tabular-nums text-muted-foreground">
          {index + 1}
        </div>
        {usesIntegratedTextField ? (
          control
        ) : (
          <div className="flex min-w-0 w-full flex-1 flex-col">
            <div className="min-w-0 w-full flex-1">{control}</div>
            {showFooter ? (
              <div
                dir={isRTLContent ? 'rtl' : 'ltr'}
                className="flex items-center justify-end gap-3 border-t border-foreground/10 bg-muted/30 px-3 py-1"
              >
                {hasLimit ? (
                  <span
                    className={cn(
                      'text-[10px] tabular-nums whitespace-nowrap',
                      charCount > (maxLength ?? 0)
                        ? 'font-medium text-destructive'
                        : 'text-muted-foreground',
                    )}
                  >
                    {charCount}/{maxLength}
                  </span>
                ) : null}
                {showNullCheckbox ? (
                  <label
                    htmlFor={`${idPrefix}-${index}-null`}
                    className="flex cursor-pointer select-none items-center gap-1.5 text-[10px] text-muted-foreground"
                  >
                    <Checkbox
                      id={`${idPrefix}-${index}-null`}
                      checked={isNull}
                      disabled={disabled}
                      onCheckedChange={(checked) => {
                        updateItem(index, checked ? null : '')
                      }}
                      className="h-3 w-3 cursor-pointer"
                    />
                    {t('Null')}
                  </label>
                ) : null}
              </div>
            ) : null}
          </div>
        )}
        <button
          type="button"
          aria-label={t('Remove item')}
          disabled={disabled}
          className="flex w-9 shrink-0 cursor-pointer items-center justify-center border-s border-foreground/10 text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
          onClick={() => removeItem(index)}
        >
          <X className="h-3.5 w-3.5" />
        </button>
      </>
    )
  }

  return (
    <div className="overflow-hidden rounded-md border border-border bg-card">
      {items.length > 0 ? (
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          onDragEnd={handleDragEnd}
        >
          <SortableContext items={sortableIds} strategy={verticalListSortingStrategy}>
            <div className="divide-y divide-foreground/10">
              {items.map((item, index) => (
                <ArraySortableRow
                  key={arrayItemSortableId(idPrefix, index)}
                  id={arrayItemSortableId(idPrefix, index)}
                  index={index}
                >
                  {renderItemRow(item, index)}
                </ArraySortableRow>
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
        disabled={disabled}
        ref={(el) => {
          if (items.length === 0) assignFocusRef(focusRef, el)
        }}
        onClick={addItem}
        className="h-9 w-full cursor-pointer justify-center rounded-none border-t border-foreground/10 bg-muted/30 text-[12px] text-muted-foreground hover:bg-muted/50 hover:text-foreground"
      >
        <Plus className="me-1.5 h-3.5 w-3.5" />
        {t('Add item')}
      </Button>
    </div>
  )
}
