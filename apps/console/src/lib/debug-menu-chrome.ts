/** Shared visual tokens for debug menu and demo navigator (English + LTR tooling). */

export const DEBUG_MENU_BORDER_MIX =
  'border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))]'

export const DEBUG_MENU_BORDER_MIX_SUBTLE =
  'border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))]'

export const DEBUG_MENU_SHELL_CLASS = [
  'flex flex-col overflow-hidden rounded-xl border bg-popover p-0 shadow-xl',
  DEBUG_MENU_BORDER_MIX,
].join(' ')

export const DEBUG_MENU_HEADER_CLASS = [
  'shrink-0 bg-popover/95 backdrop-blur-sm',
  DEBUG_MENU_BORDER_MIX_SUBTLE,
].join(' ')

export const DEBUG_MENU_MUTED_TEXT = 'text-[var(--network-globe-edge)]/70'

export const DEBUG_MENU_ICON_BUTTON_CLASS =
  'flex items-center justify-center rounded-lg text-[var(--network-globe-edge)] transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40'

export const DEBUG_MENU_ROW_HOVER =
  'hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)]'
