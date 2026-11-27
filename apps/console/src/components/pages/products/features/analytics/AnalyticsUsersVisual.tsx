import { Users } from 'lucide-react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { ArtChip, ArtLiveDot, ArtPanel } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Appwrite team members as the sample people, ranked by visits like the console Users card. */
const PEOPLE = [
  { name: 'Jake Barnby', email: 'jake@refetch.io', visits: '48', percent: 100 },
  { name: 'Matej Bačo', email: 'matej@refetch.io', visits: '31', percent: 65 },
  { name: 'Chirag Aggarwal', email: 'chirag@refetch.io', visits: '22', percent: 46 },
  { name: 'Harsh Mahajan', email: 'harsh@refetch.io', visits: '17', percent: 35 },
] as const

const ONLINE = ['Jake Barnby', 'Matej Bačo', 'Harsh Mahajan'] as const

const TABS = ['Overview', 'Sessions', 'Analytics'] as const

const USER_STATS = [
  { label: 'Visits', value: '48' },
  { label: 'Views', value: '312' },
  { label: 'Active days', value: '19' },
] as const

/**
 * Analytics events linked to Auth users: the Users card shows people, the
 * live pill shows who is signed in now, and each Auth user gets an
 * Analytics tab.
 */
export function AnalyticsUsersVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-36 pt-12 sm:pb-28">
      <ArtChip className="start-0 top-0" delayMs={600} floatDelayMs={0}>
        <span className="flex items-center gap-2 text-[11px] text-foreground">
          <ArtLiveDot />
          <span>
            <span dir="ltr" className="font-medium tabular-nums">12</span> {t('online')}
            <span className="text-muted-foreground"> · </span>
            <span dir="ltr" className="font-medium tabular-nums">3</span> {t('signed in')}
          </span>
          <span className="flex -space-x-1.5 rtl:space-x-reverse" aria-hidden>
            {ONLINE.map((name) => (
              <InitialsAvatar key={name} name={name} size="xs" className="ring-2 ring-background" />
            ))}
          </span>
        </span>
      </ArtChip>

      <ArtPanel className="w-full sm:w-[360px]" delayMs={80} innerClassName="product-tone-shadow p-2">
        <div className="flex items-center justify-between px-2 pb-1.5 pt-1">
          <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            <Users className="size-3" aria-hidden />
            {t('Users')}
          </p>
          <span className="text-[10px] text-muted-foreground">
            <span dir="ltr" className="font-medium text-foreground">38%</span> {t('of visitors signed in')}
          </span>
        </div>
        <div className="space-y-0.5">
          {PEOPLE.map((person, index) => (
            <div key={person.email} className="relative flex h-9 items-center gap-2 overflow-hidden rounded-md px-2">
              <span
                className={cn(
                  'absolute inset-y-0 start-0 rounded-md',
                  index === 0 ? 'bg-[rgb(var(--tone-rgb)/0.2)]' : 'bg-[rgb(var(--tone-rgb)/0.08)]',
                )}
                style={{ width: `${person.percent}%` }}
                aria-hidden
              />
              <InitialsAvatar name={person.name} size="sm" className="relative" />
              <span className="relative min-w-0 flex-1">
                <span className="block truncate text-[11.5px] font-medium text-foreground">{person.name}</span>
                <span dir="ltr" className="block truncate text-[10px] text-muted-foreground">
                  {person.email}
                </span>
              </span>
              <span dir="ltr" className="relative shrink-0 text-[11.5px] font-medium tabular-nums text-foreground">
                {person.visits}
              </span>
            </div>
          ))}
        </div>
      </ArtPanel>

      {/* The same person's Analytics tab in Auth. */}
      <ArtPanel
        className="absolute bottom-0 end-0 w-[min(250px,82%)]"
        delayMs={420}
        float
        floatDelayMs={400}
        innerClassName="border-[rgb(var(--tone-rgb)/0.4)] p-0 overflow-hidden dark:border-[rgb(var(--tone-rgb)/0.4)]"
      >
        <div className="flex items-center gap-2 px-3 pb-2 pt-2.5">
          <InitialsAvatar name={PEOPLE[0].name} size="sm" />
          <div className="min-w-0">
            <p className="truncate text-[11.5px] font-semibold text-foreground">{PEOPLE[0].name}</p>
            <p className="truncate text-[10px] text-muted-foreground">{t('Auth user')}</p>
          </div>
        </div>
        <div className="flex gap-3 border-b border-border px-3">
          {TABS.map((tab) => (
            <span
              key={tab}
              className={cn(
                'border-b-2 pb-1.5 text-[10.5px]',
                tab === 'Analytics'
                  ? 'border-[var(--tone-ink)] font-medium text-foreground'
                  : 'border-transparent text-muted-foreground',
              )}
            >
              {t(tab)}
            </span>
          ))}
        </div>
        <div className="grid grid-cols-3 gap-2 px-3 py-2.5">
          {USER_STATS.map((stat) => (
            <div key={stat.label} className="min-w-0">
              <p className="truncate text-[9.5px] text-muted-foreground">{t(stat.label)}</p>
              <p dir="ltr" className="mt-0.5 font-aeonik-pro text-[15px] leading-none tracking-tight text-foreground">
                {stat.value}
              </p>
            </div>
          ))}
        </div>
      </ArtPanel>
    </div>
  )
}
