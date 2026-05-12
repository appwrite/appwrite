import { useCallback, useEffect, useRef, useState } from 'react'

/** Defaults match database schema / browser canvas visualizers */
export const VIEWPORT_PAN_ZOOM_MIN = 0.2
export const VIEWPORT_PAN_ZOOM_MAX = 2
export const VIEWPORT_PAN_ZOOM_STEP = 0.05
export const VIEWPORT_PAN_ZOOM_WHEEL_STEP = 0.02

export type ViewportPanZoomOptions = {
  minZoom?: number
  maxZoom?: number
  zoomStep?: number
  wheelZoomStep?: number
}

/**
 * Pan (drag) + wheel zoom toward cursor, same behavior as database schema visualizer canvas.
 */
export function useViewportPanZoom(options?: ViewportPanZoomOptions) {
  const minZoom = options?.minZoom ?? VIEWPORT_PAN_ZOOM_MIN
  const maxZoom = options?.maxZoom ?? VIEWPORT_PAN_ZOOM_MAX
  const zoomStep = options?.zoomStep ?? VIEWPORT_PAN_ZOOM_STEP
  const wheelZoomStep = options?.wheelZoomStep ?? VIEWPORT_PAN_ZOOM_WHEEL_STEP

  const canvasRef = useRef<HTMLDivElement>(null)
  const [zoom, setZoom] = useState(1)
  const [pan, setPan] = useState({ x: 0, y: 0 })
  const [isDragging, setIsDragging] = useState(false)
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 })

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return

    const handleWheel = (e: WheelEvent) => {
      e.preventDefault()

      const delta = e.deltaY > 0 ? -wheelZoomStep : wheelZoomStep
      const newZoom = Math.max(minZoom, Math.min(maxZoom, zoom + delta))

      const rect = canvas.getBoundingClientRect()
      const mouseX = e.clientX - rect.left
      const mouseY = e.clientY - rect.top

      const zoomPointX = (mouseX - pan.x) / zoom
      const zoomPointY = (mouseY - pan.y) / zoom

      setZoom(newZoom)
      setPan({
        x: mouseX - zoomPointX * newZoom,
        y: mouseY - zoomPointY * newZoom,
      })
    }

    canvas.addEventListener('wheel', handleWheel, { passive: false })
    return () => canvas.removeEventListener('wheel', handleWheel)
  }, [zoom, pan, minZoom, maxZoom, wheelZoomStep])

  const handleMouseDown = useCallback(
    (e: React.MouseEvent) => {
      if (e.button !== 0) return
      setIsDragging(true)
      setDragStart({
        x: e.clientX - pan.x,
        y: e.clientY - pan.y,
      })
    },
    [pan],
  )

  const handleMouseMove = useCallback(
    (e: React.MouseEvent) => {
      if (!isDragging) return
      setPan({
        x: e.clientX - dragStart.x,
        y: e.clientY - dragStart.y,
      })
    },
    [isDragging, dragStart],
  )

  const handleMouseUp = useCallback(() => {
    setIsDragging(false)
  }, [])

  const zoomIn = useCallback(() => {
    setZoom((prev) => Math.min(maxZoom, prev + zoomStep))
  }, [maxZoom, zoomStep])

  const zoomOut = useCallback(() => {
    setZoom((prev) => Math.max(minZoom, prev - zoomStep))
  }, [minZoom, zoomStep])

  const resetView = useCallback(() => {
    setZoom(1)
    setPan({ x: 0, y: 0 })
  }, [])

  const bindCanvas = {
    onMouseDown: handleMouseDown,
    onMouseMove: handleMouseMove,
    onMouseUp: handleMouseUp,
    onMouseLeave: handleMouseUp,
  } as const

  const zoomPercentage = Math.round(zoom * 100)

  return {
    canvasRef,
    zoom,
    pan,
    setZoom,
    setPan,
    isDragging,
    zoomPercentage,
    zoomInDisabled: zoom >= maxZoom,
    zoomOutDisabled: zoom <= minZoom,
    bindCanvas,
    zoomIn,
    zoomOut,
    resetView,
  }
}
