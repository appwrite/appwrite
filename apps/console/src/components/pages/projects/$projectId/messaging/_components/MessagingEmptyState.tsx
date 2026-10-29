import type { ReactNode } from 'react'
import {
  Bell,
  Check,
  Hash,
  KeyRound,
  Mail,
  MessageSquare,
  Plug,
  Send,
  Smartphone,
  UserPlus,
  Users,
} from 'lucide-react'
import { MessagingProviderIcon } from '@/components/global/shared/MessagingProviderIcon'
import {
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { MessagingCreateControls } from './MessagingCreateControls'

type MessagingTab = 'messages' | 'topics' | 'providers'

/** Decorative push notifications on a phone, with an email and an SMS around it. */
function MessagesVisual() {
  return (
    <ProductEmptyStateVisual className="h-60 w-[440px]">
      <div className="absolute start-1/2 top-0 h-60 w-44 -translate-x-1/2 overflow-hidden rounded-[28px] border-[5px] border-foreground/10 bg-gradient-to-b from-muted to-muted/30 shadow-xl rtl:translate-x-1/2">
        <span className="mx-auto mt-1.5 block h-3 w-14 rounded-full bg-foreground/10" />
        <p className="mt-3 text-center font-mono text-[20px] font-semibold tabular-nums text-foreground/80">
          9:41
        </p>
        <div className="mt-4 space-y-1.5 px-2">
          {[
            { first: 'w-16', second: 'w-24', time: 'now' },
            { first: 'w-12', second: 'w-20', time: '2m' },
          ].map((notification) => (
            <div
              key={notification.time}
              className="flex gap-2 rounded-xl border border-border bg-background/85 p-2 text-start shadow-sm"
            >
              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-[var(--brand-cta)] text-[var(--brand-cta-foreground)]">
                <Bell className="h-3 w-3" />
              </span>
              <span className="min-w-0 flex-1 space-y-1 pt-0.5">
                <span className="flex items-center justify-between">
                  <span
                    className={cn(
                      'h-1.5 rounded-full bg-foreground/60',
                      notification.first,
                    )}
                  />
                  <span className="font-mono text-[8px] text-muted-foreground">
                    {notification.time}
                  </span>
                </span>
                <span
                  className={cn(
                    'block h-1.5 rounded-full bg-muted-foreground/25',
                    notification.second,
                  )}
                />
              </span>
            </div>
          ))}
        </div>
      </div>

      <div className="absolute -start-8 top-8 w-40 -rotate-3 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-1.5">
          <Mail className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-14 rounded-full bg-muted-foreground/30" />
        </div>
        <span className="mt-2.5 block h-2 w-24 rounded-full bg-foreground/60" />
        <div className="mt-2 space-y-1">
          {['w-full', 'w-11/12', 'w-3/4'].map((width) => (
            <span
              key={width}
              className={cn(
                'block h-1 rounded-full bg-muted-foreground/20',
                width,
              )}
            />
          ))}
        </div>
        <span className="mt-2.5 block h-4 w-16 rounded bg-[var(--brand-cta)]" />
      </div>

      <div className="absolute -end-10 bottom-6 w-36 rotate-2 space-y-1.5">
        <span className="flex items-center gap-1.5 text-muted-foreground">
          <Smartphone className="h-3.5 w-3.5" />
          <span className="h-1.5 w-10 rounded-full bg-muted-foreground/25" />
        </span>
        <span className="block w-fit rounded-2xl rounded-es-sm border border-border bg-popover px-3 py-2 shadow-md">
          <span className="block h-1.5 w-20 rounded-full bg-muted-foreground/40" />
        </span>
        <span className="ms-auto block w-fit rounded-2xl rounded-ee-sm bg-[var(--brand-cta)] px-3 py-2 shadow-md">
          <span className="block h-1.5 w-24 rounded-full bg-[var(--brand-cta-foreground)]/70" />
          <span className="mt-1 block h-1.5 w-14 rounded-full bg-[var(--brand-cta-foreground)]/70" />
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

const SUBSCRIBERS = [
  { icon: Mail, y: 30 },
  { icon: Smartphone, y: 96 },
  { icon: Bell, y: 162 },
]

/** Decorative topic fanning out to subscribers on each channel. */
function TopicsVisual() {
  return (
    <ProductEmptyStateVisual className="h-48 w-[460px]">
      <div dir="ltr" className="absolute inset-0">
        <svg
          className="absolute inset-0 h-full w-full"
          viewBox="0 0 460 192"
          fill="none"
        >
          {SUBSCRIBERS.map(({ y }) => (
            <path
              key={y}
              d={`M176 96 C 236 96, 236 ${y}, 296 ${y}`}
              stroke="var(--muted-foreground)"
              strokeOpacity={0.4}
              strokeWidth="1.5"
            />
          ))}
          <circle cx="176" cy="96" r="3" fill="var(--brand-cta)" />
          {SUBSCRIBERS.map(({ y }) => (
            <circle key={y} cx="296" cy={y} r="2.5" fill="var(--brand-cta)" />
          ))}
        </svg>

        <div className="absolute start-0 top-1/2 w-44 -translate-y-1/2 overflow-hidden rounded-lg border border-border bg-card text-start shadow-lg">
          <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-2.5 py-1.5">
            <Hash className="h-3.5 w-3.5 text-muted-foreground" />
            <span className="font-mono text-[11px] text-foreground">
              product-updates
            </span>
          </div>
          <div className="flex items-center gap-2 px-2.5 py-2.5">
            <span className="flex -space-x-1.5">
              {[
                'bg-muted-foreground/40',
                'bg-muted-foreground/25',
                'bg-muted-foreground/15',
              ].map((color) => (
                <span
                  key={color}
                  className={cn(
                    'h-5 w-5 rounded-full border-2 border-card',
                    color,
                  )}
                />
              ))}
            </span>
            <span className="flex items-center gap-1 font-mono text-[10px] tabular-nums text-muted-foreground">
              <Users className="h-3 w-3" />
              1,284
            </span>
          </div>
        </div>

        {SUBSCRIBERS.map(({ icon: Icon, y }) => (
          <div
            key={y}
            className="absolute end-0 flex w-40 -translate-y-1/2 items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-2 shadow-sm"
            style={{ top: y }}
          >
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
              <Icon className="h-3 w-3" />
            </span>
            <span className="h-1.5 w-16 rounded-full bg-muted-foreground/30" />
            <Check className="ms-auto h-3 w-3 text-[var(--brand-cta)]" />
          </div>
        ))}
      </div>
    </ProductEmptyStateVisual>
  )
}

const PROVIDER_ROWS = [
  { channel: 'email', providers: ['sendgrid', 'mailgun', 'resend', 'ses'] },
  { channel: 'sms', providers: ['twilio', 'vonage', 'msg91', 'textmagic'] },
  { channel: 'push', providers: ['fcm', 'apns'] },
]

/** Decorative provider catalog with one provider connected. */
function ProvidersVisual() {
  return (
    <ProductEmptyStateVisual className="w-[400px]">
      <div className="space-y-2.5">
        {PROVIDER_ROWS.map((row) => (
          <div key={row.channel} className="flex items-center gap-2.5">
            <span className="w-10 text-end font-mono text-[10px] text-muted-foreground">
              {row.channel}
            </span>
            {row.providers.map((provider) => {
              const connected = provider === 'sendgrid'
              return (
                <span
                  key={provider}
                  className={cn(
                    'relative flex h-14 w-14 items-center justify-center rounded-xl border bg-card shadow-sm',
                    connected
                      ? 'border-[color-mix(in_oklch,var(--brand-cta)_60%,transparent)] ring-4 ring-[color-mix(in_oklch,var(--brand-cta)_12%,transparent)]'
                      : 'border-border',
                  )}
                >
                  <MessagingProviderIcon serviceKey={provider} size="md" />
                  {connected ? (
                    <span className="absolute -end-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-[var(--brand-cta)] text-[var(--brand-cta-foreground)]">
                      <Check className="h-2.5 w-2.5" />
                    </span>
                  ) : null}
                </span>
              )
            })}
          </div>
        ))}
      </div>

      <div className="absolute -end-20 bottom-2 w-44 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-1.5">
          <KeyRound className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-12 rounded-full bg-muted-foreground/30" />
        </div>
        <span
          dir="ltr"
          className="mt-2 flex h-6 items-center rounded border border-border bg-background px-2 font-mono text-[10px] text-muted-foreground"
        >
          SG.••••••••••••
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function MessagingEmptyState({
  projectId,
  tab,
  createDisabled = false,
  createDisabledTooltip,
}: {
  projectId: string
  tab: MessagingTab
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const docsUrl = getDocsPageUrl('/docs/products/messaging', features.marketing)
  const params = { projectId }

  const content: Record<
    MessagingTab,
    {
      visual: ReactNode
      title: string
      description: string
      steps: ProductEmptyStateStep[]
    }
  > = {
    messages: {
      visual: <MessagesVisual />,
      title: 'Send your first message',
      description:
        'Reach your users by email, SMS, and push notifications from one place.',
      steps: [
        {
          icon: Plug,
          title: 'Add a provider',
          description:
            'Connect an email, SMS, or push provider like SendGrid, Twilio, or FCM.',
          link: { to: '/projects/$projectId/messaging/providers', params },
        },
        {
          icon: Hash,
          title: 'Create a topic',
          description:
            'Group users into topics so you can reach all of them with one message.',
          link: { to: '/projects/$projectId/messaging/topics', params },
        },
        {
          icon: Send,
          title: 'Send a message',
          description:
            'Write it once, then send it now or schedule it for later.',
        },
      ],
    },
    topics: {
      visual: <TopicsVisual />,
      title: 'Create your first topic',
      description:
        'Topics group subscribers so you can message everyone who opted in at once.',
      steps: [
        {
          icon: Hash,
          title: 'Create a topic',
          description:
            'Name it after what people subscribe to, like product updates or alerts.',
        },
        {
          icon: UserPlus,
          title: 'Add subscribers',
          description:
            'Subscribe users through their email, phone, or push targets.',
        },
        {
          icon: Send,
          title: 'Message the topic',
          description:
            'Choose the topic as the audience when you create a message.',
          link: { to: '/projects/$projectId/messaging/', params },
        },
      ],
    },
    providers: {
      visual: <ProvidersVisual />,
      title: 'Connect your first provider',
      description:
        'Providers deliver your messages. Connect services like SendGrid, Twilio, or Firebase Cloud Messaging.',
      steps: [
        {
          icon: MessageSquare,
          title: 'Choose a channel',
          description: 'Pick email, SMS, or push, then a provider for it.',
        },
        {
          icon: KeyRound,
          title: 'Add credentials',
          description:
            'Paste the API key or credentials from your provider account.',
        },
        {
          icon: Send,
          title: 'Start sending',
          description:
            'Messages on that channel go through the provider you enabled.',
        },
      ],
    },
  }
  const active = content[tab]

  return (
    <div className="mx-auto w-full max-w-4xl py-8 sm:py-12">
      <ProductEmptyStateHero
        visual={active.visual}
        icon={MessageSquare}
        title={t(active.title)}
        description={t(active.description)}
        actions={
          <>
            <MessagingCreateControls
              projectId={projectId}
              activeTab={tab}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
              layout="inline"
            />
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <a href={docsUrl} target="_blank" rel="noopener noreferrer">
                {t('Read the docs')}
              </a>
            </Button>
          </>
        }
      />
      <ProductEmptyStateSteps key={tab} steps={active.steps} />
    </div>
  )
}
