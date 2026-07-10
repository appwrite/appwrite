import { ID } from '@appwrite.io/console'
import type { ComponentProps, FocusEvent, ReactNode } from 'react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Eye, EyeOff, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
import { getQueryFilterColumnsForMethod } from '@/lib/api-explorer/field-helpers'
import type { ApiExplorerMethod } from '@/lib/api-explorer/types'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { ExplorerPermissionsField } from './ExplorerPermissionsField'
import { ExplorerQueryBuilderField } from './ExplorerQueryBuilderField'
import { ExplorerArrayItemInputs, ExplorerArrayAddControl } from './ExplorerArrayItemInputs'
import {
  ExplorerResourceIdHelper,
  ExplorerResourceIdValue,
} from './ExplorerResourceIdInput'
import {
  getFormFieldPlaceholder,
  getFormFieldTypeLabel,
  type FormValue,
  type RequestFormField,
} from '@/lib/api-explorer/request-form'
import {
  FORM_FIELD_TYPE_PILL_CLASS,
  getFormFieldTypeBadgeVariant,
} from '@/lib/api-explorer/form-field-type-badge'
import {
  REQUEST_BUILDER_HELPER_CELL,
  REQUEST_BUILDER_HELPER_LINK,
  REQUEST_BUILDER_HELPER_SLOT,
  REQUEST_BUILDER_INPUT,
  REQUEST_BUILDER_NAME_CELL,
  REQUEST_BUILDER_REQUIRED,
  REQUEST_BUILDER_REQUIRED_CELL,
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
  projectId?: string
  formValues?: Record<string, FormValue>
  method?: ApiExplorerMethod
}

export function RequestFormFieldInput({
  field,
  value,
  onChange,
  idPrefix = 'field',
  className,
  projectId,
  formValues,
  method,
}: RequestFormFieldInputProps) {
  const t = useT()
  const inputId = `${idPrefix}-${field.name}`
  const hasHelperField = Boolean(field.helper)
  const isHelperArrayField =
    field.helper?.type === 'permissions' || field.helper?.type === 'queries'
  const helperArrayItemCount =
    isHelperArrayField && Array.isArray(value) ? value.length : 0
  const plainArrayItemCount =
    !hasHelperField &&
    (field.kind === 'array-string' || field.kind === 'array-number') &&
    Array.isArray(value)
      ? value.length
      : 0

  const isComplex =
    (isHelperArrayField && helperArrayItemCount > 0) ||
    plainArrayItemCount > 0 ||
    (!hasHelperField &&
      (field.kind === 'json' || field.kind === 'array-enum'))

  const useCombinedHelperArrayLayout =
    isHelperArrayField && helperArrayItemCount > 0

  const helperControl = renderHelperControl(
    field,
    value,
    onChange,
    inputId,
    projectId,
    formValues,
    method,
    t,
  )
  const hasHelperControl = helperControl != null

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
        <Badge
          variant={getFormFieldTypeBadgeVariant(field.kind)}
          className={FORM_FIELD_TYPE_PILL_CLASS}
        >
          {getFormFieldTypeLabel(field.kind)}
        </Badge>
      </div>

      <div
        className={cn(
          REQUEST_BUILDER_REQUIRED_CELL,
          isComplex && '@[680px]/request-builder:items-start',
        )}
      >
        {field.required ? (
          <span className={REQUEST_BUILDER_REQUIRED}>{t('Required')}</span>
        ) : null}
      </div>

      {useCombinedHelperArrayLayout ? (
        <div
          className={cn(
            REQUEST_BUILDER_VALUE_CELL,
            'col-span-3 @[680px]/request-builder:col-span-2 @[680px]/request-builder:items-start',
          )}
        >
          <ValueCell complex={isComplex}>
            {renderValueControl(
              field,
              value,
              onChange,
              inputId,
              projectId,
              formValues,
              method,
              true,
              t,
            )}
          </ValueCell>
        </div>
      ) : (
        <>
          <div className={REQUEST_BUILDER_VALUE_CELL}>
            <ValueCell complex={isComplex}>
              {renderValueControl(
                field,
                value,
                onChange,
                inputId,
                projectId,
                formValues,
                method,
                false,
                t,
              )}
            </ValueCell>
          </div>

          <div
            className={cn(
              REQUEST_BUILDER_HELPER_CELL,
              !hasHelperControl && 'hidden @[680px]/request-builder:flex',
              isComplex && '@[680px]/request-builder:items-start',
            )}
          >
            <div className={REQUEST_BUILDER_HELPER_SLOT}>{helperControl}</div>
          </div>
        </>
      )}
    </div>
  )
}

