import { BellRing, Check, Mail, MessageSquareText, Radio, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

function ChannelCard({
  channel,
  icon: Icon,
  className,
  delayMs,
  floatDelayMs,
  children,
}: {
  channel: string
  icon: typeof Mail
  className: string
  delayMs: number
  floatDelayMs: number
  children: ReactNode
}) {
  return (
    <div className={cn('product-hero-rise absolute z-[1]', className)} style={riseStyle(delayMs)}>
      <div
        className="product-hero-float product-tone-shadow rounded-2xl border border-border bg-background p-3.5 text-start dark:bg-card"
        style={floatStyle(floatDelayMs)}
      >
        <div className="mb-2.5 flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          <Icon className="size-3 text-[var(--tone-ink)]" aria-hidden />
          {channel}
        </div>
        {children}
      </div>
    </div>
  )
}

export function MessagingHeroArt() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[440px] w-full max-w-[520px] sm:h-[470px]">
      <div
        className="absolute bottom-12 start-1/2 top-12 w-px border-s border-dashed border-border"
        aria-hidden
      />

      <div
        className="product-hero-rise absolute start-1/2 top-0 z-[2] -translate-x-1/2 rtl:translate-x-1/2"
        style={riseStyle(60)}
      >
        <div className="flex items-center gap-2 whitespace-nowrap rounded-full border border-border bg-background py-1.5 ps-1.5 pe-3 shadow-sm dark:bg-card">
          <span className="flex size-6 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]">
            <Radio className="size-3" aria-hidden />
          </span>
          <span className="text-[11px] text-muted-foreground">{t('Topic')}</span>
          <span dir="ltr" className="font-mono text-[11px] text-foreground">product-updates</span>
          <span className="h-3 w-px bg-border" aria-hidden />
          <Users className="size-3 text-muted-foreground" aria-hidden />
          <span dir="ltr" className="font-mono text-[11px] text-foreground">12.4k</span>
        </div>
      </div>

      <ChannelCard
        channel={t('Push')}
        icon={BellRing}
        className="start-0 top-[60px] w-[80%] sm:w-[74%]"
        delayMs={300}
        floatDelayMs={0}
      >
        <div className="flex items-start gap-2.5">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--brand-cta)] text-[13px] font-semibold text-white">
            A
          </span>
          <div className="min-w-0">
            <p className="text-[12px] font-semibold text-foreground">{t('Your order has shipped')}</p>
            <p className="mt-0.5 text-[11px] leading-4 text-muted-foreground">
              {t('Track your delivery in the app.')}
            </p>
          </div>
        </div>
      </ChannelCard>

      <ChannelCard
        channel="SMS"
        icon={MessageSquareText}
        className="end-0 top-[188px] w-[64%] sm:top-[196px] sm:w-[58%]"
        delayMs={600}
        floatDelayMs={700}
      >
        <div className="rounded-2xl rounded-ss-sm bg-muted px-3 py-2 text-[12px] leading-5 text-foreground">
          Acme · {t('Verification code')}: <span dir="ltr" className="font-mono font-medium">482913</span>
        </div>
      </ChannelCard>

      <ChannelCard
        channel={t('Email')}
        icon={Mail}
        className="bottom-0 start-[4%] w-[84%] sm:w-[78%]"
        delayMs={900}
        floatDelayMs={1400}
      >
        <div className="flex items-center justify-between gap-2">
          <p dir="ltr" className="truncate text-[11px] text-muted-foreground">Acme &lt;hello@acme.io&gt;</p>
          <span className="flex shrink-0 items-center gap-1 rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:text-emerald-400">
            <Check className="size-2.5" aria-hidden />
            {t('Delivered')}
          </span>
        </div>
        <p className="mt-1.5 text-[13px] font-semibold text-foreground">{t('Welcome to Acme')}</p>
        <div className="mt-2.5 space-y-1.5" aria-hidden>
          <div className="h-1.5 w-full rounded-full bg-muted" />
          <div className="h-1.5 w-4/5 rounded-full bg-muted" />
        </div>
        <div className="mt-3 h-6 w-24 rounded-md bg-[var(--brand-cta)]/90" aria-hidden />
      </ChannelCard>
    </div>
  )
}
