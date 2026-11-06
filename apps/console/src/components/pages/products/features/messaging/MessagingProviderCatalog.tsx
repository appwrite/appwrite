import { Bell, Globe, Mail, MessageSquare, MessagesSquare, Smartphone } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtIconBadge, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type MessagingProviderItem = {
  id: string
  name: string
  icon?: string
}

type MessagingChannelTile = {
  id: string
  title: string
  description: string
  icon: LucideIcon
  comingSoon?: boolean
  providers: MessagingProviderItem[]
}

const AVAILABLE_CHANNELS: MessagingChannelTile[] = [
  {
    id: 'email',
    title: 'Emails',
    description:
      'Send receipts, digests, and transactional mail through SendGrid, Mailgun, Amazon SES, SMTP, and other email providers.',
    icon: Mail,
    providers: [
      { id: 'resend', name: 'Resend', icon: '/icons/resend.svg' },
      { id: 'sendgrid', name: 'SendGrid', icon: '/icons/sendgrid.svg' },
      { id: 'mailgun', name: 'Mailgun', icon: '/icons/mailgun.svg' },
      { id: 'ses', name: 'Amazon SES', icon: '/icons/amazon.svg' },
      { id: 'smtp', name: 'SMTP' },
    ],
  },
  {
    id: 'sms',
    title: 'SMS',
    description:
      'Send OTP codes, delivery updates, and alerts outside your app through Twilio, Vonage, MSG91, Telesign, Textmagic, and other SMS vendors.',
    icon: MessageSquare,
    providers: [
      { id: 'twilio', name: 'Twilio', icon: '/icons/twilio.svg' },
      { id: 'vonage', name: 'Vonage', icon: '/icons/vonage.svg' },
      { id: 'msg91', name: 'MSG91', icon: '/icons/msg91.svg' },
      { id: 'telesign', name: 'Telesign', icon: '/icons/telesign.svg' },
      { id: 'textmagic', name: 'Textmagic', icon: '/icons/textmagic.svg' },
    ],
  },
  {
    id: 'push',
    title: 'Push notifications',
    description: 'Reach users instantly on iOS, Android, and web with APNS and FCM.',
    icon: Smartphone,
    providers: [
      { id: 'apns', name: 'APNS', icon: '/icons/apple.svg' },
      { id: 'fcm', name: 'FCM', icon: '/icons/firebase.svg' },
    ],
  },
]

const UPCOMING_CHANNELS: MessagingChannelTile[] = [
  {
    id: 'chat',
    title: 'Chat',
    description: 'Connect Slack, Discord, WhatsApp, and other chat surfaces for team and user notifications.',
    icon: MessagesSquare,
    comingSoon: true,
    providers: [
      { id: 'slack', name: 'Slack', icon: '/icons/slack.svg' },
      { id: 'discord', name: 'Discord', icon: '/icons/discord-simple.svg' },
      { id: 'whatsapp', name: 'WhatsApp', icon: '/icons/whatsapp.svg' },
    ],
  },
  {
    id: 'in-app',
    title: 'In app notifications',
    description: 'Send realtime alerts to signed-in users without leaving your application experience.',
    icon: Bell,
    comingSoon: true,
    providers: [],
  },
]

function ProviderPill({ provider, muted }: { provider: MessagingProviderItem; muted?: boolean }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium',
        muted
          ? 'border-dashed border-border text-muted-foreground'
          : 'border-border bg-background text-foreground shadow-sm dark:bg-card',
      )}
    >
      {provider.icon ? (
        <ProductFeaturePublicIcon
          src={provider.icon}
          className="size-3.5"
          tone={muted ? 'muted-foreground' : 'foreground'}
        />
      ) : (
        <Globe className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
      )}
      {provider.name}
    </span>
  )
}

function AvailableChannel({
  tile,
  delayMs,
  className,
}: {
  tile: MessagingChannelTile
  delayMs: number
  className?: string
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'product-hero-rise rounded-2xl border border-border/70 bg-background/40 p-5 backdrop-blur-[2px] dark:border-white/[0.07] dark:bg-white/[0.015] lg:col-span-2',
        className,
      )}
      style={riseStyle(delayMs)}
    >
      <ArtIconBadge icon={tile.icon} className="size-8" />
      <h3 className="mt-4 text-[15px] font-semibold text-foreground">{t(tile.title)}</h3>
      <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">{t(tile.description)}</p>
      <div className="mt-4 flex flex-wrap gap-1.5">
        {tile.providers.map((provider, index) => (
          <span key={provider.id} className="product-hero-rise" style={riseStyle(delayMs + 200 + index * 60)}>
            <ProviderPill provider={provider} />
          </span>
        ))}
      </div>
    </div>
  )
}

function UpcomingChannel({ tile, delayMs }: { tile: MessagingChannelTile; delayMs: number }) {
  const t = useT()
  return (
    <div
      className="product-hero-rise flex flex-col gap-4 rounded-2xl border border-dashed border-border p-5 sm:flex-row sm:items-start lg:col-span-3"
      style={riseStyle(delayMs)}
    >
      <ArtIconBadge icon={tile.icon} tone="neutral" className="size-8" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="text-[15px] font-semibold text-foreground">{t(tile.title)}</h3>
          <Badge variant="warning" className="shrink-0 text-[10px]">
            {t('Coming soon')}
          </Badge>
        </div>
        <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">{t(tile.description)}</p>
        {tile.providers.length > 0 ? (
          <div className="mt-3 flex flex-wrap gap-1.5">
            {tile.providers.map((provider) => (
              <ProviderPill key={provider.id} provider={provider} muted />
            ))}
          </div>
        ) : null}
      </div>
    </div>
  )
}

type MessagingProviderCatalogProps = {
  className?: string
}

export function MessagingProviderCatalog({ className }: MessagingProviderCatalogProps) {
  return (
    <div className={cn('grid gap-3 text-start sm:grid-cols-2 lg:grid-cols-6 lg:gap-4', className)}>
      {AVAILABLE_CHANNELS.map((tile, index) => (
        <AvailableChannel
          key={tile.id}
          tile={tile}
          delayMs={100 + index * 120}
          className={index === AVAILABLE_CHANNELS.length - 1 ? 'sm:col-span-2' : undefined}
        />
      ))}
      {UPCOMING_CHANNELS.map((tile, index) => (
        <UpcomingChannel key={tile.id} tile={tile} delayMs={500 + index * 120} />
      ))}
    </div>
  )
}
