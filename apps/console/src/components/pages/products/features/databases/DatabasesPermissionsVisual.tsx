import { Check, Eye, KeyRound, Link2, Pencil, Plus, ShieldCheck, Table as TableIcon, Trash2, User, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { MockPermissionChip } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Action = 'Read' | 'Create' | 'Update' | 'Delete'

const ACTIONS: { id: Action; icon: LucideIcon }[] = [
  { id: 'Read', icon: Eye },
  { id: 'Create', icon: Plus },
  { id: 'Update', icon: Pencil },
  { id: 'Delete', icon: Trash2 },
]

const PRINCIPALS: {
  id: string
  label: string
  kind: 'Team' | 'Role' | 'User'
  icon: LucideIcon
  actions: Action[]
  offset: string
  row: string
}[] = [
  {
    id: 'team',
    label: 'team:acme',
    kind: 'Team',
    icon: Users,
    actions: ['Read', 'Create', 'Update'],
    offset: 'sm:me-4',
    row: 'sm:row-start-1',
  },
  {
    id: 'role',
    label: 'role:admin',
    kind: 'Role',
    icon: ShieldCheck,
    actions: ['Read', 'Create', 'Update', 'Delete'],
    offset: 'sm:ms-3',
    row: 'sm:row-start-2',
  },
  {
    id: 'user',
    label: 'user:owner',
    kind: 'User',
    icon: User,
    actions: ['Read', 'Update'],
    offset: 'sm:me-2',
    row: 'sm:row-start-3',
  },
]

function PermissionMatrix() {
  const t = useT()
  return (
    <ArtPanel
      className="relative z-[1] sm:col-start-3 sm:row-span-3 sm:row-start-1 sm:self-center"
      innerClassName="product-tone-shadow p-0 overflow-hidden"
      delayMs={60}
    >
      <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
        <TableIcon className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
        <span dir="ltr" className="font-mono text-[12px] font-semibold text-foreground">
          orders
        </span>
        <span className="ms-auto text-[10px] text-muted-foreground">{t('3 rules')}</span>
      </div>
      <div className="px-3 py-2">
        <div className="grid grid-cols-[minmax(0,1fr)_repeat(4,24px)] items-center gap-x-1 pb-1.5">
          <span className="text-[10px] font-medium text-muted-foreground">{t('Permissions')}</span>
          {ACTIONS.map((action) => {
            const Icon = action.icon
            return (
              <span key={action.id} className="flex justify-center text-muted-foreground" title={t(action.id)}>
                <Icon className="size-3" aria-hidden />
                <span className="sr-only">{t(action.id)}</span>
              </span>
            )
          })}
        </div>
        {PRINCIPALS.map((principal, rowIndex) => (
          <div
            key={principal.id}
            className="grid grid-cols-[minmax(0,1fr)_repeat(4,24px)] items-center gap-x-1 border-t border-border/60 py-1.5"
          >
            <span dir="ltr" className="truncate text-start font-mono text-[11px] text-foreground">
              {principal.label}
            </span>
            {ACTIONS.map((action, colIndex) => {
              const granted = principal.actions.includes(action.id)
              return (
                <span key={action.id} className="flex justify-center">
                  {granted ? (
                    <span
                      className="product-hero-rise flex size-4 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]"
                      style={riseStyle(500 + rowIndex * 160 + colIndex * 60)}
                    >
                      <Check className="size-2.5" strokeWidth={3} aria-hidden />
                    </span>
                  ) : (
                    <span className="h-px w-2 bg-foreground/20" aria-hidden />
                  )}
                </span>
              )
            })}
          </div>
        ))}
      </div>
      <div className="border-t border-border bg-muted/20 px-3 py-2.5">
        <p className="text-[10px] font-medium text-muted-foreground">{t('Rows')}</p>
        <div className="mt-1.5 flex flex-wrap items-center gap-1.5" dir="ltr">
          <span className="font-mono text-[10px] text-muted-foreground">row_8f2c</span>
          <MockPermissionChip label="user:paige/read" tone="accent" />
          <MockPermissionChip label="user:paige/update" />
        </div>
      </div>
    </ArtPanel>
  )
}

export function DatabasesPermissionsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] pb-4 pt-4 sm:pt-14">
      <div className="grid gap-3 sm:grid-cols-[minmax(0,190px)_40px_minmax(0,1fr)] sm:grid-rows-3 sm:gap-x-0">
        {PRINCIPALS.map((principal, index) => (
          <div key={principal.id} className="contents">
            <ArtPanel
              className={cn('sm:col-start-1 sm:self-center', principal.row, principal.offset)}
              innerClassName="flex items-center gap-2.5 px-3 py-2.5"
              delayMs={200 + index * 140}
              float
              floatDelayMs={index * 500}
            >
              <ArtIconBadge icon={principal.icon} tone={index === 1 ? 'primary' : 'secondary'} />
              <div className="min-w-0">
                <p dir="ltr" className="truncate text-start font-mono text-[11px] font-medium text-foreground">
                  {principal.label}
                </p>
                <p className="text-[10px] text-muted-foreground">{t(principal.kind)}</p>
              </div>
            </ArtPanel>
            <div className={cn('hidden items-center sm:col-start-2 sm:flex', principal.row)}>
              <ArtConnector travel travelDelayMs={index * 500} />
            </div>
          </div>
        ))}
        <PermissionMatrix />
      </div>

      <ArtChip className="start-0 top-0 hidden sm:block" delayMs={900} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <KeyRound className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          <span className="text-[11px] font-medium text-foreground">{t('Users')}</span>
          <span className="text-muted-foreground/50" aria-hidden>
            ·
          </span>
          <span className="text-[11px] font-medium text-foreground">{t('Teams')}</span>
          <span className="text-muted-foreground/50" aria-hidden>
            ·
          </span>
          <span className="text-[11px] font-medium text-foreground">{t('Roles')}</span>
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-1 hidden sm:block" delayMs={1100} floatDelayMs={1300}>
        <div className="flex items-center gap-1.5">
          <Link2 className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          <span className="text-[11px] font-medium text-foreground">{t('Auth linked')}</span>
        </div>
      </ArtChip>
    </div>
  )
}
