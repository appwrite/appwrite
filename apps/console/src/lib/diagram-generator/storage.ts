import { DIAGRAM_STORAGE_KEY } from '@/lib/diagram-generator/constants'
import { normalizeDiagramEdge } from '@/lib/diagram-generator/edge-appearance'
import { normalizeDiagramNode } from '@/lib/diagram-generator/node-normalize'
import { createDefaultDiagramDocument } from '@/lib/diagram-generator/templates'
import type { DiagramDocument, DiagramEdge, DiagramNode } from '@/lib/diagram-generator/types'
import { resolveCoverEditorThemeId } from '@/lib/cover-generator/themes'
import { resolveDiagramSizePresetKey } from '@/lib/diagram-generator/constants'

type StoredDiagramState = {
  version: 1
  document: DiagramDocument
}

export function normalizeDiagramDocument(document: DiagramDocument): DiagramDocument {
  return {
    ...document,
    theme: resolveCoverEditorThemeId(document.theme),
    format: document.format === 'jpeg' || document.format === 'avif' ? document.format : 'png',
    nodes: Array.isArray(document.nodes)
      ? document.nodes.map((node) => normalizeDiagramNode(node as DiagramNode))
      : [],
    edges: Array.isArray(document.edges)
      ? document.edges.map((edge) => normalizeDiagramEdge(edge as DiagramEdge))
      : [],
  }
}

export function readDiagramGeneratorState(): DiagramDocument {
  if (typeof window === 'undefined') return createDefaultDiagramDocument()

  try {
    const raw = window.localStorage.getItem(DIAGRAM_STORAGE_KEY)
    if (!raw) return createDefaultDiagramDocument()

    const parsed = JSON.parse(raw) as StoredDiagramState
    if (parsed?.version !== 1 || !parsed.document) {
      return createDefaultDiagramDocument()
    }

    return normalizeDiagramDocument(parsed.document)
  } catch {
    return createDefaultDiagramDocument()
  }
}

export function writeDiagramGeneratorState(document: DiagramDocument): void {
  if (typeof window === 'undefined') return

  const payload: StoredDiagramState = {
    version: 1,
    document: normalizeDiagramDocument(document),
  }

  window.localStorage.setItem(DIAGRAM_STORAGE_KEY, JSON.stringify(payload))
}

export function resolveDiagramCanvasSize(width: number, height: number): {
  width: number
  height: number
} {
  if (
    Number.isFinite(width) &&
    Number.isFinite(height) &&
    width >= 320 &&
    width <= 4096 &&
    height >= 200 &&
    height <= 4096
  ) {
    return { width: Math.round(width), height: Math.round(height) }
  }

  return resolveDiagramSizePresetKey('og')
}

export function getDiagramDisplayHeight(width: number, height: number): number {
  return Math.round((height / width) * 720)
}
