import { useState, useRef, useEffect, useMemo } from 'react'
import {
  ZoomIn,
  ZoomOut,
  Maximize2,
  Database,
  Zap,
  Users,
  ExternalLink,
  ArrowUp,
  ArrowDown,
  Table2,
  Folder,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useNavigate, useParams } from '@tanstack/react-router'
import { useT } from '@/lib/i18n/translate'

interface BrowserProps {
  databaseId: string
}

const MIN_ZOOM = 0.2
const MAX_ZOOM = 2
const ZOOM_STEP = 0.05
const WHEEL_ZOOM_STEP = 0.02

interface BrowserFrame {
  id: string
  name: string
  url: string
  x: number
  y: number
  width: number
  height: number
  zIndex: number
}

interface ResourceNode {
  id: string
  type: 'database' | 'storage' | 'function' | 'auth' | 'table' | 'bucket'
  name: string
  x: number
  y: number
  zIndex: number
  parentFrameId?: string // For tables and buckets linked to frames
}

interface Connection {
  from: string // frame id
  to: string // resource id
  fromSide: 'left' | 'right' | 'top' | 'bottom'
  toSide: 'left' | 'right' | 'top' | 'bottom'
}

const BROWSER_FRAMES: Omit<BrowserFrame, 'x' | 'y' | 'zIndex'>[] = [
  {
    id: 'imagine',
    name: 'Imagine.dev',
    url: 'https://imagine.dev',
    width: 1200,
    height: 800,
  },
  {
    id: 'alo',
    name: 'Alo',
    url: 'https://eldadfux.com',
    width: 1200,
    height: 800,
  },
  {
    id: 'openai',
    name: 'OpenAI',
    url: 'https://openai.com',
    width: 1200,
    height: 800,
  },
  {
    id: 'appwrite',
    name: 'Appwrite',
    url: 'https://appwrite.io',
    width: 1200,
    height: 800,
  },
]

// Mock resource data
const MOCK_RESOURCES: Omit<ResourceNode, 'x' | 'y' | 'zIndex'>[] = [
  { id: 'db-prod', type: 'database', name: 'Production DB' },
  { id: 'db-staging', type: 'database', name: 'Staging DB' },
  { id: 'storage-images', type: 'storage', name: 'Image Storage' },
  { id: 'storage-docs', type: 'storage', name: 'Documents' },
  { id: 'func-notify', type: 'function', name: 'Send Notification' },
  { id: 'func-payment', type: 'function', name: 'Process Payment' },
  { id: 'auth-email', type: 'auth', name: 'Email Auth' },
  { id: 'auth-oauth', type: 'auth', name: 'OAuth Providers' },
]

// Mock tables and buckets data
const MOCK_TABLES: Array<{ id: string; name: string; frameIds: string[] }> = [
  { id: 'table-users', name: 'users', frameIds: ['appwrite', 'alo'] },
  { id: 'table-products', name: 'products', frameIds: ['appwrite', 'imagine'] },
  { id: 'table-orders', name: 'orders', frameIds: ['alo'] },
  { id: 'table-sessions', name: 'sessions', frameIds: ['openai', 'imagine'] },
  { id: 'table-analytics', name: 'analytics', frameIds: ['openai'] },
]

const MOCK_BUCKETS: Array<{ id: string; name: string; frameIds: string[] }> = [
  { id: 'bucket-avatars', name: 'avatars', frameIds: ['appwrite', 'alo'] },
  { id: 'bucket-uploads', name: 'uploads', frameIds: ['imagine', 'openai'] },
  { id: 'bucket-assets', name: 'assets', frameIds: ['appwrite'] },
  { id: 'bucket-media', name: 'media', frameIds: ['alo', 'openai'] },
]

// Connections between frames and resources
const CONNECTIONS: Connection[] = [
  // Appwrite connections
  { from: 'appwrite', to: 'db-prod', fromSide: 'right', toSide: 'left' },
  { from: 'appwrite', to: 'storage-images', fromSide: 'right', toSide: 'left' },
  { from: 'appwrite', to: 'func-notify', fromSide: 'bottom', toSide: 'top' },
  { from: 'appwrite', to: 'auth-email', fromSide: 'bottom', toSide: 'top' },
  // Imagine.dev connections
  { from: 'imagine', to: 'db-staging', fromSide: 'left', toSide: 'right' },
  { from: 'imagine', to: 'storage-images', fromSide: 'left', toSide: 'right' },
  { from: 'imagine', to: 'func-payment', fromSide: 'bottom', toSide: 'top' },
  // Alo connections
  { from: 'alo', to: 'db-prod', fromSide: 'right', toSide: 'left' },
  { from: 'alo', to: 'storage-docs', fromSide: 'right', toSide: 'left' },
  { from: 'alo', to: 'auth-oauth', fromSide: 'bottom', toSide: 'top' },
  // OpenAI connections
  { from: 'openai', to: 'db-staging', fromSide: 'left', toSide: 'right' },
  { from: 'openai', to: 'func-notify', fromSide: 'top', toSide: 'bottom' },
]

