import { Badge } from '@/components/ui/badge'
import type { ApiReferenceModelProperty } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import {
  formatOpenApiTypeLabel,
  getModelPropertyTypeBadgeVariant,
  getOpenApiTypeBadgeVariant,
  REFERENCE_TYPE_PILL_CLASS,
} from './explorer-styles'

const TYPE_BADGE_CLASS = REFERENCE_TYPE_PILL_CLASS

type ApiReferencePropertyTypeCellProps = {
  property: ApiReferenceModelProperty
  className?: string
}

function getArrayItemType(property: ApiReferenceModelProperty): string | undefined {
  if (property.variants?.length === 1) {
    return property.variants[0]!.name
  }
  return property.itemType
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

function ItemTypeLabel({ itemType }: { itemType: string }) {
  const normalizedType = formatOpenApiTypeLabel(itemType)
  const isPrimitive = [
    'string',
    'integer',
    'number',
    'boolean',
    'array',
    'object',
    'enum',
  ].includes(normalizedType.toLowerCase())

  if (isPrimitive) {
    return (
      <Badge
        variant={getOpenApiTypeBadgeVariant(normalizedType)}
        className={TYPE_BADGE_CLASS}
      >
        {normalizedType}
      </Badge>
    )
  }

  return <span className="font-mono text-foreground">{itemType}</span>
}

export function ApiReferencePropertyTypeCell({
  property,
  className,
}: ApiReferencePropertyTypeCellProps) {
  const variantHint = getVariantHint(property)
  const typeLabel =
    property.typeKind === 'scalar'
      ? formatOpenApiTypeLabel(property.type)
      : formatOpenApiTypeLabel(property.typeKind)

  if (property.typeKind === 'array') {
    const itemType = getArrayItemType(property)

    return (
      <div
        className={cn(
          'flex min-w-0 flex-wrap items-start justify-start gap-1.5 text-[11px] text-muted-foreground',
          className,
        )}
      >
        <Badge
          variant={getModelPropertyTypeBadgeVariant(property.typeKind, property.type)}
          className={TYPE_BADGE_CLASS}
        >
          {typeLabel}
        </Badge>
        {itemType ? (
          <span className="flex items-start gap-1">
            of <ItemTypeLabel itemType={itemType} />
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
          'flex min-w-0 flex-wrap items-start justify-start gap-1.5 text-[11px] text-muted-foreground',
          className,
        )}
      >
        <Badge
          variant={getModelPropertyTypeBadgeVariant(property.typeKind, property.type)}
          className={TYPE_BADGE_CLASS}
        >
          {typeLabel}
        </Badge>
        {property.itemType ? (
          <ItemTypeLabel itemType={property.itemType} />
        ) : variantHint ? (
          <span>{variantHint}</span>
        ) : null}
      </div>
    )
  }

  return (
    <div className={cn('flex justify-start', className)}>
      <Badge
        variant={getModelPropertyTypeBadgeVariant(property.typeKind, property.type)}
        className={TYPE_BADGE_CLASS}
      >
        {typeLabel}
      </Badge>
    </div>
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
