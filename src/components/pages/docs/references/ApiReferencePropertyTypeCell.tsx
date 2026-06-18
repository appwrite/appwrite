import { Badge } from '@/components/ui/badge'
import type { ApiReferenceModelProperty } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'

type ApiReferencePropertyTypeCellProps = {
  property: ApiReferenceModelProperty
  className?: string
}

function getVariantHint(property: ApiReferenceModelProperty): string | null {
  const count = property.variantCount ?? property.variants?.length ?? 0
  if (count <= 1) return null

  if (property.typeKind === 'array') {
    return `${count} possible object types`
  }
  if (property.typeKind === 'object') {
    return `${count} possible types`
  }
  return `${count} options`
}

export function ApiReferencePropertyTypeCell({
  property,
  className,
}: ApiReferencePropertyTypeCellProps) {
  const variantHint = getVariantHint(property)

  if (property.typeKind === 'array') {
    return (
      <div
        className={cn(
          'flex min-w-0 items-center gap-1.5 whitespace-nowrap text-[11px] text-muted-foreground',
          className,
        )}
      >
        <Badge
          variant="secondary"
          className="shrink-0 text-[10px] font-medium uppercase"
        >
          Array
        </Badge>
        {property.itemType ? (
          <span>
            of <span className="font-mono text-foreground">{property.itemType}</span>
          </span>
        ) : variantHint ? (
          <span>{variantHint}</span>
        ) : null}
      </div>
    )
  }

  if (property.typeKind === 'object') {
    return (
      <div
        className={cn(
          'flex min-w-0 items-center gap-1.5 whitespace-nowrap text-[11px] text-muted-foreground',
          className,
        )}
      >
        <Badge
          variant="secondary"
          className="shrink-0 text-[10px] font-medium uppercase"
        >
          Object
        </Badge>
        {property.itemType ? (
          <span>
            <span className="font-mono text-foreground">{property.itemType}</span>
          </span>
        ) : variantHint ? (
          <span>{variantHint}</span>
        ) : null}
      </div>
    )
  }

  return (
    <span className={cn('font-mono text-[12px] text-muted-foreground', className)}>
      {property.type}
    </span>
  )
}

export function getPropertyVariantsSectionTitle(
  property: ApiReferenceModelProperty,
): string {
  const count = property.variants?.length ?? 0
  if (count === 1) {
    return 'Object type'
  }
  if (property.typeKind === 'array') {
    return `Array object types (${count})`
  }
  if (property.typeKind === 'object') {
    return `Object types (${count})`
  }
  return `Types (${count})`
}
