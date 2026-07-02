import { KeyRound, Lock, Mail, Smartphone, UserPlus } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  MockPolicyCard,
  MockSwitchRow,
} from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const AUTH_METHODS = [
  { label: 'Email/Password', icon: Mail, enabled: true },
  { label: 'Magic URL', icon: KeyRound, enabled: true },
  { label: 'Phone', icon: Smartphone, enabled: true },
  { label: 'Anonymous', icon: UserPlus, enabled: false },
  { label: 'JWT', icon: Lock, enabled: true },
] as const

export function AuthSecurityVisual() {
  const t = useT()
  return (
    <div className="space-y-4">
      <div className="grid gap-3 xl:grid-cols-4">
        <MockPolicyCard
          title={t('Session length')}
          description={t('Session validity duration.')}
          footer={<p className="text-[12px] font-medium text-foreground">{t('30 days')}</p>}
        />
        <MockPolicyCard
          title={t('Sessions limit')}
          description={t('Concurrent sessions per user.')}
          footer={
            <p className="text-[12px] font-medium text-foreground">
              {t('10 active sessions')}
            </p>
          }
        />
        <MockPolicyCard
          title={t('Session alerts')}
          description={t('Notify on new sign-in.')}
          footer={
            <div className="flex items-center justify-between gap-2">
              <Badge variant="success" className="text-[10px]">
                {t('Enabled')}
              </Badge>
              <Switch
                checked
                disabled
                className="scale-90 data-[state=checked]:bg-foreground/80"
                aria-hidden
              />
            </div>
          }
        />
        <MockPolicyCard
          title={t('Invalidate sessions')}
          description={t('Revoke after password change.')}
          footer={
            <div className="flex items-center justify-between gap-2">
              <Badge variant="success" className="text-[10px]">
                {t('Enabled')}
              </Badge>
              <Switch
                checked
                disabled
                className="scale-90 data-[state=checked]:bg-foreground/80"
                aria-hidden
              />
            </div>
          }
        />
      </div>

      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <p className="text-[12px] font-semibold text-foreground">
            {t('Password policies')}
          </p>
          <p className="mt-1 text-[11px] leading-5 text-muted-foreground">
            {t('Strength presets, compliance checks, and reuse rules.')}
          </p>
          <div className="mt-3 space-y-2">
            <MockSwitchRow label={t('Dictionary check')} checked />
            <MockSwitchRow label={t('Personal data check')} checked />
          </div>
          <div className="mt-3 flex flex-wrap gap-1.5">
            <Badge variant="inactive" className="text-[10px]">
              {t('Strong preset')}
            </Badge>
            <Badge variant="inactive" className="text-[10px]">
              {t('Min 12 chars')}
            </Badge>
            <Badge variant="inactive" className="text-[10px]">
              {t('History: 5')}
            </Badge>
            <Badge variant="success" className="text-[10px]">
              {t('4/7 compliant')}
            </Badge>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card/50 p-4">
          <p className="text-[12px] font-semibold text-foreground">
            {t('Email policies')}
          </p>
          <div className="mt-3 space-y-2">
            <MockSwitchRow label={t('Block disposable emails')} checked />
            <MockSwitchRow label={t('Block aliased emails')} checked />
            <MockSwitchRow label={t('Block free providers')} />
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card/50 p-4 md:col-span-2 xl:col-span-1">
          <p className="text-[12px] font-semibold text-foreground">
            {t('Auth methods')}
          </p>
          <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
            {AUTH_METHODS.map((method) => {
              const Icon = method.icon
              return (
                <div
                  key={method.label}
                  className={cn(
                    'flex items-center justify-between gap-2 rounded-lg border border-border bg-background/80 px-2.5 py-2',
                    method.enabled && 'border-foreground/10 bg-muted/40',
                  )}
                >
                  <div className="flex min-w-0 items-center gap-2">
                    <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="truncate text-[11px] font-medium text-foreground">
                      {t(method.label)}
                    </span>
                  </div>
                  <Switch
                    checked={method.enabled}
                    disabled
                    className="scale-90 data-[state=checked]:bg-foreground/80"
                    aria-hidden
                  />
                </div>
              )
            })}
          </div>
        </div>
      </div>
    </div>
  )
}
