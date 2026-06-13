import { CheckCircle2, KeyRound, Lock, Mail, Smartphone, UserPlus } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  MockPolicyCard,
  MockSwitchRow,
} from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { cn } from '@/lib/utils'

const AUTH_METHODS = [
  { label: 'Email/Password', icon: Mail, enabled: true },
  { label: 'Magic URL', icon: KeyRound, enabled: true },
  { label: 'Phone', icon: Smartphone, enabled: true },
  { label: 'Anonymous', icon: UserPlus, enabled: false },
  { label: 'JWT', icon: Lock, enabled: true },
] as const

const PASSWORD_CHARACTER_RULES = [
  { label: 'Uppercase', enabled: true },
  { label: 'Lowercase', enabled: true },
  { label: 'Numbers', enabled: true },
  { label: 'Symbols', enabled: true },
] as const

const COMPLIANCE_STANDARDS = [
  { name: 'NIST SP 800-63B', compliant: true },
  { name: 'OWASP ASVS L2', compliant: true },
  { name: 'PCI DSS 4.0', compliant: true },
  { name: 'Microsoft Entra ID', compliant: true },
  { name: 'CIS Controls', compliant: false },
] as const

export function AuthSecurityVisual() {
  return (
    <div className="space-y-4">
      <div className="grid gap-3 xl:grid-cols-4">
        <MockPolicyCard
          title="Session length"
          description="Session validity duration."
          footer={<p className="text-[12px] font-medium text-foreground">30 days</p>}
        />
        <MockPolicyCard
          title="Sessions limit"
          description="Concurrent sessions per user."
          footer={
            <p className="text-[12px] font-medium text-foreground">10 active sessions</p>
          }
        />
        <MockPolicyCard
          title="Session alerts"
          description="Notify on new sign-in."
          footer={
            <div className="flex items-center justify-between gap-2">
              <Badge variant="success" className="text-[10px]">
                Enabled
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
          title="Invalidate sessions"
          description="Revoke after password change."
          footer={
            <div className="flex items-center justify-between gap-2">
              <Badge variant="success" className="text-[10px]">
                Enabled
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
        <div className="overflow-hidden rounded-xl border border-border bg-card/50 md:col-span-2 xl:col-span-2">
          <div className="border-b border-border px-4 py-3">
            <p className="text-[13px] font-semibold text-foreground">Password strength</p>
            <p className="mt-1 text-[12px] leading-5 text-muted-foreground">
              Presets, minimum length, character rules, and compliance against common standards.
            </p>
          </div>

          <div className="grid divide-y border-border md:grid-cols-2 md:divide-x md:divide-y-0">
            <div className="space-y-3 p-4">
              <div>
                <p className="text-[11px] font-medium text-muted-foreground">Preset</p>
                <div className="mt-1.5 flex items-center justify-between gap-2 rounded-lg border border-foreground/10 bg-muted/40 px-3 py-2">
                  <span className="text-[12px] font-medium text-foreground">Strong</span>
                  <span className="text-[10px] text-muted-foreground">12 chars, all types</span>
                </div>
              </div>

              <div>
                <p className="text-[11px] font-medium text-muted-foreground">Minimum length</p>
                <p className="mt-1 text-[13px] font-semibold text-foreground">12 characters</p>
              </div>

              <div>
                <p className="text-[11px] font-medium text-muted-foreground">
                  Required character types
                </p>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                  {PASSWORD_CHARACTER_RULES.map((rule) => (
                    <Badge
                      key={rule.label}
                      variant={rule.enabled ? 'success' : 'inactive'}
                      className="text-[10px]"
                    >
                      {rule.label}
                    </Badge>
                  ))}
                </div>
              </div>

              <div className="space-y-2 border-t border-border pt-3">
                <MockSwitchRow label="Dictionary check" description="Block common passwords" checked />
                <MockSwitchRow
                  label="Personal data check"
                  description="Reject passwords with user info"
                  checked
                />
                <div className="flex flex-wrap gap-1.5 pt-0.5">
                  <Badge variant="inactive" className="text-[10px]">
                    History: 5 passwords
                  </Badge>
                </div>
              </div>
            </div>

            <div className="p-4">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-[12px] font-semibold text-foreground">Compliance</p>
                <Badge variant="success" className="text-[10px]">
                  4/5 compliant
                </Badge>
              </div>
              <p className="mt-1 text-[11px] leading-5 text-muted-foreground">
                Compare your policy against NIST, OWASP, PCI DSS, and other guidance.
              </p>
              <div className="mt-3 space-y-2">
                {COMPLIANCE_STANDARDS.map((standard) => (
                  <div
                    key={standard.name}
                    className="flex items-center justify-between gap-2 rounded-lg border border-border bg-background/80 px-2.5 py-2"
                  >
                    <span className="text-[11px] font-medium text-foreground">{standard.name}</span>
                    {standard.compliant ? (
                      <span className="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 className="size-3" aria-hidden />
                        Compliant
                      </span>
                    ) : (
                      <Badge variant="warning" className="text-[10px]">
                        Gap
                      </Badge>
                    )}
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card/50 p-4">
          <p className="text-[12px] font-semibold text-foreground">Email policies</p>
          <div className="mt-3 space-y-2">
            <MockSwitchRow label="Block disposable emails" checked />
            <MockSwitchRow label="Block aliased emails" checked />
            <MockSwitchRow label="Block free providers" />
          </div>
        </div>

        <div className="rounded-xl border border-border bg-card/50 p-4 md:col-span-2 xl:col-span-3">
          <p className="text-[12px] font-semibold text-foreground">Auth methods</p>
          <div className="mt-3 grid gap-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
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
                      {method.label}
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
