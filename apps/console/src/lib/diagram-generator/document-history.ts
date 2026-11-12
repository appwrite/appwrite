import type { DiagramDocument } from '@/lib/diagram-generator/types'

export const DIAGRAM_HISTORY_LIMIT = 50

export function cloneDiagramDocument(document: DiagramDocument): DiagramDocument {
  return structuredClone(document)
}

export function diagramDocumentsEqual(
  left: DiagramDocument,
  right: DiagramDocument,
): boolean {
  return JSON.stringify(left) === JSON.stringify(right)
}
