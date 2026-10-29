import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { cn } from '@/lib/utils'

/**
 * Compact markdown body for user-authored copy (app descriptions and their
 * editor preview). Styled with explicit selectors because the console has no
 * typography plugin — bare `prose-*` classes are inert here.
 */
const MARKDOWN_CONTENT_CLASSES = [
  '[&>:first-child]:mt-0 [&>:last-child]:mb-0',
  '[&_p]:my-2',
  '[&_ul]:my-2 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:ps-5',
  '[&_ol]:my-2 [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:ps-5',
  '[&_li]:my-0',
  '[&_h1]:mb-2 [&_h1]:mt-3 [&_h1]:text-[15px] [&_h1]:font-semibold [&_h1]:text-foreground',
  '[&_h2]:mb-2 [&_h2]:mt-3 [&_h2]:text-[14px] [&_h2]:font-semibold [&_h2]:text-foreground',
  '[&_h3]:mb-1.5 [&_h3]:mt-3 [&_h3]:text-[13px] [&_h3]:font-semibold [&_h3]:text-foreground',
  '[&_h4]:mb-1.5 [&_h4]:mt-3 [&_h4]:text-[13px] [&_h4]:font-semibold [&_h4]:text-foreground',
  '[&_h5]:mb-1.5 [&_h5]:mt-3 [&_h5]:text-[13px] [&_h5]:font-semibold [&_h5]:text-foreground',
  '[&_h6]:mb-1.5 [&_h6]:mt-3 [&_h6]:text-[13px] [&_h6]:font-semibold [&_h6]:text-foreground',
  '[&_strong]:font-semibold [&_strong]:text-foreground',
  '[&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[0.92em] [&_code]:text-foreground',
  '[&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:border [&_pre]:border-border [&_pre]:bg-muted/50 [&_pre]:p-3',
  '[&_pre_code]:bg-transparent [&_pre_code]:p-0',
  '[&_a]:font-medium [&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:text-primary',
  '[&_blockquote]:my-2 [&_blockquote]:border-s-2 [&_blockquote]:border-border [&_blockquote]:ps-3 [&_blockquote]:italic',
  '[&_hr]:my-4 [&_hr]:border-border',
  '[&_table]:my-2 [&_table]:w-full [&_table]:border-collapse',
  '[&_th]:border [&_th]:border-border [&_th]:bg-muted/50 [&_th]:px-2 [&_th]:py-1 [&_th]:text-start [&_th]:font-medium',
  '[&_td]:border [&_td]:border-border [&_td]:px-2 [&_td]:py-1',
] as const

function sanitizeHref(href?: string): string | undefined {
  const trimmed = href?.trim()
  if (!trimmed) return undefined

  if (
    trimmed.startsWith('/') ||
    trimmed.startsWith('#') ||
    /^https?:\/\//i.test(trimmed) ||
    /^mailto:/i.test(trimmed)
  ) {
    return trimmed
  }

  return undefined
}

function MarkdownContentLink({
  href,
  children,
  ...props
}: {
  href?: string
  children?: React.ReactNode
}) {
  const safeHref = sanitizeHref(href)
  if (!safeHref) {
    return <span>{children}</span>
  }

  const external = /^https?:\/\//i.test(safeHref)
  return (
    <a
      href={safeHref}
      {...props}
      {...(external
        ? { target: '_blank', rel: 'nofollow ugc noopener noreferrer' }
        : {})}
    >
      {children}
    </a>
  )
}

type MarkdownContentProps = {
  content: string
  className?: string
}

export function MarkdownContent({ content, className }: MarkdownContentProps) {
  return (
    <div
      className={cn('min-w-0 break-words', ...MARKDOWN_CONTENT_CLASSES, className)}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        skipHtml
        components={{
          a: MarkdownContentLink,
          // App descriptions are third-party copy; never load remote images.
          img: ({ alt }) =>
            alt ? (
              <span className="text-muted-foreground">[{alt}]</span>
            ) : null,
        }}
      >
        {content}
      </ReactMarkdown>
    </div>
  )
}
