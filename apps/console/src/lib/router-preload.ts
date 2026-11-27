/**
 * Hover time before a link starts loading its page (code and loader data).
 * Long enough that sweeping the pointer across the sidebar loads nothing, short
 * enough that the page is usually loading by the time the click lands. Touch
 * devices do not preload at all (see below), so this only applies to hover.
 */
export const ROUTER_PRELOAD_DELAY_MS = 100

/**
 * Intent preload on touchstart competes with the tap's next paint (INP).
 * Keep hover preload on fine pointers; skip it on phones and tablets.
 */
export function getDefaultRouterPreload(
  media: Pick<MediaQueryList, 'matches'> | null | undefined = typeof window ===
  'undefined'
    ? null
    : window.matchMedia('(hover: hover) and (pointer: fine)'),
): false | 'intent' {
  if (!media) return 'intent'
  return media.matches ? 'intent' : false
}
