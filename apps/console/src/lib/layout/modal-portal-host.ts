import { useCallback, useRef, useState } from 'react'

const MODAL_PORTAL_HOST_SELECTOR = [
  '[data-slot="dialog-content"]',
  '[data-slot="sheet-content"]',
  '[data-slot="alert-dialog-content"]',
].join(', ')

export function resolveModalPortalHost(
  from: Element | null | undefined,
): HTMLElement | null {
  if (!from) return null
  return from.closest(MODAL_PORTAL_HOST_SELECTOR) as HTMLElement | null
}

export function useModalAwarePopover() {
  const rootRef = useRef<HTMLDivElement>(null)
  const [portalContainer, setPortalContainer] = useState<HTMLElement | null>(
    null,
  )

  const prepareOpen = useCallback(() => {
    setPortalContainer(
      resolveModalPortalHost(rootRef.current) ??
        resolveModalPortalHost(
          document.activeElement instanceof Element
            ? document.activeElement
            : null,
        ),
    )
  }, [])

  const handleOpenChange = useCallback(
    (nextOpen: boolean) => {
      if (nextOpen) prepareOpen()
      else setPortalContainer(null)
    },
    [prepareOpen],
  )

  return {
    rootRef,
    portalContainer,
    modal: !portalContainer,
    handleOpenChange,
    prepareOpen,
  }
}
