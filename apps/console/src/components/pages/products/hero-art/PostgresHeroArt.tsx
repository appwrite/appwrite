import { Clock3, Lock, Server, Waypoints } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { PostgresElephantIcon } from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import {
  ArtConnector,
  ArtLiveDot,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'

const ATTACHMENTS: { icon: LucideIcon; label: string; value: string }[] = [
  { icon: Waypoints, label: 'Pooler', value: 'On' },
  { icon: Server, label: 'Replicas', value: '2 sync' },
  { icon: Clock3, label: 'PITR', value: '7 days' },
]

export function PostgresHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[420px] py-8 text-start sm:max-w-[460px]">
      <div
        className="pointer-events-none absolute left-1/2 top-1/2 size-[320px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgb(var(--tone-rgb)/0.2),transparent)] blur-2xl"
        aria-hidden
      />

      <div className="relative flex flex-col items-center">
        <ArtPanel
          className="w-fit max-w-full"
          innerClassName="flex items-center gap-2 px-3 py-2"
          delayMs={60}
          float
        >
          <ArtLiveDot className="size-1.5" />
          <span
            dir="ltr"
            className="truncate font-mono text-[11px] text-foreground"
          >
            db-7f3a2c.fra.appwrite.center
          </span>
          <span className="flex items-center gap-1 border-s border-border ps-2 text-[10px] text-muted-foreground">
            <Lock className="size-3" aria-hidden />
            TLS
          </span>
        </ArtPanel>

        <div className="h-7" aria-hidden>
          <ArtConnector orientation="vertical" />
        </div>

        <ArtPanel
          className="relative z-[1] w-full"
          innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] p-4 dark:border-[rgb(var(--tone-rgb)/0.4)]"
          delayMs={220}
        >
          <div className="flex items-center gap-3">
            <span className="relative flex size-11 shrink-0">
              <span
                className="absolute -inset-2 rounded-full bg-[rgb(var(--tone-rgb)/0.22)] blur-lg"
                aria-hidden
              />
              <span className="relative flex size-11 items-center justify-center rounded-xl border border-[rgb(var(--tone-rgb)/0.4)] bg-background text-[var(--tone-ink)] dark:bg-card">
                <PostgresElephantIcon className="size-6" aria-hidden />
              </span>
            </span>
            <div className="min-w-0">
              <p
                dir="ltr"
                className="truncate text-[14px] font-semibold text-foreground"
              >
                orders-prod
              </p>
              <p className="mt-0.5 text-[11px] text-muted-foreground">
                {t('Managed PostgreSQL')}
              </p>
            </div>
            <Badge variant="success" className="ms-auto shrink-0 text-[10px]">
              {t('Ready')}
            </Badge>
          </div>

          <div className="mt-3.5 flex flex-wrap items-center gap-1.5 border-t border-border pt-3">
            <span
              dir="ltr"
              className="rounded-md bg-[rgb(var(--tone-rgb)/0.14)] px-1.5 py-0.5 font-mono text-[10px] text-[var(--tone-ink)]"
            >
              PostgreSQL 18
            </span>
            <span
              dir="ltr"
              className="rounded-md bg-[rgb(var(--tone2-rgb)/0.18)] px-1.5 py-0.5 font-mono text-[10px] text-foreground"
            >
              s-4vcpu-8gb
            </span>
            <span
              dir="ltr"
              className="rounded-md border border-border px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
            >
              fra
            </span>
          </div>
        </ArtPanel>

        <div className="mt-3 flex w-full items-stretch justify-center gap-2">
          {ATTACHMENTS.map((attachment, index) => {
            const Icon = attachment.icon
            return (
              <div
                key={attachment.label}
                className="product-hero-rise min-w-0 flex-1"
                style={riseStyle(380 + index * 120)}
              >
                <div className="rounded-lg border border-border bg-background/95 px-2 py-2 text-center dark:bg-card">
                  <Icon
                    className="mx-auto size-3.5 text-[var(--tone-ink)]"
                    aria-hidden
                  />
                  <p className="mt-1 truncate text-[10px] text-muted-foreground">
                    {t(attachment.label)}
                  </p>
                  <p
                    dir="ltr"
                    className="truncate font-mono text-[11px] text-foreground"
                  >
                    {attachment.value}
                  </p>
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
