import {
  buildCoverApiDocsMarkdown,
  type CoverApiDocsContext,
} from '@/lib/cover-generator/api-docs'
import {
  COVER_HEIGHT,
  COVER_IMAGE_FORMATS,
  COVER_WIDTH,
} from '@/lib/cover-generator/constants'
import { COVER_THEME_IDS } from '@/lib/cover-generator/themes'
import { createDiagramFromTemplate } from '@/lib/diagram-generator/templates'
import type { DiagramDocument, DiagramTemplateId } from '@/lib/diagram-generator/types'
import {
  formatApiParametersMarkdownTable,
  formatApiResponseMarkdown,
  GENERATOR_API_QUERY_PARAMETERS,
  type GeneratorApiParameterDoc,
} from '@/lib/generator/api-docs-shared'
import { GENERATOR_DIAGRAM_API_PATH } from '@/lib/generator/constants'

export const DIAGRAM_API_PATH = GENERATOR_DIAGRAM_API_PATH

export type DiagramApiParameterDoc = GeneratorApiParameterDoc

export type DiagramApiDocsContext = {
  origin: string
  endpointUrl: string
  queryParameters: DiagramApiParameterDoc[]
  documentParameters: DiagramApiParameterDoc[]
  nodeParameters: DiagramApiParameterDoc[]
  edgeParameters: DiagramApiParameterDoc[]
  parameters: DiagramApiParameterDoc[]
  payload: DiagramDocument
  displayPayload: DiagramDocument
  curlPost: string
  curlPostDownload: string
  fetchExample: string
  fetchDownloadExample: string
}

const DIAGRAM_NODE_KINDS = [
  'service',
  'title',
  'label',
  'group',
  'icon',
  'screenshot',
  'table',
] as const

const DIAGRAM_EDGE_LINE_STYLES = ['solid', 'dashed', 'dotted'] as const
const DIAGRAM_EDGE_ARROWS = ['forward', 'both', 'none'] as const
const DIAGRAM_EDGE_STROKE_TONES = ['default', 'muted', 'accent', 'purple'] as const
const DIAGRAM_ANCHOR_SIDES = ['top', 'right', 'bottom', 'left'] as const

