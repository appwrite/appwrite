import { useCallback, useEffect, useRef, useState } from 'react'

type PopoutOptions = {
  title?: string
  width?: number
  /** Fraction of the current screen's available width; overrides `width`. */
  widthRatio?: number
  height?: number
  onBlocked?: () => void
}

function isStyleNode(node: Node): node is HTMLStyleElement | HTMLLinkElement {
  return (
    node instanceof HTMLStyleElement ||
    (node instanceof HTMLLinkElement && node.rel === 'stylesheet')
  )
}

function mirrorRootAttributes(target: Document) {
  const { documentElement: from, body } = document
  target.documentElement.className = from.className
  target.documentElement.setAttribute('style', from.getAttribute('style') ?? '')
  target.documentElement.dir = from.dir
  target.documentElement.lang = from.lang
  target.body.className = body.className
}

type DocumentPictureInPicture = {
  requestWindow: (options: { width: number; height: number }) => Promise<Window>
}

/**
 * Chrome turns `window.open` popups into tabs while the browser is in macOS
 * full screen. Document Picture-in-Picture still gets a separate window there,
 * though Chrome caps its initial size (the user can resize it).
 */
async function openChildWindow(w: number, h: number): Promise<Window | null> {
  const pip = (
    window as Window & { documentPictureInPicture?: DocumentPictureInPicture }
  ).documentPictureInPicture
  if (pip && window.matchMedia('(display-mode: fullscreen)').matches) {
    try {
      return await pip.requestWindow({ width: w, height: h })
    } catch {
      // Fall through to a regular popup.
    }
  }
  const screen = window.screen as Screen & {
    availLeft?: number
    availTop?: number
  }
  const minLeft = screen.availLeft ?? 0
  const minTop = screen.availTop ?? 0
  const left = Math.round(
    Math.min(
      Math.max(window.screenX + (window.outerWidth - w) / 2, minLeft),
      minLeft + screen.availWidth - w,
    ),
  )
  const top = Math.round(
    Math.min(
      Math.max(window.screenY + (window.outerHeight - h) / 2, minTop),
      minTop + screen.availHeight - h,
    ),
  )
  return window.open(
    'about:blank',
    '_blank',
    [
      'popup=yes',
      `width=${w}`,
      `height=${h}`,
      `left=${left}`,
      `top=${top}`,
      'toolbar=no',
      'location=no',
      'menubar=no',
      'status=no',
      'scrollbars=yes',
      'resizable=yes',
    ].join(','),
  )
}

/**
 * Opens a same-origin child window and returns a container element inside it.
 * Render into it with `createPortal`: the content stays in this React tree, so
 * both windows share state, context, and callbacks without any messaging.
 */
export function usePopoutWindow({
  title,
  width = 1360,
  widthRatio,
  height = 880,
  onBlocked,
}: PopoutOptions) {
  const [container, setContainer] = useState<HTMLElement | null>(null)
  const windowRef = useRef<Window | null>(null)
  const cleanupRef = useRef<(() => void) | null>(null)

  const close = useCallback(() => {
    cleanupRef.current?.()
    cleanupRef.current = null
    windowRef.current?.close()
    windowRef.current = null
    setContainer(null)
  }, [])

  const open = useCallback(
    async (titleOverride?: string) => {
      if (windowRef.current && !windowRef.current.closed) {
        if (titleOverride) windowRef.current.document.title = titleOverride
        windowRef.current.focus()
        return
      }
      const w = widthRatio
        ? Math.round(window.screen.availWidth * widthRatio)
        : Math.min(width, window.screen.availWidth)
      const h = Math.min(height, window.screen.availHeight)
      const popup = await openChildWindow(w, h)
      if (!popup) {
        onBlocked?.()
        return
      }
      windowRef.current = popup

      const doc = popup.document
      doc.title = titleOverride ?? title ?? ''
      doc.head.replaceChildren()
      doc.body.replaceChildren()
      for (const node of Array.from(document.head.childNodes)) {
        if (isStyleNode(node)) doc.head.appendChild(node.cloneNode(true))
      }
      mirrorRootAttributes(doc)

      const root = doc.createElement('div')
      root.className = 'h-full'
      doc.body.style.margin = '0'
      doc.body.style.height = '100%'
      doc.documentElement.style.height = '100%'
      doc.body.appendChild(root)

      // Keep styles (dev HMR) and theme / direction in sync with the main window.
      const headObserver = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
          for (const node of Array.from(mutation.addedNodes)) {
            if (isStyleNode(node)) doc.head.appendChild(node.cloneNode(true))
          }
        }
      })
      headObserver.observe(document.head, { childList: true })
      const rootObserver = new MutationObserver(() => {
        mirrorRootAttributes(doc)
        doc.body.style.margin = '0'
        doc.body.style.height = '100%'
        doc.documentElement.style.height = '100%'
      })
      rootObserver.observe(document.documentElement, { attributes: true })
      rootObserver.observe(document.body, { attributes: true })

      const handlePopupClose = () => {
        cleanupRef.current?.()
        cleanupRef.current = null
        windowRef.current = null
        setContainer(null)
      }
      popup.addEventListener('pagehide', handlePopupClose)
      const handleMainUnload = () => popup.close()
      window.addEventListener('pagehide', handleMainUnload)

      cleanupRef.current = () => {
        headObserver.disconnect()
        rootObserver.disconnect()
        popup.removeEventListener('pagehide', handlePopupClose)
        window.removeEventListener('pagehide', handleMainUnload)
      }
      setContainer(root)
    },
    [title, width, widthRatio, height, onBlocked],
  )

  useEffect(() => close, [close])

  return { container, open, close, isOpen: container != null }
}
