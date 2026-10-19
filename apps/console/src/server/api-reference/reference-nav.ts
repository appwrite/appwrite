import { parseOpenApiSpec } from '@/lib/api-explorer/parse-spec'
import {
  isReferenceService,
  type ReferenceService,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import { loadReferenceOpenApiSpec } from './load-spec'

export async function loadReferenceNavServiceCounts(
  version: ReferenceVersion,
  mode: 'client' | 'server',
): Promise<Map<ReferenceService, number>> {
  const platform = mode === 'client' ? 'client-web' : 'server-nodejs'
  const spec = await loadReferenceOpenApiSpec(version, platform)
  const parsed = parseOpenApiSpec(spec, mode)
  const counts = new Map<ReferenceService, number>()

  for (const service of parsed.services) {
    if (service.methods.length === 0 || !isReferenceService(service.id)) continue
    counts.set(service.id, service.methods.length)
  }

  return counts
}
