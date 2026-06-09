import { useMemo } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import { cn } from '@/lib/utils'

const APPWRITE_DOCS_ORIGIN = 'https://appwrite.io'

function normalizeApiDescriptionMarkdown(content: string): string {
  return content
    .replace(/\r\n/g, '\n')
    .trim()
    .replace(
      /(\]\()\/(docs\/[^)]+)(\))/g,
      `$1${APPWRITE_DOCS_ORIGIN}/$2$3`,
    )
}

function isExternalDomainLink(href?: string): boolean {
  if (!href) return false

  if (
    href.startsWith('mailto:') ||
    href.startsWith('tel:') ||
    href.startsWith('#')
  ) {
    return false
  }

  try {
    if (typeof window === 'undefined') {
      return /^https?:\/\//i.test(href) || href.startsWith('//')
    }

    const current = new URL(window.location.href)
    const resolved = new URL(href, current)

    return (
      (resolved.protocol === 'http:' || resolved.protocol === 'https:') &&
      resolved.hostname !== current.hostname
    )
  } catch {
    return false
  }
}

type MethodDescriptionMarkdownProps = {
  content: string
  className?: string
}

export function MethodDescriptionMarkdown({
  content,
  className,
}: MethodDescriptionMarkdownProps) {
  const normalized = useMemo(
    () => normalizeApiDescriptionMarkdown(content),
    [content],
  )

  return (
    <div
      className={cn(
        'rounded-lg border border-border bg-muted/25 px-4 py-3.5',
        'text-[14px] leading-[1.65] tracking-[0.01em] text-foreground/88',
        '[&_p]:my-0 [&_p+p]:mt-3',
        '[&_strong]:font-semibold [&_strong]:text-foreground',
        '[&_em]:text-foreground/90',
        '[&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_a]:underline-offset-[3px] hover:[&_a]:text-primary/80',
        '[&_code]:rounded-sm [&_code]:bg-background/80 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[12px] [&_code]:text-foreground [&_code]:ring-1 [&_code]:ring-border/60',
        '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5',
        '[&_ol]:my-3 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:pl-5',
        '[&_li]:text-foreground/88',
        className,
      )}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          a({ href, children, ...props }) {
            const openInNewWindow = isExternalDomainLink(href)

            return (
              <a
                href={href}
                target={openInNewWindow ? '_blank' : undefined}
                rel={openInNewWindow ? 'noopener noreferrer' : undefined}
                {...props}
              >
                {children}
              </a>
            )
          },
          pre({ children }) {
            return <>{children}</>
          },
          code({ className: codeClassName, children, ...props }) {
            if (!codeClassName) {
              return (
                <code {...props}>{children}</code>
              )
            }

            return (
              <code
                className={cn(
                  'block overflow-x-auto whitespace-pre-wrap break-words p-3 font-mono text-[12px] leading-relaxed text-foreground',
                  'rounded-md border border-border bg-background/80',
                )}
              >
                {String(children ?? '').replace(/\n$/, '')}
              </code>
            )
          },
        }}
      >
        {normalized}
      </ReactMarkdown>
    </div>
  )
}
