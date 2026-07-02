import { ArrowUpRight } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { MarketingSectionHeading, marketingSplitLayoutClassName } from './MarketingSections'
import { useT } from '@/lib/i18n/translate'

export type MarketingFaqLink = {
  label: string
  href: string
}

export type MarketingFaqItem = {
  question: string
  answer: string
  links?: MarketingFaqLink[]
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
  const t = useT()
  return (
    <section className="py-16 sm:py-20">
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className={marketingSplitLayoutClassName()}>
          <MarketingSectionHeading
            align="left"
            size="md"
            title={title}
            description={description}
          />

          <Accordion type="single" collapsible defaultValue="item-0" className="w-full">
            {items.map((item, index) => (
              <AccordionItem key={item.question} value={`item-${index}`}>
                <AccordionTrigger className="py-5 text-start hover:no-underline">
                  <span className="pe-4 text-[14px] font-medium text-foreground">
                    {t(item.question)}
                  </span>
                </AccordionTrigger>
                <AccordionContent className="text-[13px] leading-6 text-muted-foreground">
                  <p>{t(item.answer)}</p>
                  {item.links?.length ? (
                    <div className="mt-3 flex flex-wrap gap-x-4 gap-y-2">
                      {item.links.map((link) => (
                        <Link
                          key={link.href}
                          to={link.href}
                          className="inline-flex items-center gap-1 font-medium text-foreground transition-colors hover:text-muted-foreground"
                        >
                          {t(link.label)}
                          <ArrowUpRight className="size-3" aria-hidden />
                        </Link>
                      ))}
                    </div>
                  ) : null}
                </AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
        </div>
      </div>
    </section>
  )
}
