import {
  createContext,
  useContext,
  useMemo,
  type ReactElement,
  type ReactNode,
} from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { CONTENT_BODY_FONT_CLASS } from '@/lib/docs/prose-typography'
import { cn } from '@/lib/utils'
import { slugifyHeading } from '@/lib/marketing/slugify'

type PolicyMarkdownProps = {
  content: string
  className?: string
}

const policyTableHeadClassName =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider whitespace-normal'

const policyTableCellClassName = 'px-4 py-3 align-top whitespace-normal text-[13px]'

const PolicyTableSectionContext = createContext<'header' | 'body'>('body')

function isExternalLink(href?: string): boolean {
  if (!href) return false
  if (href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('#')) {
    return false
  }
  if (href.startsWith('/')) return false
  return /^https?:\/\//i.test(href) || href.startsWith('//')
}

function extractText(children: ReactNode): string {
  if (typeof children === 'string') return children
  if (Array.isArray(children)) return children.map(extractText).join('')
  if (children && typeof children === 'object' && 'props' in children) {
    const element = children as ReactElement<{ children?: ReactNode }>
    return extractText(element.props.children)
  }
  return ''
}

function PolicyTableCellContent({ children }: { children: ReactNode }) {
  const text = extractText(children)
  if (text.includes('<br>')) {
    return (
      <>
        {text.split('<br>').map((line, index) => (
          <span key={index} className="block">
            {line.trim()}
          </span>
        ))}
      </>
    )
  }
  return <>{children}</>
}

export function extractPolicyToc(content: string) {
  const items: { id: string; label: string }[] = [{ id: 'introduction', label: 'Introduction' }]

  for (const match of content.matchAll(/^## (.+)$/gm)) {
    const label = match[1].trim()
    items.push({ id: slugifyHeading(label), label })
  }

  return items
}

export function PolicyMarkdown({ content, className }: PolicyMarkdownProps) {
  const safeContent = useMemo(() => content.replace(/\r\n/g, '\n').trim(), [content])

  return (
    <div
      className={cn(
        'policy-prose',
        CONTENT_BODY_FONT_CLASS,
        'text-[14px] leading-7 text-muted-foreground',
        '[&_p]:my-0 [&_p+p]:mt-4',
        '[&_h2]:mb-3 [&_h2]:mt-10 [&_h2]:scroll-mt-24 [&_h2]:font-aeonik-pro [&_h2]:text-[20px] [&_h2]:font-normal [&_h2]:text-foreground',
        '[&_h3]:mb-2 [&_h3]:mt-6 [&_h3]:scroll-mt-24 [&_h3]:text-[15px] [&_h3]:font-semibold [&_h3]:text-foreground',
        '[&_h4]:mb-2 [&_h4]:mt-5 [&_h4]:text-[14px] [&_h4]:font-semibold [&_h4]:text-foreground',
        '[&_ul]:my-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:ps-5',
        '[&_ol]:my-3 [&_ol]:list-decimal [&_ol]:space-y-1.5 [&_ol]:ps-5',
        '[&_li]:my-0',
        'prose-links-neutral',
        '[&_strong]:font-semibold [&_strong]:text-foreground',
        className,
      )}
    >
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          h2({ children, ...props }) {
            const text = extractText(children)
            const id = slugifyHeading(text)
            return (
              <h2 id={id} {...props}>
                {children}
              </h2>
            )
          },
          h3({ children, ...props }) {
            const text = extractText(children)
            const id = slugifyHeading(text)
            return (
              <h3 id={id} {...props}>
                {children}
              </h3>
            )
          },
          a({ href, children, ...props }) {
            const external = isExternalLink(href)
            return (
              <a
                href={href}
                {...props}
                {...(external
                  ? { target: '_blank', rel: 'noopener noreferrer' }
                  : {})}
              >
                {children}
              </a>
            )
          },
          table({ children }) {
            return (
              <div className="my-4 overflow-hidden rounded-lg border border-border bg-card">
                <Table>{children}</Table>
              </div>
            )
          },
          thead({ children }) {
            return (
              <PolicyTableSectionContext.Provider value="header">
                <TableHeader>{children}</TableHeader>
              </PolicyTableSectionContext.Provider>
            )
          },
          tbody({ children }) {
            return (
              <PolicyTableSectionContext.Provider value="body">
                <TableBody>{children}</TableBody>
              </PolicyTableSectionContext.Provider>
            )
          },
          tr({ children }) {
            const section = useContext(PolicyTableSectionContext)
            return (
              <TableRow
                className={cn(
                  'border-b border-border',
                  section === 'header'
                    ? 'hover:bg-transparent'
                    : 'hover:bg-muted/30',
                )}
              >
                {children}
              </TableRow>
            )
          },
          th({ children }) {
            return (
              <TableHead className={policyTableHeadClassName}>
                <PolicyTableCellContent>{children}</PolicyTableCellContent>
              </TableHead>
            )
          },
          td({ children }) {
            return (
              <TableCell className={policyTableCellClassName}>
                <PolicyTableCellContent>{children}</PolicyTableCellContent>
              </TableCell>
            )
          },
        }}
      >
        {safeContent}
      </ReactMarkdown>
    </div>
  )
}
