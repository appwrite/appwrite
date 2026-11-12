'use client'

import { useMemo, useState } from 'react'
import { List } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { cn } from '@/lib/utils'
import { getHttpMethodVariant, REFERENCE_PILL_CLASS } from './explorer-styles'
import { ApiReferenceMethodsNavContent } from './ApiReferenceMethodsNavContent'

type ApiReferenceMethodsMobileNavProps = {
  serviceId: string
  serviceLabel: string
  methods: ApiReferenceMethod[]
  selectedMethodId?: string
  onSelectMethod: (methodId: string) => void
  className?: string
}

export function ApiReferenceMethodsMobileNav({
  serviceId,
  serviceLabel,
  methods,
  selectedMethodId,
  onSelectMethod,
  className,
}: ApiReferenceMethodsMobileNavProps) {
  const [sheetOpen, setSheetOpen] = useState(false)
  const selectedMethod = useMemo(
    () => methods.find((method) => method.id === selectedMethodId),
    [methods, selectedMethodId],
  )

  return (
    <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
      <SheetTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className={cn(
            'h-8 min-w-0 flex-1 justify-start gap-1.5 px-2.5 text-[13px]',
            className,
          )}
        >
          {selectedMethod ? (
            <>
              <Badge
                variant={getHttpMethodVariant(selectedMethod.httpMethod)}
                className={cn('shrink-0 text-[10px] uppercase', REFERENCE_PILL_CLASS)}
              >
                {selectedMethod.httpMethod}
              </Badge>
              <span className="min-w-0 truncate">{selectedMethod.summary}</span>
            </>
          ) : (
            <>
              <List className="size-3.5 shrink-0" />
              <span className="truncate">{serviceLabel}</span>
            </>
          )}
        </Button>
      </SheetTrigger>
      <SheetContent side="left" className="flex w-[min(100vw,320px)] flex-col p-0">
        <SheetHeader className="shrink-0 border-b border-border px-4 py-4 text-start">
          <SheetTitle className="truncate text-[15px]">{serviceLabel}</SheetTitle>
        </SheetHeader>
        <ApiReferenceMethodsNavContent
          serviceId={serviceId}
          methods={methods}
          selectedMethodId={selectedMethodId}
          onSelectMethod={onSelectMethod}
          onMethodSelected={() => setSheetOpen(false)}
          className="min-h-0 flex-1"
        />
      </SheetContent>
    </Sheet>
  )
}
