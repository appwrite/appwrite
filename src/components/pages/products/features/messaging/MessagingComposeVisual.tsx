import { CalendarClock, Hash, Mail, Phone, Bell } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const CHANNELS = [
  { id: 'email', label: 'Email', icon: Mail, active: true },
  { id: 'sms', label: 'SMS', icon: Phone, active: false },
  { id: 'push', label: 'Push', icon: Bell, active: false },
] as const

function ChannelTab({
  label,
  icon: Icon,
  active,
}: {
  label: string
  icon: LucideIcon
  active: boolean
}) {
  const t = useT()
  return (
    <span
      className={cn(
        'inline-flex flex-1 items-center justify-center gap-1.5 rounded-md border px-2 py-1.5 text-[11px] font-medium transition-colors',
        active
          ? 'border-foreground/10 bg-background text-foreground'
          : 'border-transparent bg-transparent text-muted-foreground',
      )}
    >
      <Icon className="size-3.5 shrink-0" aria-hidden />
      {t(label)}
    </span>
  )
}

export function MessagingComposeVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      tabs={[
        { id: 'messages', label: t('Messages'), active: true }, // pragma: allowlist secret
        { id: 'topics', label: t('Topics') },
        { id: 'providers', label: t('Providers') },
      ]}
      contentClassName="p-0"
    >
      <div className="grid min-h-[16rem] lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
        <div className="min-w-0 border-b border-border p-4 sm:p-5 lg:border-b-0 lg:border-e">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Compose message')}
          </p>
          <p className="mt-0.5 text-[13px] font-semibold text-foreground">{t('Weekly product digest')}</p>

          <div className="mt-3 flex gap-1 rounded-lg border border-border bg-muted/15 p-1">
            {CHANNELS.map((channel) => (
              <ChannelTab
                key={channel.id}
                label={channel.label}
                icon={channel.icon}
                active={channel.active}
              />
            ))}
          </div>

          <div className="mt-3 space-y-2.5">
            <div>
              <p className="mb-1 text-[10px] font-medium text-muted-foreground">{t('Subject')}</p>
              <Input
                readOnly
                value={t("What's new in Acme this week")}
                className="h-8 text-[12px]"
              />
            </div>
            <div>
              <p className="mb-1 text-[10px] font-medium text-muted-foreground">{t('Content')}</p>
              <div className="rounded-lg border border-border bg-muted/15 px-3 py-2.5 text-[11px] leading-relaxed text-muted-foreground">
                {t('Ship notes, feature highlights, and links for your subscribers.')}
              </div>
            </div>
            <div className="flex items-center gap-2 rounded-lg border border-border bg-background/80 px-3 py-2">
              <Hash className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
              <div className="min-w-0 flex-1">
                <p className="truncate font-mono text-[11px] text-foreground">weekly-digest</p>
                <p className="text-[10px] text-muted-foreground">{t('Topic')} · 3,401 {t('targets')}</p>
              </div>
            </div>
          </div>
        </div>

        <div className="flex min-w-0 flex-col bg-muted/10 p-4 sm:p-5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Delivery')}
          </p>

          <div className="mt-3 space-y-2.5">
            <div className="rounded-lg border border-border bg-card px-3 py-2.5">
              <div className="flex items-center gap-2">
                <CalendarClock className="size-3.5 text-muted-foreground" aria-hidden />
                <p className="text-[12px] font-medium text-foreground">{t('Scheduled send')}</p>
              </div>
              <p className="mt-1 font-mono text-[11px] text-muted-foreground">
                Mon, 9:00 AM · America/New_York
              </p>
            </div>

            <div className="rounded-lg border border-border bg-card px-3 py-2.5">
              <p className="text-[11px] font-medium text-foreground">{t('Common flows')}</p>
              <div className="mt-2 flex flex-wrap gap-1.5">
                <Badge variant="outline" className="text-[10px]">
                  {t('OTP verification')}
                </Badge>
                <Badge variant="outline" className="text-[10px]">
                  {t('Password reset')}
                </Badge>
                <Badge variant="outline" className="text-[10px]">
                  {t('Account alerts')}
                </Badge>
              </div>
            </div>
          </div>

          <div className="mt-auto flex flex-wrap gap-2 pt-4">
            <span className="inline-flex h-8 items-center rounded-md bg-foreground px-3 text-[12px] font-medium text-background">
              {t('Schedule message')}
            </span>
            <span className="inline-flex h-8 items-center rounded-md border border-border bg-background px-3 text-[12px] font-medium text-foreground">
              {t('Send now')}
            </span>
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
