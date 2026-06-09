import { ID } from '@appwrite.io/console'
import type { ComponentProps, FocusEvent, ReactNode } from 'react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Eye, EyeOff, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { cn } from '@/lib/utils'
import {
  getFormFieldPlaceholder,
  getFormFieldTypeLabel,
  type FormValue,
  type RequestFormField,
  type RequestFormFieldKind,
} from '@/lib/api-explorer/request-form'
import {
  REQUEST_BUILDER_INPUT,
  REQUEST_BUILDER_NAME_CELL,
  REQUEST_BUILDER_REQUIRED,
  REQUEST_BUILDER_ROW,
  REQUEST_BUILDER_ROW_COMPLEX,
  REQUEST_BUILDER_SELECT,
  REQUEST_BUILDER_TYPE_CELL,
  REQUEST_BUILDER_VALUE_CELL,
  REQUEST_BUILDER_VALUE_INNER,
  REQUEST_BUILDER_VALUE_INNER_COMPLEX,
} from './request-form-table'

function useSelectAllOnFirstFocus(fieldKey: string) {
  const hasSelectedRef = useRef(false)

  useEffect(() => {
    hasSelectedRef.current = false
  }, [fieldKey])

  return useCallback((event: FocusEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    if (hasSelectedRef.current) return
    hasSelectedRef.current = true
    if (event.currentTarget.value.length > 0) {
      event.currentTarget.select()
    }
  }, [])
}

function ExplorerParamInput({
  fieldKey,
  onFocus,
  ...props
}: ComponentProps<typeof Input> & { fieldKey: string }) {
  const handleFirstFocus = useSelectAllOnFirstFocus(fieldKey)

  return (
    <Input
      {...props}
      onFocus={(event) => {
        handleFirstFocus(event)
        onFocus?.(event)
      }}
    />
  )
}

function ExplorerParamTextarea({
  fieldKey,
  onFocus,
  ...props
}: ComponentProps<typeof Textarea> & { fieldKey: string }) {
  const handleFirstFocus = useSelectAllOnFirstFocus(fieldKey)

  return (
    <Textarea
      {...props}
      onFocus={(event) => {
        handleFirstFocus(event)
        onFocus?.(event)
      }}
    />
  )
}

type RequestFormFieldInputProps = {
  field: RequestFormField
  value: FormValue
  onChange: (value: FormValue) => void
  idPrefix?: string
  className?: string
}

export function RequestFormFieldInput({
  field,
  value,
  onChange,
  idPrefix = 'field',
  className,
}: RequestFormFieldInputProps) {
  const inputId = `${idPrefix}-${field.name}`
  const typeDisplay = getFieldTypeDisplay(field.kind)
  const isComplex =
    field.kind === 'json' ||
    field.kind === 'array-string' ||
    field.kind === 'array-number' ||
    field.kind === 'array-enum'

  return (
    <div
      className={cn(
        REQUEST_BUILDER_ROW,
        isComplex && REQUEST_BUILDER_ROW_COMPLEX,
        className,
      )}
    >
      <div className={REQUEST_BUILDER_NAME_CELL}>
        <ParameterNameLabel
          inputId={inputId}
          name={field.label}
          description={field.description}
        />
      </div>

      <div className={REQUEST_BUILDER_TYPE_CELL}>
        <FieldTypeLabel label={typeDisplay.label} tone={typeDisplay.tone} />
      </div>

      <div className={REQUEST_BUILDER_VALUE_CELL}>
        <ValueCell required={field.required} complex={isComplex}>
          {renderControl(field, value, onChange, inputId)}
        </ValueCell>
      </div>
    </div>
  )
}

function ValueCell({
  required,
  complex,
  children,
}: {
  required: boolean
  complex: boolean
  children: ReactNode
}) {
  if (complex) {
    return (
      <div className={REQUEST_BUILDER_VALUE_INNER_COMPLEX}>
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0 flex-1">{children}</div>
          {required && (
            <span className={cn(REQUEST_BUILDER_REQUIRED, 'pr-0 pt-0.5')}>
              Required
            </span>
          )}
        </div>
      </div>
    )
  }

  return (
    <div className={REQUEST_BUILDER_VALUE_INNER}>
      <div className="min-w-0 flex-1">{children}</div>
      {required && <span className={REQUEST_BUILDER_REQUIRED}>Required</span>}
    </div>
  )
}

