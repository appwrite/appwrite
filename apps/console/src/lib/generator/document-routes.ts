export const GENERATOR_COVER_INDEX_ROUTE = '/generator' as const
export const GENERATOR_COVER_EDITOR_ROUTE = '/generator/$generationId' as const
export const GENERATOR_DIAGRAM_INDEX_ROUTE = '/generator/diagrams' as const
export const GENERATOR_DIAGRAM_EDITOR_ROUTE = '/generator/diagrams/$generationId' as const

export function getCoverEditorPath(generationId: string): string {
  return `/generator/${encodeURIComponent(generationId)}`
}

export function getDiagramEditorPath(generationId: string): string {
  return `/generator/diagrams/${encodeURIComponent(generationId)}`
}
