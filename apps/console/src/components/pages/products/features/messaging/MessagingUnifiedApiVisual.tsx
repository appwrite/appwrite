import { BellRing, Check, Layers, Mail, MessageSquareText } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  ArtToken as T,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CHANNELS: {
  id: string
  label: string
  icon: LucideIcon
  method: string
  args: string
  active?: boolean
}[] = [
  { id: 'email', label: 'Email', icon: Mail, method: 'createEmail', args: 'topics, subject', active: true },
  { id: 'sms', label: 'SMS', icon: MessageSquareText, method: 'createSms', args: 'targets, content' },
  { id: 'push', label: 'Push', icon: BellRing, method: 'createPush', args: 'topics, title' },
]

function CodeLine({ method, args, active }: { method: string; args: string; active?: boolean }) {
  return (
    <span
      className={cn(
        '-mx-4 block border-s-2 px-4',
        active ? 'border-[var(--tone-ink)] bg-[rgb(var(--tone-rgb)/0.08)]' : 'border-transparent',
      )}
    >
      <T tone="identifier">messaging</T>
      <T tone="punctuation">.</T>
      <T tone="function">{method}</T>
      <T tone="punctuation">({'{ '}</T>
      <T tone="property">{args}</T>
      <T tone="punctuation">{' })'}</T>
    </span>
  )
}

export function MessagingUnifiedApiVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[600px] pb-14 pt-12">
      <ArtChip className="start-0 top-0" delayMs={900}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Layers} />
          <div>
            <p className="text-[12px] font-medium text-foreground">{t('One Messaging service')}</p>
            <p className="text-[10px] text-muted-foreground">{t('3 channels')}</p>
          </div>
        </div>
      </ArtChip>

      <div className="grid items-center gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-0">
        <ArtWindow
          className="product-hero-rise"
          style={riseStyle(60)}
          title={<span dir="ltr">notify.ts</span>}
          bodyClassName="px-4 py-3.5"
        >
          <pre dir="ltr" className="font-mono text-[11px] leading-[1.9]">
            <code>
              <T tone="keyword">const</T> <T tone="identifier">messaging</T> <T tone="operator">=</T>{' '}
              <T tone="keyword">new</T> <T tone="class">Messaging</T>
              <T tone="punctuation">(</T>
              <T tone="identifier">client</T>
              <T tone="punctuation">)</T>
              {'\n\n'}
              {CHANNELS.map((channel) => (
                <CodeLine key={channel.id} method={channel.method} args={channel.args} active={channel.active} />
              ))}
            </code>
          </pre>
        </ArtWindow>

        <div className="grid grid-cols-3 gap-2 sm:flex sm:flex-col sm:gap-3">
          {CHANNELS.map((channel, index) => (
            <div key={channel.id} className="flex min-w-0 items-center">
              <ArtConnector travel travelDelayMs={index * 700} className="hidden w-10 shrink-0 sm:block" />
              <ArtPanel
                className="min-w-0 flex-1"
                delayMs={350 + index * 150}
                float
                floatDelayMs={index * 450}
                innerClassName={cn(
                  'flex flex-col items-start gap-2 px-2.5 py-2.5 sm:flex-row sm:items-center sm:gap-2.5 sm:px-3',
                  channel.active && 'border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
                )}
              >
                <ArtIconBadge icon={channel.icon} tone={channel.active ? 'primary' : 'neutral'} />
                <div className="min-w-0">
                  <p className="text-[12px] font-medium text-foreground">{t(channel.label)}</p>
                  <p dir="ltr" className="truncate font-mono text-[10px] text-muted-foreground">
                    {channel.method}()
                  </p>
                </div>
                <span
                  className="ms-auto hidden size-1.5 shrink-0 rounded-full bg-emerald-500 sm:block"
                  aria-hidden
                />
              </ArtPanel>
            </div>
          ))}
        </div>
      </div>

      <ArtChip className="bottom-0 end-0 hidden sm:block" delayMs={1200} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <span className="flex size-4 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
            <Check className="size-2.5" strokeWidth={3} aria-hidden />
          </span>
          <span className="text-[11px] text-muted-foreground">
            {t('Email, SMS, and push from a single SDK client.')}
          </span>
        </div>
      </ArtChip>
    </div>
  )
}
