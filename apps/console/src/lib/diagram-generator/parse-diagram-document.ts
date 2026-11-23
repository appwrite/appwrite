import { normalizeDiagramEdge } from '@/lib/diagram-generator/edge-appearance'
import { normalizeDiagramNode } from '@/lib/diagram-generator/node-normalize'
import { resolveDiagramCanvasSize } from '@/lib/diagram-generator/storage'
import { createDefaultDiagramDocument } from '@/lib/diagram-generator/templates'
import type { DiagramDocument, DiagramEdge, DiagramNode } from '@/lib/diagram-generator/types'
import { resolveCoverEditorThemeId } from '@/lib/cover-generator/themes'

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function normalizeDiagramDocumentInput(document: DiagramDocument): DiagramDocument {
  const size = resolveDiagramCanvasSize(document.width, document.height)

  return {
    title: typeof document.title === 'string' ? document.title.slice(0, 120) : 'Untitled diagram',
    theme: resolveCoverEditorThemeId(document.theme),
    width: size.width,
    height: size.height,
    format: document.format === 'jpeg' || document.format === 'avif' ? document.format : 'png',
    nodes: document.nodes.map((node) => normalizeDiagramNode(node)),
    edges: document.edges.map((edge) => normalizeDiagramEdge(edge)),
  }
}

export function parseDiagramDocumentFromJson(body: unknown): DiagramDocument {
  if (!isRecord(body)) {
    throw new Error('Invalid diagram payload')
  }

  const defaults = createDefaultDiagramDocument()
  const nodes = Array.isArray(body.nodes) ? (body.nodes as DiagramNode[]) : defaults.nodes
  const edges = Array.isArray(body.edges) ? (body.edges as DiagramEdge[]) : defaults.edges

  return normalizeDiagramDocumentInput({
    ...defaults,
    title: typeof body.title === 'string' ? body.title : defaults.title,
    theme: resolveCoverEditorThemeId(
      typeof body.theme === 'string' ? body.theme : defaults.theme,
    ),
    width: typeof body.width === 'number' ? body.width : defaults.width,
    height: typeof body.height === 'number' ? body.height : defaults.height,
    format:
      body.format === 'jpeg' || body.format === 'png' || body.format === 'avif'
        ? body.format
        : defaults.format,
    nodes,
    edges,
  })
}
