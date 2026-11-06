import { DOCS_SECTION_HEADER_CLASS } from '@/lib/docs/nav-styles'
import {
  FORM_FIELD_TYPE_PILL_CLASS,
  getFormFieldTypeBadgeVariant,
  getOpenApiTypeBadgeVariant,
  API_EXPLORER_PILL_CLASS,
  type FormFieldTypeBadgeVariant,
} from '@/lib/api-explorer/form-field-type-badge'
import { normalizeOpenApiPrimitiveType } from '@/lib/api-explorer/request-form'
import type { ApiReferencePropertyTypeKind } from '@/lib/docs/references/types'

export type ReferenceTypeBadgeVariant = FormFieldTypeBadgeVariant

/**
 * Container on the explorer root (measures `main` content width only).
 * Pair utilities with the `/reference-explorer` container name - not the layout-row
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

import { verticalPanelResizeHandleClass } from '@/lib/layout/horizontal-resize'

export const REFERENCE_RESIZE_HANDLE_CLASS = verticalPanelResizeHandleClass('z-[45]')

export { getHttpMethodBadgeVariant as getHttpMethodVariant } from '@/lib/http-method-badge'
export { getHttpMethodAccentClasses } from '@/lib/http-method-badge'

/** Status pills in API references: tinted fill without an outline. */
export const REFERENCE_PILL_CLASS = API_EXPLORER_PILL_CLASS

/** Type and status pills in reference tables. */
export const REFERENCE_TYPE_PILL_CLASS = FORM_FIELD_TYPE_PILL_CLASS

export {
  getFormFieldTypeBadgeVariant,
  getOpenApiTypeBadgeVariant,
} from '@/lib/api-explorer/form-field-type-badge'

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
