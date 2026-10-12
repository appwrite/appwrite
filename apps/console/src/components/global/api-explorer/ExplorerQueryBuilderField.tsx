import { useEffect, useMemo, useState } from 'react'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  buildFilterQueryString,
  getOperatorsForType,
  type FilterColumn,
} from '@/lib/table-filters'
import {
  getFormFieldPlaceholder,
  type FormValue,
  type RequestFormField,
} from '@/lib/api-explorer/request-form'
import {
  ExplorerArrayItemHelperRows,
  ExplorerArrayItemInputs,
  ExplorerCombinedArrayField,
} from './ExplorerArrayItemInputs'

type ExplorerQueryBuilderFieldProps = {
  field: RequestFormField
  value: FormValue
  onChange: (value: FormValue) => void
  inputId: string
  columns: FilterColumn[]
  part: 'value' | 'helper' | 'combined'
}

export function ExplorerQueryBuilderField({
  field,
  value,
  onChange,
  inputId,
  columns,
  part,
}: ExplorerQueryBuilderFieldProps) {
  const items = Array.isArray(value) ? value.map(String) : []

  const setItems = (next: string[]) => {
    onChange(next)
  }

  if (part === 'combined') {
    return (
      <ExplorerCombinedArrayField
        idPrefix={inputId}
        items={items}
        onChange={setItems}
        placeholder={getFormFieldPlaceholder('string')}
        renderItemHelper={(index) => (
          <ExplorerQueryItemHelper
            index={index}
            items={items}
            onChange={setItems}
            fieldLabel={field.label}
            columns={columns}
          />
        )}
      />
    )
  }

  if (part === 'value') {
    return (
      <ExplorerArrayItemInputs
        idPrefix={inputId}
        items={items}
        onChange={setItems}
        emptyState="add-control"
        placeholder={getFormFieldPlaceholder('string')}
      />
    )
  }

  if (items.length === 0) {
    return null
  }

  return (
    <ExplorerArrayItemHelperRows
      count={items.length}
      renderItemHelper={(index) => (
        <ExplorerQueryItemHelper
          index={index}
          items={items}
          onChange={setItems}
          fieldLabel={field.label}
          columns={columns}
        />
      )}
    />
  )
}

/** @deprecated Use ExplorerQueryBuilderField with part="value" */
export function ExplorerQueryBuilderValue({ value }: { value: FormValue }) {
  return (
    <ExplorerArrayItemInputs
      idPrefix="queries"
      items={Array.isArray(value) ? value.map(String) : []}
      onChange={() => undefined}
      emptyLabel="No queries"
      placeholder="// query string"
    />
  )
}

/** @deprecated Use ExplorerQueryBuilderField with part="helper" */
export function ExplorerQueryBuilderHelper(
  props: Omit<ExplorerQueryBuilderFieldProps, 'part' | 'inputId'>,
) {
  return (
    <ExplorerQueryBuilderField {...props} inputId="queries" part="helper" />
  )
}

type ExplorerQueryItemHelperProps = {
  index: number
  items: string[]
  onChange: (items: string[]) => void
  fieldLabel: string
  columns: FilterColumn[]
}

function ExplorerQueryItemHelper({
  index,
  items,
  onChange,
  fieldLabel,
  columns,
}: ExplorerQueryItemHelperProps) {
  const t = useT()
  const [open, setOpen] = useState(false)

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-8 shrink-0 px-3 text-[12px] leading-none"
        onClick={() => setOpen(true)}
      >
        {t('Build')}
      </Button>

      <ExplorerSingleQueryBuilderDialog
        open={open}
        onOpenChange={setOpen}
        fieldLabel={fieldLabel}
        columns={columns}
        onApply={(query) => {
          const copy = [...items]
          copy[index] = query
          onChange(copy)
          setOpen(false)
        }}
      />
    </>
  )
}

type ExplorerSingleQueryBuilderDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  fieldLabel: string
  columns: FilterColumn[]
  onApply: (query: string) => void
}

function ExplorerSingleQueryBuilderDialog({
  open,
  onOpenChange,
  fieldLabel,
  columns,
  onApply,
}: ExplorerSingleQueryBuilderDialogProps) {
  const t = useT()
  const [columnId, setColumnId] = useState(columns[0]?.id ?? '$id')
  const [operatorKey, setOperatorKey] = useState('equal')
  const [valueInput, setValueInput] = useState('')

  const selectedColumn =
    columns.find((column) => column.id === columnId) ?? columns[0]

  const operators = useMemo(() => {
    if (!selectedColumn) return []
    return getOperatorsForType(selectedColumn.type, {
      enumOptional: selectedColumn.optional,
      fulltextSearchable: selectedColumn.fulltextSearchable,
    })
  }, [selectedColumn])

  useEffect(() => {
    if (!open) return
    setColumnId(columns[0]?.id ?? '$id')
    setOperatorKey('equal')
    setValueInput('')
  }, [open, columns])

  const handleApply = () => {
    const noValue = ['isNull', 'isNotNull', 'exists', 'notExists'].includes(
      operatorKey,
    )
    const query = buildFilterQueryString(
      operatorKey,
      columnId,
      noValue ? null : valueInput,
    )
    if (!query.trim()) return
    onApply(query)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[min(90dvh,520px)] w-[min(96vw,640px)] flex-col gap-0 overflow-hidden p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Build')} {fieldLabel}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Add one Appwrite Query condition for this entry.')} {/* pragma: allowlist secret */}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-4">
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-4 py-3">
              <p className="text-[13px] font-semibold text-foreground">
                {t('Condition')}
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="grid gap-3 px-4 py-4 sm:grid-cols-3">
              <label className="space-y-1.5">
                <span className="text-[12px] font-medium text-muted-foreground">
                  {t('Attribute')}
                </span>
                <select
                  value={columnId}
                  onChange={(event) => {
                    setColumnId(event.target.value)
                    setOperatorKey('equal')
                  }}
                  className="h-9 w-full rounded-md border border-border bg-background px-3 font-mono text-[13px]"
                >
                  {columns.map((column) => (
                    <option key={column.id} value={column.id}>
                      {column.title}
                    </option>
                  ))}
                </select>
              </label>

              <label className="space-y-1.5">
                <span className="text-[12px] font-medium text-muted-foreground">
                  {t('Operator')}
                </span>
                <select
                  value={operatorKey}
                  onChange={(event) => setOperatorKey(event.target.value)}
                  className="h-9 w-full rounded-md border border-border bg-background px-3 font-mono text-[13px]"
                >
                  {operators.map((operator) => (
                    <option key={operator.key} value={operator.key}>
                      {operator.label}
                    </option>
                  ))}
                </select>
              </label>

              <label className="space-y-1.5">
                <span className="text-[12px] font-medium text-muted-foreground">
                  {t('Value')}
                </span>
                <input
                  value={valueInput}
                  onChange={(event) => setValueInput(event.target.value)}
                  disabled={['isNull', 'isNotNull', 'exists', 'notExists'].includes(
                    operatorKey,
                  )}
                  placeholder={
                    ['isNull', 'isNotNull', 'exists', 'notExists'].includes(
                      operatorKey,
                    )
                      ? t('No value needed')
                      : t('Enter value')
                  }
                  className="h-9 w-full rounded-md border border-border bg-background px-3 font-mono text-[13px] disabled:opacity-50"
                />
              </label>
            </div>
          </div>
        </div>
        <div className="border-t border-border bg-muted/30 px-6 py-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
          >
            {t('Cancel')}
          </Button>
          <Button type="button" className="h-9 text-[13px]" onClick={handleApply}>
            {t('Apply query')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
