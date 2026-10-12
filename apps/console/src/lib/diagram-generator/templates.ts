import {
  DIAGRAM_NODE_DEFAULTS,
  DIAGRAM_NODE_KIND_LABELS,
  DIAGRAM_SNAP_GRID,
  DEFAULT_DIAGRAM_ICON,
} from '@/lib/diagram-generator/constants'
import type {
  DiagramDocument,
  DiagramEdge,
  DiagramNode,
  DiagramTemplateId,
} from '@/lib/diagram-generator/types'
import { createAppwriteArchitectureDiagram } from '@/lib/diagram-generator/appwrite-architecture-template'
import { getDefaultDiagramEdgeAppearance } from '@/lib/diagram-generator/edge-appearance'
import type { DiagramEdgeAppearance } from '@/lib/diagram-generator/edge-appearance'
import { formatCoverLucideIconValue } from '@/lib/cover-generator/lucide-icon-utils'

import {
  createDefaultDiagramDocument,
  createDiagramEdge,
  createDiagramNode,
  snapDiagramValue,
} from '@/lib/diagram-generator/diagram-factory'

export {
  createDefaultDiagramDocument,
  createDiagramEdge,
  createDiagramNode,
  snapDiagramValue,
} from '@/lib/diagram-generator/diagram-factory'

type DiagramEdgePresetId =
  | 'request'
  | 'response'
  | 'event'
  | 'data'
  | 'stream'
  | 'link'

const DIAGRAM_TEMPLATE_EDGE_PRESETS: Record<DiagramEdgePresetId, DiagramEdgeAppearance> = {
  request: { lineStyle: 'solid', arrow: 'forward', strokeTone: 'default' },
  response: { lineStyle: 'dashed', arrow: 'forward', strokeTone: 'muted' },
  event: { lineStyle: 'dashed', arrow: 'forward', strokeTone: 'muted' },
  data: { lineStyle: 'dotted', arrow: 'forward', strokeTone: 'purple' },
  stream: { lineStyle: 'solid', arrow: 'both', strokeTone: 'accent' },
  link: { lineStyle: 'dotted', arrow: 'none', strokeTone: 'muted' },
}

function diagramEdge(
  fromNode: DiagramNode,
  toNode: DiagramNode,
  preset: DiagramEdgePresetId,
  overrides?: Partial<
    Pick<
      DiagramEdge,
      'label' | 'fromSide' | 'toSide' | 'lineStyle' | 'arrow' | 'strokeTone'
    >
  >,
): DiagramEdge {
  return createDiagramEdge(fromNode, toNode, {
    ...DIAGRAM_TEMPLATE_EDGE_PRESETS[preset],
    ...overrides,
  })
}

function diagramElement(
  x: number,
  y: number,
  label: string,
  icon?: string | typeof DEFAULT_DIAGRAM_ICON,
): DiagramNode {
  let iconSrc: string | undefined
  if (icon === DEFAULT_DIAGRAM_ICON) {
    iconSrc = DEFAULT_DIAGRAM_ICON
  } else if (icon) {
    iconSrc = formatCoverLucideIconValue(icon)
  }

  return createDiagramNode('service', { x, y }, {
    label,
    ...(iconSrc ? { iconSrc } : {}),
  })
}

const DIAGRAM_TITLE_STACK_GAP = 16
/** Space between the title block and the diagram content or group below. */
const DIAGRAM_TITLE_TO_CONTENT_GAP = 72
/** Legacy offset used while building template node coordinates. */
const DIAGRAM_TITLE_GAP = DIAGRAM_TITLE_TO_CONTENT_GAP

function diagramTitle(
  label: string,
  subtitle?: string,
  width = 720,
): DiagramNode {
  return createDiagramNode('title', { x: 0, y: 0 }, {
    label,
    subtitle,
    width,
    height: subtitle ? 88 : 56,
  })
}

function diagramTitleAt(
  x: number,
  y: number,
  label: string,
  subtitle?: string,
  width = 720,
): DiagramNode {
  return createDiagramNode('title', { x, y }, {
    label,
    subtitle,
    width,
    height: subtitle ? 88 : 56,
  })
}

/** Place a heading above template content and shift nodes down. */
function withDiagramHeading(
  label: string,
  nodes: DiagramNode[],
  subtitle?: string,
): DiagramNode[] {
  const heading = diagramTitle(label, subtitle)
  const offset = heading.height + DIAGRAM_TITLE_GAP

  return [
    heading,
    ...nodes.map((node) => ({
      ...node,
      y: snapDiagramValue(node.y + offset),
    })),
  ]
}

