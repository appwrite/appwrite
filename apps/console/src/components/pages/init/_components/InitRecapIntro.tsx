import type { InitDisplayEvent } from '@/lib/init/types'

const CARD_SHELL =
  'rounded-xl border border-border bg-card/50 overflow-hidden'

interface InitRecapIntroProps {
  event: InitDisplayEvent
}

export function InitRecapIntro({ event }: InitRecapIntroProps) {
  if (!event.isRecapMode) return null

  const title = event.recap?.introTitle ?? 'Everything we shipped'
  const description =
    event.recap?.introDescription ??
    'Explore the full launch timeline, blog posts, and session replays from Init week.'

  return (
    <section className={CARD_SHELL}>
      <div className="px-6 py-4">
        <h2 className="text-[15px] font-semibold text-foreground">{title}</h2>
        <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
    </section>
  )
}
