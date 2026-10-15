import type {
  ReferencePlatform,
  ReferenceService,
  ReferenceVersion,
} from '@/lib/docs/references/constants'
import type { ApiReferenceServiceData } from '@/lib/docs/references/types'
import { ApiReferenceExplorer } from './ApiReferenceExplorer'

type ServiceViewProps = {
  data: ApiReferenceServiceData
  version: ReferenceVersion
  platform: ReferencePlatform
  service: ReferenceService
}

export function ApiReferenceServiceView({
  data,
  version,
  platform,
}: ServiceViewProps) {
  return (
    <div className="flex h-full min-h-0 flex-col">
      <ApiReferenceExplorer data={data} version={version} platform={platform} />
    </div>
  )
}
