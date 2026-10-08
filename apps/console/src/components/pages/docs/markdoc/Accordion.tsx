'use client'

import {
  createContext,
  useCallback,
  useContext,
  useRef,
  type ReactNode,
} from 'react'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { DOCS_BODY_TEXT_CLASS } from '@/lib/docs/prose-typography'
import { cn } from '@/lib/utils'

const accordionContentClassName = cn(
  '[&_p]:my-0 [&_p+p]:mt-3',
  '[&_strong]:font-semibold [&_strong]:text-foreground',
  '[&_code]:rounded-md [&_code]:border [&_code]:border-border [&_code]:bg-muted/50 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[13px] [&_code]:text-foreground/85',
  '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-4',
  '[&_ol]:my-3 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-4',
  '[&_li]:leading-[1.65]',
  '[&_.not-prose]:my-4 [&_.not-prose]:w-full [&_.not-prose:first-child]:mt-0',
  'prose-links-neutral',
)

type MarkdocAccordionIndexContextValue = {
  nextIndex: () => number
}

const MarkdocAccordionIndexContext =
  createContext<MarkdocAccordionIndexContextValue | null>(null)

type MarkdocAccordionProps = {
  children?: ReactNode
}

export function MarkdocAccordion({ children }: MarkdocAccordionProps) {
  const indexRef = useRef(0)
  indexRef.current = 0

  const nextIndex = useCallback(() => indexRef.current++, [])

  return (
    <MarkdocAccordionIndexContext.Provider value={{ nextIndex }}>
      <Accordion
        type="single"
        collapsible
        className="not-prose my-6 w-full overflow-hidden rounded-xl border border-border bg-card/50"
      >
        {children}
      </Accordion>
    </MarkdocAccordionIndexContext.Provider>
  )
}

type MarkdocAccordionItemProps = {
  title?: string
  children?: ReactNode
  compact?: boolean
}

export function MarkdocAccordionItem({
  title,
  children,
  compact = false,
}: MarkdocAccordionItemProps) {
  const indexContext = useContext(MarkdocAccordionIndexContext)
  const itemIndex = indexContext?.nextIndex() ?? 0
  const value = title ? `${itemIndex}-${title}` : `item-${itemIndex}`

  const triggerTextClass = compact
    ? 'text-[13px] @[480px]:text-[14px]'
    : 'text-[14px] @[640px]:text-[15px]'

  const contentTextClass = compact
    ? 'text-[13px] leading-[1.6] @[480px]:text-[14px]'
    : cn(DOCS_BODY_TEXT_CLASS, 'text-[15px] leading-[1.65] @[640px]:text-[16px]')

  return (
    <AccordionItem value={value} className="border-border">
      <AccordionTrigger
        className={cn(
          'rounded-none px-4 py-4 text-start transition-colors duration-150 hover:bg-muted/40 hover:no-underline data-[state=open]:bg-muted/30',
          triggerTextClass,
        )}
      >
        <span className="pe-4 font-medium text-foreground">{title}</span>
      </AccordionTrigger>
      <AccordionContent
        className={cn(
          'px-4 pb-4 text-muted-foreground',
          contentTextClass,
          accordionContentClassName,
        )}
      >
        {children}
      </AccordionContent>
    </AccordionItem>
  )
}
