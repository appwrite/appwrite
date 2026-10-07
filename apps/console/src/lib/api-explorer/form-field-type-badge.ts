import {
  getFormFieldOpenApiTypeLabel,
  type RequestFormFieldKind,
} from './request-form'
import { cn } from '@/lib/utils'

export type FormFieldTypeBadgeVariant =
  | 'processing'
  | 'success'
  | 'warning'
  | 'info'
  | 'inactive'
  | 'secondary'

/** HTTP method, scope, and meta pills: tinted fill without an outline. */
export const API_EXPLORER_PILL_CLASS = cn(
  'border-0 shadow-none shrink-0',
)

/** Type pills in API explorer and docs reference parameter tables. */
export const FORM_FIELD_TYPE_PILL_CLASS = cn(
  API_EXPLORER_PILL_CLASS,
  'px-2 font-mono text-[10px]',
)

export function getOpenApiTypeBadgeVariant(
  type: string,
): FormFieldTypeBadgeVariant {
  const normalized = type.toLowerCase().trim()

  switch (normalized) {
    case 'boolean':
      return 'info'
    case 'integer':
    case 'number':
      return 'warning'
    case 'string':
      return 'processing'
    case 'file':
    case 'binary':
      return 'inactive'
    case 'array':
      return 'success'
    case 'object':
      return 'info'
    default:
      if (normalized.includes('|')) return 'warning'
      return 'processing'
  }
}

export function getFormFieldTypeBadgeVariant(
  kind: RequestFormFieldKind,
): FormFieldTypeBadgeVariant {
  if (kind === 'enum' || kind === 'array-enum') return 'warning'
  if (kind === 'array-string' || kind === 'array-number') return 'success'
  if (kind === 'json') return 'info'
  return getOpenApiTypeBadgeVariant(getFormFieldOpenApiTypeLabel(kind))
}
