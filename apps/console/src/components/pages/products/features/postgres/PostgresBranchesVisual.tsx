import { Camera, GitBranch, Hourglass } from 'lucide-react'
import {
  ArtChip,
  ArtIconBadge,
  ArtLiveDot,
  ArtPanel,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Branch = {
  id: string
  name: string
  host: string
  ttl: string
}

const BRANCHES: Branch[] = [
  { id: 'pr-482', name: 'pr-482', host: 'db-2b9d41.fra', ttl: '23h 41m' },
  {
    id: 'migration-check',
    name: 'migration-check',
    host: 'db-5c7e08.fra',
    ttl: '6d 2h',
  },
]

export function PostgresBranchesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6 text-start">
      <ArtPanel
        className="relative z-[1] w-[min(240px,72%)]"
        innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] px-3 py-2.5 dark:border-[rgb(var(--tone-rgb)/0.4)]"
        delayMs={60}
      >
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={GitBranch} />
          <div className="min-w-0">
            <p
              dir="ltr"
              className="truncate text-[12px] font-semibold text-foreground"
            >
              orders-prod
            </p>
            <p className="text-[10px] text-muted-foreground">
              {t('Parent database')}
            </p>
          </div>
          <ArtLiveDot className="ms-auto size-1.5" />
        </div>
      </ArtPanel>

      {BRANCHES.map((branch, index) => {
        const last = index === BRANCHES.length - 1
        return (
          <div key={branch.id} className="flex items-stretch pt-4">
            <div className="relative w-12 shrink-0" aria-hidden>
              <span
                className={cn(
                  'absolute start-[25px] -top-4 border-s border-dashed border-foreground/25',
                  last ? 'h-[46px]' : 'h-[calc(100%+1rem)]',
                )}
              />
              <span className="absolute start-[25px] top-[30px] end-0 border-t border-dashed border-foreground/25" />
              <span className="absolute start-[21px] top-[26px] size-2 rounded-full bg-[var(--tone-ink)]" />
            </div>

            <ArtPanel
              className="min-w-0 flex-1 sm:max-w-[320px]"
              innerClassName="px-3 py-2.5"
              delayMs={320 + index * 200}
              float
              floatDelayMs={index * 650}
            >
              <div className="flex items-center gap-2">
                <span
                  dir="ltr"
                  className="truncate rounded-md bg-[rgb(var(--tone2-rgb)/0.18)] px-1.5 py-0.5 font-mono text-[11px] text-foreground"
                >
                  {branch.name}
                </span>
                <span
                  dir="ltr"
                  className="ms-auto flex shrink-0 items-center gap-1 font-mono text-[10px] text-muted-foreground"
                >
                  <Hourglass className="size-2.5" aria-hidden />
                  {branch.ttl}
                </span>
              </div>
              <p
                dir="ltr"
                className="mt-1.5 truncate font-mono text-[10px] text-foreground"
              >
                {branch.host}:5432
              </p>
            </ArtPanel>
          </div>
        )
      })}

      <ArtChip
        className="end-0 top-0 hidden sm:block"
        delayMs={760}
        floatDelayMs={900}
      >
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Camera} tone="secondary" />
          <p className="text-[11px] font-medium text-foreground">
            {t('Snapshot copy')}
          </p>
        </div>
      </ArtChip>
    </div>
  )
}
