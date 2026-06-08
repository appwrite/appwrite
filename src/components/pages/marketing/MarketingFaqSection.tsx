import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { MarketingSectionHeading } from './MarketingSections'

export type MarketingFaqItem = {
  question: string
  answer: string
}

type MarketingFaqSectionProps = {
  items: MarketingFaqItem[]
  title?: string
  description?: string
}

export function MarketingFaqSection({
  items,
  title = 'FAQ',
  description,
}: MarketingFaqSectionProps) {
  return (
    <section className="border-t border-border py-16 sm:py-20">
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="grid gap-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)] lg:gap-12">
          <MarketingSectionHeading
            align="left"
            size="md"
            title={title}
            description={description}
          />

          <Accordion type="single" collapsible defaultValue="item-0" className="w-full">
            {items.map((item, index) => (
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
