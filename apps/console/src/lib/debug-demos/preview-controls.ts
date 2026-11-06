export const DEBUG_DEMO_PREVIEW_CONTROL_EVENT = 'debugDemoPreviewControl'

export type DebugDemoPreviewControl = { type: 'oauth2-reset-outcome' }

export function dispatchDebugDemoPreviewControl(
  control: DebugDemoPreviewControl,
) {
  if (typeof window === 'undefined') return
  window.dispatchEvent(
    new CustomEvent(DEBUG_DEMO_PREVIEW_CONTROL_EVENT, { detail: control }),
  )
}

export function subscribeDebugDemoPreviewControl(
  listener: (control: DebugDemoPreviewControl) => void,
) {
  if (typeof window === 'undefined') return () => {}
  const handler = (event: Event) => {
    const detail = (event as CustomEvent<DebugDemoPreviewControl>).detail
    if (detail?.type) listener(detail)
  }
  window.addEventListener(DEBUG_DEMO_PREVIEW_CONTROL_EVENT, handler)
  return () =>
    window.removeEventListener(DEBUG_DEMO_PREVIEW_CONTROL_EVENT, handler)
}
