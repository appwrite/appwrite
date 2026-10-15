import { ChangelogMarkdown } from '@/components/pages/changelog/ChangelogMarkdown'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import { cn } from '@/lib/utils'

const INTEGRATION_PROSE_LINK_CLASS = cn(DOCS_PROSE_LINK_CLASS, 'text-inherit')

type IntegrationMarkdownProps = {
  content: string
  className?: string
}

export function IntegrationMarkdown({ content, className }: IntegrationMarkdownProps) {
  return (
    <ChangelogMarkdown
      content={content}
      linkClassName={INTEGRATION_PROSE_LINK_CLASS}
      className={className}
    />
  )
}
