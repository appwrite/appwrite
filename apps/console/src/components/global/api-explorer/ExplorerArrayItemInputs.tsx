import type { ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import {
  REQUEST_BUILDER_ARRAY_ADD_ROW,
  REQUEST_BUILDER_ARRAY_ITEM_ROW,
  REQUEST_BUILDER_ARRAY_ITEMS,
  REQUEST_BUILDER_COMBINED_ARRAY_HELPER_CELL,
  REQUEST_BUILDER_COMBINED_ARRAY_ROW,
  REQUEST_BUILDER_HELPER_LINK,
  REQUEST_BUILDER_INPUT,
} from './request-form-table'

type ExplorerArrayItemInputsProps = {
  idPrefix: string
  items: string[]
  onChange: (items: string[]) => void
  emptyLabel?: string
  emptyState?: 'label' | 'add-control'
  placeholder?: string
  inputType?: 'text' | 'number'
}

export function ExplorerArrayItemInputs({
  idPrefix,
  items,
  onChange,
  emptyLabel = 'No items',
  emptyState = 'label',
  placeholder = '// enter value',
  inputType = 'text',
}: ExplorerArrayItemInputsProps) {
  const t = useT()
  const updateItem = (index: number, next: string) => {
    const copy = [...items]
    copy[index] = next
    onChange(copy)
  }

  const removeItem = (index: number) => {
    onChange(items.filter((_, itemIndex) => itemIndex !== index))
  }

  if (items.length === 0) {
    if (emptyState === 'add-control') {
      return (
        <div className="flex min-h-[44px] items-center px-4">
          <ExplorerArrayAddControl items={items} onChange={onChange} />
        </div>
      )
    }

    return (
      <span className="flex min-h-[44px] items-center px-4 font-mono text-[13px] text-muted-foreground/45">
        {t(emptyLabel)}
      </span>
    )
  }

  return (
    <div className={REQUEST_BUILDER_ARRAY_ITEMS}>
      {items.map((item, index) => (
        <div key={`${idPrefix}-${index}`} className={REQUEST_BUILDER_ARRAY_ITEM_ROW}>
          <Input
            id={`${idPrefix}-${index}`}
            type={inputType}
            placeholder={placeholder}
            value={item}
            onChange={(event) => updateItem(index, event.target.value)}
            className={cn(
              REQUEST_BUILDER_INPUT,
              'h-9 min-h-0 flex-1 py-0',
            )}
            spellCheck={false}
            autoComplete="off"
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="me-2 h-8 w-8 shrink-0 p-0 text-muted-foreground/60"
            onClick={() => removeItem(index)}
            aria-label={t('Remove item')}
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        </div>
      ))}
      <div className={REQUEST_BUILDER_ARRAY_ADD_ROW}>
        <ExplorerArrayAddControl items={items} onChange={onChange} />
        {items.length > 0 ? (
          <button
            type="button"
            className={cn(
              REQUEST_BUILDER_HELPER_LINK,
              'ms-3 h-8 w-auto text-muted-foreground',
            )}
            onClick={() => onChange([])}
          >
            {t('Clear all')}
          </button>
        ) : null}
      </div>
    </div>
  )
}

type ExplorerArrayAddControlProps = {
  items: string[]
  onChange: (items: string[]) => void
}

export function ExplorerArrayAddControl({
  items,
  onChange,
}: ExplorerArrayAddControlProps) {
  const t = useT()
  return (
    <button
      type="button"
      className={cn(REQUEST_BUILDER_HELPER_LINK, 'h-8 w-auto')}
      onClick={() => onChange([...items, ''])}
    >
      {items.length === 0 ? t('Add array') : t('Add item')}
    </button>
  )
}

type ExplorerCombinedArrayFieldProps = {
  idPrefix: string
  items: string[]
  onChange: (items: string[]) => void
  placeholder?: string
  renderItemHelper: (index: number) => ReactNode
}

export function ExplorerCombinedArrayField({
  idPrefix,
  items,
  onChange,
  placeholder = '// enter value',
  renderItemHelper,
}: ExplorerCombinedArrayFieldProps) {
  const t = useT()
  const updateItem = (index: number, next: string) => {
    const copy = [...items]
    copy[index] = next
    onChange(copy)
  }

  const removeItem = (index: number) => {
    onChange(items.filter((_, itemIndex) => itemIndex !== index))
  }

  return (
    <div className={REQUEST_BUILDER_ARRAY_ITEMS}>
      {items.map((item, index) => (
        <div key={`${idPrefix}-${index}`} className={REQUEST_BUILDER_COMBINED_ARRAY_ROW}>
          <div className={REQUEST_BUILDER_ARRAY_ITEM_ROW}>
            <Input
              id={`${idPrefix}-${index}`}
              type="text"
              placeholder={placeholder}
              value={item}
              onChange={(event) => updateItem(index, event.target.value)}
              className={cn(
                REQUEST_BUILDER_INPUT,
                'h-9 min-h-0 flex-1 py-0',
              )}
              spellCheck={false}
              autoComplete="off"
            />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="me-2 h-8 w-8 shrink-0 p-0 text-muted-foreground/60"
              onClick={() => removeItem(index)}
              aria-label={t('Remove item')}
            >
              <X className="h-3.5 w-3.5" />
            </Button>
          </div>
          <div className={REQUEST_BUILDER_COMBINED_ARRAY_HELPER_CELL}>
            {renderItemHelper(index)}
          </div>
        </div>
      ))}
      <div className={REQUEST_BUILDER_COMBINED_ARRAY_ROW}>
        <div className={REQUEST_BUILDER_ARRAY_ADD_ROW}>
          <ExplorerArrayAddControl items={items} onChange={onChange} />
          <button
            type="button"
            className={cn(
              REQUEST_BUILDER_HELPER_LINK,
              'ms-3 h-8 w-auto text-muted-foreground',
            )}
            onClick={() => onChange([])}
          >
            {t('Clear all')}
          </button>
        </div>
        <div
          className={REQUEST_BUILDER_COMBINED_ARRAY_HELPER_CELL}
          aria-hidden
        />
      </div>
    </div>
  )
}

type ExplorerArrayItemHelperRowsProps = {
  count: number
  renderItemHelper: (index: number) => ReactNode
}

export function ExplorerArrayItemHelperRows({
  count,
  renderItemHelper,
}: ExplorerArrayItemHelperRowsProps) {
  if (count === 0) {
    return null
  }

  return (
    <div className={REQUEST_BUILDER_ARRAY_ITEMS}>
      {Array.from({ length: count }, (_, index) => (
        <div key={index} className={REQUEST_BUILDER_ARRAY_ITEM_ROW}>
          {renderItemHelper(index)}
        </div>
      ))}
    </div>
  )
}
