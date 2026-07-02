import type { CSSProperties, ReactNode } from 'react'
import { cn } from '@/lib/utils'

const SQL_MS_PER_CHAR = 36
const SQL_LINE_GAP_MS = 32

/** VS Code–inspired token colors for compact mock code snippets (on bento hover). */
export const syntax = {
  keyword: 'text-blue-600 dark:text-blue-400',
  string: 'text-emerald-600 dark:text-emerald-400',
  number: 'text-amber-600 dark:text-amber-400',
  property: 'text-sky-600 dark:text-sky-400',
  function: 'text-violet-600 dark:text-violet-400',
  class: 'text-yellow-600 dark:text-yellow-400',
  type: 'text-cyan-700 dark:text-cyan-400',
  operator: 'text-muted-foreground',
  punctuation: 'text-muted-foreground/90',
  identifier: 'text-foreground',
  comment: 'text-muted-foreground italic',
  sqlKeyword: 'text-blue-600 dark:text-blue-400',
  sqlFunction: 'text-violet-600 dark:text-violet-400',
  sqlTable: 'text-cyan-700 dark:text-cyan-400',
  sqlAlias: 'text-orange-600 dark:text-orange-400',
} as const

const syntaxHover: Record<keyof typeof syntax, string> = {
  keyword: 'group-hover:text-blue-600 dark:group-hover:text-blue-400',
  string: 'group-hover:text-emerald-600 dark:group-hover:text-emerald-400',
  number: 'group-hover:text-amber-600 dark:group-hover:text-amber-400',
  property: 'group-hover:text-sky-600 dark:group-hover:text-sky-400',
  function: 'group-hover:text-violet-600 dark:group-hover:text-violet-400',
  class: 'group-hover:text-yellow-600 dark:group-hover:text-yellow-400',
  type: 'group-hover:text-cyan-700 dark:group-hover:text-cyan-400',
  operator: 'group-hover:text-muted-foreground',
  punctuation: 'group-hover:text-muted-foreground/90',
  identifier: 'group-hover:text-foreground',
  comment: 'group-hover:text-muted-foreground',
  sqlKeyword: 'group-hover:text-blue-600 dark:group-hover:text-blue-400',
  sqlFunction: 'group-hover:text-violet-600 dark:group-hover:text-violet-400',
  sqlTable: 'group-hover:text-cyan-700 dark:group-hover:text-cyan-400',
  sqlAlias: 'group-hover:text-orange-600 dark:group-hover:text-orange-400',
}

/** Shared muted-until-hover classes for product bento visuals. */
export const productBentoIdle = {
  text: 'text-muted-foreground transition-colors duration-300 group-hover:text-foreground',
  brandIcon:
    'text-muted-foreground transition-colors duration-300 group-hover:text-[var(--brand-cta)]',
  brandDot:
    'bg-muted-foreground transition-colors duration-300 group-hover:bg-[var(--brand-cta)]',
  emeraldIcon:
    'text-muted-foreground transition-colors duration-300 group-hover:text-emerald-600 dark:group-hover:text-emerald-400',
  link: 'text-muted-foreground transition-colors duration-300 group-hover:text-blue-600 dark:group-hover:text-blue-400',
  scaleBar:
    'bg-muted-foreground/20 transition-all duration-500 group-hover:bg-[var(--brand-cta)]/80 motion-reduce:group-hover:bg-muted-foreground/20',
  slider:
    'bg-muted-foreground/30 transition-colors duration-300 group-hover:bg-primary/75',
  ring: 'stroke-muted-foreground transition-colors duration-300 group-hover:stroke-emerald-500',
  buildBar:
    'bg-muted-foreground/30 transition-colors duration-300 group-hover:bg-[var(--brand-cta)]',
  ctaBlock:
    'bg-muted-foreground/25 transition-colors duration-300 group-hover:bg-[var(--brand-cta)]/85',
  providerIcon:
    'opacity-45 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:group-hover:opacity-45',
} as const

/** Shared container surfaces so idle mock cards match across product tiles. */
export const productBentoContainer = {
  shell:
    'overflow-hidden rounded-md border border-border bg-card/80 shadow-sm',
  panel: 'rounded-md border border-border bg-card/80',
  panelMd: 'rounded-md border border-border bg-card/80',
  header: 'shrink-0 border-b border-border bg-muted/15',
} as const

