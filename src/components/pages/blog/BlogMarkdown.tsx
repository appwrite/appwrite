import { ChangelogMarkdown } from '@/components/pages/changelog/ChangelogMarkdown'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import { cn } from '@/lib/utils'

const BLOG_PROSE_LINK_CLASS = cn(DOCS_PROSE_LINK_CLASS, 'text-inherit')

type BlogMarkdownProps = {
  content: string
  className?: string
}

export function BlogMarkdown({ content, className }: BlogMarkdownProps) {
  return (
    <ChangelogMarkdown
      content={content}
      linkClassName={BLOG_PROSE_LINK_CLASS}
      className={className}
    />
  )
}
