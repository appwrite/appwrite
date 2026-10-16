'use client'

import { useState, type ReactNode } from 'react'
import { List, Layers } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import {
  getHttpMethodBadgeVariant as getHttpMethodVariant,
} from '@/lib/http-method-badge'
import { API_EXPLORER_PILL_CLASS } from '@/lib/api-explorer/form-field-type-badge'
import type {
  ApiExplorerMethod,
  ApiExplorerService,
} from '@/lib/api-explorer'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

function ServicesSheet({
  selectedService,
  children,
}: {
  selectedService?: ApiExplorerService
  children: (close: () => void) => ReactNode
}) {
  const t = useT()
  const [open, setOpen] = useState(false)

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="h-8 min-w-0 flex-1 justify-start gap-1.5 px-2.5 text-[13px]"
        >
          {selectedService ? (
            <>
              <Layers className="size-3.5 shrink-0 text-muted-foreground" />
              <span className="min-w-0 truncate">{t(selectedService.label)}</span>
            </>
          ) : (
            <>
              <Layers className="size-3.5 shrink-0" />
              <span className="truncate">{t('Select a service')}</span>
            </>
          )}
        </Button>
      </SheetTrigger>
      <SheetContent side="left" className="flex w-[min(100vw,320px)] flex-col p-0">
        <SheetHeader className="shrink-0 border-b border-border px-4 py-4 text-start">
          <SheetTitle className="truncate text-[15px]">{t('APIs')}</SheetTitle>
        </SheetHeader>
        <div className="min-h-0 flex-1 overflow-hidden">
          {children(() => setOpen(false))}
        </div>
      </SheetContent>
    </Sheet>
  )
}

function MethodsSheet({
  selectedMethod,
  serviceLabel,
  disabled,
  children,
}: {
  selectedMethod?: ApiExplorerMethod
  serviceLabel?: string
  disabled?: boolean
  children: (close: () => void) => ReactNode
}) {
  const t = useT()
  const [open, setOpen] = useState(false)

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          disabled={disabled}
          className="h-8 min-w-0 flex-1 justify-start gap-1.5 px-2.5 text-[13px]"
        >
          {selectedMethod ? (
            <>
              <Badge
                variant={getHttpMethodVariant(selectedMethod.httpMethod)}
                className={cn(
                  'shrink-0 text-[10px] uppercase',
                  API_EXPLORER_PILL_CLASS,
                )}
              >
                {selectedMethod.httpMethod}
              </Badge>
              <span className="min-w-0 truncate">{selectedMethod.summary}</span>
            </>
          ) : (
            <>
              <List className="size-3.5 shrink-0" />
              <span className="truncate">
                {serviceLabel ? t(serviceLabel) : t('Select a method')}
              </span>
            </>
          )}
        </Button>
      </SheetTrigger>
      <SheetContent side="left" className="flex w-[min(100vw,320px)] flex-col p-0">
        <SheetHeader className="shrink-0 border-b border-border px-4 py-4 text-start">
          <SheetTitle className="truncate text-[15px]">
            {serviceLabel ? t(serviceLabel) : t('Methods')}
          </SheetTitle>
        </SheetHeader>
        <div className="min-h-0 flex-1 overflow-hidden">
          {children(() => setOpen(false))}
        </div>
      </SheetContent>
    </Sheet>
  )
}

export type ApiExplorerMobileNavProps = {
  selectedService?: ApiExplorerService
  selectedMethod?: ApiExplorerMethod
  servicesContent: (close: () => void) => ReactNode
  methodsContent: (close: () => void) => ReactNode
  className?: string
}

export function ApiExplorerMobileNav({
  selectedService,
  selectedMethod,
  servicesContent,
  methodsContent,
  className,
}: ApiExplorerMobileNavProps) {
  return (
    <div
      className={cn(
        'flex shrink-0 items-center gap-2 border-b border-border px-3 py-2',
        className,
      )}
    >
      <ServicesSheet selectedService={selectedService}>
        {servicesContent}
      </ServicesSheet>
      <MethodsSheet
        selectedMethod={selectedMethod}
        serviceLabel={selectedService?.label}
        disabled={!selectedService}
      >
        {methodsContent}
      </MethodsSheet>
    </div>
  )
}
