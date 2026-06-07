import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { pricingFaqItems } from '@/lib/pricing/faq'
import { PricingSectionHeading } from './_components/PricingSectionHeading'

export function FaqSection() {
  return (
    <section className="bg-background py-16 sm:py-20">
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="grid gap-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)] lg:gap-12">
          <PricingSectionHeading
            align="left"
            title="FAQ"
            description="Common questions about plans, billing, and usage limits."
          />

          <Accordion type="single" collapsible defaultValue="item-0" className="w-full">
            {pricingFaqItems.map((item, index) => (
              <AccordionItem key={item.question} value={`item-${index}`}>
                <AccordionTrigger className="py-5 text-left hover:no-underline">
                  <span className="pr-4 text-[14px] font-medium text-foreground">
                    {item.question}
                  </span>
                </AccordionTrigger>
                <AccordionContent className="text-[13px] leading-6 text-muted-foreground">
                  {item.answer}
                </AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
        </div>
      </div>
    </section>
  )
}