function ParameterNameLabel({
  inputId,
  name,
  description,
}: {
  inputId: string
  name: string
  description?: string
}) {
  const label = (
    <Label
      htmlFor={inputId}
      className={cn(
        'truncate font-mono text-[13px] font-normal text-foreground/85',
        description &&
          'cursor-help underline decoration-dashed decoration-muted-foreground/40 underline-offset-[3px] hover:decoration-muted-foreground/70',
      )}
    >
      {name}
    </Label>
  )

  if (!description?.trim()) {
    return label
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>{label}</TooltipTrigger>
      <TooltipContent
        side="top"
        sideOffset={6}
        className="max-w-sm text-[12px] leading-relaxed"
      >
        {description.trim()}
      </TooltipContent>
    </Tooltip>
  )
}

type FieldTypeTone = 'string' | 'enum' | 'object' | 'array' | 'boolean' | 'number'

function getFieldTypeDisplay(kind: RequestFormFieldKind): {
  label: string
  tone: FieldTypeTone
} {
  const label = getFormFieldTypeLabel(kind)
  switch (kind) {
    case 'boolean':
      return { label, tone: 'boolean' }
    case 'integer':
    case 'number':
      return { label, tone: 'number' }
    case 'enum':
      return { label, tone: 'enum' }
    case 'array-string':
    case 'array-number':
    case 'array-enum':
      return { label, tone: 'array' }
    case 'json':
      return { label, tone: 'object' }
    case 'binary':
      return { label, tone: 'number' }
    case 'password':
    case 'email':
    case 'url':
    case 'phone':
    case 'datetime':
    case 'ip':
    case 'id':
    case 'string':
    default:
      return { label, tone: 'string' }
  }
}

function FieldTypeLabel({
  label,
  tone,
}: {
  label: string
  tone: FieldTypeTone
}) {
  return (
    <span
      className={cn(
        'text-[11px] font-medium leading-none',
        tone === 'string' && 'text-emerald-600/55 dark:text-emerald-400/55',
        tone === 'enum' && 'text-rose-500/55 dark:text-rose-400/55',
        tone === 'object' && 'text-sky-600/55 dark:text-sky-400/55',
        tone === 'array' && 'text-amber-600/55 dark:text-amber-400/55',
        tone === 'boolean' && 'text-violet-600/55 dark:text-violet-400/55',
        tone === 'number' && 'text-muted-foreground/55',
      )}
    >
      {label}
    </span>
  )
}