const DIAGRAM_GROUP_PADDING = 32
const DIAGRAM_GROUP_LABEL_BAND = 36
const DIAGRAM_TEMPLATE_MARGIN = 56

function getNodesBoundingBox(nodes: DiagramNode[]) {
  const minX = Math.min(...nodes.map((node) => node.x))
  const minY = Math.min(...nodes.map((node) => node.y))
  const maxX = Math.max(...nodes.map((node) => node.x + node.width))
  const maxY = Math.max(...nodes.map((node) => node.y + node.height))

  return {
    minX,
    minY,
    maxX,
    maxY,
    width: maxX - minX,
    height: maxY - minY,
  }
}

/** Scale template node spacing from the content center so layouts fill the artboard. */
function fitNodesToArtboard(
  nodes: DiagramNode[],
  artboardWidth: number,
  artboardHeight: number,
  margin = DIAGRAM_TEMPLATE_MARGIN,
  reservedTop = 0,
): DiagramNode[] {
  if (nodes.length === 0) return nodes

  const { minX, minY, width: contentWidth, height: contentHeight } =
    getNodesBoundingBox(nodes)
  const availableWidth = artboardWidth - margin * 2
  const availableHeight = artboardHeight - margin * 2 - reservedTop

  if (contentWidth <= 0 || contentHeight <= 0 || availableWidth <= 0 || availableHeight <= 0) {
    return nodes
  }

  const scaleX = availableWidth / contentWidth
  const scaleY = availableHeight / contentHeight
  const contentCenterX = minX + contentWidth / 2
  const contentCenterY = minY + contentHeight / 2
  const artboardCenterX = artboardWidth / 2
  const artboardCenterY = margin + reservedTop + availableHeight / 2

  return nodes.map((node) => {
    const nodeCenterX = node.x + node.width / 2
    const nodeCenterY = node.y + node.height / 2
    const newCenterX = artboardCenterX + (nodeCenterX - contentCenterX) * scaleX
    const newCenterY = artboardCenterY + (nodeCenterY - contentCenterY) * scaleY

    return {
      ...node,
      x: snapDiagramValue(newCenterX - node.width / 2),
      y: snapDiagramValue(newCenterY - node.height / 2),
    }
  })
}

function getDiagramTitleBlockHeight(titleNodes: DiagramNode[]): number {
  if (titleNodes.length === 0) return 0

  const titlesHeight = titleNodes.reduce((total, node) => total + node.height, 0)
  const stackGaps = DIAGRAM_TITLE_STACK_GAP * Math.max(0, titleNodes.length - 1)

  return (
    DIAGRAM_TEMPLATE_MARGIN +
    titlesHeight +
    stackGaps +
    DIAGRAM_TITLE_TO_CONTENT_GAP
  )
}

function positionDiagramTitlesAboveContent(
  titleNodes: DiagramNode[],
  bodyNodes: DiagramNode[],
  artboardWidth: number,
  margin = DIAGRAM_TEMPLATE_MARGIN,
  anchorTop?: number,
): DiagramNode[] {
  if (titleNodes.length === 0) return []

  const titleWidth = snapDiagramValue(
    Math.min(720, artboardWidth - margin * 2),
  )
  const contentTop =
    anchorTop ??
    (bodyNodes.length > 0
      ? getNodesBoundingBox(bodyNodes).minY
      : margin + DIAGRAM_TITLE_TO_CONTENT_GAP)
  let cursor = contentTop - DIAGRAM_TITLE_TO_CONTENT_GAP

  return [...titleNodes].reverse().map((title) => {
    const y = snapDiagramValue(cursor - title.height)
    cursor = y - DIAGRAM_TITLE_STACK_GAP

    return {
      ...title,
      width: titleWidth,
      x: snapDiagramValue((artboardWidth - titleWidth) / 2),
      y,
    }
  }).reverse()
}

function splitDiagramTitleNodes(contentNodes: DiagramNode[]) {
  return {
    titleNodes: contentNodes.filter((node) => node.kind === 'title'),
    bodyNodes: contentNodes.filter((node) => node.kind !== 'title'),
  }
}

