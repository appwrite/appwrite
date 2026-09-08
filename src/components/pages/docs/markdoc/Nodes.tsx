import { useTheme } from 'next-themes'
import type { CSSProperties, ReactNode } from 'react'
import { useMemo } from 'react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { resolveHeadingId } from '@/lib/docs/markdoc-heading'
import {
  DOCS_BODY_TEXT_CLASS,
  DOCS_TABLE_CELL_TEXT_CLASS,
} from '@/lib/docs/prose-typography'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import {
  BLOG_BODY_TEXT_CLASS,
  BLOG_TABLE_CELL_TEXT_CLASS,
  type MarkdocProseVariant,
} from '@/lib/blog/prose-typography'
import { cn } from '@/lib/utils'
import { DocsHeadingLink } from './DocsHeadingLink'
import { DocsMarkdocInTableProvider } from './DocsImage'
import { DocsRouteLink } from '../DocsRouteLink'

const tableHeadClassName =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider whitespace-normal'

function extractText(children: ReactNode): string {
  if (typeof children === 'string') return children
  if (Array.isArray(children)) return children.map(extractText).join('')
  if (children && typeof children === 'object' && 'props' in children) {
    const element = children as { props: { children?: ReactNode } }
    return extractText(element.props.children)
  }
  return ''
}

