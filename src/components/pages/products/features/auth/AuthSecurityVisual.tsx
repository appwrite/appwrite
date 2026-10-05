import { BellRing, Clock, KeyRound, Lock, LogOut, Mail, MonitorSmartphone, ShieldCheck, Smartphone, UserPlus } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SESSION_POLICIES = [
  { title: 'Session length', value: '30 days', icon: Clock, offset: 'lg:ms-10' },
  { title: 'Sessions limit', value: '10 active sessions', icon: MonitorSmartphone, offset: 'lg:ms-0' },
  { title: 'Session alerts', value: 'Enabled', icon: BellRing, offset: 'lg:ms-6' },
  { title: 'Invalidate sessions', value: 'Enabled', icon: LogOut, offset: 'lg:ms-14' },
] as const

const AUTH_METHODS = [
  { label: 'Email/Password', icon: Mail, enabled: true },
  { label: 'Magic URL', icon: KeyRound, enabled: true },
  { label: 'Phone', icon: Smartphone, enabled: true },
  { label: 'JWT', icon: Lock, enabled: true },
  { label: 'Anonymous', icon: UserPlus, enabled: false },
] as const

function ShieldEmblem() {
  return (
    <div className="relative mx-auto aspect-square w-[220px] sm:w-[260px]" aria-hidden>
      <div className="absolute inset-0 rounded-full border border-dashed border-foreground/15" />
      <div className="product-hero-orbit absolute inset-[12%] rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.4)]" />
      <div className="absolute inset-[26%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.25),transparent_70%)]" />
      <div className="product-tone-shadow absolute inset-[32%] flex items-center justify-center rounded-[28%] border border-[rgb(var(--tone-rgb)/0.45)] bg-background dark:bg-card">
        <ShieldCheck className="size-10 text-[var(--tone-ink)]" strokeWidth={1.5} />
      </div>
    </div>
  )
}

export function AuthSecurityVisual() {
  const t = useT()

  return (
    <div className="grid items-center gap-10 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:gap-8">
      <div className="space-y-3">
        {SESSION_POLICIES.map((policy, index) => (
          <ArtPanel
            key={policy.title}
            className={cn('lg:max-w-[300px]', policy.offset)}
            innerClassName="flex items-center gap-3 px-3 py-2.5"
            delayMs={150 + index * 120}
            float
            floatDelayMs={index * 450}
          >
            <ArtIconBadge icon={policy.icon} tone={index % 2 === 0 ? 'primary' : 'secondary'} />
            <div className="min-w-0 flex-1">
              <p className="text-[12px] font-medium text-foreground">{t(policy.title)}</p>
              <p className="text-[11px] text-muted-foreground">{t(policy.value)}</p>
            </div>
          </ArtPanel>
        ))}
      </div>

      <div className="product-hero-rise order-first lg:order-none" style={riseStyle(0)}>
        <ShieldEmblem />
      </div>

      <div className="space-y-3">
        <ArtPanel className="lg:ms-auto lg:max-w-[320px]" innerClassName="px-3.5 py-3" delayMs={300} float floatDelayMs={300}>
          <p className="text-[12px] font-semibold text-foreground">{t('Password policies')}</p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            <Badge variant="inactive" className="text-[10px]">{t('Strong preset')}</Badge>
            <Badge variant="inactive" className="text-[10px]">{t('Min 12 chars')}</Badge>
            <Badge variant="inactive" className="text-[10px]">{t('History: 5')}</Badge>
            <Badge variant="success" className="text-[10px]">{t('4/7 compliant')}</Badge>
          </div>
        </ArtPanel>

        <ArtPanel className="lg:me-10 lg:ms-auto lg:max-w-[300px]" innerClassName="space-y-2 px-3.5 py-3" delayMs={450} float floatDelayMs={900}>
          <p className="text-[12px] font-semibold text-foreground">{t('Email policies')}</p>
          {(['Block disposable emails', 'Block aliased emails', 'Block free providers'] as const).map((label, index) => (
            <div key={label} className="flex items-center justify-between gap-3">
              <span className="text-[11px] text-muted-foreground">{t(label)}</span>
              <Switch checked={index < 2} disabled className="scale-75 data-[state=checked]:bg-foreground/80" aria-hidden />
            </div>
          ))}
        </ArtPanel>

        <div className="product-hero-rise flex flex-wrap gap-1.5 lg:justify-end" style={riseStyle(650)}>
          {AUTH_METHODS.map((method) => {
            const Icon = method.icon
            return (
              <span
                key={method.label}
                className={cn(
                  'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px]',
                  method.enabled
                    ? 'border-border bg-background text-foreground shadow-sm dark:bg-card'
                    : 'border-dashed border-border text-muted-foreground',
                )}
              >
                <Icon className="size-3" aria-hidden />
                {t(method.label)}
                <span
                  className={cn('size-1.5 rounded-full', method.enabled ? 'bg-emerald-500' : 'bg-muted-foreground/40')}
                  aria-hidden
                />
              </span>
            )
          })}
        </div>
      </div>
    </div>
  )
}
