import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import type { BlogFaq } from '@/lib/blog/types'
import { DOCS_BODY_TEXT_CLASS } from '@/lib/docs/prose-typography'
import { cn } from '@/lib/utils'
import { BlogMarkdown } from './BlogMarkdown'

type BlogFaqSectionProps = {
  faqs: BlogFaq[]
}

export function BlogFaqSection({ faqs }: BlogFaqSectionProps) {
  if (faqs.length === 0) return null

  return (
    <section className="mt-12 border-t border-border pt-8">
      <h2 className="font-aeonik-pro text-[14px] font-normal text-foreground">
        Frequently asked questions
      </h2>
      <div className="mt-6 overflow-hidden rounded-xl border border-border bg-card/50">
        <Accordion type="single" collapsible className="w-full">
          {faqs.map((faq, index) => (
            <AccordionItem key={faq.question} value={`item-${index}`}>
              <AccordionTrigger
                className={cn(
                  'rounded-none px-4 py-4 text-left transition-colors duration-150 hover:bg-muted/40 hover:no-underline',
                )}
              >
                <span className="pr-4 text-[14px] font-medium text-foreground">
                  {faq.question}
                </span>
              </AccordionTrigger>
              <AccordionContent className={cn(DOCS_BODY_TEXT_CLASS, 'px-4 pb-4')}>
                <BlogMarkdown content={faq.answer} />
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      </div>
    </section>
  )
}