function ValueCell({
  complex,
  children,
}: {
  complex: boolean
  children: ReactNode
}) {
  if (complex) {
    return (
      <div className={REQUEST_BUILDER_VALUE_INNER_COMPLEX}>
        <div className="min-w-0 flex-1">{children}</div>
      </div>
    )
  }

  return (
    <div className={REQUEST_BUILDER_VALUE_INNER}>
      <div className="min-w-0 flex-1">{children}</div>
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
        'block min-w-0 w-full truncate font-mono text-[13px] font-normal text-foreground/85',
        description &&
          'cursor-help underline decoration-dotted decoration-muted-foreground/38 underline-offset-[3px] hover:decoration-muted-foreground/58',
      )}
    >
      {name}
    </Label>
  )

  if (!description?.trim()) {
    return <div className="min-w-0 w-full">{label}</div>
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <div className="min-w-0 w-full">{label}</div>
      </TooltipTrigger>
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

function renderValueControl(
  field: RequestFormField,
  value: FormValue,
  onChange: (value: FormValue) => void,
  inputId: string,
  projectId?: string,
  formValues?: Record<string, FormValue>,
  method?: ApiExplorerMethod,
  combinedHelperArrayLayout = false,
  translate: (text: string) => string = (text) => text,
) {
  if (field.helper?.type === 'permissions') {
    return (
      <ExplorerPermissionsField
        field={field}
        value={value}
        onChange={onChange}
        inputId={inputId}
        projectId={projectId}
        part={combinedHelperArrayLayout ? 'combined' : 'value'}
      />
    )
  }

  if (field.helper?.type === 'queries') {
    return (
      <ExplorerQueryBuilderField
        field={field}
        value={value}
        onChange={onChange}
        inputId={inputId}
        columns={getQueryFilterColumnsForMethod(method)}
        part={combinedHelperArrayLayout ? 'combined' : 'value'}
      />
    )
  }

  if (field.helper?.type === 'resource-id') {
    return (
      <ExplorerResourceIdValue
        inputId={inputId}
        value={String(value ?? '')}
        onChange={(next) => onChange(next)}
      />
    )
  }

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
        <ExplorerArrayItemInputs
          idPrefix={inputId}
          items={Array.isArray(value) ? value.map(String) : []}
          onChange={(next) => onChange(next)}
          inputType={field.kind === 'array-number' ? 'number' : 'text'}
        />
      )

    case 'array-enum':
      return (
        <div className="px-4 py-3">
          <ArrayEnumInput
            options={field.enumValues ?? []}
            selected={Array.isArray(value) ? value : []}
            onChange={onChange}
          />
        </div>
      )

    case 'json':
      if (!String(value ?? '').trim()) {
        return (
          <span
            id={inputId}
            className="block px-4 font-mono text-[13px] text-muted-foreground/45"
          >
            {translate('No object')}
          </span>
        )
      }

      return (
        <div className="px-4 py-3">
          <ExplorerParamTextarea
            fieldKey={inputId}
            id={inputId}
            value={String(value ?? '')}
            onChange={(event) => onChange(event.target.value)}
            placeholder={getFormFieldPlaceholder('json')}
            className="min-h-[88px] w-full resize-y rounded-none border-0 bg-transparent p-0 font-mono text-[13px] leading-relaxed shadow-none outline-none ring-0 focus-visible:ring-0 dark:bg-transparent placeholder:font-mono placeholder:text-muted-foreground/45"
            spellCheck={false}
          />
        </div>
      )

    case 'password':
      return (
        <ExplorerPasswordValue
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
        <ExplorerIdValue
          inputId={inputId}
          value={String(value ?? '')}
          onChange={(next) => onChange(next)}
          required={field.required}
        />
      )

    case 'binary':
      return (
        <ExplorerFileValue
          inputId={inputId}
          value={value instanceof File ? value : null}
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
  }
}

