import { Check, MessageSquare, Radio } from 'lucide-react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const COLLABORATORS = [
  { name: 'Happy Quinn', color: 'var(--brand-cta)' },
  { name: 'Paige Dineen', color: '#7C67FE' },
  { name: 'Toby Curtis', color: '#85DBD8' },
] as const

function PeerCursor({
  name,
  color,
  className,
}: {
  name: string
  color: string
  className?: string
}) {
  return (
    <div
      className={cn(
        'pointer-events-none absolute z-10 flex origin-top-start items-center gap-1 opacity-0 transition-all duration-700 ease-out',
        className,
      )}
    >
      <svg
        width="17"
        height="20"
        viewBox="0 0 24 28"
        className="shrink-0 drop-shadow-[0_1px_4px_rgba(0,0,0,0.22)]"
        aria-hidden
      >
        <path
          d="M4 2.5v19.8c0 .55.66.82 1.04.43l5.9-5.7a.6.6 0 0 1 .42-.17h8.2c.55 0 .82-.66.43-1.04L5.47 2.07A.6.6 0 0 0 4 2.5Z"
          fill={color}
          stroke="white"
          strokeWidth="1.75"
          strokeLinejoin="round"
        />
      </svg>
      <span
        className="rounded-full px-2 py-0.5 text-[9px] font-semibold leading-none text-white shadow-[0_1px_4px_rgba(0,0,0,0.18)]"
        style={{ backgroundColor: color }}
      >
        {name.split(' ')[0]}
      </span>
    </div>
  )
}

export function AuthPresencesVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      eyebrow={t('Team workspace')}
      title={t('See who is working together')}
    >
      <div className="space-y-4">
        <div className="flex items-center justify-between gap-2">
          <div className="flex -space-x-1.5">
            {COLLABORATORS.map((peer) => (
              <InitialsAvatar
                key={peer.name}
                name={peer.name}
                size="xs"
                className="ring-2 ring-background"
              />
            ))}
          </div>
          <div className="flex items-center gap-2">
            <span className="relative flex size-2">
              <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500/30 opacity-0 motion-reduce:animate-none group-hover/visual:opacity-100" />
              <span className="relative inline-flex size-2 rounded-full bg-muted-foreground transition-colors duration-300 group-hover/visual:bg-emerald-500" />
            </span>
            <p className="text-[11px] font-medium text-muted-foreground transition-colors duration-300 group-hover/visual:text-foreground">
              3 {t('online')}
            </p>
            <Badge variant="info" className="hidden shrink-0 text-[10px] sm:inline-flex">
              {t('Live')}
            </Badge>
          </div>
        </div>

        <div className="relative overflow-hidden rounded-lg border border-border bg-muted/15 p-3">
          <PeerCursor
            name="Happy Quinn"
            color={COLLABORATORS[0].color}
            className="start-[6%] top-[18%] translate-x-0 translate-y-0 group-hover/visual:translate-x-12 group-hover/visual:translate-y-1 group-hover/visual:opacity-100 motion-reduce:translate-x-12 motion-reduce:translate-y-1 motion-reduce:opacity-100"
          />
          <PeerCursor
            name="Paige Dineen"
            color={COLLABORATORS[1].color}
            className="start-[6%] top-[58%] translate-x-0 translate-y-0 delay-150 group-hover/visual:translate-x-16 group-hover/visual:-translate-y-1 group-hover/visual:opacity-100 motion-reduce:translate-x-16 motion-reduce:-translate-y-1 motion-reduce:opacity-100"
          />

          <div className="flex items-start justify-between gap-2">
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Shared doc')}
              </p>
              <p className="mt-1 text-[12px] font-semibold text-foreground">
                {t('Q4 launch plan')}
              </p>
            </div>
            <Radio
              className="size-3.5 shrink-0 text-muted-foreground motion-reduce:animate-none group-hover/visual:animate-pulse"
              aria-hidden
            />
          </div>

          <div className="relative mt-3 space-y-1.5">
            <div className="flex items-center gap-2 rounded-md border border-border/60 bg-background/80 px-2 py-1.5">
              <span
                className={cn(
                  'flex size-3.5 shrink-0 items-center justify-center rounded border border-border bg-background transition-[border-color,background-color] duration-500 delay-300',
                  'group-hover/visual:border-emerald-500/50 group-hover/visual:bg-emerald-500/15 motion-reduce:delay-0',
                )}
              >
                <Check
                  className="size-2.5 text-emerald-600 opacity-0 transition-opacity duration-500 delay-300 group-hover/visual:opacity-100 motion-reduce:opacity-100 motion-reduce:delay-0"
                  aria-hidden
                />
              </span>
              <span className="text-[11px] text-muted-foreground transition-[color,text-decoration-color] duration-500 delay-300 group-hover/visual:line-through motion-reduce:delay-0">
                {t('Finalize hero copy')}
              </span>
            </div>
            <div className="flex items-center gap-2 px-2 py-1">
              <span className="size-3.5 shrink-0 rounded border border-border/80 bg-background" />
              <span className="text-[11px] text-muted-foreground">
                {t('Review pricing section')}
              </span>
            </div>
          </div>

          <p className="mt-3 text-[10px] text-muted-foreground">
            <span className="font-medium text-foreground/80">Paige</span> {t('viewing')}{' '}
            <span className="font-mono">/teams/acme/roadmap</span>
          </p>
        </div>

        <div className="flex items-center gap-2 rounded-lg border border-border bg-background/80 px-3 py-2.5">
          <MessageSquare className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <div className="min-w-0 flex-1">
            <p className="truncate text-[11px] text-muted-foreground">
              <span className="font-medium text-foreground">Happy Quinn</span>{' '}
              {t('is typing in team chat')}
            </p>
            <div className="mt-1 flex gap-1">
              {[0, 1, 2].map((dot) => (
                <span
                  key={dot}
                  className="size-1 rounded-full bg-muted-foreground/50 motion-reduce:animate-none group-hover/visual:animate-bounce"
                  style={{ animationDelay: `${dot * 120}ms` }}
                  aria-hidden
                />
              ))}
            </div>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('Typing')}
          </Badge>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
