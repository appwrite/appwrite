import type { CSSProperties, ReactNode } from 'react'
import { cn } from '@/lib/utils'

const SQL_MS_PER_CHAR = 36
const SQL_LINE_GAP_MS = 32

/** VS Code–inspired token colors for compact mock code snippets. */
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

export function Syn({
  tone,
  className,
  children,
}: {
  tone: keyof typeof syntax
  className?: string
  children: ReactNode
}) {
  return <span className={cn(syntax[tone], className)}>{children}</span>
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
            className="ml-px inline-block h-[1em] w-px shrink-0 bg-muted-foreground opacity-0 group-hover:animate-[ai-mock-cursor-blink_1s_step-end_infinite] motion-reduce:opacity-100"
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
      <div className="pl-2">
        <Syn tone="property">email</Syn>
        <Syn tone="punctuation">: </Syn>
        <span className="text-foreground">&apos;sarah@acme.io&apos;</span>
        <Syn tone="punctuation">,</Syn>
      </div>
      <div className="pl-2">
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
      <div className="pl-2">
        <Syn tone="property">plan</Syn>
        <Syn tone="punctuation">: </Syn>
        <Syn tone="string">&apos;pro&apos;</Syn>
        <Syn tone="punctuation">,</Syn>
      </div>
      <div className="pl-2">
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
