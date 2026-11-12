'use client'

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import type { ApiReferenceResponseModel } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import { ApiReferenceInlineModelPropertiesTable } from './ApiReferenceInlineModelPropertiesTable'

type ApiReferenceCollapsibleModelsProps = {
  models: ApiReferenceResponseModel[]
  className?: string
  /** Accordion item ids that start expanded. Defaults to none (all collapsed). */
  defaultOpenIds?: string[]
  /** When true, always use a collapsed accordion (including for a single model). */
  alwaysUseAccordion?: boolean
}

export function ApiReferenceCollapsibleModels({
  models,
  className,
  defaultOpenIds,
  alwaysUseAccordion = false,
}: ApiReferenceCollapsibleModelsProps) {
  if (models.length === 0) return null

  if (models.length === 1 && !alwaysUseAccordion) {
    const model = models[0]!
    return (
      <div className={cn('space-y-2', className)}>
        <p className="text-[13px] font-medium text-foreground">{model.name}</p>
        {model.properties.length > 0 ? (
          <ApiReferenceInlineModelPropertiesTable properties={model.properties} />
        ) : (
          <span className="text-[13px] text-muted-foreground/60">-</span>
        )}
      </div>
    )
  }

  return (
    <Accordion
      type="multiple"
      defaultValue={defaultOpenIds ?? []}
      className={cn('w-full space-y-1', className)}
    >
      {models.map((model) => (
        <AccordionItem
          key={model.id}
          value={model.id}
          className="overflow-hidden rounded-md border border-border/60 bg-muted/10 px-0"
        >
          <AccordionTrigger className="px-3 py-2 text-[13px] font-medium text-foreground hover:no-underline [&>svg]:size-3.5 [&>svg]:text-muted-foreground">
            {model.name}
          </AccordionTrigger>
          <AccordionContent className="border-t border-border/50 px-3 pb-3 pt-2">
            {model.properties.length > 0 ? (
              <ApiReferenceInlineModelPropertiesTable properties={model.properties} />
            ) : (
              <span className="text-[13px] text-muted-foreground/60">-</span>
            )}
          </AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  )
}
