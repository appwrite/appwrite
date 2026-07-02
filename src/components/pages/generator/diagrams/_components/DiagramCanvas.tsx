import {
  ChevronDown,
  Download,
  ExternalLink,
  LayoutGrid,
  Maximize2,
  Redo2,
  Undo2,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import { DiagramArtboard } from '@/components/pages/generator/diagrams/_components/DiagramArtboard'
import { CoverBrandBackgroundPreview } from '@/lib/cover-generator/cover-brand-background-preview'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { COVER_IMAGE_FORMATS } from '@/lib/cover-generator/constants'
import {
  DIAGRAM_SIZE_PRESETS,
  getDiagramSizePresetKey,
  resolveDiagramSizePresetKey,
} from '@/lib/diagram-generator/constants'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import {
  COVER_DOWNLOAD_SCALES,
  formatCoverDimensionsLabel,
  formatCoverDownloadScaleLabel,
  getCoverScaledDimensions,
  type CoverDownloadScale,
} from '@/lib/cover-generator/download-scale'
import {
  getDiagramArtboardDisplaySize,
  getDiagramArtboardRenderScale,
} from '@/lib/diagram-generator/constants'
import type {
  DiagramConnectDraft,
  DiagramDocument,
  DiagramSelection,
} from '@/lib/diagram-generator/types'
import type { DiagramAnchorSide } from '@/lib/diagram-generator/types'
import {
  resolveDiagramNodeDragPosition,
  type DiagramAlignGuide,
} from '@/lib/diagram-generator/node-align-snap'
import {
  getDiagramNodesInRect,
  resolveNodePointerSelection,
  selectDiagramNodes,
} from '@/lib/diagram-generator/selection'
import { useViewportPanZoom } from '@/lib/hooks/useViewportPanZoom'
import { cn } from '@/lib/utils'

type DiagramCanvasProps = {
  document: DiagramDocument
  selection: DiagramSelection
  connectDraft: DiagramConnectDraft | null
  onSelectionChange: (selection: DiagramSelection) => void
  onCancelConnectDraft: () => void
  onMoveNode: (nodeId: string, x: number, y: number) => void
  onMoveNodes: (updates: Array<{ id: string; x: number; y: number }>) => void
  onResizeNode: (nodeId: string, width: number, height: number) => void
  onBeginDocumentGesture: () => void
  onCommitDocumentGesture: () => void
  canUndo: boolean
  canRedo: boolean
  onUndo: () => void
  onRedo: () => void
  onPortClick: (nodeId: string, side: DiagramAnchorSide) => void
  onPortConnectComplete: (
    fromNodeId: string,
    fromSide: DiagramAnchorSide,
    toNodeId: string,
    toSide: DiagramAnchorSide,
  ) => void
  onCanvasSizeChange: (width: number, height: number) => void
  onDownload: (format: CoverImageFormat, scale: CoverDownloadScale) => void
  onOpenImage: () => void
  onBackToStart?: () => void
}

export function DiagramCanvas({
  document,
  selection,
  connectDraft,
  onSelectionChange,
  onCancelConnectDraft,
  onMoveNode,
  onMoveNodes,
  onResizeNode,
  onBeginDocumentGesture,
  onCommitDocumentGesture,
  canUndo,
  canRedo,
  onUndo,
  onRedo,
  onPortClick,
  onPortConnectComplete,
  onCanvasSizeChange,
  onDownload,
  onOpenImage,
  onBackToStart,
}: DiagramCanvasProps) {
  const canvasPresetKey = getDiagramSizePresetKey(document.width, document.height)
  const dragStateRef = useRef<{
    anchorNodeId: string
    startX: number
    startY: number
    nodeOrigins: Map<string, { x: number; y: number }>
  } | null>(null)

  const marqueeStateRef = useRef<{
    startX: number
    startY: number
    additive: boolean
  } | null>(null)
  const suppressNextCanvasClickRef = useRef(false)
  const [marqueeRect, setMarqueeRect] = useState<{
    x: number
    y: number
    width: number
    height: number
  } | null>(null)

  const isNodeDraggingRef = useRef(false)
  const canvasPanGestureRef = useRef({
    active: false,
    moved: false,
    startX: 0,
    startY: 0,
  })
  const [alignGuides, setAlignGuides] = useState<DiagramAlignGuide[]>([])
  const [connectPreviewPoint, setConnectPreviewPoint] = useState<{
    x: number
    y: number
  } | null>(null)
  const [pendingConnectDraft, setPendingConnectDraft] = useState<DiagramConnectDraft | null>(
    null,
  )
  const effectiveConnectDraft = connectDraft ?? pendingConnectDraft

  const connectGestureRef = useRef<{
    startX: number
    startY: number
    moved: boolean
    fromNodeId: string
    fromSide: DiagramAnchorSide
  } | null>(null)

  const findPortTarget = useCallback((clientX: number, clientY: number) => {
    const element = window.document
      .elementFromPoint(clientX, clientY)
      ?.closest('[data-diagram-port]')
    if (!(element instanceof HTMLElement)) return null

    const nodeId = element.dataset.nodeId
    const side = element.dataset.portSide as DiagramAnchorSide | undefined
    if (!nodeId || !side) return null

    return { nodeId, side }
  }, [])

  const toDocumentPointFromClient = useCallback(
    (clientX: number, clientY: number) => {
      const artboard = window.document.querySelector('[data-diagram-artboard]')
      if (!(artboard instanceof HTMLElement)) return null

      const rect = artboard.getBoundingClientRect()
      if (rect.width <= 0 || rect.height <= 0) return null

      return {
        x: ((clientX - rect.left) / rect.width) * document.width,
        y: ((clientY - rect.top) / rect.height) * document.height,
      }
    },
    [document.width, document.height],
  )

  const getArtboardContentSize = useCallback(
    (nextZoom: number) =>
      getDiagramArtboardDisplaySize(document.width, document.height, nextZoom),
    [document.width, document.height],
  )

  const {
    canvasRef,
    pan,
    zoom,
    isDragging,
    bindCanvas,
    zoomIn,
    zoomOut,
    resetView,
    zoomPercentage,
    zoomInDisabled,
    zoomOutDisabled,
  } = useViewportPanZoom({
    maxZoom: 3,
    contentLayout: 'sized',
    getContentSize: getArtboardContentSize,
  })

  const artboardDisplaySize = getDiagramArtboardDisplaySize(
    document.width,
    document.height,
    zoom,
  )
  const artboardRenderScale = getDiagramArtboardRenderScale(document.width, zoom)
  const artboardTransformStyle = {
    width: document.width,
    height: document.height,
    transform: `scale(${artboardRenderScale})`,
    transformOrigin: 'top left',
  } as const

  const isDiagramElementTarget = useCallback((target: EventTarget | null) => {
    if (!(target instanceof HTMLElement)) return false
    return Boolean(
      target.closest('[data-diagram-node]') ||
        target.closest('[data-diagram-edge]') ||
        target.closest('[data-diagram-port]') ||
        target.closest('[data-diagram-resize]'),
    )
  }, [])

  const isArtboardBackgroundTarget = useCallback(
    (target: EventTarget | null) => {
      if (!(target instanceof HTMLElement)) return false
      if (!target.closest('[data-diagram-artboard]')) return false
      return !isDiagramElementTarget(target)
    },
    [isDiagramElementTarget],
  )

  const clearCanvasFocus = useCallback(() => {
    if (suppressNextCanvasClickRef.current) {
      return
    }
    onSelectionChange({ type: 'none' })
    onCancelConnectDraft()
    setConnectPreviewPoint(null)
  }, [onCancelConnectDraft, onSelectionChange])

  const consumeSuppressedCanvasClick = useCallback(() => {
    if (!suppressNextCanvasClickRef.current) return false
    suppressNextCanvasClickRef.current = false
    return true
  }, [])

  const handleCanvasMouseDown = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      if (isDiagramElementTarget(event.target)) return
      if (isArtboardBackgroundTarget(event.target)) return
      canvasPanGestureRef.current = {
        active: true,
        moved: false,
        startX: event.clientX,
        startY: event.clientY,
      }
      bindCanvas.onMouseDown(event)
    },
    [bindCanvas, isArtboardBackgroundTarget, isDiagramElementTarget],
  )

  const handleCanvasMouseMove = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      const panGesture = canvasPanGestureRef.current
      if (panGesture.active) {
        const distance = Math.hypot(
          event.clientX - panGesture.startX,
          event.clientY - panGesture.startY,
        )
        if (distance > 4) {
          panGesture.moved = true
        }
      }
      if (isNodeDraggingRef.current) return
      bindCanvas.onMouseMove(event)
    },
    [bindCanvas],
  )

  const handleCanvasMouseUp = useCallback(() => {
    isNodeDraggingRef.current = false
    canvasPanGestureRef.current.active = false
    bindCanvas.onMouseUp()
  }, [bindCanvas])

  const handleCanvasClick = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      if (event.target instanceof HTMLElement && event.target.closest('[data-diagram-artboard]')) {
        return
      }
      if (consumeSuppressedCanvasClick()) return
      if (isDiagramElementTarget(event.target)) return
      const wasPanGesture = canvasPanGestureRef.current.moved
      canvasPanGestureRef.current.moved = false
      if (wasPanGesture) return
      clearCanvasFocus()
    },
    [clearCanvasFocus, consumeSuppressedCanvasClick, isDiagramElementTarget],
  )

  useEffect(() => {
    resetView()
  }, [document.width, document.height, document.theme, resetView])

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        clearCanvasFocus()
      }
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [clearCanvasFocus])

  useEffect(() => {
    if (!effectiveConnectDraft) {
      setConnectPreviewPoint(null)
    }
  }, [effectiveConnectDraft])

  useEffect(() => {
    if (connectDraft) {
      setPendingConnectDraft(null)
    }
  }, [connectDraft])


  const handleArtboardPointerMove = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!effectiveConnectDraft) return
      setConnectPreviewPoint(toDocumentPointFromClient(event.clientX, event.clientY))
    },
    [effectiveConnectDraft, toDocumentPointFromClient],
  )

  const handlePortPointerDown = useCallback(
    (
      nodeId: string,
      side: DiagramAnchorSide,
      event: React.PointerEvent<HTMLButtonElement>,
    ) => {
      event.stopPropagation()
      event.preventDefault()
      isNodeDraggingRef.current = true

      if (connectDraft) {
        onPortClick(nodeId, side)
        isNodeDraggingRef.current = false
        setConnectPreviewPoint(null)
        return
      }

      connectGestureRef.current = {
        startX: event.clientX,
        startY: event.clientY,
        moved: false,
        fromNodeId: nodeId,
        fromSide: side,
      }

      setPendingConnectDraft({ nodeId, side })
      onPortClick(nodeId, side)
      event.currentTarget.setPointerCapture(event.pointerId)

      const handlePointerMove = (moveEvent: PointerEvent) => {
        const gesture = connectGestureRef.current
        if (!gesture) return

        if (
          Math.hypot(
            moveEvent.clientX - gesture.startX,
            moveEvent.clientY - gesture.startY,
          ) > 4
        ) {
          gesture.moved = true
        }

        const point = toDocumentPointFromClient(moveEvent.clientX, moveEvent.clientY)
        if (point) {
          setConnectPreviewPoint(point)
        }
      }

      const handlePointerUp = (upEvent: PointerEvent) => {
        const gesture = connectGestureRef.current
        connectGestureRef.current = null
        isNodeDraggingRef.current = false
        window.removeEventListener('pointermove', handlePointerMove)
        window.removeEventListener('pointerup', handlePointerUp)
        window.removeEventListener('pointercancel', handlePointerUp)

        if (!gesture) return

        if (gesture.moved) {
          const target = findPortTarget(upEvent.clientX, upEvent.clientY)
          if (target) {
            onPortConnectComplete(
              gesture.fromNodeId,
              gesture.fromSide,
              target.nodeId,
              target.side,
            )
          } else {
            onCancelConnectDraft()
          }
          setConnectPreviewPoint(null)
        }

        setPendingConnectDraft(null)
      }

      window.addEventListener('pointermove', handlePointerMove)
      window.addEventListener('pointerup', handlePointerUp)
      window.addEventListener('pointercancel', handlePointerUp)
    },
    [
      connectDraft,
      findPortTarget,
      onCancelConnectDraft,
      onPortClick,
      onPortConnectComplete,
      toDocumentPointFromClient,
    ],
  )

  const handleNodePointerDown = useCallback(
    (event: React.PointerEvent<HTMLDivElement>, nodeId: string) => {
      if (connectDraft) return

      event.stopPropagation()
      isNodeDraggingRef.current = true

      const { selection: nextSelection, dragNodeIds } = resolveNodePointerSelection(
        selection,
        nodeId,
        {
          shiftKey: event.shiftKey,
          metaKey: event.metaKey,
          ctrlKey: event.ctrlKey,
        },
      )
      onSelectionChange(nextSelection)

      onBeginDocumentGesture()

      const nodeOrigins = new Map<string, { x: number; y: number }>()
      for (const id of dragNodeIds) {
        const node = document.nodes.find((item) => item.id === id)
        if (node) {
          nodeOrigins.set(id, { x: node.x, y: node.y })
        }
      }

      const anchorNodeId = nodeId
      const anchorNode = document.nodes.find((item) => item.id === anchorNodeId)
      if (!anchorNode || nodeOrigins.size === 0) return

      dragStateRef.current = {
        anchorNodeId,
        startX: event.clientX,
        startY: event.clientY,
        nodeOrigins,
      }
      event.currentTarget.setPointerCapture(event.pointerId)

      const handlePointerMove = (moveEvent: PointerEvent) => {
        const dragState = dragStateRef.current
        if (!dragState || dragState.anchorNodeId !== anchorNodeId) return

        const deltaX = (moveEvent.clientX - dragState.startX) / artboardRenderScale
        const deltaY = (moveEvent.clientY - dragState.startY) / artboardRenderScale
        const anchorOrigin = dragState.nodeOrigins.get(anchorNodeId)
        const draggedNode = document.nodes.find((item) => item.id === anchorNodeId)
        if (!anchorOrigin || !draggedNode) return

        const dragIds = [...dragState.nodeOrigins.keys()]
        const { x, y, guides } = resolveDiagramNodeDragPosition({
          node: draggedNode,
          originX: anchorOrigin.x,
          originY: anchorOrigin.y,
          deltaX,
          deltaY,
          shiftKey: moveEvent.shiftKey,
          otherNodes: document.nodes.filter((item) => !dragIds.includes(item.id)),
        })

        const appliedDeltaX = x - anchorOrigin.x
        const appliedDeltaY = y - anchorOrigin.y

        setAlignGuides(guides)

        if (dragIds.length === 1) {
          onMoveNode(anchorNodeId, x, y)
          return
        }

        onMoveNodes(
          dragIds.map((id) => {
            const origin = dragState.nodeOrigins.get(id)
            return {
              id,
              x: (origin?.x ?? 0) + appliedDeltaX,
              y: (origin?.y ?? 0) + appliedDeltaY,
            }
          }),
        )
      }

      const handlePointerUp = () => {
        dragStateRef.current = null
        isNodeDraggingRef.current = false
        setAlignGuides([])
        onCommitDocumentGesture()
        window.removeEventListener('pointermove', handlePointerMove)
        window.removeEventListener('pointerup', handlePointerUp)
      }

      window.addEventListener('pointermove', handlePointerMove)
      window.addEventListener('pointerup', handlePointerUp)
    },
    [
      artboardRenderScale,
      connectDraft,
      document.nodes,
      onMoveNode,
      onMoveNodes,
      onBeginDocumentGesture,
      onCommitDocumentGesture,
      onSelectionChange,
      selection,
    ],
  )

  const handleCanvasMarqueePointerDown = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (connectDraft) return

      const target = event.target as HTMLElement
      if (
        target.closest(
          '[data-diagram-node],[data-diagram-edge],[data-diagram-port],[data-diagram-resize]',
        )
      ) {
        return
      }

      event.stopPropagation()
      event.preventDefault()
      isNodeDraggingRef.current = true
      const marqueePointerTarget = event.currentTarget
      marqueePointerTarget.setPointerCapture(event.pointerId)

      const point = toDocumentPointFromClient(event.clientX, event.clientY)
      if (!point) return

      marqueeStateRef.current = {
        startX: point.x,
        startY: point.y,
        additive: event.shiftKey || event.metaKey || event.ctrlKey,
      }
      setMarqueeRect({ x: point.x, y: point.y, width: 0, height: 0 })

      const handlePointerMove = (moveEvent: PointerEvent) => {
        const marqueeState = marqueeStateRef.current
        if (!marqueeState) return

        const currentPoint = toDocumentPointFromClient(
          moveEvent.clientX,
          moveEvent.clientY,
        )
        if (!currentPoint) return

        setMarqueeRect({
          x: marqueeState.startX,
          y: marqueeState.startY,
          width: currentPoint.x - marqueeState.startX,
          height: currentPoint.y - marqueeState.startY,
        })
      }

      const handlePointerUp = (upEvent: PointerEvent) => {
        const marqueeState = marqueeStateRef.current
        marqueeStateRef.current = null
        isNodeDraggingRef.current = false
        setMarqueeRect(null)
        window.removeEventListener('pointermove', handlePointerMove)
        window.removeEventListener('pointerup', handlePointerUp)
        window.removeEventListener('pointercancel', handlePointerUp)

        try {
          if (marqueePointerTarget.hasPointerCapture(upEvent.pointerId)) {
            marqueePointerTarget.releasePointerCapture(upEvent.pointerId)
          }
        } catch {
          /* pointer may already be released */
        }

        if (!marqueeState) return

        const endPoint = toDocumentPointFromClient(upEvent.clientX, upEvent.clientY)
        if (!endPoint) return

        const rect = {
          x: marqueeState.startX,
          y: marqueeState.startY,
          width: endPoint.x - marqueeState.startX,
          height: endPoint.y - marqueeState.startY,
        }

        if (Math.abs(rect.width) < 4 && Math.abs(rect.height) < 4) return

        suppressNextCanvasClickRef.current = true

        const nodeIds = getDiagramNodesInRect(document.nodes, rect)
        if (nodeIds.length === 0) {
          if (!marqueeState.additive) {
            onSelectionChange({ type: 'none' })
          }
          return
        }

        if (marqueeState.additive) {
          const currentIds = selection.type === 'nodes'
            ? selection.ids
            : selection.type === 'node'
              ? [selection.id]
              : []
          onSelectionChange(selectDiagramNodes([...currentIds, ...nodeIds]))
          return
        }

        onSelectionChange(selectDiagramNodes(nodeIds))
      }

      window.addEventListener('pointermove', handlePointerMove)
      window.addEventListener('pointerup', handlePointerUp)
      window.addEventListener('pointercancel', handlePointerUp)
    },
    [
      connectDraft,
      document.nodes,
      onSelectionChange,
      selection,
      toDocumentPointFromClient,
    ],
  )

  const handleResizePointerDown = useCallback(
    (event: React.PointerEvent<HTMLButtonElement>, nodeId: string) => {
      event.stopPropagation()
      isNodeDraggingRef.current = true
      const node = document.nodes.find((item) => item.id === nodeId)
      if (!node) return

      onBeginDocumentGesture()

      const start = {
        clientX: event.clientX,
        clientY: event.clientY,
        width: node.width,
        height: node.height,
      }

      const handlePointerMove = (moveEvent: PointerEvent) => {
        const deltaX = (moveEvent.clientX - start.clientX) / artboardRenderScale
        const deltaY = (moveEvent.clientY - start.clientY) / artboardRenderScale
        onResizeNode(nodeId, start.width + deltaX, start.height + deltaY)
      }

      const handlePointerUp = () => {
        isNodeDraggingRef.current = false
        onCommitDocumentGesture()
        window.removeEventListener('pointermove', handlePointerMove)
        window.removeEventListener('pointerup', handlePointerUp)
      }

      window.addEventListener('pointermove', handlePointerMove)
      window.addEventListener('pointerup', handlePointerUp)
    },
    [
      artboardRenderScale,
      document.nodes,
      onBeginDocumentGesture,
      onCommitDocumentGesture,
      onResizeNode,
    ],
  )

  const handleCanvasKeyDown = useCallback(
    (event: React.KeyboardEvent<HTMLDivElement>) => {
      const isMeta = event.metaKey || event.ctrlKey

      if (isMeta && (event.key === 'z' || event.key === 'Z')) {
        event.preventDefault()
        if (event.shiftKey) {
          if (canRedo) onRedo()
        } else if (canUndo) {
          onUndo()
        }
        return
      }

      if (event.ctrlKey && event.key === 'y') {
        event.preventDefault()
        if (canRedo) onRedo()
        return
      }

      if (isMeta || event.altKey) return
      const key = event.key
      if (key === '+' || key === '=') {
        if (zoomInDisabled) return
        event.preventDefault()
        zoomIn()
      } else if (key === '-' || key === '_') {
        if (zoomOutDisabled) return
        event.preventDefault()
        zoomOut()
      } else if (key === '0') {
        event.preventDefault()
        resetView()
      }
    },
    [canRedo, canUndo, onRedo, onUndo, zoomIn, zoomOut, resetView, zoomInDisabled, zoomOutDisabled],
  )

  return (
    <div className="relative min-h-0 flex-1 overflow-hidden bg-background">
      <div
        ref={canvasRef}
        onMouseDown={handleCanvasMouseDown}
        onMouseMove={handleCanvasMouseMove}
        onMouseUp={handleCanvasMouseUp}
        onMouseLeave={handleCanvasMouseUp}
        onClick={handleCanvasClick}
        tabIndex={-1}
        role="application"
        aria-label="Pan and zoom diagram canvas. Scroll to zoom, drag the diagram background to pan."
        onKeyDown={handleCanvasKeyDown}
        className={cn(
          'absolute inset-0 overflow-hidden select-none outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
          isDragging ? 'cursor-grabbing' : 'cursor-grab',
        )}
      >
        <div
          className="pointer-events-none absolute inset-0 overflow-visible will-change-transform"
          style={{
            transform: `translate(${pan.x}px, ${pan.y}px)`,
          }}
        >
          <SchemaBlueprintMat />
          <div
            className="pointer-events-auto absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
            style={{
              width: artboardDisplaySize.width,
              height: artboardDisplaySize.height,
            }}
          >
            <div
              className="absolute left-0 top-0 origin-top-left overflow-hidden rounded-xl border border-border shadow-sm"
              style={artboardTransformStyle}
            >
                <CoverBrandBackgroundPreview
                  themeId={document.theme}
                  width={document.width}
                  height={document.height}
                />
                <div
                  data-diagram-artboard=""
                  className="absolute inset-0 overflow-visible"
                >
                  <DiagramArtboard
                    document={document}
                    width={document.width}
                    height={document.height}
                    hideBackground
                    interactive
                    selection={selection}
                    connectDraft={effectiveConnectDraft}
                    connectPreviewPoint={connectPreviewPoint}
                    onNodePointerDown={handleNodePointerDown}
                    onPortPointerDown={handlePortPointerDown}
                    onResizePointerDown={handleResizePointerDown}
                    onCanvasPointerDown={handleCanvasMarqueePointerDown}
                    marqueeRect={marqueeRect}
                    onEdgeClick={(edgeId) => {
                      onCancelConnectDraft()
                      onSelectionChange({ type: 'edge', id: edgeId })
                    }}
                    onPointerMove={handleArtboardPointerMove}
                    alignGuides={alignGuides}
                    onCanvasClick={() => {
                      if (consumeSuppressedCanvasClick()) return
                      clearCanvasFocus()
                    }}
                  />
                </div>
            </div>
          </div>
        </div>
      </div>

      <div className="pointer-events-none absolute inset-x-4 top-4 z-20 flex items-start justify-between gap-2">
        <div className="pointer-events-auto flex shrink-0 items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={onUndo}
            disabled={!canUndo}
            aria-label="Undo"
          >
            <Undo2 className="h-4 w-4" />
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={onRedo}
            disabled={!canRedo}
            aria-label="Redo"
          >
            <Redo2 className="h-4 w-4" />
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={zoomIn}
            disabled={zoomInDisabled}
            aria-label="Zoom in"
          >
            <ZoomIn className="h-4 w-4" />
          </Button>
          <div className="flex h-8 min-w-[64px] items-center justify-center rounded-md border border-border bg-card/95 px-3 backdrop-blur-sm">
            <span className="text-[12px] font-medium text-foreground">
              {zoomPercentage}%
            </span>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={zoomOut}
            disabled={zoomOutDisabled}
            aria-label="Zoom out"
          >
            <ZoomOut className="h-4 w-4" />
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={resetView}
            aria-label="Reset pan and zoom"
          >
            <Maximize2 className="h-4 w-4" />
          </Button>
        </div>

        <div className="pointer-events-auto flex max-w-[min(100%,720px)] shrink-0 flex-wrap items-center justify-end gap-2">
          {onBackToStart ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 border-border bg-card/95 text-[12px] backdrop-blur-sm"
              onClick={onBackToStart}
            >
              <LayoutGrid className="me-1.5 size-3.5" />
              All diagrams
            </Button>
          ) : null}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 border-border bg-card/95 text-[12px] backdrop-blur-sm"
            onClick={onOpenImage}
          >
            <ExternalLink className="me-1.5 size-3.5" />
            Open image
          </Button>

          <Select
            value={canvasPresetKey}
            onValueChange={(value) => {
              const { width, height } = resolveDiagramSizePresetKey(value)
              onCanvasSizeChange(width, height)
            }}
          >
            <SelectTrigger
              aria-label="Canvas size"
              className="h-8 w-auto max-w-[min(100%,220px)] border-border bg-card/95 text-[12px] backdrop-blur-sm"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
              {DIAGRAM_SIZE_PRESETS.map((preset) => (
                <SelectItem key={preset.id} value={preset.id}>
                  {preset.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <div className="relative isolate flex shrink-0 items-stretch">
            <Button
              type="button"
              size="sm"
              className="relative z-[1] h-8 rounded-e-none px-3 text-[12px] shadow-sm"
              onClick={() => onDownload(document.format, 1)}
            >
              <Download className="me-1.5 size-3.5" />
              Download
            </Button>
            <DropdownMenu modal={false}>
              <DropdownMenuTrigger asChild>
                <Button
                  type="button"
                  size="sm"
                  className="relative z-[2] h-8 rounded-s-none border-s border-primary-foreground/15 px-2 shadow-sm"
                  aria-label="More download options"
                >
                  <ChevronDown className="size-3.5" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-60 p-1.5">
                {COVER_IMAGE_FORMATS.map((format, formatIndex) => (
                  <DropdownMenuGroup key={format}>
                    {formatIndex > 0 ? <DropdownMenuSeparator className="my-1.5" /> : null}
                    <DropdownMenuLabel className="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {format.toUpperCase()}
                    </DropdownMenuLabel>
                    {COVER_DOWNLOAD_SCALES.map((scaleOption) => {
                      const dimensions = getCoverScaledDimensions(
                        document.width,
                        document.height,
                        scaleOption,
                      )
                      const scaleLabel = formatCoverDownloadScaleLabel(scaleOption)
                      const sizeLabel = dimensions
                        ? formatCoverDimensionsLabel(dimensions.width, dimensions.height)
                        : 'Too large'

                      return (
                        <DropdownMenuItem
                          key={`${format}-${scaleOption}`}
                          disabled={!dimensions}
                          className="min-h-10 cursor-pointer px-2.5 py-2 text-[13px]"
                          onSelect={() => onDownload(format, scaleOption)}
                        >
                          <span className="font-medium">{scaleLabel}</span>
                          <span className="ms-auto text-[12px] text-muted-foreground">
                            {sizeLabel}
                          </span>
                        </DropdownMenuItem>
                      )
                    })}
                  </DropdownMenuGroup>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
      </div>

      {connectDraft ? (
        <div className="pointer-events-none absolute inset-x-4 bottom-4 z-20 flex justify-center">
          <div className="rounded-lg border border-border bg-card/95 px-4 py-2 text-[12px] text-foreground shadow-sm backdrop-blur-sm">
            Click a dot on another node to connect. Press Escape to cancel.
          </div>
        </div>
      ) : null}
    </div>
  )
}