export function Syn({
  tone,
  className,
  children,
}: {
  tone: keyof typeof syntax
  className?: string
  children: ReactNode
}) {
  const mutedIdle =
    tone === 'punctuation' ? 'text-muted-foreground/80' : 'text-muted-foreground'

  return (
    <span
      className={cn(
        'transition-colors duration-300',
        mutedIdle,
        syntaxHover[tone],
        tone === 'comment' && 'italic',
        className,
      )}
    >
      {children}
    </span>
  )
}

export function SqlBlock({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return <div className={cn('space-y-0.5 leading-normal', className)}>{children}</div>
}

export function SqlLine({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return <div className={className}>{children}</div>
}

export function SqlTypingLine({
  children,
  charCount,
  startDelayMs,
  className,
  showCursor = false,
}: {
  children: ReactNode
  charCount: number
  startDelayMs: number
  className?: string
  showCursor?: boolean
}) {
  const durationMs = charCount * SQL_MS_PER_CHAR
  const cursorDelayMs = startDelayMs + durationMs

  return (
    <div className={cn('min-h-[1.4em] overflow-hidden', className)}>
      <span className="inline-flex max-w-full items-center">
        <span
          className="product-bento-sql-typing-line inline-block max-w-0 overflow-hidden whitespace-nowrap"
          style={
            {
              '--sql-type-chars': charCount,
              '--sql-type-duration': `${durationMs}ms`,
              '--sql-type-delay': `${startDelayMs}ms`,
            } as CSSProperties
          }
        >
          {children}
        </span>
        {showCursor ? (
          <span
            className="ms-px inline-block h-[1em] w-px shrink-0 bg-muted-foreground opacity-0 group-hover:animate-[ai-mock-cursor-blink_1s_step-end_infinite] motion-reduce:opacity-100"
            style={{ animationDelay: `${cursorDelayMs}ms` }}
            aria-hidden
          />
        ) : null}
      </span>
    </div>
  )
}

function nextSqlLineDelay(currentDelayMs: number, charCount: number) {
  return currentDelayMs + charCount * SQL_MS_PER_CHAR + SQL_LINE_GAP_MS
}

function buildSqlLineDelays(charCounts: number[], baseDelayMs: number) {
  let delay = baseDelayMs
  return charCounts.map((charCount) => {
    const start = delay
    delay = nextSqlLineDelay(delay, charCount)
    return start
  })
}

export function PostgresIdleSql() {
  return (
    <SqlBlock>
      <SqlLine>
        <Syn tone="sqlKeyword">SELECT</Syn>{' '}
        <Syn tone="operator">*</Syn>
      </SqlLine>
      <SqlLine>
        <Syn tone="sqlKeyword">FROM</Syn>{' '}
        <Syn tone="sqlTable">lap_times</Syn>
        <Syn tone="punctuation">;</Syn>
      </SqlLine>
    </SqlBlock>
  )
}

export function PostgresTypedSql({ baseDelayMs = 160 }: { baseDelayMs?: number }) {
  const [line1, line2, line3, line4, line5] = buildSqlLineDelays(
    [24, 16, 36, 23, 12],
    baseDelayMs,
  )

  return (
    <SqlBlock>
      <SqlTypingLine charCount={24} startDelayMs={line1}>
        <Syn tone="sqlKeyword">SELECT</Syn>{' '}
        <Syn tone="sqlAlias">d</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="identifier">code</Syn>
        <Syn tone="punctuation">, </Syn>
        <Syn tone="sqlAlias">l</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="identifier">lap_ms</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={16} startDelayMs={line2}>
        <Syn tone="sqlKeyword">FROM</Syn>{' '}
        <Syn tone="sqlTable">lap_times</Syn>{' '}
        <Syn tone="sqlAlias">l</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={36} startDelayMs={line3}>
        <Syn tone="sqlKeyword">JOIN</Syn>{' '}
        <Syn tone="sqlTable">drivers</Syn>{' '}
        <Syn tone="sqlAlias">d</Syn>{' '}
        <Syn tone="sqlKeyword">ON</Syn>{' '}
        <Syn tone="sqlAlias">d</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="identifier">id</Syn>{' '}
        <Syn tone="operator">=</Syn>{' '}
        <Syn tone="sqlAlias">l</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="identifier">driver_id</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={23} startDelayMs={line4}>
        <Syn tone="sqlKeyword">WHERE</Syn>{' '}
        <Syn tone="sqlAlias">l</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="identifier">session</Syn>{' '}
        <Syn tone="operator">=</Syn>{' '}
        <Syn tone="string">&apos;Q3&apos;</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={12} startDelayMs={line5} showCursor>
        <Syn tone="sqlKeyword">LIMIT</Syn>{' '}
        <Syn tone="number">5</Syn>
        <Syn tone="punctuation">;</Syn>
      </SqlTypingLine>
    </SqlBlock>
  )
}

export function MySqlIdleSql() {
  return (
    <SqlBlock>
      <SqlLine>
        <Syn tone="sqlKeyword">SELECT</Syn>{' '}
        <Syn tone="operator">*</Syn>
      </SqlLine>
      <SqlLine>
        <Syn tone="sqlKeyword">FROM</Syn>{' '}
        <Syn tone="sqlTable">championship_standings</Syn>
        <Syn tone="punctuation">;</Syn>
      </SqlLine>
    </SqlBlock>
  )
}

export function MySqlTypedSql({ baseDelayMs = 160 }: { baseDelayMs?: number }) {
  const [line1, line2, line3, line4, line5] = buildSqlLineDelays(
    [27, 17, 19, 14, 18],
    baseDelayMs,
  )

  return (
    <SqlBlock>
      <SqlTypingLine charCount={27} startDelayMs={line1}>
        <Syn tone="sqlKeyword">SELECT</Syn>{' '}
        <Syn tone="identifier">team</Syn>
        <Syn tone="punctuation">, </Syn>
        <Syn tone="sqlFunction">SUM</Syn>
        <Syn tone="punctuation">(</Syn>
        <Syn tone="identifier">points</Syn>
        <Syn tone="punctuation">)</Syn>{' '}
        <Syn tone="sqlKeyword">AS</Syn>{' '}
        <Syn tone="sqlAlias">pts</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={17} startDelayMs={line2}>
        <Syn tone="sqlKeyword">FROM</Syn>{' '}
        <Syn tone="sqlTable">race_results</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={19} startDelayMs={line3}>
        <Syn tone="sqlKeyword">WHERE</Syn>{' '}
        <Syn tone="identifier">season</Syn>{' '}
        <Syn tone="operator">=</Syn>{' '}
        <Syn tone="number">2026</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={14} startDelayMs={line4}>
        <Syn tone="sqlKeyword">GROUP BY</Syn>{' '}
        <Syn tone="identifier">team</Syn>
      </SqlTypingLine>
      <SqlTypingLine charCount={18} startDelayMs={line5} showCursor>
        <Syn tone="sqlKeyword">ORDER BY</Syn>{' '}
        <Syn tone="sqlAlias">pts</Syn>{' '}
        <Syn tone="sqlKeyword">DESC</Syn>
        <Syn tone="punctuation">;</Syn>
      </SqlTypingLine>
    </SqlBlock>
  )
}

export function QueryEqualFilter() {
  return (
    <>
      <Syn tone="class">Query</Syn>
      <Syn tone="punctuation">.</Syn>
      <Syn tone="function">equal</Syn>
      <Syn tone="punctuation">(</Syn>
      <Syn tone="string">&quot;session_id&quot;</Syn>
      <Syn tone="punctuation">, </Syn>
      <Syn tone="string">&quot;Q3_MON&quot;</Syn>
      <Syn tone="punctuation">)</Syn>
    </>
  )
}

export function AuthMagicLinkSnippet() {
  return (
    <div className="font-mono text-[9px] leading-relaxed sm:text-[10px]">
      <div>
        <Syn tone="keyword">await</Syn>{' '}
        <Syn tone="identifier">account</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="function">createMagicURLSession</Syn>
        <Syn tone="punctuation">({'{'}</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="property">email</Syn>
        <Syn tone="punctuation">: </Syn>
        <Syn tone="string">&apos;paige@acme.io&apos;</Syn>
        <Syn tone="punctuation">,</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="property">url</Syn>
        <Syn tone="punctuation">: </Syn>
        <Syn tone="string">&apos;https://acme.io/auth&apos;</Syn>
        <Syn tone="punctuation">,</Syn>
      </div>
      <div>
        <Syn tone="punctuation">{'}'})</Syn>
      </div>
    </div>
  )
}

export function MessagingEmailSnippet() {
  return (
    <div className="font-mono text-[9px] leading-relaxed sm:text-[10px]">
      <div>
        <Syn tone="keyword">await</Syn>{' '}
        <Syn tone="identifier">messaging</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="function">createEmail</Syn>
        <Syn tone="punctuation">({'{'}</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="property">subject</Syn>
        <Syn tone="punctuation">: </Syn>
        <Syn tone="string">&apos;Welcome to Acme&apos;</Syn>
        <Syn tone="punctuation">,</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="property">topics</Syn>
        <Syn tone="punctuation">: [</Syn>
        <Syn tone="string">&apos;product-updates&apos;</Syn>
        <Syn tone="punctuation">],</Syn>
      </div>
      <div>
        <Syn tone="punctuation">{'}'})</Syn>
      </div>
    </div>
  )
}

export function FunctionsStripeSnippet() {
  return (
    <div className="font-mono text-[9px] leading-relaxed sm:text-[10px]">
      <div>
        <Syn tone="keyword">await</Syn>{' '}
        <Syn tone="identifier">tables</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="function">updateRow</Syn>
        <Syn tone="punctuation">({'{'}</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="property">plan</Syn>
        <Syn tone="punctuation">: </Syn>
        <Syn tone="string">&apos;pro&apos;</Syn>
        <Syn tone="punctuation">,</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="property">stripeCustomerId</Syn>
        <Syn tone="punctuation">: </Syn>
        <Syn tone="identifier">customer</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="property">id</Syn>
        <Syn tone="punctuation">,</Syn>
      </div>
      <div>
        <Syn tone="punctuation">{'}'})</Syn>
      </div>
    </div>
  )
}

export function VectorsDbSearchSnippet() {
  return (
    <div className="font-mono text-[9px] leading-relaxed sm:text-[10px]">
      <div>
        <Syn tone="keyword">await</Syn>{' '}
        <Syn tone="identifier">vectorsDB</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="function">createTextEmbeddings</Syn>
        <Syn tone="punctuation">({'{'}</Syn>
        <Syn tone="property">texts</Syn>
        <Syn tone="punctuation">: [</Syn>
        <Syn tone="string">&apos;Monaco undercut on Medium&apos;</Syn>
        <Syn tone="punctuation">]</Syn>
        <Syn tone="punctuation">{'}'})</Syn>
      </div>
      <div>
        <Syn tone="keyword">await</Syn>{' '}
        <Syn tone="identifier">vectorsDB</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="function">listDocuments</Syn>
        <Syn tone="punctuation">({'{'}</Syn>
        <Syn tone="property">databaseId</Syn>
        <Syn tone="punctuation">,</Syn>
        <Syn tone="property"> collectionId</Syn>
        <Syn tone="punctuation">,</Syn>
        <Syn tone="property"> queries</Syn>
        <Syn tone="punctuation">{'}'})</Syn>
      </div>
    </div>
  )
}

export function RealtimeSubscribeSnippet() {
  return (
    <div className="font-mono text-[9px] leading-relaxed sm:text-[10px]">
      <div>
        <Syn tone="keyword">await</Syn>{' '}
        <Syn tone="identifier">realtime</Syn>
        <Syn tone="punctuation">.</Syn>
        <Syn tone="function">subscribe</Syn>
        <Syn tone="punctuation">([</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="string">&apos;databases.*.tables.docs.rows.*.update&apos;</Syn>
        <Syn tone="punctuation">],</Syn>
      </div>
      <div className="ps-2">
        <Syn tone="identifier">onDocChange</Syn>
        <Syn tone="punctuation">)</Syn>
      </div>
    </div>
  )
}
