import { MousePointerClick } from 'lucide-react'
import { ArtChip, ArtPanel, ArtToken, ArtWindow, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { AnalyticsArtRow } from '@/components/pages/products/features/analytics/AnalyticsArtParts'
import { useT } from '@/lib/i18n/translate'

const EVENTS = [
  { label: 'signup_completed', value: '1,284', percent: 100 },
  { label: 'checkout_started', value: '962', percent: 75 },
  { label: 'plan_upgraded', value: '311', percent: 24 },
] as const

/** A tracking call in the editor, and the event landing in the Custom events card. */
export function AnalyticsCustomEventsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] py-2">
      <div className="product-hero-rise sm:me-10" style={riseStyle(40)}>
        <ArtWindow title="signup.ts" bodyClassName="px-4 py-3.5">
          <pre dir="ltr" className="overflow-hidden font-mono text-[11.5px] leading-[1.7]">
            <code>
              <ArtToken tone="comment">{'// After a successful sign-up'}</ArtToken>
              {'\n'}
              <ArtToken tone="identifier">tracking</ArtToken>
              <ArtToken tone="punctuation">.</ArtToken>
              <ArtToken tone="function">track</ArtToken>
              <ArtToken tone="punctuation">(</ArtToken>
              <ArtToken tone="string">{"'signup_completed'"}</ArtToken>
              <ArtToken tone="punctuation">, {'{'}</ArtToken>
              {'\n  '}
              <ArtToken tone="property">props</ArtToken>
              <ArtToken tone="punctuation">: {'{'} </ArtToken>
              <ArtToken tone="property">plan</ArtToken>
              <ArtToken tone="punctuation">: </ArtToken>
              <ArtToken tone="string">{"'pro'"}</ArtToken>
              <ArtToken tone="punctuation">, </ArtToken>
              <ArtToken tone="property">source</ArtToken>
              <ArtToken tone="punctuation">: </ArtToken>
              <ArtToken tone="string">{"'pricing'"}</ArtToken>
              <ArtToken tone="punctuation"> {'}'},</ArtToken>
              {'\n'}
              <ArtToken tone="punctuation">{'})'}</ArtToken>
            </code>
          </pre>
        </ArtWindow>
      </div>

      <div className="ms-10 h-8 border-s border-dashed border-foreground/25" aria-hidden />

      {/* Breakdown by an event property, floating beside the card. */}
      <ArtChip className="end-0 top-[46%] hidden sm:block" delayMs={620} floatDelayMs={500}>
        <div className="space-y-1 text-[11px]">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('By plan')}
          </p>
          <p className="flex items-center justify-between gap-4">
            <span dir="ltr" className="font-mono text-foreground">pro</span>
            <span dir="ltr" className="tabular-nums text-foreground">812</span>
          </p>
          <p className="flex items-center justify-between gap-4">
            <span dir="ltr" className="font-mono text-foreground">starter</span>
            <span dir="ltr" className="tabular-nums text-muted-foreground">472</span>
          </p>
        </div>
      </ArtChip>

      <ArtPanel
        className="sm:ms-10 sm:w-[300px]"
        delayMs={260}
        float
        floatDelayMs={300}
        innerClassName="product-tone-shadow p-2"
      >
        <p className="flex items-center gap-1.5 px-2 pb-1.5 pt-1 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">
          <MousePointerClick className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {t('Custom events')}
        </p>
        <div className="space-y-0.5">
          {EVENTS.map((event, index) => (
            <AnalyticsArtRow
              key={event.label}
              label={event.label}
              value={event.value}
              percent={event.percent}
              mono
              highlight={index === 0}
            />
          ))}
        </div>
      </ArtPanel>
    </div>
  )
}
