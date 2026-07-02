import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { marketingSplitLayoutClassName } from '@/components/pages/marketing/MarketingSections'
import { useT } from '@/lib/i18n/translate'
import { pricingFaqItems } from '@/lib/pricing/faq'
import { PricingSectionHeading } from './_components/PricingSectionHeading'

export function FaqSection() {
  const t = useT()
  return (
    <section className="bg-background py-16 sm:py-20">
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className={marketingSplitLayoutClassName()}>
          <PricingSectionHeading
            align="left"
            title={t('FAQ')}
            description={t('Common questions about plans, billing, and usage limits.')}
          />

          <Accordion type="single" collapsible defaultValue="item-0" className="w-full">
            {pricingFaqItems.map((item, index) => (
              <AccordionItem key={item.question} value={`item-${index}`}>
                <AccordionTrigger className="py-5 text-start hover:no-underline">
                  <span className="pe-4 text-[14px] font-medium text-foreground">
                    {t(item.question)}
                  </span>
                </AccordionTrigger>
                <AccordionContent className="text-[13px] leading-6 text-muted-foreground">
                  {typeof item.answer === 'string' ? t(item.answer) : item.answer}
                </AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
        </div>
      </div>
    </section>
  )
}
