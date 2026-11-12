import type { ReactNode } from 'react'
import { ArtPanel, ArtToken, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type PayloadField = {
  name: string
  type: string
  offsetClassName: string
  value: ReactNode
}

const PAYLOAD_FIELDS: PayloadField[] = [
  {
    name: 'events',
    type: 'string[]',
    offsetClassName: 'sm:me-10',
    value: (
      <>
        [<ArtToken tone="string">&apos;tablesdb.main.tables.orders.rows.8f2a.update&apos;</ArtToken>]
      </>
    ),
  },
  {
    name: 'channels',
    type: 'string[]',
    offsetClassName: 'sm:ms-6 sm:me-4',
    value: (
      <>
        [<ArtToken tone="string">&apos;rows&apos;</ArtToken>,{' '}
        <ArtToken tone="string">&apos;tablesdb.main.tables.orders.rows&apos;</ArtToken>]
      </>
    ),
  },
  {
    name: 'payload',
    type: 'object',
    offsetClassName: 'sm:ms-4 sm:me-8',
    value: (
      <>
        {'{ '}
        <ArtToken tone="property">$id</ArtToken>: <ArtToken tone="string">&apos;8f2a&apos;</ArtToken>
        , <ArtToken tone="property">status</ArtToken>:{' '}
        <ArtToken tone="string">&apos;paid&apos;</ArtToken>
        {' }'}
      </>
    ),
  },
]

function PayloadFieldPanel({ field, index }: { field: PayloadField; index: number }) {
  return (
    <ArtPanel
      className={cn('min-w-0', field.offsetClassName)}
      innerClassName={cn(
        'px-3 py-2.5',
        index === PAYLOAD_FIELDS.length - 1 &&
          'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
      )}
      delayMs={120 + index * 150}
    >
      <div className="flex items-center gap-2">
        <span dir="ltr" className="font-mono text-[11.5px] font-medium text-[var(--tone-ink)]">
          {field.name}
        </span>
        <span
          dir="ltr"
          className="shrink-0 rounded-full bg-[rgb(var(--tone2-rgb)/0.18)] px-1.5 py-px font-mono text-[9.5px] text-foreground"
        >
          {field.type}
        </span>
      </div>
      <p dir="ltr" className="mt-1 truncate font-mono text-[10.5px] text-muted-foreground">
        {field.value}
      </p>
    </ArtPanel>
  )
}

export function RealtimePayloadVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[420px] w-full max-w-[540px]">
      <div
        className="pointer-events-none absolute inset-x-6 top-1/2 h-[300px] -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.14),transparent)] blur-2xl"
        aria-hidden
      />

      <div className="relative flex h-full flex-col justify-center gap-2.5">
        <p
          className="product-hero-rise text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
          style={riseStyle(0)}
        >
          {t('Every message carries')}
        </p>
        {PAYLOAD_FIELDS.map((field, index) => (
          <PayloadFieldPanel key={field.name} field={field} index={index} />
        ))}
      </div>
    </div>
  )
}
