import type { ApiReferenceModelProperty } from '@/lib/docs/references/types'
import { ApiReferenceModelPropertyRows } from './ApiReferenceModelPropertyRows'

type ApiReferenceInlineModelPropertiesTableProps = {
  properties: ApiReferenceModelProperty[]
  className?: string
}

export function ApiReferenceInlineModelPropertiesTable({
  properties,
  className,
}: ApiReferenceInlineModelPropertiesTableProps) {
  return (
    <ApiReferenceModelPropertyRows properties={properties} className={className} />
  )
}
