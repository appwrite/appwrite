import type { CoverEditorThemeId } from '@/lib/cover-generator/themes'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'

export type DiagramNodeKind =
  | 'service'
  | 'title'
  | 'label'
  | 'group'
  | 'icon'
  | 'screenshot'
  | 'table'

export type DiagramEdgeLineStyle = 'solid' | 'dashed' | 'dotted'

export type DiagramEdgeArrow = 'forward' | 'both' | 'none'

export type DiagramEdgeStrokeTone = 'default' | 'muted' | 'accent' | 'purple'

export type DiagramAnchorSide = 'top' | 'right' | 'bottom' | 'left'

export type DiagramNode = {
  id: string
  kind: DiagramNodeKind
  x: number
  y: number
  width: number
  height: number
  label: string
  subtitle?: string
  /** Service and icon elements: cover icon path or `lucide:*` value. */
  iconSrc?: string
  /** Screenshot element: uploaded image data URL. */
  imageSrc?: string
  /** Screenshot element: horizontal crop focus (0 = left, 100 = right). */
  focusX?: number
  /** Screenshot element: vertical crop focus (0 = top, 100 = bottom). */
  focusY?: number
  /** Table element: column headers. */
  tableHeaders?: string[]
  /** Table element: body rows aligned to headers. */
  tableRows?: string[][]
}

export type DiagramEdge = {
  id: string
  fromNodeId: string
  toNodeId: string
  fromSide: DiagramAnchorSide
  toSide: DiagramAnchorSide
  lineStyle: DiagramEdgeLineStyle
  arrow: DiagramEdgeArrow
  strokeTone: DiagramEdgeStrokeTone
  label?: string
}

export type DiagramDocument = {
  title: string
  theme: CoverEditorThemeId
  width: number
  height: number
  format: CoverImageFormat
  nodes: DiagramNode[]
  edges: DiagramEdge[]
}

export type DiagramSelection =
  | { type: 'none' }
  | { type: 'node'; id: string }
  | { type: 'nodes'; ids: string[] }
  | { type: 'edge'; id: string }

export type DiagramConnectDraft = {
  nodeId: string
  side: DiagramAnchorSide
}

export type DiagramTemplateId =
  | 'blank'
  | 'three-tier'
  | 'serverless'
  | 'realtime-flow'
  | 'appwrite-platform'
  | 'appwrite-architecture'
  | 'appwrite-auth'
  | 'appwrite-storage'
  | 'appwrite-messaging'
