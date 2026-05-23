/**
 * Container-query breakpoints for ServiceHeader toolbars.
 * Ancestor must include `@container/service-header` (toolbar) or
 * `@container/service-header-title` (title row).
 *
 * Narrow → medium → wide (toolbar width, not viewport):
 * - Search grows flexibly, then fixed w-64 at @[520px]
 * - Filters / custom actions: icon-only until @[560px]
 * - View toggle / right slot: hidden until @[480px]
 * - Import / export: hidden until @[600px]
 * - Create CTA: icon-only until @[640px]
 */

export const SERVICE_HEADER_CONTAINER = '@container/service-header'
export const SERVICE_HEADER_TITLE_CONTAINER = '@container/service-header-title'

/** Hide label text until toolbar is wide enough */
export const serviceHeaderShowLabel = 'hidden @[640px]:inline'

/** Icon-only control padding until wide enough */
export const serviceHeaderIconOnlyButton =
  'h-9 w-9 shrink-0 gap-0 p-0 @[640px]:h-9 @[640px]:w-auto @[640px]:gap-2 @[640px]:px-4'

/** Filters trigger: icon-only until @[560px] */
export const serviceHeaderFiltersButton =
  'h-9 shrink-0 gap-0 px-2.5 @[560px]:gap-1.5 @[560px]:px-3'

export const serviceHeaderFiltersLabel = 'sr-only @[560px]:not-sr-only'
