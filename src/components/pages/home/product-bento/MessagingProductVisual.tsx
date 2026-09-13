import { Bell, CheckCircle2, Mail, Phone, Users } from 'lucide-react'
import type { CSSProperties, LucideIcon } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { productBentoContainer, productBentoIdle } from './MockSyntax'

/** Brand supporting palette (orange, mint, purple) plus primary CTA pink. */
const BRAND = {
  orange: '#FE9567',
  mint: '#85DBD8',
  purple: '#7C67FE',
  pink: 'var(--brand-cta)',
} as const

const CHANNELS = [
  { id: 'email', label: 'Email', icon: Mail, color: BRAND.orange },
  { id: 'sms', label: 'SMS', icon: Phone, color: BRAND.mint },
  { id: 'push', label: 'Push', icon: Bell, color: BRAND.purple },
] as const

const PROVIDERS = [
  { label: 'SendGrid', icon: '/icons/sendgrid.svg' },
  { label: 'Twilio', icon: '/icons/twilio.svg' },
  { label: 'Firebase', icon: '/icons/firebase.svg' },
] as const

function channelColorStyle(color: string, extra?: CSSProperties): CSSProperties {
  return { '--channel-color': color, ...extra } as CSSProperties
}

function ChannelPill({
  label,
  icon: Icon,
  color,
  index,
}: {
  label: string
  icon: LucideIcon
  color: string
  index: number
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'flex flex-1 items-center justify-center gap-1.5 rounded-md border border-border bg-background px-2 py-1.5 text-[11px] font-medium text-muted-foreground transition-colors duration-300 sm:text-[12px]',
        'group-hover:bg-[color-mix(in_srgb,var(--channel-color)_16%,var(--background))] group-hover:text-foreground motion-reduce:group-hover:bg-background motion-reduce:group-hover:text-muted-foreground',
      )}
      style={channelColorStyle(color, { transitionDelay: `${index * 60}ms` })}
    >
      <Icon
        className="size-3.5 shrink-0 transition-colors duration-300 group-hover:text-[var(--channel-color)] motion-reduce:group-hover:text-muted-foreground"
        aria-hidden
      />
      <span>{t(label)}</span>
    </div>
  )
}

function DeliveryChip({
  label,
  color,
  delayMs,
}: {
  label: string
  color: string
  delayMs: number
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'flex flex-1 items-center justify-center gap-1.5 rounded-md border border-border/80 px-2 py-1.5 text-muted-foreground transition-colors duration-300',
        productBentoContainer.panelMd,
        'group-hover:bg-[color-mix(in_srgb,var(--channel-color)_10%,var(--background))] group-hover:text-foreground motion-reduce:group-hover:bg-card/70 motion-reduce:group-hover:text-muted-foreground',
      )}
      style={channelColorStyle(color, { transitionDelay: `${delayMs}ms` })}
    >
      <CheckCircle2
        className={cn(
          'hidden size-3.5 shrink-0 text-[var(--channel-color)]',
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
      <div className={cn('flex h-full min-h-0 w-full flex-col', productBentoContainer.shell)}>
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
                  color={channel.color}
                  index={index}
                />
              ))}
            </div>

            <div
              className={cn(
                'mt-2.5 px-3 py-2.5 transition-colors duration-300',
                productBentoContainer.panelMd,
                'group-hover:bg-background',
              )}
              style={channelColorStyle(BRAND.pink)}
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
              <div className="flex shrink-0 items-center gap-2">
                <div className="flex items-center gap-1" aria-hidden>
                  {CHANNELS.map((channel) => (
                    <span
                      key={channel.id}
                      className="size-1.5 rounded-full bg-muted-foreground/30 transition-colors duration-300 group-hover:bg-[var(--channel-color)] motion-reduce:group-hover:bg-muted-foreground/30"
                      style={channelColorStyle(channel.color)}
                    />
                  ))}
                </div>
                <p className="text-[10px] tabular-nums text-muted-foreground sm:text-[11px]">
                  <span className={cn('font-medium', productBentoIdle.text)}>1,248</span>{' '}
                  {t('targets')}
                </p>
              </div>
            </div>
          </div>

          <div className="mt-auto shrink-0 border-t border-border bg-muted/5">
            <div className="px-3 py-2.5">
              <div className="flex gap-1.5">
                {CHANNELS.map((channel, index) => (
                  <DeliveryChip
                    key={channel.id}
                    label={channel.label}
                    color={channel.color}
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
