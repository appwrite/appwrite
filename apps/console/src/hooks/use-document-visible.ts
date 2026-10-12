import { useEffect, useState } from 'react'

function readDocumentVisible(): boolean {
  return typeof document === 'undefined' || document.visibilityState === 'visible'
}

/**
 * Whether the browser tab is visible to the user (Page Visibility API).
 * Unlike window focus, this stays true when the user switches to another app
 * while this tab remains the active tab in the browser.
 */
export function useDocumentVisible(): boolean {
  const [visible, setVisible] = useState(readDocumentVisible)

  useEffect(() => {
    const onVisibilityChange = () => {
      setVisible(readDocumentVisible())
    }

    document.addEventListener('visibilitychange', onVisibilityChange)
    return () => document.removeEventListener('visibilitychange', onVisibilityChange)
  }, [])

  return visible
}
