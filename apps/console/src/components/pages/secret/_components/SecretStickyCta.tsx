import { useEffect, useState } from 'react'
import { AppwriteMark } from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { TryAppwriteButton } from './SecretParts'

/**
 * Slim Appwrite bar pinned to the bottom once the hero scrolls away,
 * hidden again from the closing call to action onward.
 */
export function SecretStickyCta({ heroId, closingId }: { heroId: string; closingId: string }) {
  const t = useT()
  const [pastHero, setPastHero] = useState(false)
  const [atClosing, setAtClosing] = useState(false)

  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return
    const hero = document.getElementById(heroId)
    const closing = document.getElementById(closingId)
    const observers: IntersectionObserver[] = []

    if (hero) {
      const observer = new IntersectionObserver(([entry]) => setPastHero(entry ? !entry.isIntersecting : false), {
        rootMargin: '-35% 0px 0px 0px',
      })
      observer.observe(hero)
      observers.push(observer)
    }
    if (closing) {
      // Stay hidden from the closing section down through the footer.
      const observer = new IntersectionObserver(([entry]) =>
        setAtClosing(entry ? entry.isIntersecting || entry.boundingClientRect.top < 0 : false),
      )
      observer.observe(closing)
      observers.push(observer)
    }
    return () => observers.forEach((observer) => observer.disconnect())
  }, [heroId, closingId])

  const visible = pastHero && !atClosing

  return (
    <div
      className={cn(
        'pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] transition-[opacity,transform] duration-300 sm:pb-5',
        visible ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0',
      )}
      inert={!visible}
    >
      <div
        className={cn(
          'relative flex w-full max-w-xl items-center gap-3 rounded-2xl border border-border bg-background/90 py-2 ps-2 pe-2 shadow-[0_1px_2px_rgba(0,0,0,0.03),0_24px_48px_-20px_rgba(0,0,0,0.45)] backdrop-blur-md sm:w-auto sm:rounded-full dark:bg-card/90',
          visible && 'pointer-events-auto',
        )}
      >
        <span className="product-tone-hairline absolute inset-x-10 -top-px h-px" aria-hidden />
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-[var(--brand-cta)]/10">
          <AppwriteMark className="size-4" />
        </span>
        <p className="min-w-0 flex-1 text-start leading-tight">
          <span className="block font-aeonik-pro text-[14px] text-foreground">Appwrite</span>
          <span className="block truncate text-[11px] text-muted-foreground">
            {t('The open-source cloud for agents and developers')}
          </span>
        </p>
        {/* Pill inside the pill: same radius family as the bar so the button nests instead of floating. */}
        <TryAppwriteButton
          size="md"
          analytics="secret-sticky-start-building"
          className="h-8 shrink-0 rounded-xl px-3.5 text-[13px] sm:ms-4 sm:rounded-full"
        />
      </div>
    </div>
  )
}