function renderHelperControl(
  field: RequestFormField,
  value: FormValue,
  onChange: (value: FormValue) => void,
  inputId: string,
  projectId?: string,
  formValues?: Record<string, FormValue>,
  method?: ApiExplorerMethod,
  translate: (text: string) => string = (text) => text,
) {
  if (field.helper?.type === 'permissions') {
    return (
      <ExplorerPermissionsField
        field={field}
        value={value}
        onChange={onChange}
        inputId={inputId}
        projectId={projectId}
        part="helper"
      />
    )
  }

  if (field.helper?.type === 'queries') {
    return (
      <ExplorerQueryBuilderField
        field={field}
        value={value}
        onChange={onChange}
        inputId={inputId}
        columns={getQueryFilterColumnsForMethod(method)}
        part="helper"
      />
    )
  }

  if (field.helper?.type === 'resource-id') {
    return (
      <ExplorerResourceIdHelper
        resourceType={field.helper.resourceType}
        value={String(value ?? '')}
        onChange={(next) => onChange(next)}
        projectId={projectId}
        formValues={formValues}
      />
    )
  }

  switch (field.kind) {
    case 'id':
      return (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={() => onChange(ID.unique())}
        >
          {translate('Generate')}
        </Button>
      )

    case 'binary':
      return (
        <ExplorerFileHelper
          inputId={inputId}
          value={value instanceof File ? value : null}
          onChange={onChange}
        />
      )

    case 'json':
      if (!String(value ?? '').trim()) {
        return (
          <button
            type="button"
            className={REQUEST_BUILDER_HELPER_LINK}
            onClick={() => onChange('{}')}
          >
            {translate('Add object')}
          </button>
        )
      }
      return null

    case 'array-string':
    case 'array-number':
      return (
        <ArrayStringHelper
          items={Array.isArray(value) ? value : []}
          onChange={onChange}
        />
      )

    default:
      return null
  }
}

function ExplorerFileValue({
  value,
}: {
  inputId: string
  value: File | null
}) {
  return (
    <span
      className={cn(
        'block min-w-0 truncate px-4 font-mono text-[13px]',
        value ? 'text-foreground/85' : 'text-muted-foreground/45',
      )}
      title={value?.name}
    >
      {value?.name ?? '// No file selected'}
    </span>
  )
}

function ExplorerFileHelper({
  inputId,
  value,
  onChange,
}: {
  inputId: string
  value: File | null
  onChange: (value: FormValue) => void
}) {
  const t = useT()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const handleChooseFile = () => {
    fileInputRef.current?.click()
  }

  const handleClearFile = () => {
    onChange(null)
    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }

  return (
    <>
      <input
        ref={fileInputRef}
        id={inputId}
        type="file"
        className="sr-only"
        onChange={(event) => {
          const file = event.target.files?.[0]
          onChange(file ?? null)
        }}
      />
      {value ? (
        <div className="flex min-w-0 items-center gap-1.5">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            onClick={handleChooseFile}
          >
            {t('Change')}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 w-8 shrink-0 p-0 text-muted-foreground/60"
            onClick={handleClearFile}
            aria-label={t('Clear file')}
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        </div>
      ) : (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={handleChooseFile}
        >
          {t('Choose file')}
        </Button>
      )}
    </>
  )
}

function ExplorerPasswordValue({
  inputId,
  value,
  onChange,
}: {
  inputId: string
  value: string
  onChange: (value: string) => void
}) {
  const t = useT()
  const [revealed, setRevealed] = useState(false)

  useEffect(() => {
    setRevealed(false)
  }, [inputId])

  return (
    <div className="relative w-full">
      <ExplorerParamInput
        fieldKey={inputId}
        id={inputId}
        type={revealed ? 'text' : 'password'}
        placeholder={getFormFieldPlaceholder('password')}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className={cn(REQUEST_BUILDER_INPUT, 'pe-10')}
        autoComplete="new-password"
        spellCheck={false}
      />
      <button
        type="button"
        onClick={() => setRevealed((current) => !current)}
        className="absolute end-4 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
        aria-label={revealed ? t('Hide password') : t('Show password')}
        title={revealed ? t('Hide password') : t('Show password')}
      >
        {revealed ? (
          <EyeOff className="h-4 w-4" />
        ) : (
          <Eye className="h-4 w-4" />
        )}
      </button>
    </div>
  )
}

function ExplorerIdValue({
  inputId,
  value,
  onChange,
  required,
}: {
  inputId: string
  value: string
  onChange: (value: string) => void
  required: boolean
}) {
  return (
    <ExplorerParamInput
      fieldKey={inputId}
      id={inputId}
      type="text"
      placeholder={getFormFieldPlaceholder('id', { required })}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      className={REQUEST_BUILDER_INPUT}
      spellCheck={false}
      autoComplete="off"
    />
  )
}

function ArrayStringHelper({
  items,
  onChange,
}: {
  items: string[]
  onChange: (value: FormValue) => void
}) {
  if (items.length > 0) {
    return null
  }

  return (
    <div className="flex min-h-[44px] items-center">
      <ExplorerArrayAddControl
        items={items}
        onChange={(next) => onChange(next)}
      />
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
