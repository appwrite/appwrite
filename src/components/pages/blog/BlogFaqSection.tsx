import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  BLOG_FAQ_ANSWER_CLASS,
  BLOG_FAQ_QUESTION_CLASS,
} from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'
import { ChevronDownIcon } from 'lucide-react'
import { BlogMarkdown } from './BlogMarkdown'

type BlogFaqSectionProps = {
  faqs: Array<{ question: string; html: string }>
}

export function BlogFaqSection({ faqs }: BlogFaqSectionProps) {
  if (faqs.length === 0) return null

  return (
    <section className="mt-12 border-t border-border pt-8">
      <Collapsible defaultOpen={false}>
        <CollapsibleTrigger
          className={cn(
            'flex w-full cursor-pointer items-center justify-between gap-4 text-start transition-colors duration-150 hover:text-muted-foreground',
            '[&[data-state=open]>svg]:rotate-180',
          )}
        >
          <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">
            Frequently asked questions
          </h2>
          <ChevronDownIcon className="text-muted-foreground size-4 shrink-0 transition-transform duration-200" />
        </CollapsibleTrigger>
        <CollapsibleContent>
          <div className="mt-6 overflow-hidden rounded-xl border border-border bg-card/50">
            <Accordion type="single" collapsible className="w-full">
              {faqs.map((faq, index) => (
                <AccordionItem key={faq.question} value={`item-${index}`}>
                  <AccordionTrigger
                    className={cn(
                      'rounded-none px-4 py-4 text-start transition-colors duration-150 hover:bg-muted/40 hover:no-underline',
                    )}
                  >
                    <span className={cn('pe-4', BLOG_FAQ_QUESTION_CLASS)}>
                      {faq.question}
                    </span>
                  </AccordionTrigger>
                  <AccordionContent className="px-4 pb-4">
                    <BlogMarkdown
                      html={faq.html}
                      className={BLOG_FAQ_ANSWER_CLASS}
                    />
                  </AccordionContent>
                </AccordionItem>
              ))}
            </Accordion>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </section>
  )
}
