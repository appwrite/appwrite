import type { ApiReferenceModelProperty } from '@/lib/docs/references/types'
import { ApiReferenceModelPropertyRows } from './ApiReferenceModelPropertyRows'

type ApiReferenceInlineModelPropertiesProps = {
  properties: ApiReferenceModelProperty[]
  className?: string
}

export function ApiReferenceInlineModelProperties({
  properties,
  className,
}: ApiReferenceInlineModelPropertiesProps) {
  return (
    <ApiReferenceModelPropertyRows properties={properties} className={className} />
  )
}
