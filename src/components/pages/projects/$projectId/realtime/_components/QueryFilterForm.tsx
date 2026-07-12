'use client'

import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import {
  createSubscriptionQueryEntry,
  REALTIME_QUERY_VALUE_TYPES,
  subscriptionQueryNeedsValue,
  subscriptionQueryOperatorsForType,
  type RealtimeQueryValueType,
  type SubscriptionQueryEntry,
} from '@/lib/realtime/subscription-queries'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

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
  const t = useT()
  const [attribute, setAttribute] = useState('')
  const [valueType, setValueType] = useState<RealtimeQueryValueType>('string')
  const [operatorKey, setOperatorKey] = useState('equal')
  const [valueInput, setValueInput] = useState('')

  const operators = useMemo(
    () => subscriptionQueryOperatorsForType(valueType),
    [valueType],
  )

  useEffect(() => {
    if (operators.some((operator) => operator.key === operatorKey)) return
    setOperatorKey(operators[0]?.key ?? 'equal')
    setValueInput('')
  }, [operatorKey, operators])

  const needsValue = subscriptionQueryNeedsValue(operatorKey)
  const isNumericType = valueType === 'integer' || valueType === 'double'

  const handleValueTypeChange = useCallback((nextType: RealtimeQueryValueType) => {
    setValueType(nextType)
    setValueInput('')
    const nextOperators = subscriptionQueryOperatorsForType(nextType)
    setOperatorKey(nextOperators[0]?.key ?? 'equal')
  }, [])

  const resetForm = useCallback(() => {
    setAttribute('')
    setValueType('string')
    setOperatorKey('equal')
    setValueInput('')
  }, [])

  const canSubmit = useMemo(() => {
    if (!attribute.trim()) return false
    if (!needsValue) return true
    return !!valueInput.trim()
  }, [attribute, needsValue, valueInput])

  const handleSubmit = useCallback(
    (event: FormEvent) => {
      event.preventDefault()
      if (!canSubmit) return

      const trimmedAttribute = attribute.trim()

      onSubmit(
        createSubscriptionQueryEntry({
          attribute: trimmedAttribute,
          operatorKey,
          value: needsValue ? valueInput : '',
          valueType,
        }),
      )
      resetForm()
    },
    [
      attribute,
      canSubmit,
      needsValue,
      onSubmit,
      operatorKey,
      resetForm,
      valueInput,
      valueType,
    ],
  )

  const valueInputNode = (() => {
    if (!needsValue) return null

    if (valueType === 'boolean') {
      return (
        <div className="space-y-1">
          <label className={LABEL_CLASS} htmlFor="query-value">
            {t('Value')}
          </label>
          <SearchableSelect
            value={valueInput}
            onValueChange={setValueInput}
            items={[
              { value: 'true', label: t('True') },
              { value: 'false', label: t('False') },
            ]}
            placeholder={t('Select value')}
            searchPlaceholder={t('Search...')}
            emptyMessage={t('No results')}
            disabled={disabled}
            triggerClassName={INPUT_CLASS}
          />
        </div>
      )
    }

    if (valueType === 'datetime') {
      return (
        <div className="space-y-1">
          <label className={LABEL_CLASS} htmlFor="query-value">
            {t('Value')}
          </label>
          <DateTimePicker
            id="query-value"
            value={valueInput || null}
            onChange={(next) => setValueInput(next ?? '')}
            className={INPUT_CLASS}
            disabled={disabled}
            clearable
          />
        </div>
      )
    }

    return (
      <div className="space-y-1">
        <label className={LABEL_CLASS} htmlFor="query-value">
          {t('Value')}
        </label>
        <Input
          id="query-value"
          type={isNumericType ? 'number' : 'text'}
          value={valueInput}
          onChange={(event) => setValueInput(event.target.value)}
          placeholder={isNumericType ? '0' : t('Enter value')}
          className={cn(INPUT_CLASS, isNumericType ? '' : 'font-mono')}
          disabled={disabled}
          spellCheck={false}
          step={valueType === 'integer' ? 1 : valueType === 'double' ? 'any' : undefined}
        />
      </div>
    )
  })()

  return (
    <form onSubmit={handleSubmit} className="space-y-3">
      <div className="space-y-1">
        <label className={LABEL_CLASS} htmlFor="query-attribute">
          {t('Attribute')}
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

      <div className="grid grid-cols-2 gap-2">
        <div className="space-y-1">
          <label className={LABEL_CLASS} htmlFor="query-value-type">
            {t('Value type')}
          </label>
          <SearchableSelect
            value={valueType}
            onValueChange={(value) =>
              handleValueTypeChange(value as RealtimeQueryValueType)
            }
            items={REALTIME_QUERY_VALUE_TYPES.map((item) => ({
              value: item.value,
              label: t(item.label),
            }))}
            placeholder={t('Type')}
            searchPlaceholder={t('Search types...')}
            emptyMessage={t('No types found')}
            disabled={disabled}
            triggerClassName={INPUT_CLASS}
          />
        </div>
        <div className="space-y-1">
          <label className={LABEL_CLASS} htmlFor="query-operator">
            {t('Operator')}
          </label>
          <SearchableSelect
            value={operatorKey}
            onValueChange={(value) => {
              setOperatorKey(value)
              setValueInput('')
            }}
            items={operators.map((operator) => ({
              value: operator.key,
              label: t(operator.label),
            }))}
            placeholder={t('Operator')}
            searchPlaceholder={t('Search operators...')}
            emptyMessage={t('No operators found')}
            disabled={disabled}
            triggerClassName={INPUT_CLASS}
          />
        </div>
      </div>

      {valueInputNode}

      <div className="flex gap-2 pt-1">
        <Button
          type="submit"
          size="sm"
          className="h-9 min-w-0 flex-1 text-[13px]"
          disabled={disabled || !canSubmit}
        >
          {t(submitLabel)}
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
            {t('Cancel')}
          </Button>
        ) : null}
      </div>
    </form>
  )
}

export function getQueryDisplayPartsFromEntry(entry: SubscriptionQueryEntry) {
  const operator =
    subscriptionQueryOperatorsForType(entry.valueType).find(
      (item) => item.key === entry.operatorKey,
    )?.label ?? entry.operatorKey
  const needsValue = subscriptionQueryNeedsValue(entry.operatorKey)
  const valueTypeLabel =
    REALTIME_QUERY_VALUE_TYPES.find((item) => item.value === entry.valueType)
      ?.label ?? 'String'

  return {
    attribute: entry.attribute,
    operator,
    value: needsValue ? entry.value : null,
    valueType: valueTypeLabel,
  }
}
