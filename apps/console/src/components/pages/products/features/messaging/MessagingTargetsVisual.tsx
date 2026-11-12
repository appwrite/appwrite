import { BellRing, Check, Hash, Info, Mail, MessageSquareText } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TARGETS: {
  id: string
  label: string
  providerType: string
  icon: LucideIcon
  verified?: boolean
}[] = [
  { id: '68a4e2f91b0c', label: 'walter@acme.io', providerType: 'Email', icon: Mail, verified: true },
  { id: '68a4d8c03f21', label: '+1 (555) 014-8921', providerType: 'SMS', icon: MessageSquareText, verified: true },
  { id: '68a4c1aa7e55', label: 'iPhone 15 Pro', providerType: 'Push', icon: BellRing },
]

/** Spine sits under the user avatar; each target branches off it with a stub. */
const SPINE_OFFSET = 'start-[30px]'

export function MessagingTargetsVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] pb-24 pt-2 sm:pb-20">
      <ArtPanel
        className="w-fit max-w-full"
        innerClassName="product-tone-shadow flex items-center gap-3 px-3.5 py-3"
        delayMs={60}
      >
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.14)] text-[12px] font-semibold text-[var(--tone-ink)]">
          W
        </span>
        <div className="min-w-0">
          <p className="text-[13px] font-semibold text-foreground">Walter O&apos;Brien</p>
          <p className="truncate text-[11px] text-muted-foreground">
            <span dir="ltr">walter@acme.io</span> · {t('Auth user')}
          </p>
        </div>
        <Badge variant="info" className="ms-2 shrink-0 text-[10px]">
          {t('3 targets')}
        </Badge>
      </ArtPanel>

      <div className="sm:pe-36">
        {TARGETS.map((target, index) => {
          const last = index === TARGETS.length - 1
          return (
            <div key={target.id} className="relative pt-3 ps-[60px] sm:ps-[68px]">
              <span
                className={cn(
                  'absolute top-0 border-s border-dashed border-foreground/25',
                  SPINE_OFFSET,
                  last ? 'h-[calc(50%+6px)]' : 'h-full',
                )}
                aria-hidden
              />
              <ArtConnector
                travel
                travelDelayMs={400 + index * 600}
                className={cn('absolute top-[calc(50%+6px)] w-[30px] sm:w-[38px]', SPINE_OFFSET)}
              />
              {index === 0 ? (
                <ArtConnector className="absolute -end-5 top-[calc(50%+6px)] hidden w-5 sm:block" />
              ) : null}
              <ArtPanel
                delayMs={300 + index * 150}
                float
                floatDelayMs={index * 500}
                innerClassName="flex items-center gap-2.5 px-3 py-2.5"
              >
                <ArtIconBadge icon={target.icon} tone={index === 0 ? 'primary' : 'neutral'} />
                <div className="min-w-0 flex-1">
                  <p dir="ltr" className="truncate text-start text-[12px] font-medium text-foreground">
                    {target.label}
                  </p>
                  <p dir="ltr" className="truncate text-start font-mono text-[10px] text-muted-foreground">
                    {target.id}
                  </p>
                </div>
                {target.verified ? (
                  <span
                    className="hidden size-4 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 sm:flex"
                    aria-hidden
                  >
                    <Check className="size-2.5" strokeWidth={3} />
                  </span>
                ) : null}
                <Badge variant="inactive" className="shrink-0 text-[10px]">
                  {t(target.providerType)}
                </Badge>
              </ArtPanel>
            </div>
          )
        })}
      </div>

      <ArtChip className="end-0 top-[84px] hidden sm:block" delayMs={1000} floatDelayMs={400}>
        <p className="text-[10px] text-muted-foreground">{t('Topic')}</p>
        <div className="mt-1 flex items-center gap-1.5">
          <Hash className="size-3 text-[var(--tone-ink)]" aria-hidden />
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            weekly-digest
          </span>
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 end-0 w-[min(300px,100%)]" delayMs={1250} floatDelayMs={1100}>
        <div className="flex items-start gap-2">
          <Info className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden />
          <p className="text-[11px] leading-4 text-muted-foreground">
            {t('Targets are created when users verify email or phone in Auth, or when your app registers push device tokens.')}
          </p>
        </div>
      </ArtChip>
    </div>
  )
}
