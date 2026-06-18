import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer/types'
import {
  getReferenceOpenApiSpecDownloadFilename,
  isReferenceVersion,
  type ReferenceVersion,
} from './constants'
import { loadReferenceOpenApiSpecFn } from '@/server/functions/api-reference'

function downloadJsonFile(content: string, filename: string): void {
  const blob = new Blob([content], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}

export async function downloadReferenceOpenApiSpec(
  version: ReferenceVersion,
  mode: ApiExplorerProjectPlatform,
): Promise<void> {
  if (!isReferenceVersion(version)) {
    throw new Error('Invalid reference version')
  }

  const spec = await loadReferenceOpenApiSpecFn({ data: { version, mode } })
  const content = JSON.stringify(spec, null, 2)
  downloadJsonFile(content, getReferenceOpenApiSpecDownloadFilename(version, mode))
}
