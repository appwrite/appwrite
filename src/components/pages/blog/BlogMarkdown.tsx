import { ChangelogMarkdown } from '@/components/pages/changelog/ChangelogMarkdown'
import {
  BLOG_BODY_TEXT_CLASS,
  BLOG_BODY_TEXT_SIZE_CLASS,
  BLOG_FAQ_QUESTION_CLASS,
} from '@/lib/blog/prose-typography'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import { cn } from '@/lib/utils'

const BLOG_PROSE_LINK_CLASS = cn(DOCS_PROSE_LINK_CLASS, 'text-inherit')

const BLOG_ARROW_LINK_TEXT_CLASS = cn(
  BLOG_BODY_TEXT_CLASS,
  'font-medium text-foreground',
)

type BlogMarkdownProps = {
  content: string
  className?: string
}

export function BlogMarkdown({ content, className }: BlogMarkdownProps) {
  return (
    <ChangelogMarkdown
      content={content}
      bodyTextClass={BLOG_BODY_TEXT_CLASS}
      linkClassName={BLOG_PROSE_LINK_CLASS}
      arrowLinkTextClass={BLOG_ARROW_LINK_TEXT_CLASS}
      proseVariant="blog"
      className={className}
    />
  )
}

export {
  BLOG_BODY_TEXT_CLASS,
  BLOG_BODY_TEXT_SIZE_CLASS,
  BLOG_FAQ_QUESTION_CLASS,
}
