export const ROUTER_PRELOAD_DELAY_MS = 400

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