export function Browser({}: BrowserProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const canvasRef = useRef<HTMLDivElement>(null)
  const hasAutoFocusedRef = useRef(false)
  const zoomRef = useRef(1)
  const panRef = useRef({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [pan, setPan] = useState({ x: 0, y: 0 })

  // Keep refs in sync with state
  useEffect(() => {
    zoomRef.current = zoom
  }, [zoom])

  useEffect(() => {
    panRef.current = pan
  }, [pan])
  const [isDragging, setIsDragging] = useState(false)
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 })
  const [resizing, setResizing] = useState<{
    frameId: string
    handle: string
    startX: number
    startY: number
    startWidth: number
    startHeight: number
    startFrameX: number
    startFrameY: number
  } | null>(null)
  const [selectedNode, setSelectedNode] = useState<{
    type: 'frame' | 'resource'
    id: string
  } | null>(null)

  // Initialize frame positions in a grid layout
  const [frames, setFrames] = useState<BrowserFrame[]>(() => {
    const cols = 2
    const spacing = 200
    const startX = 100
    const startY = 100

    return BROWSER_FRAMES.map((frame, index) => {
      const row = Math.floor(index / cols)
      const col = index % cols
      return {
        ...frame,
        x: startX + col * (frame.width + spacing),
        y: startY + row * (frame.height + spacing),
        zIndex: index + 1, // Start with z-index based on initial order
      }
    })
  })

  // Resource z-index state
  const [resourceZIndices, setResourceZIndices] = useState<Map<string, number>>(
    () => {
      const map = new Map<string, number>()
      let zIndex = frames.length + 1

      // Add main resources
      MOCK_RESOURCES.forEach((resource) => {
        map.set(resource.id, zIndex++)
      })

      // Add tables and buckets (will be duplicated per frame)
      MOCK_TABLES.forEach((table) => {
        table.frameIds.forEach((frameId) => {
          const instanceId = `${table.id}-${frameId}`
          map.set(instanceId, zIndex++)
        })
      })
      MOCK_BUCKETS.forEach((bucket) => {
        bucket.frameIds.forEach((frameId) => {
          const instanceId = `${bucket.id}-${frameId}`
          map.set(instanceId, zIndex++)
        })
      })

      return map
    },
  )

  // Calculate resource node positions based on frame positions
  const resources = useMemo<ResourceNode[]>(() => {
    if (frames.length === 0) return []

    const resourceOffset = 250
    const nodeWidth = 180
    const nodeHeight = 60
    const nodeSpacing = 20
    const childNodeOffset = 220 // Offset for tables/buckets from their parent frames
    const childNodeSpacing = 80 // Spacing between child nodes

    // Find frame bounds
    const maxFrameX = Math.max(...frames.map((f) => f.x + f.width))
    const minFrameY = Math.min(...frames.map((f) => f.y))

    // Position main resources in a grid to the right of frames
    const cols = 2
    const baseX = maxFrameX + resourceOffset
    const baseY = minFrameY

    const mainResources: ResourceNode[] = MOCK_RESOURCES.map(
      (resource, index) => {
        const row = Math.floor(index / cols)
        const col = index % cols

        return {
          ...resource,
          x: baseX + col * (nodeWidth + nodeSpacing),
          y: baseY + row * (nodeHeight + nodeSpacing),
          zIndex:
            resourceZIndices.get(resource.id) || frames.length + index + 1,
        }
      },
    )

    // Add tables and buckets linked to frames
    const childResources: ResourceNode[] = []
    let childZIndex = frames.length + MOCK_RESOURCES.length + 1

    // Add tables
    MOCK_TABLES.forEach((table) => {
      table.frameIds.forEach((frameId) => {
        const frame = frames.find((f) => f.id === frameId)
        if (!frame) return

        // Count how many tables come before this one for the same frame
        const tablesBefore = MOCK_TABLES.filter((t) =>
          t.frameIds.includes(frameId),
        ).findIndex((t) => t.id === table.id)

        const instanceId = `${table.id}-${frameId}`
        childResources.push({
          id: instanceId,
          type: 'table',
          name: table.name,
          x: frame.x + frame.width + childNodeOffset,
          y: frame.y + tablesBefore * (nodeHeight + childNodeSpacing),
          zIndex: resourceZIndices.get(instanceId) || childZIndex++,
          parentFrameId: frameId,
        })
      })
    })

    // Add buckets
    MOCK_BUCKETS.forEach((bucket) => {
      bucket.frameIds.forEach((frameId) => {
        const frame = frames.find((f) => f.id === frameId)
        if (!frame) return

        // Count tables for this frame to position buckets below them
        const tableCount = MOCK_TABLES.filter((t) =>
          t.frameIds.includes(frameId),
        ).length
        // Count how many buckets come before this one for the same frame
        const bucketsBefore = MOCK_BUCKETS.filter((b) =>
          b.frameIds.includes(frameId),
        ).findIndex((b) => b.id === bucket.id)

        const instanceId = `${bucket.id}-${frameId}`
        childResources.push({
          id: instanceId,
          type: 'bucket',
          name: bucket.name,
          x: frame.x + frame.width + childNodeOffset,
          y:
            frame.y +
            tableCount * (nodeHeight + childNodeSpacing) +
            bucketsBefore * (nodeHeight + childNodeSpacing),
          zIndex: resourceZIndices.get(instanceId) || childZIndex++,
          parentFrameId: frameId,
        })
      })
    })

    return [...mainResources, ...childResources]
  }, [frames, resourceZIndices])

  // Auto-focus on first iframe (Appwrite) - using known coordinates
  useEffect(() => {
    if (hasAutoFocusedRef.current || frames.length === 0) return

    const canvas = canvasRef.current
    if (!canvas) return

    // Calculate Appwrite frame position based on layout algorithm
    // Appwrite is index 0: row 0, col 0
    const spacing = 200
    const startX = 100
    const startY = 100
    const frameWidth = 1200
    const frameHeight = 800

    // Appwrite frame position (index 0, row 0, col 0)
    const appwriteX = startX + 0 * (frameWidth + spacing) // = 100
    const appwriteY = startY + 0 * (frameHeight + spacing) // = 100
    const appwriteCenterX = appwriteX + frameWidth / 2 // = 100 + 600 = 700
    const appwriteCenterY = appwriteY + frameHeight / 2 // = 100 + 400 = 500

    // Use double requestAnimationFrame to ensure DOM is fully ready
    const rafId1 = requestAnimationFrame(() => {
      const rafId2 = requestAnimationFrame(() => {
        // Wait for canvas to have dimensions
        const canvasWidth = canvas.clientWidth
        const canvasHeight = canvas.clientHeight
        if (canvasWidth === 0 || canvasHeight === 0) {
          return
        }

        // Calculate zoom to fit the frame nicely (with some padding)
        const padding = 100
        const fitZoom = Math.min(
          (canvasWidth - padding * 2) / frameWidth,
          (canvasHeight - padding * 2) / frameHeight,
          1, // Don't zoom in beyond 100%
        )

        // Center the Appwrite frame in the viewport
        setZoom(fitZoom)
        setPan({
          x: canvasWidth / 2 - appwriteCenterX * fitZoom,
          y: canvasHeight / 2 - appwriteCenterY * fitZoom,
        })

        hasAutoFocusedRef.current = true
      })

      return () => cancelAnimationFrame(rafId2)
    })

    return () => cancelAnimationFrame(rafId1)
  }, [frames])

  // Handle global mouse events for resizing
  useEffect(() => {
    if (!resizing) return

    const handleMouseMove = (e: MouseEvent) => {
      const deltaX = (e.clientX - resizing.startX) / zoom
      const deltaY = (e.clientY - resizing.startY) / zoom
      const minWidth = 400
      const minHeight = 300

      setFrames((prevFrames) =>
        prevFrames.map((frame) => {
          if (frame.id !== resizing.frameId) return frame

          let newWidth = resizing.startWidth
          let newHeight = resizing.startHeight
          let newX = resizing.startFrameX
          let newY = resizing.startFrameY

          // Handle different resize handles (only edges, no corners)
          switch (resizing.handle) {
            case 'e': // East (right)
              newWidth = Math.max(minWidth, resizing.startWidth + deltaX)
              break
            case 'w': // West (left)
              newWidth = Math.max(minWidth, resizing.startWidth - deltaX)
              newX = resizing.startFrameX + (resizing.startWidth - newWidth)
              break
            case 's': // South (bottom)
              newHeight = Math.max(minHeight, resizing.startHeight + deltaY)
              break
            case 'n': // North (top)
              newHeight = Math.max(minHeight, resizing.startHeight - deltaY)
              newY = resizing.startFrameY + (resizing.startHeight - newHeight)
              break
          }

          return {
            ...frame,
            x: newX,
            y: newY,
            width: newWidth,
            height: newHeight,
          }
        }),
      )
    }

    const handleMouseUp = () => {
      setResizing(null)
    }

    window.addEventListener('mousemove', handleMouseMove)
    window.addEventListener('mouseup', handleMouseUp)

    return () => {
      window.removeEventListener('mousemove', handleMouseMove)
      window.removeEventListener('mouseup', handleMouseUp)
    }
  }, [resizing, zoom])

  // Handle mouse wheel for zoom
  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return

    const handleWheel = (e: WheelEvent) => {
      e.preventDefault()

      // Use functional updates to get latest state values
      let newZoomValue = 0
      setZoom((currentZoom) => {
        setPan((currentPan) => {
          const delta = e.deltaY > 0 ? -WHEEL_ZOOM_STEP : WHEEL_ZOOM_STEP
          newZoomValue = Math.max(
            MIN_ZOOM,
            Math.min(MAX_ZOOM, currentZoom + delta),
          )

          // Get mouse position relative to canvas
          const rect = canvas.getBoundingClientRect()
          const mouseX = e.clientX - rect.left
          const mouseY = e.clientY - rect.top

          // Calculate the point in the untransformed (world) space that's under the cursor
          // With transform: translate(pan.x, pan.y) scale(zoom) and transformOrigin: '0 0'
          // A world point (wx, wy) appears at screen position (pan.x + wx * zoom, pan.y + wy * zoom)
          // So to find the world point under the mouse:
          const worldX = (mouseX - currentPan.x) / currentZoom
          const worldY = (mouseY - currentPan.y) / currentZoom

          // After zooming, we want the same world point to still be under the mouse
          // mouseX = newPan.x + worldX * newZoom
          // Therefore: newPan.x = mouseX - worldX * newZoom
          const newPanX = mouseX - worldX * newZoomValue
          const newPanY = mouseY - worldY * newZoomValue

          // Update refs immediately for next interaction
          zoomRef.current = newZoomValue
          panRef.current = { x: newPanX, y: newPanY }

          return {
            x: newPanX,
            y: newPanY,
          }
        })

        return newZoomValue
      })
    }

    canvas.addEventListener('wheel', handleWheel, { passive: false })
    return () => canvas.removeEventListener('wheel', handleWheel)
  }, [])

  // Handle panning
  const handleMouseDown = (e: React.MouseEvent) => {
    if (e.button !== 0) return // Only left mouse button
    // Don't start panning if we're clicking on a frame, resource, resize handle, or action bar
    const target = e.target as HTMLElement
    if (
      target.closest('[data-frame]') ||
      target.closest('[data-resource]') ||
      target.closest('[data-resize-handle]') ||
      target.closest('[data-action-bar]')
    ) {
      return
    }
    // Deselect when clicking on canvas
    setSelectedNode(null)
    setIsDragging(true)
    setDragStart({
      x: e.clientX - pan.x,
      y: e.clientY - pan.y,
    })
  }

  const handleMouseMove = (e: React.MouseEvent) => {
    if (resizing) {
      // Resizing is handled by global mouse events
      return
    }

    if (!isDragging) return
    setPan({
      x: e.clientX - dragStart.x,
      y: e.clientY - dragStart.y,
    })
  }

  const handleMouseUp = () => {
    setIsDragging(false)
    setResizing(null)
  }

  // Handle resize start
  const handleResizeStart = (
    e: React.MouseEvent,
    frameId: string,
    handle: string,
  ) => {
    e.stopPropagation()
    if (e.button !== 0) return

    const frame = frames.find((f) => f.id === frameId)
    if (!frame) return

    setResizing({
      frameId,
      handle,
      startX: e.clientX,
      startY: e.clientY,
      startWidth: frame.width,
      startHeight: frame.height,
      startFrameX: frame.x,
      startFrameY: frame.y,
    })
  }

  // Zoom controls
  const handleZoomIn = () => {
    setZoom((prev) => Math.min(MAX_ZOOM, prev + ZOOM_STEP))
  }

  const handleZoomOut = () => {
    setZoom((prev) => Math.max(MIN_ZOOM, prev - ZOOM_STEP))
  }

  const handleResetZoom = () => {
    setZoom(1)
    setPan({ x: 0, y: 0 })
  }

  // Fit selected node to view
  const handleFitToView = () => {
    if (!selectedNode) return

    const canvas = canvasRef.current
    if (!canvas) return

    const canvasWidth = canvas.clientWidth
    const canvasHeight = canvas.clientHeight

    if (selectedNode.type === 'frame') {
      const frame = frames.find((f) => f.id === selectedNode.id)
      if (!frame) return

      // Calculate zoom to fit frame with padding
      const padding = 100
      const fitZoom = Math.min(
        (canvasWidth - padding * 2) / frame.width,
        (canvasHeight - padding * 2) / frame.height,
        1, // Don't zoom in beyond 100%
      )

      // Center the frame in the viewport
      const frameCenterX = frame.x + frame.width / 2
      const frameCenterY = frame.y + frame.height / 2

      setZoom(fitZoom)
      setPan({
        x: canvasWidth / 2 - frameCenterX * fitZoom,
        y: canvasHeight / 2 - frameCenterY * fitZoom,
      })
    } else if (selectedNode.type === 'resource') {
      const resource = resources.find((r) => r.id === selectedNode.id)
      if (!resource) return

      // Resource nodes have fixed size
      const nodeWidth = 180
      const nodeHeight = 60

      // Calculate zoom to fit resource with padding
      const padding = 100
      const fitZoom = Math.min(
        (canvasWidth - padding * 2) / nodeWidth,
        (canvasHeight - padding * 2) / nodeHeight,
        1, // Don't zoom in beyond 100%
      )

      // Center the resource in the viewport
      const resourceCenterX = resource.x + nodeWidth / 2
      const resourceCenterY = resource.y + nodeHeight / 2

      setZoom(fitZoom)
      setPan({
        x: canvasWidth / 2 - resourceCenterX * fitZoom,
        y: canvasHeight / 2 - resourceCenterY * fitZoom,
      })
    }
  }

  const zoomPercentage = Math.round(zoom * 100)

  // Get connection points for drawing lines
  const getConnectionPoint = (
    frameId: string | null,
    resourceId: string | null,
    side: 'left' | 'right' | 'top' | 'bottom',
  ): { x: number; y: number } | null => {
    if (frameId) {
      const frame = frames.find((f) => f.id === frameId)
      if (!frame) return null

      switch (side) {
        case 'left':
          return { x: frame.x, y: frame.y + frame.height / 2 }
        case 'right':
          return { x: frame.x + frame.width, y: frame.y + frame.height / 2 }
        case 'top':
          return { x: frame.x + frame.width / 2, y: frame.y }
        case 'bottom':
          return { x: frame.x + frame.width / 2, y: frame.y + frame.height }
        default:
          return null
      }
    }

    if (resourceId) {
      const resource = resources.find((r) => r.id === resourceId)
      if (!resource) return null

      const nodeWidth = 180
      const nodeHeight = 60

      switch (side) {
        case 'left':
          return { x: resource.x, y: resource.y + nodeHeight / 2 }
        case 'right':
          return { x: resource.x + nodeWidth, y: resource.y + nodeHeight / 2 }
        case 'top':
          return { x: resource.x + nodeWidth / 2, y: resource.y }
        case 'bottom':
          return { x: resource.x + nodeWidth / 2, y: resource.y + nodeHeight }
        default:
          return null
      }
    }

    return null
  }

  // Get icon for resource type
  const getResourceIcon = (type: ResourceNode['type']) => {
    switch (type) {
      case 'database':
        return Database
      case 'storage':
        return Folder
      case 'function':
        return Zap
      case 'auth':
        return Users
      case 'table':
        return Table2
      case 'bucket':
        return Folder
      default:
        return Database
    }
  }

  // Get route for resource type
  const getResourceRoute = (type: ResourceNode['type']) => {
    switch (type) {
      case 'database':
        return '/projects/$projectId/databases'
      case 'storage':
        return '/projects/$projectId/storage'
      case 'function':
        return '/projects/$projectId/functions'
      case 'auth':
        return '/projects/$projectId/auth'
      case 'table':
        return '/projects/$projectId/databases'
      case 'bucket':
        return '/projects/$projectId/storage'
      default:
        return '/projects/$projectId'
    }
  }

  // Handle resource node click
  const handleResourceClick = (
    resource: ResourceNode,
    e?: React.MouseEvent,
  ) => {
    // Don't navigate if clicking on action bar or buttons
    if (e && (e.target as HTMLElement).closest('[data-action-bar]')) {
      return
    }

    // Toggle selection or navigate on double click
    if (e?.detail === 2) {
      // Double click to navigate
      const route = getResourceRoute(resource.type)
      navigate({
        to: route as unknown,
        params: { projectId } as unknown,
      })
    } else {
      // Single click to select
      setSelectedNode({ type: 'resource', id: resource.id })
    }
  }

  // Handle frame click
  const handleFrameClick = (frameId: string, e?: React.MouseEvent) => {
    // Don't select if clicking on action bar or buttons
    if (e && (e.target as HTMLElement).closest('[data-action-bar]')) {
      return
    }
    setSelectedNode({ type: 'frame', id: frameId })
  }

  // Move frame forward/backward
  const moveFrame = (frameId: string, direction: 'forward' | 'backward') => {
    setFrames((prevFrames) => {
      const sorted = [...prevFrames].sort((a, b) => a.zIndex - b.zIndex)
      const frameIndex = sorted.findIndex((f) => f.id === frameId)
      if (frameIndex === -1) return prevFrames

      if (direction === 'forward' && frameIndex < sorted.length - 1) {
        // Swap with next frame
        const nextFrame = sorted[frameIndex + 1]
        return prevFrames.map((f) => {
          if (f.id === frameId) return { ...f, zIndex: nextFrame.zIndex }
          if (f.id === nextFrame.id)
            return { ...f, zIndex: sorted[frameIndex].zIndex }
          return f
        })
      } else if (direction === 'backward' && frameIndex > 0) {
        // Swap with previous frame
        const prevFrame = sorted[frameIndex - 1]
        return prevFrames.map((f) => {
          if (f.id === frameId) return { ...f, zIndex: prevFrame.zIndex }
          if (f.id === prevFrame.id)
            return { ...f, zIndex: sorted[frameIndex].zIndex }
          return f
        })
      }
      return prevFrames
    })
  }

  // Move resource forward/backward
  const moveResource = (
    resourceId: string,
    direction: 'forward' | 'backward',
  ) => {
    setResourceZIndices((prev) => {
      const newMap = new Map(prev)
      const allZIndices = [
        ...frames.map((f) => f.zIndex),
        ...Array.from(newMap.values()),
      ].sort((a, b) => a - b)

      const currentZIndex = newMap.get(resourceId) || 0
      const currentIndex = allZIndices.indexOf(currentZIndex)

      if (direction === 'forward' && currentIndex < allZIndices.length - 1) {
        newMap.set(resourceId, allZIndices[currentIndex + 1])
      } else if (direction === 'backward' && currentIndex > 0) {
        newMap.set(resourceId, allZIndices[currentIndex - 1])
      }

      return newMap
    })
  }

  return (
    <div className="relative h-full w-full overflow-hidden bg-background">
      {/* Top controls - Right side */}
      <div className="absolute top-4 end-4 z-10 flex items-center gap-2">
        {/* Zoom controls */}
        <Button
          variant="outline"
          size="sm"
          className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
          onClick={handleZoomIn}
          disabled={zoom >= MAX_ZOOM}
        >
          <ZoomIn className="h-4 w-4" />
        </Button>
        <div className="h-8 rounded-md border border-border bg-card/95 backdrop-blur-sm px-3 flex items-center justify-center min-w-[64px]">
          <span className="text-[12px] font-medium text-foreground">
            {zoomPercentage}%
          </span>
        </div>
        <Button
          variant="outline"
          size="sm"
          className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
          onClick={handleZoomOut}
          disabled={zoom <= MIN_ZOOM}
        >
          <ZoomOut className="h-4 w-4" />
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
          onClick={handleResetZoom}
        >
          <Maximize2 className="h-4 w-4" />
        </Button>
      </div>

      {/* Canvas */}
      <div
        ref={canvasRef}
        className={cn(
          'h-full w-full overflow-hidden select-none',
          isDragging && 'cursor-grabbing',
          !isDragging && !resizing && 'cursor-grab',
        )}
        onMouseDown={handleMouseDown}
        onMouseMove={handleMouseMove}
        onMouseUp={handleMouseUp}
        onMouseLeave={handleMouseUp}
      >
        <div
          className="relative h-full w-full"
          style={{
            transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
            transformOrigin: '0 0',
          }}
        >
          {/* Blueprint dot pattern */}
          <svg
            className="absolute pointer-events-none"
            style={{
              left: '-5000px',
              top: '-5000px',
              width: '10000px',
              height: '10000px',
              zIndex: 0,
            }}
          >
            <defs>
              <pattern
                id="browser-dots"
                width="40"
                height="40"
                patternUnits="userSpaceOnUse"
              >
                <circle
                  cx="0"
                  cy="0"
                  r="2.5"
                  className="fill-foreground/20 dark:fill-foreground/30"
                />
              </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#browser-dots)" />
          </svg>

          {/* Connection lines */}
          <svg
            className="absolute pointer-events-none z-5"
            style={{
              width: '100%',
              height: '100%',
              left: 0,
              top: 0,
            }}
          >
            <defs>
              <marker
                id="arrowhead-browser"
                markerWidth="10"
                markerHeight="10"
                refX="9"
                refY="3"
                orient="auto"
              >
                <polygon points="0 0, 10 3, 0 6" fill="hsl(var(--border))" />
              </marker>
            </defs>
            <g>
              {/* Main resource connections */}
              {CONNECTIONS.map((conn, index) => {
                const fromPoint = getConnectionPoint(
                  conn.from,
                  null,
                  conn.fromSide,
                )
                const toPoint = getConnectionPoint(null, conn.to, conn.toSide)

                if (!fromPoint || !toPoint) return null

                return (
                  <line
                    key={`${conn.from}-${conn.to}-${index}`}
                    x1={fromPoint.x}
                    y1={fromPoint.y}
                    x2={toPoint.x}
                    y2={toPoint.y}
                    stroke="hsl(var(--border))"
                    strokeWidth="2"
                    markerEnd="url(#arrowhead-browser)"
                    strokeDasharray="4,4"
                    opacity={0.6}
                  />
                )
              })}

              {/* Table and bucket connections to frames */}
              {resources
                .filter((r) => r.type === 'table' || r.type === 'bucket')
                .map((resource) => {
                  if (!resource.parentFrameId) return null

                  const frame = frames.find(
                    (f) => f.id === resource.parentFrameId,
                  )
                  if (!frame) return null

                  const fromPoint = getConnectionPoint(
                    resource.parentFrameId,
                    null,
                    'right',
                  )
                  const toPoint = getConnectionPoint(null, resource.id, 'left')

                  if (!fromPoint || !toPoint) return null

                  return (
                    <line
                      key={`${resource.parentFrameId}-${resource.id}`}
                      x1={fromPoint.x}
                      y1={fromPoint.y}
                      x2={toPoint.x}
                      y2={toPoint.y}
                      stroke="hsl(var(--primary))"
                      strokeWidth="1.5"
                      markerEnd="url(#arrowhead-browser)"
                      strokeDasharray="3,3"
                      opacity={0.5}
                    />
                  )
                })}
            </g>
          </svg>

          {/* Resource nodes */}
          {resources
            .sort((a, b) => a.zIndex - b.zIndex)
            .map((resource) => {
              const Icon = getResourceIcon(resource.type)
              const isSelected =
                selectedNode?.type === 'resource' &&
                selectedNode.id === resource.id
              return (
                <div key={resource.id}>
                  {/* Action bar */}
                  {isSelected && (
                    <div
                      data-action-bar
                      className="absolute flex items-center gap-1 bg-card border border-border rounded-md p-1 z-50"
                      style={{
                        left: `${resource.x}px`,
                        top: `${resource.y - 40}px`,
                        zIndex: resource.zIndex + 1000,
                      }}
                      onClick={(e) => e.stopPropagation()}
                    >
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => moveResource(resource.id, 'forward')}
                        title={t('Move Forward')}
                      >
                        <ArrowUp className="h-3.5 w-3.5" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => moveResource(resource.id, 'backward')}
                        title={t('Move Backward')}
                      >
                        <ArrowDown className="h-3.5 w-3.5" />
                      </Button>
                      <div className="h-4 w-px bg-border mx-0.5" />
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={handleFitToView}
                        title={t('Fit to view')}
                      >
                        <Maximize2 className="h-3.5 w-3.5" />
                      </Button>
                      <div className="h-4 w-px bg-border mx-0.5" />
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => {
                          const route = getResourceRoute(resource.type)
                          navigate({
                            to: route as unknown,
                            params: { projectId } as unknown,
                          })
                        }}
                        title={`Open ${resource.type} page`}
                      >
                        <ExternalLink className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  )}
                  {/* Resource node */}
                  <div
                    data-resource
                    className={cn(
                      'absolute border rounded-lg bg-card p-3 flex items-center gap-3 transition-all cursor-pointer group',
                      isSelected
                        ? 'border-blue-500 outline outline-2 outline-blue-500 outline-offset-2'
                        : 'border-border hover:border-primary/50',
                    )}
                    style={{
                      left: `${resource.x}px`,
                      top: `${resource.y}px`,
                      width: '180px',
                      height: '60px',
                      zIndex: resource.zIndex,
                    }}
                    onClick={(e) => {
                      e.stopPropagation()
                      handleResourceClick(resource, e)
                    }}
                    title={
                      isSelected
                        ? `Double-click to open ${resource.type} page`
                        : `Click to select, double-click to open ${resource.type} page`
                    }
                  >
                    <div
                      className={cn(
                        'flex-shrink-0 w-10 h-10 rounded-md bg-muted flex items-center justify-center transition-colors',
                        !isSelected && 'group-hover:bg-primary/10',
                      )}
                    >
                      <Icon
                        className={cn(
                          'h-5 w-5 text-muted-foreground transition-colors',
                          !isSelected && 'group-hover:text-foreground',
                        )}
                      />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div
                        className={cn(
                          'text-[12px] font-medium text-foreground truncate transition-colors',
                          !isSelected && 'group-hover:text-foreground',
                        )}
                      >
                        {resource.name}
                      </div>
                      <div className="text-[11px] text-muted-foreground capitalize">
                        {resource.type}
                      </div>
                    </div>
                    <ExternalLink
                      className={cn(
                        'h-3.5 w-3.5 text-muted-foreground transition-opacity flex-shrink-0',
                        isSelected
                          ? 'opacity-0'
                          : 'opacity-0 group-hover:opacity-100',
                      )}
                    />
                  </div>
                </div>
              )
            })}

          {/* Browser frames */}
          {frames
            .sort((a, b) => a.zIndex - b.zIndex)
            .map((frame) => {
              const isSelected =
                selectedNode?.type === 'frame' && selectedNode.id === frame.id
              return (
                <div key={frame.id}>
                  {/* Action bar */}
                  {isSelected && (
                    <div
                      data-action-bar
                      className="absolute flex items-center gap-1 bg-card border border-border rounded-md p-1 z-50"
                      style={{
                        left: `${frame.x}px`,
                        top: `${frame.y - 40}px`,
                        zIndex: frame.zIndex + 1000,
                      }}
                      onClick={(e) => e.stopPropagation()}
                    >
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => moveFrame(frame.id, 'forward')}
                        title={t('Move Forward')}
                      >
                        <ArrowUp className="h-3.5 w-3.5" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={() => moveFrame(frame.id, 'backward')}
                        title={t('Move Backward')}
                      >
                        <ArrowDown className="h-3.5 w-3.5" />
                      </Button>
                      <div className="h-4 w-px bg-border mx-0.5" />
                      <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 p-0"
                        onClick={handleFitToView}
                        title={t('Fit to view')}
                      >
                        <Maximize2 className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  )}
                  {/* Frame */}
                  <div
                    data-frame
                    className={cn(
                      'absolute border rounded-lg overflow-hidden bg-card group',
                      isSelected
                        ? 'border-blue-500 outline outline-2 outline-blue-500 outline-offset-2'
                        : 'border-border',
                    )}
                    style={{
                      left: `${frame.x}px`,
                      top: `${frame.y}px`,
                      width: `${frame.width}px`,
                      height: `${frame.height}px`,
                      zIndex: frame.zIndex,
                    }}
                    onClick={(e) => {
                      e.stopPropagation()
                      handleFrameClick(frame.id, e)
                    }}
                  >
                    {/* Frame header */}
                    <div className="h-10 bg-muted border-b border-border flex items-center px-4">
                      <div className="flex items-center gap-2 flex-1 min-w-0">
                        <div className="flex gap-1.5">
                          <div className="w-3 h-3 rounded-full bg-red-500/80" />
                          <div className="w-3 h-3 rounded-full bg-yellow-500/80" />
                          <div className="w-3 h-3 rounded-full bg-green-500/80" />
                        </div>
                        <div className="flex-1 bg-background rounded px-3 py-1 text-[12px] text-muted-foreground truncate">
                          {frame.url}
                        </div>
                      </div>
                    </div>
                    {/* Iframe */}
                    <iframe
                      src={frame.url}
                      className="w-full h-[calc(100%-2.5rem)] border-0 pointer-events-none"
                      title={frame.name}
                      sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-modals"
                    />

                    {/* Resize handles - Edges only */}
                    {/* Top edge */}
                    <div
                      data-resize-handle
                      className={cn(
                        'absolute -top-1 start-0 end-0 h-2 cursor-ns-resize bg-primary/50 z-10',
                        resizing?.frameId === frame.id ||
                          'opacity-0 group-hover:opacity-100 transition-opacity',
                      )}
                      onMouseDown={(e) => handleResizeStart(e, frame.id, 'n')}
                    />
                    {/* Bottom edge */}
                    <div
                      data-resize-handle
                      className={cn(
                        'absolute -bottom-1 start-0 end-0 h-2 cursor-ns-resize bg-primary/50 z-10',
                        resizing?.frameId === frame.id ||
                          'opacity-0 group-hover:opacity-100 transition-opacity',
                      )}
                      onMouseDown={(e) => handleResizeStart(e, frame.id, 's')}
                    />
                    {/* Left edge */}
                    <div
                      data-resize-handle
                      className={cn(
                        'absolute -start-1 top-0 bottom-0 w-2 cursor-ew-resize bg-primary/50 z-10',
                        resizing?.frameId === frame.id ||
                          'opacity-0 group-hover:opacity-100 transition-opacity',
                      )}
                      onMouseDown={(e) => handleResizeStart(e, frame.id, 'w')}
                    />
                    {/* Right edge */}
                    <div
                      data-resize-handle
                      className={cn(
                        'absolute -end-1 top-0 bottom-0 w-2 cursor-ew-resize bg-primary/50 z-10',
                        resizing?.frameId === frame.id ||
                          'opacity-0 group-hover:opacity-100 transition-opacity',
                      )}
                      onMouseDown={(e) => handleResizeStart(e, frame.id, 'e')}
                    />
                  </div>
                </div>
              )
            })}
        </div>
      </div>
    </div>
  )
}
