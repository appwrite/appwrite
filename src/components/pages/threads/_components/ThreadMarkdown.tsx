'use client'

import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { cn } from '@/lib/utils'

type ThreadMarkdownProps = {
  content: string
  className?: string
}

function isExternalLink(href?: string): boolean {
  if (!href) return false
  if (href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('#')) {
    return false
  }
  if (href.startsWith('/')) return false
  return /^https?:\/\//i.test(href) || href.startsWith('//')
}

function sanitizeUserMessageHref(href?: string): string | undefined {
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

export function ThreadMarkdown({ content, className }: ThreadMarkdownProps) {
  return (
    <div
      className={cn(
        'min-w-0 break-words text-[13px] leading-relaxed text-foreground',
        '[&_p]:my-0 [&_p+p]:mt-3',
        '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-5',
        '[&_ol]:my-3 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-5',
        '[&_a]:text-foreground [&_a]:underline [&_a]:underline-offset-2',
        '[&_code:not(pre_code)]:rounded [&_code:not(pre_code)]:bg-muted [&_code:not(pre_code)]:px-1 [&_code:not(pre_code)]:py-0.5 [&_code:not(pre_code)]:text-[12px]',
        '[&_pre]:my-3 [&_pre]:overflow-x-auto',
        '[&_blockquote]:my-3 [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:pl-4 [&_blockquote]:text-muted-foreground',
        className,
      )}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        skipHtml
        components={{
          a({ href, children, ...props }) {
            const safeHref = sanitizeUserMessageHref(href)
            if (!safeHref) {
              return <span>{children}</span>
            }

            const external = isExternalLink(safeHref)
            return (
              <a
                href={safeHref}
                {...props}
                rel="nofollow ugc noopener noreferrer"
                {...(external
                  ? { target: '_blank' }
                  : {})}
              >
                {children}
              </a>
            )
          },
          img({ alt }) {
            return alt ? (
              <span className="text-muted-foreground">[Image: {alt}]</span>
            ) : null
          },
          code({ className: codeClassName, children, ...props }) {
            return (
              <code className={codeClassName} {...props}>
                {children}
              </code>
            )
          },
          pre({ children }) {
            return (
              <pre className="my-3 overflow-x-auto rounded-lg border border-border bg-muted/40 p-3 text-[12px] leading-5 text-foreground">
                {children}
              </pre>
            )
          },
        }}
      >
        {content}
      </ReactMarkdown>
    </div>
  )
}
