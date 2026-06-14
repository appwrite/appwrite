import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import type { BlogFaq } from '@/lib/blog/types'
import {
  BLOG_BODY_TEXT_CLASS,
  BLOG_BODY_TEXT_SIZE_CLASS,
  BLOG_FAQ_QUESTION_CLASS,
} from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'
import { BlogMarkdown } from './BlogMarkdown'

type BlogFaqSectionProps = {
  faqs: BlogFaq[]
}

export function BlogFaqSection({ faqs }: BlogFaqSectionProps) {
  if (faqs.length === 0) return null

  return (
    <section className="mt-12 border-t border-border pt-8">
      <h2
        className={cn(
          BLOG_BODY_TEXT_SIZE_CLASS,
          'font-aeonik-pro font-normal text-foreground',
        )}
      >
        Frequently asked questions
      </h2>
      <div className="mt-6 overflow-hidden rounded-xl border border-border bg-card/50">
        <Accordion type="single" collapsible className="w-full">
          {faqs.map((faq, index) => (
            <AccordionItem key={faq.question} value={`item-${index}`} className="px-6">
              <AccordionTrigger
                className={cn(
                  BLOG_FAQ_QUESTION_CLASS,
                  '-mx-6 px-6 py-5 text-left transition-colors duration-150 hover:bg-muted/40 hover:no-underline',
                )}
              >
                <span className="pr-4">{faq.question}</span>
              </AccordionTrigger>
              <AccordionContent className={cn(BLOG_BODY_TEXT_CLASS, 'pb-4')}>
                <BlogMarkdown content={faq.answer} />
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      </div>
    </section>
  )
}
