import { useCallback, useEffect, useRef, useState } from 'react'

/** Defaults match database schema / browser canvas visualizers */
export const VIEWPORT_PAN_ZOOM_MIN = 0.2
export const VIEWPORT_PAN_ZOOM_MAX = 2
export const VIEWPORT_PAN_ZOOM_STEP = 0.05
export const VIEWPORT_PAN_ZOOM_WHEEL_STEP = 0.02

export type ViewportContentSize = {
  width: number
  height: number
}

export type ViewportPanZoomOptions = {
  minZoom?: number
  maxZoom?: number
  zoomStep?: number
  wheelZoomStep?: number
  /**
   * `scaled` (default): zoom is applied via CSS scale on the panned layer.
   * `sized`: zoom changes content layout size; pan layer only translates.
   */
  contentLayout?: 'scaled' | 'sized'
  getContentSize?: (zoom: number) => ViewportContentSize
}

/**
 * Pan (drag) + wheel zoom toward cursor, same behavior as database schema visualizer canvas.
 */
export function useViewportPanZoom(options?: ViewportPanZoomOptions) {
  const minZoom = options?.minZoom ?? VIEWPORT_PAN_ZOOM_MIN
  const maxZoom = options?.maxZoom ?? VIEWPORT_PAN_ZOOM_MAX
  const zoomStep = options?.zoomStep ?? VIEWPORT_PAN_ZOOM_STEP
  const wheelZoomStep = options?.wheelZoomStep ?? VIEWPORT_PAN_ZOOM_WHEEL_STEP
  const contentLayout = options?.contentLayout ?? 'scaled'
  const getContentSize = options?.getContentSize

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

      if (contentLayout === 'sized' && getContentSize) {
        const contentSize = getContentSize(zoom)
        const contentLeft = (rect.width - contentSize.width) / 2 + pan.x
        const contentTop = (rect.height - contentSize.height) / 2 + pan.y
        const relX = mouseX - contentLeft
        const relY = mouseY - contentTop
        const contentFractionX =
          contentSize.width > 0 ? relX / contentSize.width : 0
        const contentFractionY =
          contentSize.height > 0 ? relY / contentSize.height : 0

        const nextContentSize = getContentSize(newZoom)
        const nextRelX = contentFractionX * nextContentSize.width
        const nextRelY = contentFractionY * nextContentSize.height
        const nextContentLeft = mouseX - nextRelX
        const nextContentTop = mouseY - nextRelY

        setZoom(newZoom)
        setPan({
          x: nextContentLeft - (rect.width - nextContentSize.width) / 2,
          y: nextContentTop - (rect.height - nextContentSize.height) / 2,
        })
        return
      }

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
  }, [zoom, pan, minZoom, maxZoom, wheelZoomStep, contentLayout, getContentSize])

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
    const nextZoom = Math.min(maxZoom, zoom + zoomStep)
    if (nextZoom === zoom) return

    if (contentLayout === 'sized' && getContentSize && canvasRef.current) {
      const rect = canvasRef.current.getBoundingClientRect()
      const centerX = rect.width / 2
      const centerY = rect.height / 2
      const contentSize = getContentSize(zoom)
      const relX = centerX - (rect.width - contentSize.width) / 2 - pan.x
      const relY = centerY - (rect.height - contentSize.height) / 2 - pan.y
      const contentFractionX =
        contentSize.width > 0 ? relX / contentSize.width : 0.5
      const contentFractionY =
        contentSize.height > 0 ? relY / contentSize.height : 0.5
      const nextContentSize = getContentSize(nextZoom)

      setPan({
        x:
          centerX -
          contentFractionX * nextContentSize.width -
          (rect.width - nextContentSize.width) / 2,
        y:
          centerY -
          contentFractionY * nextContentSize.height -
          (rect.height - nextContentSize.height) / 2,
      })
    }

    setZoom(nextZoom)
  }, [maxZoom, zoomStep, contentLayout, getContentSize, zoom, pan.x, pan.y])

  const zoomOut = useCallback(() => {
    const nextZoom = Math.max(minZoom, zoom - zoomStep)
    if (nextZoom === zoom) return

    if (contentLayout === 'sized' && getContentSize && canvasRef.current) {
      const rect = canvasRef.current.getBoundingClientRect()
      const centerX = rect.width / 2
      const centerY = rect.height / 2
      const contentSize = getContentSize(zoom)
      const relX = centerX - (rect.width - contentSize.width) / 2 - pan.x
      const relY = centerY - (rect.height - contentSize.height) / 2 - pan.y
      const contentFractionX =
        contentSize.width > 0 ? relX / contentSize.width : 0.5
      const contentFractionY =
        contentSize.height > 0 ? relY / contentSize.height : 0.5
      const nextContentSize = getContentSize(nextZoom)

      setPan({
        x:
          centerX -
          contentFractionX * nextContentSize.width -
          (rect.width - nextContentSize.width) / 2,
        y:
          centerY -
          contentFractionY * nextContentSize.height -
          (rect.height - nextContentSize.height) / 2,
      })
    }

    setZoom(nextZoom)
  }, [minZoom, zoomStep, contentLayout, getContentSize, zoom, pan.x, pan.y])

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