function renderControl(
  field: RequestFormField,
  value: FormValue,
  onChange: (value: FormValue) => void,
  inputId: string,
) {
  switch (field.kind) {
    case 'boolean':
      return (
        <div className="flex min-h-[44px] items-center gap-2.5 px-4">
          <Switch
            id={inputId}
            checked={Boolean(value)}
            onCheckedChange={(checked) => onChange(checked)}
          />
          <Label
            htmlFor={inputId}
            className="font-mono text-[13px] font-normal text-muted-foreground/75"
          >
            {Boolean(value) ? 'true' : 'false'}
          </Label>
        </div>
      )

    case 'integer':
      return (
        <ExplorerParamInput
          fieldKey={inputId}
          id={inputId}
          type="number"
          inputMode="numeric"
          step={1}
          placeholder={getFormFieldPlaceholder(field.kind)}
          value={value === null || value === undefined ? '' : String(value)}
          onChange={(event) =>
            onChange(
              event.target.value === '' ? '' : parseInt(event.target.value, 10) || 0,
            )
          }
          className={REQUEST_BUILDER_INPUT}
        />
      )

    case 'number':
      return (
        <ExplorerParamInput
          fieldKey={inputId}
          id={inputId}
          type="number"
          inputMode="decimal"
          step="any"
          placeholder={getFormFieldPlaceholder(field.kind)}
          value={value === null || value === undefined ? '' : String(value)}
          onChange={(event) =>
            onChange(
              event.target.value === '' ? '' : Number(event.target.value) || 0,
            )
          }
          className={REQUEST_BUILDER_INPUT}
        />
      )

    case 'enum':
      return (
        <Select
          value={String(value ?? '')}
          onValueChange={(next) => onChange(next)}
        >
          <SelectTrigger id={inputId} className={REQUEST_BUILDER_SELECT}>
            <SelectValue placeholder="// choose value" />
          </SelectTrigger>
          <SelectContent>
            {field.enumValues?.map((option) => (
              <SelectItem key={option} value={option} className="font-mono text-[13px]">
                {option}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )

    case 'array-string':
    case 'array-number':
      return (
        <ArrayStringInput
          id={inputId}
          items={Array.isArray(value) ? value : []}
          inputType={field.kind === 'array-number' ? 'number' : 'text'}
          onChange={onChange}
        />
      )

    case 'array-enum':
      return (
        <ArrayEnumInput
          options={field.enumValues ?? []}
          selected={Array.isArray(value) ? value : []}
          onChange={onChange}
        />
      )

    case 'json':
      if (!String(value ?? '').trim()) {
        return (
          <button
            type="button"
            id={inputId}
            onClick={() => onChange('{}')}
            className="font-mono text-[13px] text-primary/90 underline underline-offset-2 hover:text-primary"
          >
            Add object
          </button>
        )
      }

      return (
        <ExplorerParamTextarea
          fieldKey={inputId}
          id={inputId}
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
          placeholder={getFormFieldPlaceholder('json')}
          className="min-h-[88px] w-full resize-y rounded-none border-0 bg-transparent p-0 font-mono text-[13px] leading-relaxed shadow-none outline-none ring-0 focus-visible:ring-0 dark:bg-transparent placeholder:font-mono placeholder:text-muted-foreground/45"
          spellCheck={false}
        />
      )

    case 'password':
      return (
        <ExplorerPasswordInput
          inputId={inputId}
          value={String(value ?? '')}
          onChange={(next) => onChange(next)}
        />
      )

    case 'email':
      return (
        <ExplorerParamInput
          fieldKey={inputId}
          id={inputId}
          type="email"
          inputMode="email"
          autoComplete="email"
          placeholder={getFormFieldPlaceholder(field.kind)}
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
          className={REQUEST_BUILDER_INPUT}
          spellCheck={false}
        />
      )

    case 'url':
      return (
        <ExplorerParamInput
          fieldKey={inputId}
          id={inputId}
          type="url"
          inputMode="url"
          autoComplete="url"
          placeholder={getFormFieldPlaceholder(field.kind)}
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
          className={REQUEST_BUILDER_INPUT}
          spellCheck={false}
        />
      )

    case 'phone':
      return (
        <ExplorerParamInput
          fieldKey={inputId}
          id={inputId}
          type="tel"
          inputMode="tel"
          autoComplete="tel"
          placeholder={getFormFieldPlaceholder(field.kind)}
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
          className={REQUEST_BUILDER_INPUT}
          spellCheck={false}
        />
      )

    case 'ip':
      return (
        <ExplorerParamInput
          fieldKey={inputId}
          id={inputId}
          type="text"
          autoComplete="off"
          placeholder={getFormFieldPlaceholder(field.kind)}
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
          className={REQUEST_BUILDER_INPUT}
          spellCheck={false}
        />
      )

    case 'datetime':
      return (
        <DateTimePicker
          id={inputId}
          value={String(value ?? '').trim() || null}
          onChange={(next) => onChange(next ?? '')}
          placeholder={getFormFieldPlaceholder(field.kind)}
          clearable={field.nullable || !field.required}
          hideIcon
          className={cn(
            REQUEST_BUILDER_INPUT,
            'h-full min-h-[44px] justify-start rounded-none border-0 bg-transparent font-mono shadow-none hover:bg-transparent dark:bg-transparent dark:hover:bg-transparent',
          )}
        />
      )

    case 'id':
      return (
        <ExplorerIdInput
          inputId={inputId}
          value={String(value ?? '')}
          onChange={(next) => onChange(next)}
        />
      )

    case 'string':
    default:
      return (
        <ExplorerParamInput
          fieldKey={inputId}
          id={inputId}
          type="text"
          placeholder={getFormFieldPlaceholder(field.kind)}
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
          className={REQUEST_BUILDER_INPUT}
          spellCheck={false}
          autoComplete="off"
        />
      )

    case 'binary':
      return (
        <span className="flex min-h-[44px] items-center px-4 font-mono text-[13px] text-muted-foreground/60">
          File uploads not supported yet
        </span>
      )
  }
}

function ExplorerPasswordInput({
  inputId,
  value,
  onChange,
}: {
  inputId: string
  value: string
  onChange: (value: string) => void
}) {
  const [revealed, setRevealed] = useState(false)

  useEffect(() => {
    setRevealed(false)
  }, [inputId])

  return (
    <div className="flex min-h-[44px] w-full items-center gap-1 pr-4">
      <ExplorerParamInput
        fieldKey={inputId}
        id={inputId}
        type={revealed ? 'text' : 'password'}
        placeholder={getFormFieldPlaceholder('password')}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={cn(REQUEST_BUILDER_INPUT, 'min-w-0 flex-1')}
        autoComplete="new-password"
        spellCheck={false}
      />
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="h-8 w-8 shrink-0 p-0 text-muted-foreground"
        onClick={() => setRevealed((current) => !current)}
        aria-label={revealed ? 'Hide password' : 'Show password'}
        title={revealed ? 'Hide password' : 'Show password'}
      >
        {revealed ? (
          <EyeOff className="h-3.5 w-3.5" />
        ) : (
          <Eye className="h-3.5 w-3.5" />
        )}
      </Button>
    </div>
  )
}

function ExplorerIdInput({
  inputId,
  value,
  onChange,
}: {
  inputId: string
  value: string
  onChange: (value: string) => void
}) {
  return (
    <div className="flex min-h-[44px] w-full items-center gap-2 pr-4">
      <ExplorerParamInput
        fieldKey={inputId}
        id={inputId}
        type="text"
        placeholder="// auto-generate if empty"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={cn(REQUEST_BUILDER_INPUT, 'min-w-0 flex-1')}
        spellCheck={false}
        autoComplete="off"
      />
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="h-8 shrink-0 px-2 text-[12px] text-muted-foreground"
        onClick={() => onChange(ID.unique())}
      >
        Generate
      </Button>
    </div>
  )
}

function ArrayStringInput({
  id,
  items,
  inputType,
  onChange,
}: {
  id: string
  items: string[]
  inputType: 'text' | 'number'
  onChange: (value: FormValue) => void
}) {
  const updateItem = (index: number, next: string) => {
    const copy = [...items]
    copy[index] = next
    onChange(copy)
  }

  const removeItem = (index: number) => {
    onChange(items.filter((_, itemIndex) => itemIndex !== index))
  }

  const addItem = () => {
    onChange([...items, ''])
  }

  if (items.length === 0) {
    return (
      <button
        type="button"
        onClick={addItem}
        className="font-mono text-[13px] text-primary/90 underline underline-offset-2 hover:text-primary"
      >
        Add array
      </button>
    )
  }

  return (
    <div className="space-y-1.5">
      {items.map((item, index) => (
        <div key={`${id}-${index}`} className="flex items-center gap-1">
          <ExplorerParamInput
            fieldKey={`${id}-${index}`}
            type={inputType}
            placeholder="// enter value"
            value={item}
            onChange={(event) => updateItem(index, event.target.value)}
            className={cn(REQUEST_BUILDER_INPUT, 'min-h-9 flex-1 py-1.5')}
            spellCheck={false}
          />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="mr-1 h-8 w-8 shrink-0 p-0 text-muted-foreground/60"
            onClick={() => removeItem(index)}
            aria-label="Remove item"
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        </div>
      ))}
      <button
        type="button"
        onClick={addItem}
        className="font-mono text-[12px] text-primary/90 underline underline-offset-2 hover:text-primary"
      >
        Add item
      </button>
    </div>
  )
}

function ArrayEnumInput({
  options,
  selected,
  onChange,
}: {
  options: string[]
  selected: string[]
  onChange: (value: FormValue) => void
}) {
  const toggle = (option: string, checked: boolean) => {
    if (checked) {
      onChange([...selected, option])
      return
    }
    onChange(selected.filter((value) => value !== option))
  }

  return (
    <div className="grid gap-1.5 sm:grid-cols-2">
      {options.map((option) => {
        const checked = selected.includes(option)
        const checkboxId = `enum-${option}`
        return (
          <label
            key={option}
            htmlFor={checkboxId}
            className="flex items-center gap-2 py-0.5"
          >
            <Checkbox
              id={checkboxId}
              checked={checked}
              onCheckedChange={(next) => toggle(option, next === true)}
            />
            <span className="font-mono text-[13px] text-foreground/85">{option}</span>
          </label>
        )
      })}
    </div>
  )
}
