import {
  Bell,
  Globe,
  Mail,
  MessageSquare,
  MessagesSquare,
  Smartphone,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
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

const MESSAGING_CHANNEL_TILES: MessagingChannelTile[] = [
  {
    id: 'push',
    title: 'Push notifications',
    description:
      'Reach users instantly on iOS, Android, and web with APNS and FCM.',
    icon: Smartphone,
    providers: [
      { id: 'apns', name: 'APNS', icon: '/icons/apple.svg' },
      { id: 'fcm', name: 'FCM', icon: '/icons/firebase.svg' },
    ],
  },
  {
    id: 'email',
    title: 'Emails',
    description:
      'Send receipts, digests, and transactional mail through SendGrid, Mailgun, SMTP, and other email providers.',
    icon: Mail,
    providers: [
      { id: 'resend', name: 'Resend', icon: '/icons/resend.svg' },
      { id: 'sendgrid', name: 'SendGrid', icon: '/icons/sendgrid.svg' },
      { id: 'mailgun', name: 'Mailgun', icon: '/icons/mailgun.svg' },
      { id: 'smtp', name: 'SMTP' },
    ],
  },
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
]

const pushTile = MESSAGING_CHANNEL_TILES[0]
const emailTile = MESSAGING_CHANNEL_TILES[1]
const chatTile = MESSAGING_CHANNEL_TILES[2]
const inAppTile = MESSAGING_CHANNEL_TILES[3]
const smsTile = MESSAGING_CHANNEL_TILES[4]

function ChannelTileIcon({ icon: Icon }: { icon: LucideIcon }) {
  return (
    <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
      <Icon className="size-3.5 text-muted-foreground" aria-hidden />
    </span>
  )
}

function ProviderLogo({ provider }: { provider: MessagingProviderItem }) {
  if (provider.icon) {
    return <ProductFeaturePublicIcon src={provider.icon} />
  }

  return <Globe className="size-4 shrink-0 text-muted-foreground" aria-hidden />
}

function ProviderPill({ provider }: { provider: MessagingProviderItem }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-[11px] font-medium text-foreground">
      <ProviderLogo provider={provider} />
      {provider.name}
    </span>
  )
}

function ChannelTile({
  tile,
  className,
}: {
  tile: MessagingChannelTile
  className?: string
}) {
  const t = useT()
  return (
    <div className={cn('p-4 sm:p-5', className)}>
      <ChannelTileIcon icon={tile.icon} />
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <h3 className="text-[14px] font-semibold text-foreground">{t(tile.title)}</h3>
        {tile.comingSoon ? (
          <Badge variant="warning" className="text-[10px] shrink-0">
            {t('Coming soon')}
          </Badge>
        ) : null}
      </div>
      <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">{t(tile.description)}</p>
      {tile.providers.length > 0 ? (
        <div className="mt-2.5 flex flex-wrap gap-1.5">
          {tile.providers.map((provider) => (
            <ProviderPill key={provider.id} provider={provider} />
          ))}
        </div>
      ) : null}
    </div>
  )
}

type MessagingProviderCatalogProps = {
  className?: string
}

export function MessagingProviderCatalog({ className }: MessagingProviderCatalogProps) {
  return (
    <div
      className={cn(
        'grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-2 lg:grid-cols-3',
        className,
      )}
    >
      <ChannelTile
        tile={pushTile}
        className="border-b border-border sm:border-e lg:border-e"
      />
      <ChannelTile tile={emailTile} className="border-b border-border lg:border-e" />
      <ChannelTile
        tile={chatTile}
        className="border-b border-border sm:border-e lg:border-e-0"
      />
      <ChannelTile
        tile={inAppTile}
        className="border-b border-border sm:border-e lg:border-e lg:border-b-0"
      />
      <ChannelTile
        tile={smsTile}
        className="border-b border-border sm:col-span-2 sm:border-b-0 lg:col-span-2 lg:border-b-0"
      />
    </div>
  )
}
