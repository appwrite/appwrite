/**
 * Container-query breakpoints for settings layout shells.
 *
 * `SETTINGS_LAYOUT_CONTAINER` must wrap an *inner* flex root — container queries
 * cannot style the container element itself, so `@[1024px]/settings-layout:flex-row`
 * has to live on a descendant of the `@container/settings-layout` node.
 *
 * Responsive behavior follows the settings host width (full page, pane, splitter),
 * not the viewport, so the mobile select vs side nav switch works in the agent
 * right pane and other narrow hosts.
 *
 * Side nav appears at @[1024px] (same threshold as the previous viewport `lg`).
 */

export const SETTINGS_LAYOUT_CONTAINER =
  '@container/settings-layout w-full min-w-0'

/** Stacked mobile select → side-by-side nav + content (inner flex root). */
export const settingsLayoutRootClass =
  'flex w-full min-w-0 flex-col gap-4 @[1024px]/settings-layout:flex-row @[1024px]/settings-layout:gap-8'

/** Mobile section select (hidden once side nav fits) */
export const settingsLayoutMobileNavClass =
  'w-full space-y-2 @[1024px]/settings-layout:hidden'

/** Desktop side nav (shown once the container is wide enough) */
export const settingsLayoutDesktopNavClass =
  'sticky top-4 hidden shrink-0 flex-col gap-2 self-start @[1024px]/settings-layout:flex'

/** Main settings content column */
export const settingsLayoutContentClass = 'min-w-0 w-full flex-1'