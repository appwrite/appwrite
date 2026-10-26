import * as React from 'react'
import * as AccordionPrimitive from '@radix-ui/react-accordion'
import { ChevronDownIcon } from 'lucide-react'

import { cn } from '@/lib/utils'

function Accordion({
  ...props
}: React.ComponentProps<typeof AccordionPrimitive.Root>) {
  return <AccordionPrimitive.Root data-slot="accordion" {...props} />
}

function AccordionItem({
  className,
  ...props
}: React.ComponentProps<typeof AccordionPrimitive.Item>) {
  return (
    <AccordionPrimitive.Item
      data-slot="accordion-item"
      className={cn('border-b last:border-b-0', className)}
      {...props}
    />
  )
}

function AccordionHeader({
  className,
  ...props
}: React.ComponentProps<typeof AccordionPrimitive.Header>) {
  return (
    <AccordionPrimitive.Header
      data-slot="accordion-header"
      className={cn('flex', className)}
      {...props}
    />
  )
}

function AccordionRowTrigger({
  className,
  children,
  ...props
}: React.ComponentProps<typeof AccordionPrimitive.Trigger>) {
  return (
    <AccordionPrimitive.Trigger
      data-slot="accordion-trigger"
      className={cn(
        'focus-visible:border-ring focus-visible:ring-ring/50 flex flex-1 cursor-pointer items-start justify-between gap-4 rounded-md py-4 text-start text-sm font-medium transition-all outline-none hover:underline focus-visible:ring-[3px] disabled:pointer-events-none disabled:opacity-50 [&[data-state=open]>svg]:rotate-180',
        className,
      )}
      {...props}
    >
      {children}
      <ChevronDownIcon className="text-muted-foreground pointer-events-none size-4 shrink-0 translate-y-0.5 transition-transform duration-200" />
    </AccordionPrimitive.Trigger>
  )
}

function AccordionTrigger({
  className,
  children,
  ...props
}: React.ComponentProps<typeof AccordionPrimitive.Trigger>) {
  return (
    <AccordionHeader>
      <AccordionRowTrigger className={className} {...props}>
        {children}
      </AccordionRowTrigger>
    </AccordionHeader>
  )
}

function AccordionContentBody({
  className,
  children,
  trackClosed,
}: {
  className?: string
  children: React.ReactNode
  trackClosed: boolean
}) {
  const ref = React.useRef<HTMLDivElement>(null)
  const [closed, setClosed] = React.useState(trackClosed)

  React.useLayoutEffect(() => {
    if (!trackClosed) return
    const content = ref.current?.parentElement
    if (!content) return

    const sync = () => setClosed(content.getAttribute('data-state') !== 'open')
    sync()
    const observer = new MutationObserver(sync)
    observer.observe(content, { attributes: true, attributeFilter: ['data-state'] })
    return () => observer.disconnect()
  }, [trackClosed])

  return (
    <div ref={ref} inert={trackClosed ? closed : undefined} className="min-h-0 overflow-hidden">
      <div className={cn('pt-0 pb-4', className)}>{children}</div>
    </div>
  )
}

function AccordionContent({
  className,
  children,
  forceMount,
  ...props
}: React.ComponentProps<typeof AccordionPrimitive.Content>) {
  return (
    <AccordionPrimitive.Content
      data-slot="accordion-content"
      forceMount={forceMount}
      className={cn(
        'overflow-hidden text-sm [overflow-anchor:none]',
        forceMount
          ? 'grid grid-rows-[0fr] transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none data-[state=open]:grid-rows-[1fr]'
          : 'data-[state=closed]:animate-accordion-up data-[state=open]:animate-accordion-down',
      )}
      {...props}
    >
      <AccordionContentBody className={className} trackClosed={Boolean(forceMount)}>
        {children}
      </AccordionContentBody>
    </AccordionPrimitive.Content>
  )
}

export {
  Accordion,
  AccordionItem,
  AccordionHeader,
  AccordionRowTrigger,
  AccordionTrigger,
  AccordionContent,
}
