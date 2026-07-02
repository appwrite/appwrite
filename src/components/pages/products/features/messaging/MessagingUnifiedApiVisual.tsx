import { Bell, Mail, Phone } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CHANNELS = [
  {
    id: 'email',
    label: 'Email',
    icon: Mail,
    method: 'createEmail()',
    example: 'subject, html body, topics',
  },
  {
    id: 'sms',
    label: 'SMS',
    icon: Phone,
    method: 'createSms()',
    example: 'message body, topics',
  },
  {
    id: 'push',
    label: 'Push',
    icon: Bell,
    method: 'createPush()',
    example: 'title, body, topics',
  },
] as const

function ChannelMethodRow({
  label,
  icon: Icon,
  method,
  example,
  active = false,
  delayMs = 0,
}: {
  label: string
  icon: LucideIcon
  method: string
  example: string
  active?: boolean
  delayMs?: number
}) {
  const t = useT()
  return (
    <div
      className={cn(
        'rounded-lg border border-border bg-background/80 px-3 py-2.5 transition-[border-color,background-color,transform] duration-300',
        active && 'border-foreground/10 bg-muted/35',
        'group-hover/visual:border-foreground/10 group-hover/visual:bg-muted/25',
      )}
      style={{ transitionDelay: `${delayMs}ms` }}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-start gap-2.5">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
            <Icon className="size-3.5 text-muted-foreground" aria-hidden />
          </span>
          <div className="min-w-0">
            <p className="text-[12px] font-medium text-foreground">{t(label)}</p>
            <p className="mt-0.5 font-mono text-[10px] text-muted-foreground">{example}</p>
          </div>
        </div>
        <Badge variant={active ? 'info' : 'outline'} className="shrink-0 font-mono text-[10px]">
          {method}
        </Badge>
      </div>
    </div>
  )
}

export function MessagingUnifiedApiVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'overview', label: t('Overview') },
        { id: 'messages', label: t('Messages'), active: true }, // pragma: allowlist secret
        { id: 'providers', label: t('Providers') },
      ]}
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('One Messaging service')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Email, SMS, and push from a single SDK client.')}
            </p>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('3 channels')}
          </Badge>
        </div>

        <div className="overflow-hidden rounded-lg border border-border bg-muted/20">
          <div className="border-b border-border bg-muted/15 px-3 py-2">
            <p className="font-mono text-[10px] text-muted-foreground">
              import {'{ Messaging }'} from &apos;appwrite&apos;
            </p>
          </div>
          <div className="space-y-2 p-3">
            {CHANNELS.map((channel, index) => (
              <ChannelMethodRow
                key={channel.id}
                label={channel.label}
                icon={channel.icon}
                method={channel.method}
                example={channel.example}
                active={channel.id === 'email'}
                delayMs={index * 70}
              />
            ))}
          </div>
        </div>

        <div className="rounded-lg border border-dashed border-border bg-muted/15 px-3 py-2.5 text-center">
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Replace separate email, SMS, and push SDKs with one API and delivery log.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
