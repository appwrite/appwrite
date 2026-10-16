import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import type { FormValue, RequestFormField } from '@/lib/api-explorer/request-form'
import type { ApiExplorerMethod } from '@/lib/api-explorer/types'
import { REQUEST_BUILDER_CONTAINER } from './request-form-table'
import { RequestFormFieldInput } from './RequestFormFieldInput'

type RequestFormFieldsProps = {
  fields: RequestFormField[]
  values: Record<string, FormValue>
  onChange: (name: string, value: FormValue) => void
  idPrefix: string
  className?: string
  projectId?: string
  formValues?: Record<string, FormValue>
  method?: ApiExplorerMethod
}

export function RequestFormFields({
  fields,
  values,
  onChange,
  idPrefix,
  className,
  projectId,
  formValues,
  method,
}: RequestFormFieldsProps) {
  if (fields.length === 0) return null

  return (
    <div className={cn(className)}>
      {fields.map((field) => (
        <RequestFormFieldInput
          key={field.name}
          field={field}
          value={values[field.name]}
          onChange={(next) => onChange(field.name, next)}
          idPrefix={idPrefix}
          projectId={projectId}
          formValues={formValues}
          method={method}
        />
      ))}
    </div>
  )
}

type RequestBuilderPanelProps = {
  children: ReactNode
  className?: string
}

export function RequestBuilderPanel({
  children,
  className,
}: RequestBuilderPanelProps) {
  return (
    <div
      className={cn(
        REQUEST_BUILDER_CONTAINER,
        'overflow-hidden rounded-lg border border-border bg-background',
        className,
      )}
    >
      {children}
    </div>
  )
}

type RequestBuilderSectionProps = {
  title: string
  action?: ReactNode
  children: ReactNode
  showTopBorder?: boolean
}

export function RequestBuilderSection({
  title,
  action,
  children,
  showTopBorder = false,
}: RequestBuilderSectionProps) {
  return (
    <div className={cn(showTopBorder && 'border-t border-border')}>
      <div className="flex items-center justify-between gap-3 border-b border-border px-3 py-2.5 @[680px]/request-builder:px-4">
        <h4 className="text-[13px] font-semibold tracking-tight text-foreground">
          {title}
        </h4>
        {action}
      </div>
      {children}
    </div>
  )
}
