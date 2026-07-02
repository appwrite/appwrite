import { Check, Radio } from 'lucide-react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

const PEERS = [
  { name: 'Happy Quinn', color: 'var(--brand-cta)' },
  { name: 'Paige Dineen', color: '#7C67FE' },
  { name: 'You', color: '#85DBD8' },
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
        className="rounded-full px-2.5 py-1 text-[10px] font-semibold leading-none text-white shadow-[0_1px_4px_rgba(0,0,0,0.18)] sm:text-[11px]"
        style={{ backgroundColor: color }}
      >
        {name.split(' ')[0]}
      </span>
    </div>
  )
}

function PresenceBar() {
  const t = useT()
  return (
    <div className="flex items-center justify-between gap-2">
      <div className="flex -space-x-1.5">
        {PEERS.map((peer) => (
          <InitialsAvatar
            key={peer.name}
            name={peer.name}
            size="xs"
            className="ring-2 ring-background"
          />
        ))}
      </div>
      <div className="flex items-center gap-1.5">
        <span className="relative flex size-2">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-muted-foreground/30 opacity-0 motion-reduce:animate-none group-hover:bg-emerald-500 group-hover:opacity-40" />
          <span className={cn('relative inline-flex size-2 rounded-full bg-muted-foreground transition-colors duration-300 group-hover:bg-emerald-500')} />
        </span>
        <p className={cn('text-[11px] font-medium sm:text-[12px]', productBentoIdle.text)}>
          3 {t('online')}
        </p>
      </div>
    </div>
  )
}

export function RealtimeProductVisual() {
  const t = useT()
  return (
    <div className="absolute inset-0 flex flex-col">
      <div className={cn('mx-auto flex h-full min-h-0 w-full max-w-[21rem] flex-col', productBentoContainer.shell)}>
        <div className={cn(productBentoContainer.header, 'px-3.5 py-2.5')}>
          <div className="flex items-start justify-between gap-2">
            <div>
              <p className={cn('text-[12px] font-medium sm:text-[13px]', productBentoIdle.text)}>
                {t('Shared doc')}
              </p>
              <p className="mt-0.5 text-[11px] text-muted-foreground sm:text-[12px]">
                {t('Collaborate on the same page in real time')}
              </p>
            </div>
            <span className="relative mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background">
              <Radio
                className={cn('size-3.5 motion-reduce:animate-none group-hover:animate-pulse', productBentoIdle.brandIcon)}
                aria-hidden
              />
            </span>
          </div>
        </div>

        <div className="overflow-hidden p-3">
            <PresenceBar />

            <div className={cn('relative mt-2.5 overflow-hidden p-3', productBentoContainer.panel)}>
              <PeerCursor
                name="Happy Quinn"
                color={PEERS[0].color}
                className="start-[4%] top-[14%] translate-x-0 translate-y-0 group-hover:translate-x-14 group-hover:translate-y-2 group-hover:opacity-100 motion-reduce:translate-x-14 motion-reduce:translate-y-2 motion-reduce:opacity-100"
              />
              <PeerCursor
                name="Paige Dineen"
                color={PEERS[1].color}
                className="start-[4%] top-[50%] translate-x-0 translate-y-0 delay-150 group-hover:translate-x-12 group-hover:translate-y-0 group-hover:opacity-100 motion-reduce:translate-x-12 motion-reduce:opacity-100"
              />

              <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground sm:text-[11px]">
                {t('Launch plan')}
              </p>

              <div className="relative mt-2 rounded-md px-2 py-1.5">
                <p className={cn('relative min-h-[1.25rem] text-[12px] font-semibold leading-snug sm:text-[13px]', productBentoIdle.text)}>
                  <span className="transition-opacity duration-500 ease-out delay-500 group-hover:opacity-0 motion-reduce:delay-0 motion-reduce:group-hover:opacity-100">
                    {t('Launch landing page')}
                  </span>
                  <span className="absolute inset-0 opacity-0 transition-opacity duration-500 ease-out delay-700 group-hover:opacity-100 motion-reduce:static motion-reduce:delay-0 motion-reduce:opacity-100">
                    {t('Launch homepage v2')}
                  </span>
                </p>
              </div>

              <div className="mt-3 space-y-1.5">
                <div className="flex items-center gap-2 rounded-md border border-border/60 bg-muted/8 px-2 py-1.5">
                  <span
                    className={cn(
                      'flex size-3.5 shrink-0 items-center justify-center rounded border border-border bg-background transition-[border-color,background-color] duration-500 ease-out delay-500',
                      'group-hover:border-emerald-500/50 group-hover:bg-emerald-500/15 motion-reduce:delay-0 motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-background',
                    )}
                  >
                    <Check
                      className={cn('size-2.5 opacity-0 transition-opacity duration-500 ease-out delay-500 group-hover:opacity-100 motion-reduce:delay-0 motion-reduce:opacity-100', productBentoIdle.emeraldIcon)}
                      aria-hidden
                    />
                  </span>
                  <span className="text-[11px] text-muted-foreground transition-[color,text-decoration-color] duration-500 ease-out delay-500 group-hover:text-muted-foreground group-hover:line-through motion-reduce:delay-0 sm:text-[12px]">
                    {t('Draft hero copy')}
                  </span>
                </div>
                <div className="flex items-center gap-2 px-2 py-1">
                  <span className="size-3.5 shrink-0 rounded border border-border/80 bg-background" />
                  <span className="text-[11px] text-muted-foreground sm:text-[12px]">
                    {t('Ship pricing section')}
                  </span>
                </div>
              </div>
            </div>

            <p className="mt-2.5 text-center text-[10px] text-muted-foreground sm:text-[11px]">
              {t('Edits sync instantly for everyone in the doc')}
            </p>
        </div>
      </div>
    </div>
  )
}
