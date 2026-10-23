import { motion } from 'motion/react'
import { RotateCcw } from 'lucide-react'
import { INIT5_HERO_VARIANTS } from '@/lib/init/init5-hero/hero-variant'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { useInit5Hero } from './init5-hero-context'

/**
 * Floating concept picker for choosing the Init 5 cover. Shown in dev builds
 * or when the page is opened with `?hero=` (e.g. `/init?event=init-nov-2026&hero=glass`).
 */
export function Init5HeroVariantSwitcher({
  className,
}: {
  className?: string
}) {
  const t = useT()
  const { variant, setVariant, replay } = useInit5Hero()

  return (
    <div
      className={cn(
        'pointer-events-none absolute inset-x-0 bottom-4 z-20 flex justify-center px-4 sm:bottom-6 lg:justify-end lg:px-6',
        className,
      )}
    >
      <div
        role="group"
        aria-label={t('Hero concept')}
        className="pointer-events-auto flex items-center gap-0.5 rounded-full border border-border/70 bg-background/70 p-1 shadow-[0_10px_40px_-12px_rgba(0,0,0,0.45)] backdrop-blur-xl"
      >
        <span className="hidden ps-3 pe-1.5 text-[10px] font-semibold uppercase tracking-[0.22em] text-muted-foreground sm:inline">
          {t('Concept')}
        </span>
        {INIT5_HERO_VARIANTS.map((option) => {
          const selected = option.id === variant
          return (
            <button
              key={option.id}
              type="button"
              aria-pressed={selected}
              title={t(option.description)}
              onClick={() => (selected ? replay() : setVariant(option.id))}
              className={cn(
                'relative rounded-full px-3 py-1.5 text-[12px] font-medium transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                selected
                  ? 'text-background'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {selected ? (
                <motion.span
                  layoutId="init5-hero-variant-pill"
                  className="absolute inset-0 rounded-full bg-foreground"
                  transition={{ type: 'spring', stiffness: 520, damping: 40 }}
                />
              ) : null}
              <span className="relative">{t(option.label)}</span>
            </button>
          )
        })}
        <button
          type="button"
          onClick={replay}
          aria-label={t('Replay intro')}
          title={t('Replay intro')}
          className="ms-0.5 grid size-7 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <RotateCcw className="size-3.5" />
        </button>
      </div>
    </div>
  )
}
