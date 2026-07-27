'use client'

import { MethodDescriptionMarkdown } from '@/components/global/api-explorer/MethodDescriptionMarkdown'
import type { ApiReferenceModelProperty } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import { ApiReferenceCopyableName } from './_components/ApiReferenceCopyableName'
import { ApiReferenceCollapsibleModels } from './ApiReferenceCollapsibleModels'
import {
  ApiReferencePropertyTypeCell,
  getPropertyVariantsSectionTitle,
} from './ApiReferencePropertyTypeCell'

export const INLINE_MODEL_HEADER =
  'grid border-b border-border/50 bg-muted/20 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground grid-cols-[minmax(100px,24%)_minmax(96px,20%)_minmax(0,1fr)]'

export const INLINE_MODEL_ROW =
  'grid grid-cols-[minmax(100px,24%)_minmax(96px,20%)_minmax(0,1fr)]'

function formatPropertyDescription(property: ApiReferenceModelProperty): string {
  const description = property.description?.trim() ?? ''
  if (property.variants?.length || !property.relatedModels) return description
  if (!description) {
    return `Can be one of: ${property.relatedModels}`
  }
  return `${description} Can be one of: ${property.relatedModels}`
}

type ApiReferenceModelPropertyRowProps = {
  property: ApiReferenceModelProperty
  showBottomBorder?: boolean
}

export function ApiReferenceModelPropertyRow({
  property,
  showBottomBorder = true,
}: ApiReferenceModelPropertyRowProps) {
  const description = formatPropertyDescription(property)

  return (
    <div className={cn(showBottomBorder && 'border-b border-border/50')}>
      <div className={INLINE_MODEL_ROW}>
        <div className="min-w-0 px-3 py-2">
          <ApiReferenceCopyableName
            name={property.name}
            textClassName="text-[12px] text-foreground"
          />
        </div>
        <div className="flex items-start justify-start border-s border-border/50 px-3 py-2">
          <ApiReferencePropertyTypeCell property={property} />
        </div>
        <div className="border-s border-border/50 px-3 py-2 text-[12px] text-muted-foreground">
          {description ? (
            <MethodDescriptionMarkdown
              content={description}
              className="border-0 bg-transparent p-0 text-[12px] leading-relaxed text-muted-foreground [&_a]:text-foreground [&_a]:underline-offset-4 [&_a]:hover:underline [&_code]:bg-muted/50"
            />
          ) : (
            <span className="text-muted-foreground/60">-</span>
          )}
        </div>
      </div>
      {property.variants?.length ? (
        <div
          className={cn(
            INLINE_MODEL_ROW,
            'border-t border-border/50 bg-background/50',
          )}
        >
          <div aria-hidden className="px-3 py-2" />
          <div className="col-span-2 min-w-0 border-s border-border/50 px-3 py-3">
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {getPropertyVariantsSectionTitle(property)}
            </p>
            <ApiReferenceCollapsibleModels
              models={property.variants}
              alwaysUseAccordion
            />
          </div>
        </div>
      ) : null}
    </div>
  )
}

type ApiReferenceModelPropertyRowsProps = {
  properties: ApiReferenceModelProperty[]
  className?: string
}

export function ApiReferenceModelPropertyRows({
  properties,
  className,
}: ApiReferenceModelPropertyRowsProps) {
  if (properties.length === 0) return null

  return (
    <div
      className={cn(
        'overflow-hidden rounded-md border border-border/60 bg-muted/10',
        className,
      )}
    >
      <div className={INLINE_MODEL_HEADER}>
        <div className="px-3 py-2">Name</div>
        <div className="border-s border-border/50 px-3 py-2">Type</div>
        <div className="border-s border-border/50 px-3 py-2">Description</div>
      </div>
      {properties.map((property, index) => (
        <ApiReferenceModelPropertyRow
          key={property.name}
          property={property}
          showBottomBorder={index < properties.length - 1}
        />
      ))}
    </div>
  )
}
