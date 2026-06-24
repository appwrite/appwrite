'use client'

import { useCallback, useMemo, useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import {
  createSubscriptionQueryEntry,
  subscriptionQueryNeedsValue,
  subscriptionQueryOperators,
  type SubscriptionQueryEntry,
} from '@/lib/realtime/subscription-queries'
import { cn } from '@/lib/utils'

const LABEL_CLASS = 'mb-1 block text-[12px] font-medium text-muted-foreground'
const INPUT_CLASS = 'h-9 w-full text-[13px]'

type QueryFilterFormProps = {
  disabled?: boolean
  submitLabel?: string
  onSubmit: (entry: SubscriptionQueryEntry) => void
  onCancel?: () => void
}

export function QueryFilterForm({
  disabled = false,
  submitLabel = 'Add query',
  onSubmit,
  onCancel,
}: QueryFilterFormProps) {
  const [attribute, setAttribute] = useState('')
  const [operatorKey, setOperatorKey] = useState('equal')
  const [valueInput, setValueInput] = useState('')

  const operators = useMemo(() => subscriptionQueryOperators(), [])
  const needsValue = subscriptionQueryNeedsValue(operatorKey)
  const isBetween = operatorKey === 'between' || operatorKey === 'notBetween'

  const resetForm = useCallback(() => {
    setAttribute('')
    setOperatorKey('equal')
    setValueInput('')
  }, [])

  const handleSubmit = useCallback(
    (event: FormEvent) => {
      event.preventDefault()

      const trimmedAttribute = attribute.trim()
      if (!trimmedAttribute) return
      if (needsValue && !valueInput.trim()) return

      onSubmit(
        createSubscriptionQueryEntry({
          attribute: trimmedAttribute,
          operatorKey,
          value: valueInput,
        }),
      )
      resetForm()
    },
    [attribute, needsValue, onSubmit, operatorKey, resetForm, valueInput],
  )

  return (
    <form onSubmit={handleSubmit} className="space-y-3">
      <div className="grid grid-cols-2 gap-2">
        <div className="space-y-1">
          <label className={LABEL_CLASS} htmlFor="query-attribute">
            Attribute
          </label>
          <Input
            id="query-attribute"
            value={attribute}
            onChange={(event) => setAttribute(event.target.value)}
            placeholder="e.g. status"
            className={cn(INPUT_CLASS, 'font-mono')}
            disabled={disabled}
            spellCheck={false}
          />
        </div>
        <div className="space-y-1">
          <label className={LABEL_CLASS} htmlFor="query-operator">
            Operator
          </label>
          <SearchableSelect
            value={operatorKey}
            onValueChange={(value) => {
              setOperatorKey(value)
              setValueInput('')
            }}
            items={operators.map((operator) => ({
              value: operator.key,
              label: operator.label,
            }))}
            placeholder="Operator"
            searchPlaceholder="Search operators…"
            emptyMessage="No operators found"
            disabled={disabled}
            triggerClassName={INPUT_CLASS}
          />
        </div>
      </div>

      {needsValue ? (
        <div className="space-y-1">
          <label className={LABEL_CLASS} htmlFor="query-value">
            Value
          </label>
          <Input
            id="query-value"
            value={valueInput}
            onChange={(event) => setValueInput(event.target.value)}
            placeholder={isBetween ? 'min, max' : 'Enter value'}
            className={cn(INPUT_CLASS, 'font-mono')}
            disabled={disabled}
            spellCheck={false}
          />
        </div>
      ) : null}

      <div className="flex gap-2 pt-1">
        <Button
          type="submit"
          size="sm"
          className="h-9 min-w-0 flex-1 text-[13px]"
          disabled={
            disabled || !attribute.trim() || (needsValue && !valueInput.trim())
          }
        >
          {submitLabel}
        </Button>
        {onCancel ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 shrink-0 text-[13px]"
            disabled={disabled}
            onClick={onCancel}
          >
            Cancel
          </Button>
        ) : null}
      </div>
    </form>
  )
}

export function getQueryDisplayPartsFromEntry(entry: SubscriptionQueryEntry) {
  const operator =
    subscriptionQueryOperators().find((item) => item.key === entry.operatorKey)
      ?.label ?? entry.operatorKey
  const needsValue = subscriptionQueryNeedsValue(entry.operatorKey)

  return {
    attribute: entry.attribute,
    operator,
    value: needsValue ? entry.value : null,
  }
}