export function Heading({
  level = 1,
  id,
  children,
  compact = false,
  proseVariant = 'docs',
}: {
  level?: number
  id?: string
  children: ReactNode
  compact?: boolean
  proseVariant?: MarkdocProseVariant
}) {
  const text = extractText(children)
  const { title, id: headingId } = resolveHeadingId(text, id)
  const hasCustomAnchor = Boolean(id ?? text.match(/\{#|{%\s*#/))
  const displayChildren = hasCustomAnchor ? title : children

  // Page title is the layout h1; shift Markdoc heading levels down by one.
  const displayLevel = Math.min(level + 1, 6)
  const className = cn(
    'scroll-mt-24 font-aeonik-pro text-foreground/95 text-balance',
    compact
      ? [
          level === 1 &&
            'mb-3 mt-6 text-[16px] font-normal leading-[1.3] first:mt-0 @[480px]:text-[17px]',
          level === 2 &&
            'mb-2 mt-5 text-[14px] font-semibold leading-snug @[480px]:text-[15px]',
          level === 3 &&
            'mb-2 mt-4 text-[13px] font-semibold @[480px]:text-[14px]',
          level >= 4 &&
            'mb-1.5 mt-3 text-[12px] font-semibold @[480px]:text-[13px]',
        ]
      : proseVariant === 'blog'
        ? [
            level === 1 &&
              'mb-4 mt-8 text-[21px] font-normal leading-[1.3] first:mt-0 @[640px]:text-[23px]',
            level === 2 &&
              'mb-3 mt-7 text-[18px] font-semibold leading-snug @[640px]:text-[19px]',
            level === 3 &&
              'mb-2 mt-6 text-[17px] font-semibold @[640px]:text-[18px]',
            level >= 4 && 'mb-2 mt-5 text-[16px] font-semibold @[640px]:text-[17px]',
          ]
        : [
            level === 1 &&
              'mb-4 mt-8 text-[20px] font-normal leading-[1.3] first:mt-0 @[640px]:text-[22px]',
            level === 2 &&
              'mb-3 mt-7 text-[17px] font-semibold leading-snug @[640px]:text-[18px]',
            level === 3 &&
              'mb-2 mt-6 text-[16px] font-semibold @[640px]:text-[17px]',
            level >= 4 && 'mb-2 mt-5 text-[15px] font-semibold',
          ],
  )

  const headingProps = { id: headingId, className: cn(className, 'group') }
  const linkIconSizeClass = compact
    ? level === 1
      ? 'size-4'
      : level === 2
        ? 'size-3.5'
        : 'size-3'
    : level === 1
      ? 'size-[18px]'
      : level === 2
        ? 'size-4'
        : 'size-3.5'

  const content = (
    <DocsHeadingLink headingId={headingId} linkIconSizeClass={linkIconSizeClass}>
      {displayChildren}
    </DocsHeadingLink>
  )

  switch (displayLevel) {
    case 2:
      return <h2 {...headingProps}>{content}</h2>
    case 3:
      return <h3 {...headingProps}>{content}</h3>
    case 4:
      return <h4 {...headingProps}>{content}</h4>
    case 5:
      return <h5 {...headingProps}>{content}</h5>
    default:
      return <h6 {...headingProps}>{content}</h6>
  }
}

export function DocsLink({
  href,
  children,
}: {
  href?: string
  children: ReactNode
}) {
  if (!href) return <span>{children}</span>

  const external =
    href.startsWith('http') ||
    href.startsWith('//') ||
    href.startsWith('mailto:') ||
    href.startsWith('tel:')

  if (external || href.startsWith('#')) {
    return (
      <a
        href={href}
        className={DOCS_PROSE_LINK_CLASS}
        {...(external && !href.startsWith('#')
          ? { target: '_blank', rel: 'noopener noreferrer' }
          : {})}
      >
        {children}
      </a>
    )
  }

  return (
    <DocsRouteLink href={href} className={DOCS_PROSE_LINK_CLASS}>
      {children}
    </DocsRouteLink>
  )
}

export function OnlyLight({ children }: { children?: ReactNode }) {
  const { resolvedTheme } = useTheme()
  if (resolvedTheme === 'dark') return null
  return <>{children}</>
}

export function OnlyDark({ children }: { children?: ReactNode }) {
  const { resolvedTheme } = useTheme()
  if (resolvedTheme !== 'dark') return null
  return <>{children}</>
}

export function Blockquote({
  children,
  proseVariant = 'docs',
}: {
  children?: ReactNode
  proseVariant?: MarkdocProseVariant
}) {
  return (
    <blockquote
      className={cn(
        'my-5 border-s-2 border-[var(--brand-cta)] ps-4 italic',
        proseVariant === 'blog' ? BLOG_BODY_TEXT_CLASS : DOCS_BODY_TEXT_CLASS,
      )}
    >
      {children}
    </blockquote>
  )
}

/** Passthrough for `{% table %}` - styling is applied on the inner table node. */
export function MarkdocTableTag({ children }: { children?: ReactNode }) {
  return <>{children}</>
}

function normalizeMarkdocTableColumnWidths(
  columnWidths: Array<number | undefined | null> | undefined,
): Array<number | undefined> {
  if (!columnWidths?.length) return []

  // Markdoc may pass sparse arrays for unset `{% width %}` columns; holes break
  // fill-column detection because Array.prototype.filter skips empty slots.
  return Array.from({ length: columnWidths.length }, (_, index) => {
    const width = columnWidths[index]
    return typeof width === 'number' ? width : undefined
  })
}

function buildMarkdocTableColStyles(
  columnWidths: Array<number | undefined>,
): Array<CSSProperties | undefined> {
  const fixedTotalPx = columnWidths.reduce((sum, width) => sum + (width ?? 0), 0)
  const fillCount = columnWidths.filter((width) => width == null).length

  if (fillCount === 0) {
    return columnWidths.map((width) =>
      width != null ? { width: `${width}px`, minWidth: `${width}px` } : undefined,
    )
  }

  return columnWidths.map((width) => {
    if (width != null) {
      return { width: `${width}px`, minWidth: `${width}px` }
    }

    if (fillCount === columnWidths.length) {
      return { width: `${100 / fillCount}%` }
    }

    return {
      width: `calc((100% - ${fixedTotalPx}px) / ${fillCount})`,
      minWidth: 0,
    }
  })
}

export function MarkdocTableRoot({
  children,
  columnWidths: columnWidthsProp = [],
}: {
  children?: ReactNode
  columnWidths?: Array<number | undefined | null>
}) {
  const columnWidths = normalizeMarkdocTableColumnWidths(columnWidthsProp)
  const hasColumnWidths = columnWidths.some((width) => width != null)
  const colStyles = useMemo(
    () => (hasColumnWidths ? buildMarkdocTableColStyles(columnWidths) : []),
    [columnWidths, hasColumnWidths],
  )

  return (
    <div className="not-prose my-6 w-full overflow-hidden rounded-lg border border-border bg-card/50">
      <Table withScrollContainer className={hasColumnWidths ? 'table-fixed' : undefined}>
        {hasColumnWidths ? (
          <colgroup>
            {colStyles.map((style, index) => (
              <col key={index} style={style} />
            ))}
          </colgroup>
        ) : null}
        {children}
      </Table>
    </div>
  )
}

export function MarkdocTableHeader({ children }: { children?: ReactNode }) {
  return (
    <TableHeader className="[&_tr]:border-b [&_tr]:border-border [&_tr]:hover:bg-transparent">
      {children}
    </TableHeader>
  )
}
MarkdocTableHeader.displayName = 'MarkdocTableHeader'

export function MarkdocTableBody({ children }: { children?: ReactNode }) {
  return <TableBody>{children}</TableBody>
}
MarkdocTableBody.displayName = 'MarkdocTableBody'

export function MarkdocTableRow({ children }: { children?: ReactNode }) {
  return <TableRow className="border-b border-border hover:bg-muted/30">{children}</TableRow>
}
MarkdocTableRow.displayName = 'MarkdocTableRow'

export function MarkdocTableHead({
  children,
}: {
  children?: ReactNode
  width?: number
}) {
  return (
    <TableHead className={cn(tableHeadClassName, 'whitespace-normal')}>{children}</TableHead>
  )
}
MarkdocTableHead.displayName = 'MarkdocTableHead'

export function MarkdocTableCell({
  children,
  proseVariant = 'docs',
}: {
  children?: ReactNode
  proseVariant?: MarkdocProseVariant
}) {
  const cellTextClass =
    proseVariant === 'blog' ? BLOG_TABLE_CELL_TEXT_CLASS : DOCS_TABLE_CELL_TEXT_CLASS

  return (
    <TableCell
      className={cn(
        'px-4 py-3 align-top whitespace-normal',
        cellTextClass,
        '[&_strong]:font-semibold [&_strong]:text-foreground',
      )}
    >
      <DocsMarkdocInTableProvider>{children}</DocsMarkdocInTableProvider>
    </TableCell>
  )
}