function shellQuote(value: string): string {
  if (/^[A-Za-z0-9_./:?&=%#@+-]+$/.test(value)) return value
  return `'${value.replace(/'/g, `'\\''`)}'`
}

function sanitizeDiagramDocumentForDisplay(document: DiagramDocument): DiagramDocument {
  return {
    ...document,
    nodes: document.nodes.map((node) => ({
      ...node,
      imageSrc: node.imageSrc?.startsWith('data:') ? 'data:image/...;base64,...' : node.imageSrc,
    })),
  }
}

function buildDiagramDocumentParameterDocs(): DiagramApiParameterDoc[] {
  return [
    {
      name: 'title',
      type: 'string',
      description: 'Diagram title used in exports and downloaded filenames.',
    },
    {
      name: 'theme',
      type: 'string',
      description: 'Background theme shared with the cover generator.',
      enumValues: COVER_THEME_IDS,
      defaultValue: 'dark',
    },
    {
      name: 'format',
      type: 'string',
      description: 'Output image format returned by the API.',
      enumValues: COVER_IMAGE_FORMATS,
      defaultValue: 'png',
    },
    {
      name: 'width',
      type: 'number',
      description: 'Artboard width in pixels.',
      defaultValue: String(COVER_WIDTH),
      example: '1200',
    },
    {
      name: 'height',
      type: 'number',
      description: 'Artboard height in pixels.',
      defaultValue: String(COVER_HEIGHT),
      example: '630',
    },
    {
      name: 'nodes',
      type: 'array',
      required: true,
      description: 'Elements positioned on the artboard. Order does not affect rendering.',
    },
    {
      name: 'edges',
      type: 'array',
      description: 'Connections between nodes. Each edge references node ids from the nodes array.',
    },
  ]
}

function buildDiagramNodeParameterDocs(): DiagramApiParameterDoc[] {
  return [
    {
      name: 'nodes[].id',
      type: 'string',
      required: true,
      description: 'Unique node id referenced by edges.',
    },
    {
      name: 'nodes[].kind',
      type: 'string',
      required: true,
      description: 'Element type.',
      enumValues: DIAGRAM_NODE_KINDS,
    },
    {
      name: 'nodes[].x',
      type: 'number',
      required: true,
      description: 'Left position in pixels from the artboard origin.',
    },
    {
      name: 'nodes[].y',
      type: 'number',
      required: true,
      description: 'Top position in pixels from the artboard origin.',
    },
    {
      name: 'nodes[].width',
      type: 'number',
      required: true,
      description: 'Node width in pixels.',
    },
    {
      name: 'nodes[].height',
      type: 'number',
      required: true,
      description: 'Node height in pixels.',
    },
    {
      name: 'nodes[].label',
      type: 'string',
      required: true,
      description: 'Primary label shown on the element.',
    },
    {
      name: 'nodes[].subtitle',
      type: 'string',
      description: 'Optional secondary line for service and title elements.',
    },
    {
      name: 'nodes[].iconSrc',
      type: 'string',
      description:
        'Optional icon for service and icon elements. Accepts /icons paths, lucide:icon-name values, or data URIs.',
    },
    {
      name: 'nodes[].imageSrc',
      type: 'string',
      description: 'Screenshot element image URL or data URI. Use POST for data URIs.',
    },
    {
      name: 'nodes[].focusX',
      type: 'number',
      description: 'Screenshot crop focus on the X axis. 0 is left, 100 is right.',
      defaultValue: '50',
    },
    {
      name: 'nodes[].focusY',
      type: 'number',
      description: 'Screenshot crop focus on the Y axis. 0 is top, 100 is bottom.',
      defaultValue: '50',
    },
    {
      name: 'nodes[].tableHeaders',
      type: 'string[]',
      description: 'Table element column headers.',
    },
    {
      name: 'nodes[].tableRows',
      type: 'string[][]',
      description: 'Table element body rows aligned to tableHeaders.',
    },
  ]
}

function buildDiagramEdgeParameterDocs(): DiagramApiParameterDoc[] {
  return [
    {
      name: 'edges[].id',
      type: 'string',
      required: true,
      description: 'Unique edge id.',
    },
    {
      name: 'edges[].fromNodeId',
      type: 'string',
      required: true,
      description: 'Source node id.',
    },
    {
      name: 'edges[].toNodeId',
      type: 'string',
      required: true,
      description: 'Target node id.',
    },
    {
      name: 'edges[].fromSide',
      type: 'string',
      description: 'Anchor side on the source node.',
      enumValues: DIAGRAM_ANCHOR_SIDES,
      defaultValue: 'right',
    },
    {
      name: 'edges[].toSide',
      type: 'string',
      description: 'Anchor side on the target node.',
      enumValues: DIAGRAM_ANCHOR_SIDES,
      defaultValue: 'left',
    },
    {
      name: 'edges[].lineStyle',
      type: 'string',
      description: 'Connection line style.',
      enumValues: DIAGRAM_EDGE_LINE_STYLES,
      defaultValue: 'solid',
    },
    {
      name: 'edges[].arrow',
      type: 'string',
      description: 'Arrow direction.',
      enumValues: DIAGRAM_EDGE_ARROWS,
      defaultValue: 'forward',
    },
    {
      name: 'edges[].strokeTone',
      type: 'string',
      description: 'Line color tone.',
      enumValues: DIAGRAM_EDGE_STROKE_TONES,
      defaultValue: 'default',
    },
    {
      name: 'edges[].label',
      type: 'string',
      description: 'Optional connection label rendered on the edge path.',
    },
  ]
}

export function buildDiagramApiParameterDocs(): DiagramApiParameterDoc[] {
  return [
    ...buildDiagramDocumentParameterDocs(),
    ...buildDiagramNodeParameterDocs(),
    ...buildDiagramEdgeParameterDocs(),
  ]
}

export function buildDefaultDiagramReferenceDocument(
  templateId: DiagramTemplateId = 'three-tier',
): DiagramDocument {
  return createDiagramFromTemplate(templateId)
}

export function buildDiagramApiEndpointUrl(origin = ''): string {
  const normalizedOrigin = origin.replace(/\/+$/, '')
  return normalizedOrigin ? `${normalizedOrigin}${DIAGRAM_API_PATH}` : DIAGRAM_API_PATH
}

export function buildDiagramApiCurlPost(
  document: DiagramDocument,
  origin = '',
  options?: { download?: boolean },
): string {
  const endpoint = buildDiagramApiEndpointUrl(origin)
  const url = options?.download ? `${endpoint}?disposition=attachment` : endpoint
  const body = JSON.stringify(document)
  return [
    `curl -X POST ${shellQuote(url)} \\`,
    `  -H ${shellQuote('Content-Type: application/json')} \\`,
    `  -d ${shellQuote(body)}`,
  ].join('\n')
}

export function buildDiagramApiFetchExample(
  document: DiagramDocument,
  origin = '',
  options?: { download?: boolean },
): string {
  const endpoint = buildDiagramApiEndpointUrl(origin)
  const url = options?.download ? `${endpoint}?disposition=attachment` : endpoint
  const payload = JSON.stringify(document, null, 2)
  return [
    `const response = await fetch(${JSON.stringify(url)}, {`,
    `  method: 'POST',`,
    `  headers: { 'Content-Type': 'application/json' },`,
    `  body: JSON.stringify(${payload.replace(/\n/g, '\n  ')}),`,
    `})`,
    '',
    `if (!response.ok) {`,
    `  throw new Error(\`Diagram render failed (\${response.status})\`)`,
    `}`,
    '',
    `const blob = await response.blob()`,
  ].join('\n')
}

export function buildDiagramApiDocsContext(
  document: DiagramDocument,
  origin = '',
): DiagramApiDocsContext {
  return {
    origin,
    endpointUrl: buildDiagramApiEndpointUrl(origin),
    queryParameters: GENERATOR_API_QUERY_PARAMETERS,
    documentParameters: buildDiagramDocumentParameterDocs(),
    nodeParameters: buildDiagramNodeParameterDocs(),
    edgeParameters: buildDiagramEdgeParameterDocs(),
    parameters: buildDiagramApiParameterDocs(),
    payload: document,
    displayPayload: sanitizeDiagramDocumentForDisplay(document),
    curlPost: buildDiagramApiCurlPost(document, origin),
    curlPostDownload: buildDiagramApiCurlPost(document, origin, { download: true }),
    fetchExample: buildDiagramApiFetchExample(document, origin),
    fetchDownloadExample: buildDiagramApiFetchExample(document, origin, { download: true }),
  }
}

export function buildDiagramApiDocsMarkdown(docs: DiagramApiDocsContext): string {
  const payloadJson = JSON.stringify(docs.displayPayload, null, 2)

  return [
    '# Diagram generator API',
    '',
    'Render PNG, JPEG, or AVIF architecture diagrams from a JSON document. The API is available on deployments with the marketing profile enabled.',
    '',
    'Send the full diagram document as JSON. This includes canvas settings, positioned nodes, and edge connections.',
    '',
    '## Endpoint',
    '',
    '```http',
    `POST ${docs.endpointUrl}`,
    '```',
    '',
    '## Method',
    '',
    'POST accepts a full diagram document as JSON. Add `?disposition=attachment` to download the file instead of returning it inline.',
    '',
    '## Query parameters',
    '',
    formatApiParametersMarkdownTable(docs.queryParameters),
    '',
    '## Document fields',
    '',
    formatApiParametersMarkdownTable(docs.documentParameters),
    '',
    '## Node fields',
    '',
    formatApiParametersMarkdownTable(docs.nodeParameters),
    '',
    '## Edge fields',
    '',
    formatApiParametersMarkdownTable(docs.edgeParameters),
    '',
    '## Example for current diagram',
    '',
    'Generated from the values in the editor.',
    '',
    '### Current JSON body',
    '',
    '```json',
    payloadJson,
    '```',
    '',
    '### cURL (POST)',
    '',
    '```bash',
    docs.curlPost,
    '```',
    '',
    '### cURL (POST download)',
    '',
    '```bash',
    docs.curlPostDownload,
    '```',
    '',
    '### fetch (POST)',
    '',
    '```javascript',
    docs.fetchExample,
    '```',
    '',
    '### fetch (POST download)',
    '',
    '```javascript',
    docs.fetchDownloadExample,
    '```',
    '',
    '## Response',
    '',
    formatApiResponseMarkdown(),
    '',
  ].join('\n')
}

export function buildGeneratorApiDocsMarkdown(
  coverDocs: CoverApiDocsContext | null,
  diagramDocs: DiagramApiDocsContext | null,
): string {
  const sections: string[] = [
    '# Generator API',
    '',
    'Programmatic rendering for cover images and architecture diagrams.',
    '',
  ]

  if (coverDocs) {
    sections.push(
      '## Covers',
      '',
      buildCoverApiDocsMarkdown(coverDocs).replace(/^# Cover generator API\n\n/, ''),
    )
  }

  if (diagramDocs) {
    if (coverDocs) sections.push('', '---', '')
    sections.push(
      '## Diagrams',
      '',
      buildDiagramApiDocsMarkdown(diagramDocs).replace(/^# Diagram generator API\n\n/, ''),
    )
  }

  return sections.join('\n')
}
