import { useCallback, useEffect, useState } from 'react'

/** Immersive rows grid: viewport overlay, Escape to exit, body scroll locked. */
export function useDatabaseRowsFullscreen(canUseFullscreen: boolean) {
  const [rowsFullscreen, setRowsFullscreen] = useState(false)

  useEffect(() => {
    if (!canUseFullscreen && rowsFullscreen) {
      setRowsFullscreen(false)
    }
  }, [canUseFullscreen, rowsFullscreen])

  useEffect(() => {
    if (!rowsFullscreen) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setRowsFullscreen(false)
      }
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [rowsFullscreen])

  useEffect(() => {
    if (!rowsFullscreen) return

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [rowsFullscreen])

  const toggleRowsFullscreen = useCallback(() => {
    if (!canUseFullscreen) return
    setRowsFullscreen((active) => !active)
  }, [canUseFullscreen])

  return {
    rowsFullscreen: canUseFullscreen && rowsFullscreen,
    toggleRowsFullscreen,
  }
}
