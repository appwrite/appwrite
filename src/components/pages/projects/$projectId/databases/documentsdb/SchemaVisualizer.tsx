import { useState, useRef, useEffect, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useAllProjectTablesForVisualizer } from '@/lib/react-query/hooks'
import { getColumnIcon } from '@/lib/utils/column-icons'
import {
  Table2,
  Key,
  ZoomIn,
  ZoomOut,
  Maximize2,
  ChevronDown,
  ChevronUp,
  Map as MapIcon,
  Download,
  Copy,
  Settings,
  Eye,
  Check,
  FileJson,
  FileText,
  ExternalLink,
  Link2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  fetchDatabaseSchema,
  formatSchemaAsJSON,
} from '@/lib/utils/database-schema-export'
import { useQuery } from '@tanstack/react-query'
import { isHtmlDarkChrome } from '@/lib/html-theme'
import {
  dbNavLink,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { useT } from '@/lib/i18n/translate'

const DB_KIND = 'documentsdb' as const satisfies DatabaseRouteKind

interface SchemaVisualizerProps {
  databaseId: string
}

interface TableNode {
  id: string
  name: string
  x: number
  y: number
  width: number
  height: number
  columns: unknown[]
  indexes: unknown[]
  enabled: boolean
}

interface Relationship {
  from: string
  to: string
  fromColumn: string
  toColumn: string
}

const NODE_WIDTH = 300
const NODE_HEADER_HEIGHT = 40
const COLUMN_HEIGHT = 28
const NODE_PADDING = 12
const MIN_ZOOM = 0.2
const MAX_ZOOM = 2
const ZOOM_STEP = 0.05 // 5% increments for smoother zooming
const WHEEL_ZOOM_STEP = 0.02 // 2% increments for mouse wheel for even smoother control
const MAX_VISIBLE_COLUMNS = 20
const MIN_SPACING = 240 // Base spacing between nodes
const MIN_NODE_GAP = 15 // Minimum gap between nodes

export function SchemaVisualizer({ databaseId }: SchemaVisualizerProps) {
  const t = useT()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const navigate = useNavigate()
  const dbNav = useMemo(() => dbNavLink(DB_KIND), [])

  const { tables, isLoading } = useAllProjectTablesForVisualizer(
    projectId,
    databaseId,
    DB_KIND,
  )

  const canvasRef = useRef<HTMLDivElement>(null)
  const minimapRef = useRef<HTMLDivElement>(null)
  const [zoom, setZoom] = useState(1)
  const [pan, setPan] = useState({ x: 0, y: 0 })
  const [isDragging, setIsDragging] = useState(false)
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 })
  const [selectedTable, setSelectedTable] = useState<string | null>(null)
  const [expandedColumns, setExpandedColumns] = useState<Set<string>>(new Set())
  const [hasAutoFocused, setHasAutoFocused] = useState(false)
  const [showMinimap, setShowMinimap] = useState(true)
  const [, setContextMenuTableId] = useState<string | null>(null)
  const [copiedLink, setCopiedLink] = useState(false)

  // Store initial positions to maintain stability when expanding/collapsing
  const initialPositionsRef = useRef<Map<string, { x: number; y: number }>>(
    new Map(),
  )

  // Helper to get computed CSS variable values
  const getCSSVariable = (variable: string): string => {
    if (typeof window === 'undefined') return ''
    return getComputedStyle(document.documentElement)
      .getPropertyValue(variable)
      .trim()
  }

  // Detect if dark mode is active (with state to trigger re-renders)
  const [isDarkMode, setIsDarkMode] = useState(() => {
    if (typeof window === 'undefined') return false
    return (
      isHtmlDarkChrome() ||
      window.matchMedia('(prefers-color-scheme: dark)').matches
    )
  })

  // Watch for theme changes
  useEffect(() => {
    const checkDarkMode = () => {
      setIsDarkMode(
        isHtmlDarkChrome() ||
          window.matchMedia('(prefers-color-scheme: dark)').matches,
      )
    }

    // Check on mount
    checkDarkMode()

    // Watch for class changes
    const observer = new MutationObserver(checkDarkMode)
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    })

    // Watch for system preference changes
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    mediaQuery.addEventListener('change', checkDarkMode)

    return () => {
      observer.disconnect()
      mediaQuery.removeEventListener('change', checkDarkMode)
    }
  }, [])

  // Get theme colors for minimap with proper contrast
  const getThemeColors = () => {
    const foreground = getCSSVariable('--foreground')
    const primary = getCSSVariable('--primary')

    // For light mode, use darker colors for better visibility and contrast
    if (!isDarkMode) {
      // In light mode, use white/light cards on darker muted background for high contrast
      // Use a bright, visible color for selected nodes
      return {
        card: 'oklch(1 0 0)', // pure white for maximum contrast
        foreground: 'oklch(0.141 0.005 285.823)', // dark text
        border: 'oklch(0.4 0.01 285)', // much darker border for visibility
        primary: 'oklch(0.3 0.15 250)', // bright blue for selected nodes in light mode
        primaryText: 'oklch(1 0 0)', // white text on selected nodes
        muted: 'oklch(0.85 0.005 286)', // darker muted background for contrast
      }
    }

    // Dark mode colors - use lighter cards on darker background
    return {
      card: 'oklch(0.2 0.006 286)', // lighter than background for contrast
      foreground: foreground || 'oklch(0.985 0 0)',
      border: 'oklch(0.5 0.01 286)', // lighter border for visibility
      primary: primary || 'oklch(0.985 0 0)', // light primary
      primaryText: 'oklch(0.141 0.005 285.823)', // dark text on selected nodes
      muted: 'oklch(0.15 0.005 286)', // darker background
    }
  }

  // Convert tables to nodes with positions using improved layout algorithm
  const nodes = useMemo<TableNode[]>(() => {
    if (!tables || tables.length === 0) return []

    const nodes: TableNode[] = []
    const placedNodes: Array<{
      x: number
      y: number
      width: number
      height: number
    }> = []

    // Calculate all node dimensions first
    const nodeDimensions = tables.map((table: unknown) => {
      const columnCount = table.columns?.length || 0

      // Calculate maximum possible height (when fully expanded) for layout stability
      const maxContentHeight =
        NODE_HEADER_HEIGHT +
        columnCount * COLUMN_HEIGHT +
        (columnCount > MAX_VISIBLE_COLUMNS ? COLUMN_HEIGHT : 0) +
        NODE_PADDING * 2 +
        40

      // Current visible height (for rendering)
      const visibleColumns = expandedColumns.has(table.$id)
        ? columnCount
        : Math.min(columnCount, MAX_VISIBLE_COLUMNS)

      const currentContentHeight =
        NODE_HEADER_HEIGHT +
        visibleColumns * COLUMN_HEIGHT +
        (columnCount > MAX_VISIBLE_COLUMNS && !expandedColumns.has(table.$id)
          ? COLUMN_HEIGHT
          : 0) +
        NODE_PADDING * 2 +
        40

      return {
        width: NODE_WIDTH,
        layoutHeight: Math.max(maxContentHeight, 100),
        renderHeight: Math.max(currentContentHeight, 100),
      }
    })

    // Calculate average dimensions for better initial spacing
    const avgWidth = NODE_WIDTH
    const avgHeight =
      nodeDimensions.reduce(
        (sum: number, dim: { layoutHeight: number }) => sum + dim.layoutHeight,
        0,
      ) / nodeDimensions.length
    const baseSpacing = Math.max(
      MIN_SPACING,
      avgWidth + MIN_NODE_GAP,
      avgHeight + MIN_NODE_GAP,
    )

    // Calculate optimal grid dimensions
    const cols = Math.ceil(Math.sqrt(tables.length))
    const startX = 100
    const startY = 100

    // Place nodes in a compact grid
    tables.forEach((table: unknown, index: number) => {
      const row = Math.floor(index / cols)
      const col = index % cols
      const { width, layoutHeight, renderHeight } = nodeDimensions[index]

      // Try to use stored position first
      const storedPosition = initialPositionsRef.current.get(table.$id)
      let x: number = startX + col * baseSpacing
      let y: number = startY + row * baseSpacing
      let useStoredPosition = false

      if (storedPosition) {
        const storedX = storedPosition.x
        const storedY = storedPosition.y

        // Check if stored position still works
        const hasOverlap = placedNodes.some((placed) => {
          return !(
            storedX + width + MIN_NODE_GAP <= placed.x ||
            storedX >= placed.x + placed.width + MIN_NODE_GAP ||
            storedY + renderHeight + MIN_NODE_GAP <= placed.y ||
            storedY >= placed.y + placed.height + MIN_NODE_GAP
          )
        })

        if (!hasOverlap) {
          x = storedX
          y = storedY
          useStoredPosition = true
        }
      }

      if (!useStoredPosition) {
        // Start with grid position (already set above)

        // Check for overlap and adjust if needed
        const checkOverlap = (testX: number, testY: number): boolean => {
          return placedNodes.some((placed) => {
            return !(
              testX + width + MIN_NODE_GAP <= placed.x ||
              testX >= placed.x + placed.width + MIN_NODE_GAP ||
              testY + layoutHeight + MIN_NODE_GAP <= placed.y ||
              testY >= placed.y + placed.height + MIN_NODE_GAP
            )
          })
        }

        // If initial position overlaps, find nearby position
        if (checkOverlap(x, y)) {
          let found = false
          const maxAttempts = 50
          let attempts = 0

          // Try positions in a compact spiral around the grid position
          while (attempts < maxAttempts && !found) {
            const radius = Math.floor(attempts / 8) + 1
            const angle = (attempts % 8) * (Math.PI / 4)
            const step = (width + MIN_NODE_GAP) * 0.5 // Smaller steps for tighter packing
            const offsetX = Math.cos(angle) * step * radius
            const offsetY = Math.sin(angle) * step * radius

            const testX = x + offsetX
            const testY = y + offsetY

            if (!checkOverlap(testX, testY)) {
              x = testX
              y = testY
              found = true
            }
            attempts++
          }
        }

        // Store position for future renders
        initialPositionsRef.current.set(table.$id, { x, y })
      }

      // Store for collision detection
      placedNodes.push({
        x,
        y,
        width,
        height: layoutHeight,
      })

      nodes.push({
        id: table.$id,
        name: table.name || t('Unnamed Table'),
        x,
        y,
        width,
        height: renderHeight,
        columns: table.columns || [],
        indexes: table.indexes || [],
        enabled: table.enabled !== false,
      })
    })

    return nodes
  }, [tables, expandedColumns])

  // Detect relationships between tables
  const relationships = useMemo<Relationship[]>(() => {
    if (!tables || tables.length === 0) return []

    const rels: Relationship[] = []
    const tableNameMap = new Map(tables.map((t: unknown) => [t.name, t]))

    tables.forEach((table: unknown) => {
      const t = table as {
        $id: string
        name?: string
        columns?: unknown[]
      }
      if (!t.columns) return

      t.columns.forEach((column: unknown) => {
        const col = column as { key?: string; type?: string }
        // Check if column name suggests a foreign key (e.g., "postId", "userId", etc.)
        const columnKey = (col.key ?? '').toLowerCase()

        // Pattern: {tableName}Id or {tableName}_id
        const match = columnKey.match(/^(.+?)(id|_id)$/)
        if (match && col.type === 'string') {
          const referencedTableName = match[1]

          // Try to find the referenced table by name
          const referencedTable =
            tableNameMap.get(referencedTableName) ||
            Array.from(tableNameMap.values()).find(
              (tbl: unknown) =>
                (tbl as { name?: string }).name?.toLowerCase() ===
                referencedTableName,
            )

          if (
            referencedTable &&
            typeof referencedTable === 'object' &&
            '$id' in referencedTable
          ) {
            // Check if referenced table has an $id column (primary key)
            const hasIdColumn = (referencedTable as unknown).columns?.some(
              (c: unknown) => c.key === '$id' || c.key === 'id',
            )

            if (hasIdColumn) {
              rels.push({
                from: t.$id,
                to: (referencedTable as unknown as { $id: string }).$id,
                fromColumn: col.key ?? '',
                toColumn: '$id',
              })
            }
          }
        }
      })
    })

    return rels
  }, [tables])

  // Auto-focus canvas when tables are loaded
  useEffect(() => {
    if (nodes.length > 0 && !hasAutoFocused && !isLoading) {
      // Calculate bounding box of all nodes
      const bounds = nodes.reduce(
        (acc, node) => {
          return {
            minX: Math.min(acc.minX, node.x),
            minY: Math.min(acc.minY, node.y),
            maxX: Math.max(acc.maxX, node.x + node.width),
            maxY: Math.max(acc.maxY, node.y + node.height),
          }
        },
        {
          minX: Infinity,
          minY: Infinity,
          maxX: -Infinity,
          maxY: -Infinity,
        },
      )

      if (bounds.minX !== Infinity) {
        const canvas = canvasRef.current
        if (canvas) {
          const canvasWidth = canvas.clientWidth
          const canvasHeight = canvas.clientHeight

          const contentWidth = bounds.maxX - bounds.minX
          const contentHeight = bounds.maxY - bounds.minY

          // Add padding around content
          const padding = 100
          const paddedWidth = contentWidth + padding * 2
          const paddedHeight = contentHeight + padding * 2

          // Calculate zoom to fit content
          const zoomX = canvasWidth / paddedWidth
          const zoomY = canvasHeight / paddedHeight
          const fitZoom = Math.min(zoomX, zoomY, 1) // Don't zoom in beyond 100%

          // Center the content
          const centerX = (bounds.minX + bounds.maxX) / 2
          const centerY = (bounds.minY + bounds.maxY) / 2

          setZoom(fitZoom)
          setPan({
            x: canvasWidth / 2 - centerX * fitZoom,
            y: canvasHeight / 2 - centerY * fitZoom,
          })

          setHasAutoFocused(true)
        }
      }
    }
  }, [nodes, isLoading, hasAutoFocused])

  // Handle mouse wheel for zoom
  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return

    const handleWheel = (e: WheelEvent) => {
      e.preventDefault()

      const delta = e.deltaY > 0 ? -WHEEL_ZOOM_STEP : WHEEL_ZOOM_STEP
      const newZoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, zoom + delta))

      // Zoom towards mouse position
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
  }, [zoom, pan])

  // Handle panning
  const handleMouseDown = (e: React.MouseEvent) => {
    if (e.button !== 0) return // Only left mouse button
    setIsDragging(true)
    setDragStart({
      x: e.clientX - pan.x,
      y: e.clientY - pan.y,
    })
  }

  const handleMouseMove = (e: React.MouseEvent) => {
    if (!isDragging) return
    setPan({
      x: e.clientX - dragStart.x,
      y: e.clientY - dragStart.y,
    })
  }

  const handleMouseUp = () => {
    setIsDragging(false)
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

  // Toggle column expansion
  const toggleColumns = (tableId: string) => {
    setExpandedColumns((prev) => {
      const next = new Set(prev)
      if (next.has(tableId)) {
        next.delete(tableId)
      } else {
        next.add(tableId)
      }
      return next
    })
  }

  const handleExportSVG = () => {
    if (!canvasRef.current) return

    try {
      // Detect if dark mode for proper color selection
      const isDark =
        isHtmlDarkChrome() ||
        window.matchMedia('(prefers-color-scheme: dark)').matches

      // Get computed color values with fallbacks for better contrast
      const cardColor =
        getCSSVariable('--card') ||
        (isDark ? 'oklch(0.141 0.005 285.823)' : 'oklch(1 0 0)')
      const foregroundColor =
        getCSSVariable('--foreground') ||
        (isDark ? 'oklch(0.985 0 0)' : 'oklch(0.141 0.005 285.823)')
      const borderColor =
        getCSSVariable('--border') ||
        (isDark ? 'oklch(0.274 0.006 286.033)' : 'oklch(0.7 0.01 285)')
      // Ensure we have proper contrast - if colors are too similar, use defaults
      const finalCardColor = cardColor || (isDark ? '#242424' : '#ffffff')
      const finalForegroundColor =
        foregroundColor || (isDark ? '#ffffff' : '#000000')
      const finalBorderColor = borderColor || (isDark ? '#444444' : '#b3b3b3')
      // Use soft grey for muted backgrounds to ensure contrast
      const finalMutedColor = isDark ? '#2a2a2a' : '#e5e5e5' // Soft grey for light mode
      const finalHeaderBgColor = isDark ? '#2a2a2a' : '#f0f0f0' // Softer grey for header
      const finalRowBgColor = isDark ? '#2a2a2a' : '#f5f5f5' // Soft grey for alternating rows

      const bounds = nodes.reduce(
        (acc, node) => {
          return {
            minX: Math.min(acc.minX, node.x),
            minY: Math.min(acc.minY, node.y),
            maxX: Math.max(acc.maxX, node.x + node.width),
            maxY: Math.max(acc.maxY, node.y + node.height),
          }
        },
        {
          minX: Infinity,
          minY: Infinity,
          maxX: -Infinity,
          maxY: -Infinity,
        },
      )

      if (bounds.minX === Infinity) return

      const padding = 100
      const width = bounds.maxX - bounds.minX + padding * 2
      const height = bounds.maxY - bounds.minY + padding * 2

      // Create SVG
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
      svg.setAttribute('width', width.toString())
      svg.setAttribute('height', height.toString())
      svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg')

      // Add style definitions for better font rendering
      const style = document.createElementNS(
        'http://www.w3.org/2000/svg',
        'style',
      )
      style.textContent = `
        text {
          font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
          -webkit-font-smoothing: antialiased;
          -moz-osx-font-smoothing: grayscale;
          text-rendering: optimizeLegibility;
        }
        .table-header {
          font-weight: 500;
        }
        .column-name {
          font-weight: 400;
        }
        .column-type {
          font-weight: 400;
          opacity: 0.8;
        }
      `
      svg.appendChild(style)

      // Add background
      const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
      bg.setAttribute('width', '100%')
      bg.setAttribute('height', '100%')
      // Use computed muted color with opacity - ensure proper contrast
      const mutedWithOpacity = `${finalMutedColor} / 0.3`
      bg.setAttribute('fill', mutedWithOpacity)
      svg.appendChild(bg)

      // Create defs element for patterns and clipPaths
      const defs = document.createElementNS(
        'http://www.w3.org/2000/svg',
        'defs',
      )

      // Add dot pattern
      const dotPattern = document.createElementNS(
        'http://www.w3.org/2000/svg',
        'pattern',
      )
      dotPattern.setAttribute('id', 'export-dots')
      dotPattern.setAttribute('width', '40')
      dotPattern.setAttribute('height', '40')
      dotPattern.setAttribute('patternUnits', 'userSpaceOnUse')
      const circle = document.createElementNS(
        'http://www.w3.org/2000/svg',
        'circle',
      )
      circle.setAttribute('cx', '0')
      circle.setAttribute('cy', '0')
      circle.setAttribute('r', '2.5')
      circle.setAttribute('fill', finalForegroundColor)
      circle.setAttribute('opacity', '0.2')
      dotPattern.appendChild(circle)
      defs.appendChild(dotPattern)
      svg.appendChild(defs)

      const patternRect = document.createElementNS(
        'http://www.w3.org/2000/svg',
        'rect',
      )
      patternRect.setAttribute('width', '100%')
      patternRect.setAttribute('height', '100%')
      patternRect.setAttribute('fill', 'url(#export-dots)')
      svg.appendChild(patternRect)

      // Add relationships
      relationships.forEach((rel) => {
        const fromPoint = getConnectionPoint(rel.from, 'right')
        const toPoint = getConnectionPoint(rel.to, 'left')
        const line = document.createElementNS(
          'http://www.w3.org/2000/svg',
          'line',
        )
        line.setAttribute(
          'x1',
          (fromPoint.x - bounds.minX + padding).toString(),
        )
        line.setAttribute(
          'y1',
          (fromPoint.y - bounds.minY + padding).toString(),
        )
        line.setAttribute('x2', (toPoint.x - bounds.minX + padding).toString())
        line.setAttribute('y2', (toPoint.y - bounds.minY + padding).toString())
        line.setAttribute('stroke', finalBorderColor)
        line.setAttribute('stroke-width', '2')
        svg.appendChild(line)
      })

      // Add table nodes with full details (columns, indexes)
      nodes.forEach((node) => {
        const group = document.createElementNS(
          'http://www.w3.org/2000/svg',
          'g',
        )
        group.setAttribute(
          'transform',
          `translate(${node.x - bounds.minX + padding}, ${node.y - bounds.minY + padding})`,
        )

        // Calculate node height based on columns - match actual rendered height
        const columnCount = node.columns.length
        const visibleColumns = Math.min(columnCount, MAX_VISIBLE_COLUMNS)
        const hasMoreColumns = columnCount > MAX_VISIBLE_COLUMNS
        const nodeHeight =
          NODE_HEADER_HEIGHT +
          visibleColumns * COLUMN_HEIGHT +
          (hasMoreColumns ? COLUMN_HEIGHT : 0) +
          NODE_PADDING * 2 +
          40 // Action buttons area

        // Table box
        const rect = document.createElementNS(
          'http://www.w3.org/2000/svg',
          'rect',
        )
        rect.setAttribute('width', node.width.toString())
        rect.setAttribute('height', nodeHeight.toString())
        rect.setAttribute('rx', '8')
        rect.setAttribute('fill', finalCardColor)
        rect.setAttribute('stroke', finalBorderColor)
        rect.setAttribute('stroke-width', '1')
        group.appendChild(rect)

        // Header section - rounded top corners only using path
        const headerPath = document.createElementNS(
          'http://www.w3.org/2000/svg',
          'path',
        )
        const headerPathData = `M 8,0 L ${node.width - 8},0 Q ${node.width},0 ${node.width},8 L ${node.width},${NODE_HEADER_HEIGHT} L 0,${NODE_HEADER_HEIGHT} L 0,8 Q 0,0 8,0 Z`
        headerPath.setAttribute('d', headerPathData)
        // Use soft grey for header background to ensure contrast
        headerPath.setAttribute('fill', finalHeaderBgColor)
        group.appendChild(headerPath)

        // Header border
        const headerBorder = document.createElementNS(
          'http://www.w3.org/2000/svg',
          'line',
        )
        headerBorder.setAttribute('x1', '0')
        headerBorder.setAttribute('y1', NODE_HEADER_HEIGHT.toString())
        headerBorder.setAttribute('x2', node.width.toString())
        headerBorder.setAttribute('y2', NODE_HEADER_HEIGHT.toString())
        headerBorder.setAttribute('stroke', finalBorderColor)
        headerBorder.setAttribute('stroke-width', '1')
        group.appendChild(headerBorder)

        // Table name in header
        const tableNameText = document.createElementNS(
          'http://www.w3.org/2000/svg',
          'text',
        )
        tableNameText.setAttribute('x', '12')
        tableNameText.setAttribute('y', '26')
        tableNameText.setAttribute('font-size', '13')
        tableNameText.setAttribute('font-weight', '500')
        tableNameText.setAttribute('fill', finalForegroundColor)
        tableNameText.setAttribute(
          'font-family',
          '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        )
        tableNameText.setAttribute('dominant-baseline', 'middle')
        tableNameText.setAttribute('class', 'table-header')
        tableNameText.textContent = node.name
        group.appendChild(tableNameText)

        // Disabled indicator if needed
        if (!node.enabled) {
          const disabledText = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'text',
          )
          disabledText.setAttribute('x', (node.width - 12).toString())
          disabledText.setAttribute('y', '26')
          disabledText.setAttribute('font-size', '10')
          disabledText.setAttribute('fill', finalForegroundColor)
          disabledText.setAttribute('text-anchor', 'end')
          disabledText.setAttribute(
            'font-family',
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
          )
          disabledText.setAttribute('dominant-baseline', 'middle')
          disabledText.textContent = 'Disabled'
          group.appendChild(disabledText)
        }

        // Columns
        let yOffset = NODE_HEADER_HEIGHT + NODE_PADDING
        const columnsToShow = node.columns.slice(0, visibleColumns)

        columnsToShow.forEach((column: unknown, index: number) => {
          const columnGroup = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'g',
          )

          // Column row background (subtle hover effect) - use soft grey for every 2nd row
          if (index % 2 === 0) {
            const rowBg = document.createElementNS(
              'http://www.w3.org/2000/svg',
              'rect',
            )
            rowBg.setAttribute('x', '8')
            rowBg.setAttribute('y', (yOffset - 2).toString())
            rowBg.setAttribute('width', (node.width - 16).toString())
            rowBg.setAttribute('height', (COLUMN_HEIGHT - 2).toString())
            rowBg.setAttribute('rx', '4')
            // Use soft grey for alternating rows to ensure contrast
            rowBg.setAttribute('fill', finalRowBgColor)
            columnGroup.appendChild(rowBg)
          }

          // Column name
          const columnNameText = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'text',
          )
          columnNameText.setAttribute('x', '12')
          columnNameText.setAttribute('y', (yOffset + 14).toString())
          columnNameText.setAttribute('font-size', '12')
          columnNameText.setAttribute('fill', finalForegroundColor)
          columnNameText.setAttribute(
            'font-family',
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
          )
          columnNameText.setAttribute('dominant-baseline', 'middle')
          columnNameText.setAttribute('class', 'column-name')
          // Truncate long column names with ellipsis
          const maxLength = 30
          const displayName =
            column.key.length > maxLength
              ? column.key.substring(0, maxLength) + '...'
              : column.key
          columnNameText.textContent = displayName
          columnGroup.appendChild(columnNameText)

          // Calculate positions for badges (end-aligned)
          let currentX = node.width - 12

          // Column type badge (always shown)
          const typeText = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'text',
          )
          typeText.setAttribute('x', currentX.toString())
          typeText.setAttribute('y', (yOffset + 14).toString())
          typeText.setAttribute('font-size', '10')
          typeText.setAttribute('fill', finalForegroundColor)
          typeText.setAttribute('text-anchor', 'end')
          typeText.setAttribute(
            'font-family',
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
          )
          typeText.setAttribute('dominant-baseline', 'middle')
          typeText.setAttribute('class', 'column-type')
          typeText.textContent = column.type
          columnGroup.appendChild(typeText)

          // Estimate type badge width and adjust for required
          const typeWidth = column.type.length * 6 + 8
          currentX = currentX - typeWidth - 4

          // Required indicator
          if (column.required) {
            const requiredText = document.createElementNS(
              'http://www.w3.org/2000/svg',
              'text',
            )
            requiredText.setAttribute('x', currentX.toString())
            requiredText.setAttribute('y', (yOffset + 14).toString())
            requiredText.setAttribute('font-size', '10')
            requiredText.setAttribute('fill', finalForegroundColor)
            requiredText.setAttribute('text-anchor', 'end')
            requiredText.setAttribute(
              'font-family',
              '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            )
            requiredText.setAttribute('dominant-baseline', 'middle')
            requiredText.textContent = 'required'
            columnGroup.appendChild(requiredText)
          }

          group.appendChild(columnGroup)
          yOffset += COLUMN_HEIGHT
        })

        // Show more indicator if there are more columns
        if (columnCount > MAX_VISIBLE_COLUMNS) {
          const showMoreText = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'text',
          )
          showMoreText.setAttribute('x', (node.width / 2).toString())
          showMoreText.setAttribute('y', (yOffset + 12).toString())
          showMoreText.setAttribute('font-size', '11')
          showMoreText.setAttribute('fill', finalForegroundColor)
          showMoreText.setAttribute('text-anchor', 'middle')
          showMoreText.setAttribute('opacity', '0.6')
          showMoreText.setAttribute(
            'font-family',
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
          )
          showMoreText.textContent = `Show ${columnCount - MAX_VISIBLE_COLUMNS} more`
          group.appendChild(showMoreText)
        }

        // Action buttons area (simplified)
        const actionY = yOffset + 8
        const actionBorder = document.createElementNS(
          'http://www.w3.org/2000/svg',
          'line',
        )
        actionBorder.setAttribute('x1', '8')
        actionBorder.setAttribute('y1', actionY.toString())
        actionBorder.setAttribute('x2', (node.width - 8).toString())
        actionBorder.setAttribute('y2', actionY.toString())
        actionBorder.setAttribute('stroke', finalBorderColor)
        actionBorder.setAttribute('stroke-width', '1')
        group.appendChild(actionBorder)

        svg.appendChild(group)
      })

      const svgData = new XMLSerializer().serializeToString(svg)
      const blob = new Blob([svgData], { type: 'image/svg+xml' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `database-schema-${databaseId}.svg`
      link.click()
      URL.revokeObjectURL(url)
      toast.success(t('Schema exported as SVG'))
    } catch {
      toast.error(t('Failed to export SVG'))
    }
  }

  const handleCopyShareLink = async () => {
    try {
      const url = window.location.href
      await navigator.clipboard.writeText(url)
      setCopiedLink(true)
      toast.success(t('Link copied to clipboard'))
      setTimeout(() => setCopiedLink(false), 2000)
    } catch {
      toast.error(t('Failed to copy link'))
    }
  }

  // Fetch database schema for export
  const { data: databaseSchema } = useQuery({
    queryKey: ['database-schema', 'project', projectId, databaseId],
    queryFn: () => fetchDatabaseSchema(projectId, databaseId, DB_KIND),
    enabled: !!projectId && !!databaseId,
    staleTime: 5 * 60 * 1000, // 5 minutes
  })

  // Export handlers
  const handleExportJSON = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      toast.success(t('Schema copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy schema'))
    }
  }

  const handleCopyMarkdown = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const { formatSchemaAsMarkdown } =
        await import('@/lib/utils/database-schema-export')
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      toast.success(t('Schema copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy schema'))
    }
  }

  // Open in AI tools using deep links
  const handleOpenInChatGPT = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const { getChatGPTDeepLink, formatSchemaAsMarkdown } =
        await import('@/lib/utils/database-schema-export')
      const deepLink = getChatGPTDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      window.open(deepLink, '_blank')
      toast.success(t('Opening ChatGPT with schema context...'))
    } catch {
      toast.error(t('Failed to open ChatGPT'))
    }
  }

  const handleOpenInClaude = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const { getClaudeDeepLink, formatSchemaAsMarkdown } =
        await import('@/lib/utils/database-schema-export')
      const deepLink = getClaudeDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const markdown = formatSchemaAsMarkdown(databaseSchema)
      await navigator.clipboard.writeText(markdown)
      window.open(deepLink, '_blank')
      toast.success(t('Opening Claude with schema context...'))
    } catch {
      toast.error(t('Failed to open Claude'))
    }
  }

  const handleOpenInCursor = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const { getCursorDeepLink, formatSchemaAsJSON } =
        await import('@/lib/utils/database-schema-export')
      const deepLink = getCursorDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      // Try to open app protocol link, fallback to clipboard message
      try {
        window.location.href = deepLink
        toast.success(t('Opening Cursor with schema context...'))
      } catch {
        toast.success(t('Schema copied to clipboard. Paste it in Cursor.'))
      }
    } catch {
      toast.error(t('Failed to open Cursor'))
    }
  }

  const handleOpenInLovable = async () => {
    if (!databaseSchema) {
      toast.error(t('Schema not loaded yet'))
      return
    }
    try {
      const { getLovableDeepLink, formatSchemaAsJSON } =
        await import('@/lib/utils/database-schema-export')
      const deepLink = getLovableDeepLink(databaseSchema)
      // Also copy to clipboard as fallback
      const json = formatSchemaAsJSON(databaseSchema)
      await navigator.clipboard.writeText(json)
      window.open(deepLink, '_blank')
      toast.success(t('Opening Lovable with schema context...'))
    } catch {
      toast.error(t('Failed to open Lovable'))
    }
  }

  // Minimap functions
  const handleMinimapClick = (nodeId: string) => {
    const node = nodes.find((n) => n.id === nodeId)
    if (!node || !canvasRef.current) return

    const canvas = canvasRef.current
    const canvasWidth = canvas.clientWidth
    const canvasHeight = canvas.clientHeight

    // Center on the node
    const centerX = node.x + node.width / 2
    const centerY = node.y + node.height / 2

    setPan({
      x: canvasWidth / 2 - centerX * zoom,
      y: canvasHeight / 2 - centerY * zoom,
    })
    setSelectedTable(nodeId)
  }

  // Calculate minimap bounds
  const minimapBounds = useMemo(() => {
    if (nodes.length === 0) return null

    const bounds = nodes.reduce(
      (acc, node) => {
        return {
          minX: Math.min(acc.minX, node.x),
          minY: Math.min(acc.minY, node.y),
          maxX: Math.max(acc.maxX, node.x + node.width),
          maxY: Math.max(acc.maxY, node.y + node.height),
        }
      },
      {
        minX: Infinity,
        minY: Infinity,
        maxX: -Infinity,
        maxY: -Infinity,
      },
    )

    if (bounds.minX === Infinity) return null

    // Add padding
    const padding = 100
    return {
      minX: bounds.minX - padding,
      minY: bounds.minY - padding,
      maxX: bounds.maxX + padding,
      maxY: bounds.maxY + padding,
      width: bounds.maxX - bounds.minX + padding * 2,
      height: bounds.maxY - bounds.minY + padding * 2,
    }
  }, [nodes])

  // Calculate viewport rectangle for minimap
  const viewportRect = useMemo(() => {
    if (!canvasRef.current || !minimapBounds) return null

    const canvas = canvasRef.current
    const canvasWidth = canvas.clientWidth
    const canvasHeight = canvas.clientHeight

    // Calculate what's visible in the viewport
    const viewportLeft = -pan.x / zoom
    const viewportTop = -pan.y / zoom
    const viewportRight = viewportLeft + canvasWidth / zoom
    const viewportBottom = viewportTop + canvasHeight / zoom

    return {
      left: viewportLeft,
      top: viewportTop,
      width: viewportRight - viewportLeft,
      height: viewportBottom - viewportTop,
    }
  }, [pan, zoom, minimapBounds])

  // Context menu handlers
  const handleNavigateToTable = (resourceId: string) => {
    navigate({
      ...dbNav.dataGrid({
        projectId,
        dbKind: DB_KIND,
        databaseId,
        resourceId,
      }),
    })
  }

  const handleNavigateToSettings = (resourceId: string) => {
    navigate({
      ...dbNav.settings({
        projectId,
        dbKind: DB_KIND,
        databaseId,
        resourceId,
      }),
    })
  }

  // Get connection points for relationships
  const getConnectionPoint = (nodeId: string, side: 'left' | 'right') => {
    const node = nodes.find((n) => n.id === nodeId)
    if (!node) return { x: 0, y: 0 }

    return {
      x: node.x + (side === 'left' ? 0 : node.width),
      y: node.y + NODE_HEADER_HEIGHT / 2,
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Loading schema...')}</div>
      </div>
    )
  }

  if (nodes.length === 0) {
    return (
      <div
        className="relative h-full w-full overflow-hidden"
        style={{ backgroundColor: 'hsl(var(--muted) / 0.3)' }}
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
              id="dots-empty"
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
          <rect width="100%" height="100%" fill="url(#dots-empty)" />
        </svg>

        {/* Placeholder table node - positioned lower */}
        <div className="absolute start-1/2 top-[45%] -translate-x-1/2 -translate-y-1/2 z-10">
          <div
            className="rounded-lg border border-dashed border-border/40 bg-card/20 opacity-40"
            style={{ width: `${NODE_WIDTH}px` }}
          >
            {/* Header */}
            <div className="flex items-center gap-2 border-b border-border/40 bg-muted/20 px-3 py-2">
              <Table2 className="h-4 w-4 shrink-0 text-muted-foreground/40" />
              <span className="truncate text-[13px] font-medium text-muted-foreground/40">
                {t('Table name')}
              </span>
            </div>

            {/* Placeholder columns */}
            <div className="p-2 space-y-0.5">
              {[1, 2, 3].map((i) => (
                <div
                  key={i}
                  className="flex items-center gap-2 px-2 py-1 text-[12px] rounded"
                >
                  <div className="h-3.5 w-3.5 shrink-0 bg-muted/30 rounded" />
                  <span className="text-muted-foreground/30 flex-1">
                    column_{i}
                  </span>
                  <div className="h-4 w-16 bg-muted/30 rounded shrink-0" />
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Message overlay - positioned higher with better contrast */}
        <div className="absolute inset-0 flex items-center justify-center z-20 pointer-events-none">
          <div className="text-center max-w-lg px-6">
            <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-card backdrop-blur-sm ring-2 ring-border">
              <Table2 className="h-6 w-6 text-foreground" />
            </div>
            <h3 className="mb-2 text-base font-semibold text-foreground">
              {t('No tables found')}
            </h3>
            <p className="text-sm leading-relaxed text-foreground/80">
              {t('Create tables to visualize your database schema. Tables will appear here as interactive nodes that you can drag, zoom, and explore.')}
            </p>
          </div>
        </div>
      </div>
    )
  }

  const zoomPercentage = Math.round(zoom * 100)

  return (
    <div
      className="relative h-full w-full overflow-hidden"
      style={{ backgroundColor: 'hsl(var(--muted) / 0.3)' }}
    >
      {/* Top controls - Left side */}
      <div className="absolute top-4 start-4 z-10 flex items-center gap-2">
        {/* Copy share link button */}
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
              onClick={handleCopyShareLink}
            >
              {copiedLink ? (
                <Check className="h-4 w-4" />
              ) : (
                <Link2 className="h-4 w-4" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            {copiedLink ? t('Link copied!') : t('Copy link')}
          </TooltipContent>
        </Tooltip>

        {/* Copy dropdown */}
        <DropdownMenu>
          <Tooltip>
            <TooltipTrigger asChild>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                >
                  <Copy className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
            </TooltipTrigger>
            <TooltipContent>{t('Copy schema')}</TooltipContent>
          </Tooltip>
          <DropdownMenuContent align="start" className="w-48">
            <DropdownMenuItem onClick={handleExportJSON}>
              <FileJson className="h-4 w-4 me-2" />
              {t('Copy as JSON')}
            </DropdownMenuItem>
            <DropdownMenuItem onClick={handleCopyMarkdown}>
              <FileText className="h-4 w-4 me-2" />
              {t('Copy as Markdown')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>

        {/* Export SVG button */}
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
              onClick={handleExportSVG}
            >
              <Download className="h-4 w-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{t('Export as SVG')}</TooltipContent>
        </Tooltip>

        {/* Open in dropdown */}
        <DropdownMenu>
          <Tooltip>
            <TooltipTrigger asChild>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
                >
                  <ExternalLink className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
            </TooltipTrigger>
            <TooltipContent>{t('Open in...')}</TooltipContent>
          </Tooltip>
          <DropdownMenuContent align="start" className="w-48">
            <DropdownMenuItem onClick={handleOpenInChatGPT}>
              <img
                src="/icons/chatgpt.svg"
                alt="ChatGPT"
                className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
              />
              ChatGPT
            </DropdownMenuItem>
            <DropdownMenuItem onClick={handleOpenInClaude}>
              <img
                src="/icons/claude.svg"
                alt="Claude"
                className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
              />
              Claude
            </DropdownMenuItem>
            <DropdownMenuItem onClick={handleOpenInCursor}>
              <img
                src="/icons/cursor-ai.svg"
                alt="Cursor"
                className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
              />
              Cursor
            </DropdownMenuItem>
            <DropdownMenuItem onClick={handleOpenInLovable}>
              <img
                src="/icons/lovable.svg"
                alt="Lovable"
                className={`h-4 w-4 me-2 ${PUBLIC_ICON_MUTED_CLASSES}`}
              />
              Lovable
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

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
          'h-full w-full cursor-grab overflow-hidden select-none',
          isDragging && 'cursor-grabbing',
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
                id="dots"
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
            <rect width="100%" height="100%" fill="url(#dots)" />
          </svg>
          {/* Relationship lines - rendered as SVG overlay */}
          <svg
            className="absolute pointer-events-none z-10"
            style={{
              width: '100%',
              height: '100%',
              left: 0,
              top: 0,
            }}
          >
            <defs>
              <marker
                id="arrowhead"
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
              {relationships.map((rel, index) => {
                const fromPoint = getConnectionPoint(rel.from, 'right')
                const toPoint = getConnectionPoint(rel.to, 'left')

                return (
                  <line
                    key={`${rel.from}-${rel.to}-${index}`}
                    x1={fromPoint.x}
                    y1={fromPoint.y}
                    x2={toPoint.x}
                    y2={toPoint.y}
                    stroke="hsl(var(--border))"
                    strokeWidth="2"
                    markerEnd="url(#arrowhead)"
                  />
                )
              })}
            </g>
          </svg>

          {/* Table nodes */}
          <div className="relative z-20">
            {nodes.map((node) => (
              <ContextMenu key={node.id}>
                <ContextMenuTrigger asChild>
                  <div
                    className="absolute"
                    style={{
                      left: `${node.x}px`,
                      top: `${node.y}px`,
                      width: `${node.width}px`,
                    }}
                  >
                    <div
                      className={cn(
                        'rounded-lg border bg-card transition-all cursor-pointer select-none overflow-hidden',
                        selectedTable === node.id && 'ring-2 ring-ring',
                        !node.enabled && 'opacity-60',
                      )}
                      onClick={() =>
                        setSelectedTable(
                          node.id === selectedTable ? null : node.id,
                        )
                      }
                    >
                      {/* Header */}
                      <div className="flex items-center gap-2 border-b border-border bg-muted/50 px-3 py-2">
                        <Table2 className="h-4 w-4 shrink-0 text-muted-foreground" />
                        <span className="truncate text-[13px] font-medium text-foreground">
                          {node.name}
                        </span>
                        {!node.enabled && (
                          <span className="ms-auto text-[10px] text-muted-foreground">
                            {t('Disabled')}
                          </span>
                        )}
                      </div>

                      {/* Columns */}
                      <div className="p-2">
                        {node.columns.length > 0 && (
                          <div className="space-y-0.5">
                            {(expandedColumns.has(node.id)
                              ? node.columns
                              : node.columns.slice(0, MAX_VISIBLE_COLUMNS)
                            ).map((column: unknown) => {
                              const Icon = getColumnIcon(column.type)
                              // Find indexes that include this column
                              const columnIndexes = node.indexes.filter(
                                (idx: unknown) =>
                                  idx.columns?.includes(column.key),
                              )
                              const hasIndex = columnIndexes.length > 0

                              return (
                                <div
                                  key={column.key}
                                  className="flex items-center gap-2 px-2 py-1 text-[12px] hover:bg-muted/50 rounded"
                                >
                                  <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                  <span className="truncate text-foreground flex-1 flex items-center gap-1.5">
                                    {column.key}
                                    {hasIndex && (
                                      <Tooltip>
                                        <TooltipTrigger asChild>
                                          <div className="flex items-center shrink-0">
                                            <Key className="h-3 w-3 text-muted-foreground" />
                                          </div>
                                        </TooltipTrigger>
                                        <TooltipContent
                                          side="right"
                                          className="max-w-xs"
                                        >
                                          <div className="space-y-1">
                                            {columnIndexes.map(
                                              (idx: unknown) => (
                                                <div
                                                  key={idx.$id || idx.key}
                                                  className="text-[12px]"
                                                >
                                                  <div className="font-medium">
                                                    {idx.key}
                                                  </div>
                                                  <div className="text-muted-foreground text-[11px]">
                                                    Type: {idx.type || 'key'}
                                                    {idx.columns &&
                                                      idx.columns.length >
                                                      1 && (
                                                        <span>
                                                          {' '}
                                                          • Columns:{' '}
                                                          {idx.columns.join(
                                                            ', ',
                                                          )}
                                                        </span>
                                                      )}
                                                  </div>
                                                </div>
                                              ),
                                            )}
                                          </div>
                                        </TooltipContent>
                                      </Tooltip>
                                    )}
                                  </span>
                                  <div className="flex items-center gap-1.5 shrink-0">
                                    {column.required && (
                                      <Badge
                                        variant="outline"
                                        className="h-4 px-1.5 text-[10px] font-normal"
                                      >
                                        {t('Required')}
                                      </Badge>
                                    )}
                                    <Badge
                                      variant="outline"
                                      className="h-4 px-1.5 text-[10px] font-normal"
                                    >
                                      {column.type}
                                    </Badge>
                                  </div>
                                </div>
                              )
                            })}
                            {node.columns.length > MAX_VISIBLE_COLUMNS && (
                              <button
                                onClick={(e) => {
                                  e.stopPropagation()
                                  toggleColumns(node.id)
                                }}
                                className="flex w-full items-center justify-center gap-1.5 px-2 py-1.5 text-[11px] text-muted-foreground hover:bg-muted/50 rounded transition-colors"
                              >
                                {expandedColumns.has(node.id) ? (
                                  <>
                                    <ChevronUp className="h-3 w-3" />
                                    {t('Show less')}
                                  </>
                                ) : (
                                  <>
                                    <ChevronDown className="h-3 w-3" />
                                    Show{' '}
                                    {node.columns.length -
                                      MAX_VISIBLE_COLUMNS}{' '}
                                    more
                                  </>
                                )}
                              </button>
                            )}
                          </div>
                        )}

                      </div>
                    </div>
                  </div>
                </ContextMenuTrigger>
                <ContextMenuContent>
                  <ContextMenuItem
                    onClick={() => {
                      setContextMenuTableId(node.id)
                      handleNavigateToTable(node.id)
                    }}
                  >
                    <Eye className="h-4 w-4 me-2" />
                    {t('View rows')}
                  </ContextMenuItem>
                  <ContextMenuSeparator />
                  <ContextMenuItem
                    onClick={() => {
                      setContextMenuTableId(node.id)
                      handleNavigateToSettings(node.id)
                    }}
                  >
                    <Settings className="h-4 w-4 me-2" />
                    {t('Table settings')}
                  </ContextMenuItem>
                </ContextMenuContent>
              </ContextMenu>
            ))}
          </div>
        </div>
      </div>

      {/* Minimap */}
      {showMinimap && nodes.length > 0 && minimapBounds && (
        <div className="absolute bottom-4 end-4 z-10 w-64 h-44 rounded-lg border border-border bg-card/95 backdrop-blur-sm overflow-hidden select-none">
          <div className="absolute top-0 start-0 end-0 h-8 bg-muted/50 border-b border-border flex items-center justify-between px-3">
            <span className="text-[12px] font-medium text-foreground flex items-center gap-2 select-none">
              <MapIcon className="h-4 w-4" />
              {t('Overview')}
            </span>
            <button
              onClick={() => setShowMinimap(false)}
              className="text-[14px] leading-none text-muted-foreground hover:text-foreground"
            >
              ×
            </button>
          </div>
          <div
            ref={minimapRef}
            className={cn(
              'relative w-full h-[calc(100%-32px)] mt-8 border select-none',
              isDarkMode
                ? 'bg-muted/80 border-border/60'
                : 'bg-[#e5e5e5] border-border',
            )}
          >
            {/* Minimap content */}
            <svg
              className="absolute inset-0 w-full h-full select-none"
              viewBox={`${minimapBounds.minX} ${minimapBounds.minY} ${minimapBounds.width} ${minimapBounds.height}`}
              preserveAspectRatio="xMidYMid meet"
              style={{ userSelect: 'none', WebkitUserSelect: 'none' }}
            >
              {/* Relationships */}
              {(() => {
                const colors = getThemeColors()
                return (
                  <>
                    {relationships.map((rel, index) => {
                      const fromPoint = getConnectionPoint(rel.from, 'right')
                      const toPoint = getConnectionPoint(rel.to, 'left')
                      // Use darker stroke in light mode for better visibility
                      const strokeColor = isDarkMode
                        ? colors.border
                        : colors.border || 'oklch(0.5 0.01 285)'
                      return (
                        <line
                          key={`minimap-rel-${index}`}
                          x1={fromPoint.x}
                          y1={fromPoint.y}
                          x2={toPoint.x}
                          y2={toPoint.y}
                          stroke={strokeColor}
                          strokeWidth="1.5"
                          opacity={isDarkMode ? '0.6' : '0.8'}
                        />
                      )
                    })}

                    {/* Table nodes */}
                    {nodes.map((node) => {
                      const isSelected = selectedTable === node.id
                      // Use brand pink color from logo (#FD366E)
                      const selectedColor = '#FD366E'
                      const borderColor = isSelected
                        ? selectedColor
                        : colors.border
                      // Use white text for selected nodes on pink background
                      const textColor = isSelected
                        ? '#ffffff'
                        : colors.foreground
                      return (
                        <g key={`minimap-${node.id}`}>
                          <rect
                            x={node.x}
                            y={node.y}
                            width={node.width}
                            height={node.height}
                            rx="6"
                            ry="6"
                            fill={isSelected ? selectedColor : colors.card}
                            stroke={borderColor}
                            strokeWidth={isSelected ? '3' : '2'}
                            className="cursor-pointer hover:opacity-90"
                            onClick={() => handleMinimapClick(node.id)}
                            style={{ userSelect: 'none' }}
                          />
                          <text
                            x={node.x + 4}
                            y={node.y + 12}
                            fontSize="8"
                            fill={textColor}
                            fontWeight="600"
                            className="pointer-events-none select-none"
                            style={{
                              userSelect: 'none',
                              WebkitUserSelect: 'none',
                            }}
                          >
                            {node.name.length > 15
                              ? node.name.substring(0, 15) + '...'
                              : node.name}
                          </text>
                        </g>
                      )
                    })}

                    {/* Viewport indicator */}
                    {viewportRect && (
                      <rect
                        x={viewportRect.left}
                        y={viewportRect.top}
                        width={viewportRect.width}
                        height={viewportRect.height}
                        fill="none"
                        stroke={colors.primary}
                        strokeWidth="2"
                        strokeDasharray="4 4"
                        opacity="0.6"
                      />
                    )}
                  </>
                )
              })()}
            </svg>
          </div>
        </div>
      )}

      {/* Minimap toggle button (when hidden) */}
      {!showMinimap && nodes.length > 0 && (
        <Button
          variant="outline"
          size="sm"
          className="absolute bottom-4 end-4 z-10 h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
          onClick={() => setShowMinimap(true)}
        >
          <MapIcon className="h-4 w-4" />
        </Button>
      )}

    </div>
  )
}
