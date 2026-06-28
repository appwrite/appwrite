import { GENERATOR_DIAGRAM_API_PATH } from '@/lib/generator/constants'
import type { DiagramDocument } from '@/lib/diagram-generator/types'

export function buildDiagramApiUrl(origin = ''): string {
  const normalizedOrigin = origin.replace(/\/+$/, '')
  return normalizedOrigin
    ? `${normalizedOrigin}${GENERATOR_DIAGRAM_API_PATH}`
    : GENERATOR_DIAGRAM_API_PATH
}

export async function fetchDiagramImage(
  document: DiagramDocument,
  origin = typeof window !== 'undefined' ? window.location.origin : '',
): Promise<Blob> {
  const endpoint = buildDiagramApiUrl(origin)
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(document),
  })

  if (!response.ok) {
    throw new Error(`Failed to render diagram (${response.status})`)
  }

  return response.blob()
}
