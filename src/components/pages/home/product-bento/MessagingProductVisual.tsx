import { Bell, CheckCircle2, Mail, Phone, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

const CHANNELS = [
  { id: 'email', label: 'Email', icon: Mail },
  { id: 'sms', label: 'SMS', icon: Phone },
  { id: 'push', label: 'Push', icon: Bell },
] as const

const PROVIDERS = [
  { label: 'SendGrid', icon: '/icons/sendgrid.svg' },
  { label: 'Twilio', icon: '/icons/twilio.svg' },
  { label: 'Firebase', icon: '/icons/firebase.svg' },
] as const

function ChannelPill({
  label,
  icon: Icon,
  index,
}: {
  label: string
  icon: LucideIcon
  index: number
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'flex flex-1 items-center justify-center gap-1.5 rounded-md border border-border bg-background px-2 py-1.5 text-[11px] font-medium text-muted-foreground transition-[border-color,background-color,color] duration-300 sm:text-[12px]',
        'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_28%,var(--border))] group-hover:bg-[color-mix(in_srgb,var(--brand-cta)_10%,var(--background))] group-hover:text-foreground motion-reduce:group-hover:border-border motion-reduce:group-hover:bg-background motion-reduce:group-hover:text-muted-foreground',
      )}
      style={{ transitionDelay: `${index * 60}ms` }}
    >
      <Icon className="size-3.5 shrink-0" aria-hidden />
      <span>{t(label)}</span>
    </div>
  )
}

function DeliveryChip({
  label,
  delayMs,
}: {
  label: string
  delayMs: number
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'flex flex-1 items-center justify-center gap-1.5 rounded-md border border-border/80 px-2 py-1.5 text-muted-foreground transition-[border-color,background-color,color] duration-300',
        productBentoContainer.panelMd,
        'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_22%,var(--border))] group-hover:bg-background group-hover:text-foreground motion-reduce:group-hover:border-border/80 motion-reduce:group-hover:bg-card/70 motion-reduce:group-hover:text-muted-foreground',
      )}
      style={{ transitionDelay: `${delayMs}ms` }}
    >
      <CheckCircle2
        className={cn(
          'hidden size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400',
          'group-hover:inline-block motion-reduce:inline-block',
        )}
        aria-hidden
      />
      <span className="text-[10px] font-medium sm:text-[11px]">{t(label)}</span>
    </div>
  )
}

export function MessagingProductVisual() {
  const t = useT()
  return (
    <div className="absolute inset-0 flex flex-col">
      <div className={cn('mx-auto flex h-full min-h-0 w-full max-w-[21rem] flex-col', productBentoContainer.shell)}>
        <div className={cn(productBentoContainer.header, 'px-3.5 py-2.5')}>
          <p className={cn('text-[12px] font-medium sm:text-[13px]', productBentoIdle.text)}>
            {t('Campaign message')}
          </p>
          <p className="mt-0.5 text-[11px] text-muted-foreground sm:text-[12px]">
            {t('Email, SMS, and push from one message')}
          </p>
        </div>

        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <div className="p-3">
            <div className="flex gap-1.5">
              {CHANNELS.map((channel, index) => (
                <ChannelPill
                  key={channel.id}
                  label={channel.label}
                  icon={channel.icon}
                  index={index}
                />
              ))}
            </div>

            <div
              className={cn(
                'mt-2.5 px-3 py-2.5 transition-[border-color,background-color] duration-300',
                productBentoContainer.panelMd,
                'group-hover:border-[color-mix(in_srgb,var(--brand-cta)_24%,var(--border))] group-hover:bg-background',
              )}
            >
              <p className={cn('text-[12px] font-medium sm:text-[13px]', productBentoIdle.text)}>
                {t('Welcome to Acme')}
              </p>
              <div className="relative mt-1 min-h-[2.25rem] text-[11px] leading-snug text-muted-foreground sm:text-[12px] sm:leading-relaxed">
                <span className="transition-opacity duration-200 group-hover:opacity-0 motion-reduce:group-hover:opacity-100">
                  {t('Draft your message once...')}
                </span>
                <span className="absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100 motion-reduce:static motion-reduce:opacity-100">
                  {t(
                    "Thanks for signing up. Here's how to get started with your new account.",
                  )}
                </span>
              </div>
            </div>

            <div className="mt-2.5 flex items-center gap-2 rounded-md border border-border/70 bg-muted/8 px-2.5 py-2">
              <Users className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
              <div className="min-w-0 flex-1">
                <p className={cn('truncate font-mono text-[11px] sm:text-[12px]', productBentoIdle.text)}>
                  product-updates
                </p>
                <p className="text-[10px] text-muted-foreground sm:text-[11px]">{t('Topic')}</p>
              </div>
              <p className="shrink-0 text-[10px] tabular-nums text-muted-foreground sm:text-[11px]">
                <span className={cn('font-medium', productBentoIdle.text)}>1,248</span>{' '}
                {t('targets')}
              </p>
            </div>
          </div>

          <div className="mt-auto shrink-0 border-t border-border bg-muted/5">
            <div className="px-3 py-2.5">
              <div className="flex gap-1.5">
                {CHANNELS.map((channel, index) => (
                  <DeliveryChip
                    key={channel.id}
                    label={channel.label}
                    delayMs={220 + index * 120}
                  />
                ))}
              </div>
              <p className="mt-2 hidden text-center text-[10px] text-muted-foreground group-hover:block motion-reduce:block sm:text-[11px]">
                {t('Delivered across every selected channel')}
              </p>
            </div>

            <div className="flex items-center justify-between gap-2 border-t border-border/80 px-3 py-2">
              <div className="flex items-center gap-2" aria-hidden>
                {PROVIDERS.map((provider) => (
                  <img
                    key={provider.label}
                    src={provider.icon}
                    alt=""
                    className={cn('size-4', productBentoIdle.providerIcon)}
                  />
                ))}
              </div>
              <p className="text-[10px] text-muted-foreground sm:text-[11px]">
                {t('Your providers')}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