function createDiagramGroupFromNodes(
  label: string,
  nodes: DiagramNode[],
): DiagramNode {
  const minX = Math.min(...nodes.map((node) => node.x))
  const minY = Math.min(...nodes.map((node) => node.y))
  const maxX = Math.max(...nodes.map((node) => node.x + node.width))
  const maxY = Math.max(...nodes.map((node) => node.y + node.height))

  return createDiagramNode('group', {
    x: snapDiagramValue(minX - DIAGRAM_GROUP_PADDING),
    y: snapDiagramValue(minY - DIAGRAM_GROUP_PADDING - DIAGRAM_GROUP_LABEL_BAND),
  }, {
    label,
    width: snapDiagramValue(
      maxX - minX + DIAGRAM_GROUP_PADDING * 2,
    ),
    height: snapDiagramValue(
      maxY - minY + DIAGRAM_GROUP_PADDING * 2 + DIAGRAM_GROUP_LABEL_BAND,
    ),
  })
}

function finalizeTemplateDocument(
  base: DiagramDocument,
  title: string,
  contentNodes: DiagramNode[],
  edges: DiagramEdge[],
  groupLabel?: string,
  options?: { fit?: boolean },
): DiagramDocument {
  const { titleNodes, bodyNodes } = splitDiagramTitleNodes(contentNodes)
  const reservedTop = getDiagramTitleBlockHeight(titleNodes)
  const fittedBody =
    options?.fit === false
      ? bodyNodes
      : fitNodesToArtboard(bodyNodes, base.width, base.height, DIAGRAM_TEMPLATE_MARGIN, reservedTop)
  const groupNode = groupLabel
    ? createDiagramGroupFromNodes(groupLabel, fittedBody)
    : undefined
  const positionedTitles = positionDiagramTitlesAboveContent(
    titleNodes,
    fittedBody,
    base.width,
    DIAGRAM_TEMPLATE_MARGIN,
    groupNode?.y,
  )
  const contentLayer = groupNode
    ? [groupNode, ...fittedBody]
    : fittedBody

  return {
    ...base,
    title,
    nodes: [...contentLayer, ...positionedTitles],
    edges,
  }
}

