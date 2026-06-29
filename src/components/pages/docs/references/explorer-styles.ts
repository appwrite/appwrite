import { DOCS_SECTION_HEADER_CLASS } from '@/lib/docs/nav-styles'
import {
  FORM_FIELD_TYPE_PILL_CLASS,
  getOpenApiTypeBadgeVariant,
  type FormFieldTypeBadgeVariant,
} from '@/lib/api-explorer/form-field-type-badge'
import { normalizeOpenApiPrimitiveType } from '@/lib/api-explorer/request-form'
import type { ApiReferencePropertyTypeKind } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'

export type ReferenceTypeBadgeVariant = FormFieldTypeBadgeVariant

/**
 * Container on the explorer root (measures `main` content width only).
 * Pair utilities with the `/reference-explorer` container name — not the layout-row
 * `@container`, which includes sidebar width and wrongly enables desktop columns on iPad.
 */
export const REFERENCE_EXPLORER_CONTAINER = '@container/reference-explorer'

/** Hide at/above this main-column width (sheet navigation). */
export const REFERENCE_EXPLORER_MOBILE_ONLY_CLASS =
  '@[900px]/reference-explorer:hidden'

/** Show at/above this main-column width (methods + detail columns). */
export const REFERENCE_EXPLORER_DESKTOP_ONLY_CLASS =
  'hidden @[900px]/reference-explorer:block'

/** Min layout width before pinning the API references section subnav beside global docs nav. */
export const REFERENCE_SECTION_SUBNAV_DESKTOP_CLASS = '@[1280px]:flex'

/** Matches {@link DOCS_SECTION_HEADER_CLASS} height for alignment with the docs section subnav. */
export const REFERENCE_COLUMN_HEADER_CLASS = `${DOCS_SECTION_HEADER_CLASS} px-4`

export const REFERENCE_SCROLL_AREA_CLASS = 'min-h-0 min-w-0 flex-1 overflow-hidden'

export const REFERENCE_RESIZE_HANDLE_CLASS =
  'relative z-[45] w-[0.5px] bg-border before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100 after:w-2 after:left-1/2 after:-translate-x-1/2'

export { getHttpMethodBadgeVariant as getHttpMethodVariant } from '@/lib/http-method-badge'

/** Status pills in API references: tinted fill without an outline. */
export const REFERENCE_PILL_CLASS = 'border-0 shadow-none'

/** Type and status pills in reference tables. */
export const REFERENCE_TYPE_PILL_CLASS = FORM_FIELD_TYPE_PILL_CLASS

export {
  getFormFieldTypeBadgeVariant,
  getOpenApiTypeBadgeVariant,
} from '@/lib/api-explorer/form-field-type-badge'

/** Subtle method-colored accents for endpoint boxes and nav selection. */
export function getHttpMethodAccentClasses(method: string): {
  endpointBox: string
  methodText: string
} {
  switch (method.toLowerCase()) {
    case 'get':
      return {
        endpointBox: 'bg-blue-500/[0.06]',
        methodText: 'text-blue-600 dark:text-blue-400',
      }
    case 'post':
      return {
        endpointBox: 'bg-emerald-500/[0.06]',
        methodText: 'text-emerald-600 dark:text-emerald-400',
      }
    case 'put':
    case 'patch':
      return {
        endpointBox: 'bg-amber-500/[0.06]',
        methodText: 'text-amber-600 dark:text-amber-400',
      }
    case 'delete':
      return {
        endpointBox: 'bg-red-500/[0.06]',
        methodText: 'text-red-600 dark:text-red-400',
      }
    default:
      return {
        endpointBox: 'bg-muted/40',
        methodText: 'text-foreground',
      }
  }
}

export { getHttpStatusCodeBadgeVariant as getResponseStatusVariant } from '@/lib/http-status-code'

export function formatOpenApiTypeLabel(type: string): string {
  const normalized = normalizeOpenApiPrimitiveType(type)
  const lower = normalized.toLowerCase().trim()

  if (
    lower === 'string' ||
    lower === 'integer' ||
    lower === 'number' ||
    lower === 'boolean' ||
    lower === 'array' ||
    lower === 'object' ||
    lower === 'enum'
  ) {
    return lower
  }

  return normalized
}

export function getModelPropertyTypeBadgeVariant(
  typeKind: ApiReferencePropertyTypeKind,
  type: string,
): ReferenceTypeBadgeVariant {
  if (typeKind === 'array') return 'success'
  if (typeKind === 'object') return 'info'
  return getOpenApiTypeBadgeVariant(formatOpenApiTypeLabel(type))
}
