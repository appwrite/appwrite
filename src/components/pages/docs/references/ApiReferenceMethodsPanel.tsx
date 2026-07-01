'use client'

import { cn } from '@/lib/utils'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { REFERENCE_COLUMN_HEADER_CLASS } from './explorer-styles'
import { ApiReferenceMethodsNavContent } from './ApiReferenceMethodsNavContent'

type ApiReferenceMethodsPanelProps = {
  serviceId: string
  serviceLabel: string
  methods: ApiReferenceMethod[]
  selectedMethodId?: string
  onSelectMethod: (methodId: string) => void
}

export function ApiReferenceMethodsPanel({
  serviceId,
  serviceLabel,
  methods,
  selectedMethodId,
  onSelectMethod,
}: ApiReferenceMethodsPanelProps) {
  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-e border-border bg-muted/20">
      <div
        className={cn(
          REFERENCE_COLUMN_HEADER_CLASS,
          'min-w-0 items-center overflow-hidden',
        )}
      >
        <p className="truncate text-[13px] font-medium text-foreground">
          {serviceLabel}
        </p>
      </div>

      <ApiReferenceMethodsNavContent
        serviceId={serviceId}
        methods={methods}
        selectedMethodId={selectedMethodId}
        onSelectMethod={onSelectMethod}
        className="min-h-0 flex-1"
      />
    </div>
  )
}