export function createDiagramFromTemplate(templateId: DiagramTemplateId): DiagramDocument {
  const base = createDefaultDiagramDocument()

  switch (templateId) {
    case 'blank':
      return base

    case 'three-tier': {
      const client = diagramElement(0, 0, 'Client app')
      const api = diagramElement(360, 0, 'API', 'server')
      const database = diagramElement(720, 0, 'Database', 'database')
      return finalizeTemplateDocument(
        base,
        'Three-tier architecture',
        withDiagramHeading('Three-tier architecture', [client, api, database], 'Client, API, and database'),
        [
          diagramEdge(client, api, 'request', { label: 'HTTPS' }),
          diagramEdge(api, database, 'data', { label: 'SQL' }),
        ],
      )
    }

    case 'serverless': {
      const client = diagramElement(0, 48, 'Client app')
      const functions = diagramElement(300, 0, 'Functions', 'zap')
      const database = diagramElement(660, 0, 'Database', 'database')
      const storage = diagramElement(660, 180, 'Storage', 'hard-drive')
      return finalizeTemplateDocument(
        base,
        'Serverless stack',
        withDiagramHeading('Serverless stack', [client, functions, database, storage], 'Functions with data and storage'),
        [
          diagramEdge(client, functions, 'request', { label: 'Invoke' }),
          diagramEdge(functions, database, 'data', { label: 'SQL' }),
          diagramEdge(functions, storage, 'event', { label: 'Files' }),
        ],
      )
    }

    case 'realtime-flow': {
      const client = diagramElement(0, 0, 'Client app')
      const realtime = diagramElement(340, 0, 'Realtime', 'radio')
      const database = diagramElement(700, 0, 'Database', 'database')
      return finalizeTemplateDocument(
        base,
        'Realtime flow',
        withDiagramHeading('Realtime flow', [client, realtime, database], 'Subscribe and event flow'),
        [
          diagramEdge(client, realtime, 'stream', { label: 'WebSocket' }),
          diagramEdge(realtime, database, 'event', { label: 'Events' }),
        ],
        'Realtime data flow',
      )
    }

    case 'appwrite-architecture':
      return createAppwriteArchitectureDiagram()

    case 'appwrite-platform': {
      const heading = diagramTitleAt(
        240,
        48,
        'Appwrite platform',
        'Clients, API, and core project services',
      )
      const webApp = diagramElement(64, 248, 'Web app', 'monitor')
      const mobileApp = diagramElement(64, 408, 'Mobile app', 'smartphone')
      const appwrite = diagramElement(352, 328, 'Appwrite', DEFAULT_DIAGRAM_ICON)
      const auth = diagramElement(640, 248, 'Auth', 'key-round')
      const storage = diagramElement(640, 408, 'Storage', 'hard-drive')
      const database = diagramElement(928, 248, 'Database', 'database')
      const functions = diagramElement(928, 408, 'Functions', 'zap')
      return finalizeTemplateDocument(
        base,
        'Appwrite platform',
        [heading, webApp, mobileApp, appwrite, auth, database, storage, functions],
        [
          diagramEdge(webApp, appwrite, 'request', {
            label: 'HTTPS',
            fromSide: 'right',
            toSide: 'left',
          }),
          diagramEdge(mobileApp, appwrite, 'request', {
            label: 'HTTPS',
            fromSide: 'right',
            toSide: 'left',
          }),
          diagramEdge(appwrite, auth, 'request', {
            label: 'REST',
            strokeTone: 'accent',
            fromSide: 'right',
            toSide: 'left',
          }),
          diagramEdge(appwrite, storage, 'event', {
            label: 'REST',
            fromSide: 'right',
            toSide: 'left',
          }),
          diagramEdge(auth, database, 'data', {
            fromSide: 'right',
            toSide: 'left',
          }),
          diagramEdge(storage, functions, 'response', {
            fromSide: 'right',
            toSide: 'left',
          }),
        ],
        'Appwrite project',
        { fit: false },
      )
    }

    case 'appwrite-auth': {
      const client = diagramElement(0, 24, 'Client app')
      const auth = diagramElement(320, 24, 'Auth', 'key-round')
      const usersTable = createDiagramNode('table', { x: 640, y: 0 }, {
        label: 'Users',
        width: 280,
        height: 176,
        tableHeaders: ['$id', 'email', 'name'],
        tableRows: [
          ['user_1', 'user@example.com', 'Alex'],
          ['user_2', 'team@example.com', 'Sam'],
        ],
      })
      return finalizeTemplateDocument(
        base,
        'Appwrite auth',
        withDiagramHeading('Appwrite auth', [client, auth, usersTable], 'Sign in, sessions, and users table'),
        [
          diagramEdge(client, auth, 'request', { label: 'Sign in' }),
          diagramEdge(auth, usersTable, 'data', { label: 'Sessions' }),
        ],
        'Authentication',
      )
    }

    case 'appwrite-storage': {
      const client = diagramElement(0, 80, 'Client app')
      const storage = diagramElement(256, 80, 'Storage', 'hard-drive')
      const functions = diagramElement(528, 0, 'Functions', 'zap')
      const database = diagramElement(528, 192, 'Database', 'database')
      return finalizeTemplateDocument(
        base,
        'Appwrite storage',
        withDiagramHeading('Appwrite storage', [client, storage, functions, database], 'Uploads, functions, and file metadata'),
        [
          diagramEdge(client, storage, 'request', { label: 'Upload' }),
          diagramEdge(storage, functions, 'event', { label: 'Event' }),
          diagramEdge(storage, database, 'data', { label: 'Metadata' }),
          diagramEdge(functions, database, 'response', { label: 'Update' }),
        ],
        'File storage',
      )
    }

    case 'appwrite-messaging': {
      const functions = diagramElement(0, 80, 'Functions', 'zap')
      const messaging = diagramElement(288, 80, 'Messaging', 'mail')
      const email = diagramElement(608, 0, 'Email provider', 'mail')
      const push = diagramElement(608, 192, 'Push provider', 'bell')
      return finalizeTemplateDocument(
        base,
        'Appwrite messaging',
        withDiagramHeading('Appwrite messaging', [functions, messaging, email, push], 'Function triggers to email and push'),
        [
          diagramEdge(functions, messaging, 'event', { label: 'Trigger' }),
          diagramEdge(messaging, email, 'request', {
            label: 'Send email',
            strokeTone: 'accent',
          }),
          diagramEdge(messaging, push, 'link', { label: 'Send push' }),
        ],
        'Messaging',
      )
    }
  }
}

export function getDiagramPlacementOffset(index: number): number {
  return (index % 6) * DIAGRAM_SNAP_GRID
}

export function getDiagramNodeCenterPlacement(
  document: Pick<DiagramDocument, 'width' | 'height'>,
  node: Pick<DiagramNode, 'width' | 'height'>,
  index = 0,
): { x: number; y: number } {
  const offset = getDiagramPlacementOffset(index)
  return {
    x: snapDiagramValue((document.width - node.width) / 2 + offset),
    y: snapDiagramValue((document.height - node.height) / 2 + offset),
  }
}
