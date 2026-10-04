import { KeyRound, ShieldCheck } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { ArtChip, ArtIconBadge, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

function CountdownRing() {
  const radius = 9
  const circumference = 2 * Math.PI * radius
  return (
    <svg viewBox="0 0 24 24" className="size-6 -rotate-90" aria-hidden>
      <circle cx="12" cy="12" r={radius} fill="none" strokeWidth="2.5" className="stroke-muted" />
      <circle
        cx="12"
        cy="12"
        r={radius}
        fill="none"
        strokeWidth="2.5"
        strokeLinecap="round"
        className="product-art-countdown stroke-[var(--tone-ink)]"
        strokeDasharray={circumference}
        style={{ strokeDashoffset: circumference }}
      />
    </svg>
  )
}

function AuthenticatorPhone() {
  const t = useT()
  return (
    <div className="product-tone-shadow relative mx-auto w-[210px] rounded-[30px] border border-border bg-background p-2 dark:bg-card">
      <div className="rounded-[24px] border border-border/70 bg-muted/20 px-3.5 pb-6 pt-3">
        <div className="mx-auto h-1.5 w-14 rounded-full bg-foreground/15" aria-hidden />
        <p className="mt-4 text-[11px] font-medium text-muted-foreground">{t('Authenticator app')}</p>
        {[
          { account: 'Acme', email: 'paige@acme.io', code: '482 190', active: true },
          { account: 'GitHub', email: 'paige', code: '913 024', active: false },
        ].map((entry, index) => (
          <div
            key={entry.account}
            className="product-hero-rise mt-3 rounded-xl border border-border bg-background px-3 py-2.5 dark:bg-card"
            style={riseStyle(300 + index * 200)}
          >
            <div className="flex items-center justify-between gap-2">
              <div className="min-w-0">
                <p className="text-[11px] font-semibold text-foreground">{entry.account}</p>
                <p className="truncate text-[10px] text-muted-foreground">{entry.email}</p>
              </div>
              {entry.active ? <CountdownRing /> : null}
            </div>
            <p
              dir="ltr"
              className={
                entry.active
                  ? 'mt-1.5 font-mono text-[20px] font-medium tracking-[0.12em] text-[var(--tone-ink)]'
                  : 'mt-1.5 font-mono text-[16px] tracking-[0.12em] text-muted-foreground'
              }
            >
              {entry.code}
            </p>
          </div>
        ))}
      </div>
    </div>
  )
}

export function AuthMfaVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[420px] w-full max-w-[520px]">
      <div className="product-hero-rise absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2" style={riseStyle(60)}>
        <AuthenticatorPhone />
      </div>

      <ArtChip className="end-0 top-[8%]" delayMs={700}>
        <p className="text-[10px] font-medium text-muted-foreground">{t('Enter verification code')}</p>
        <div dir="ltr" className="mt-1.5 flex gap-1">
          {['4', '8', '2', '1', '9', '0'].map((digit, index) => (
            <span
              key={index}
              className="product-hero-rise flex size-7 items-center justify-center rounded-md border border-border bg-muted/30 font-mono text-[13px] font-medium text-foreground"
              style={riseStyle(1000 + index * 110)}
            >
              {digit}
            </span>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="start-0 top-[22%]" delayMs={900} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ShieldCheck} tone="success" />
          <p className="text-[12px] font-medium text-foreground">{t('MFA enabled')}</p>
        </div>
      </ArtChip>

      <ArtChip className="bottom-[4%] start-0 hidden w-[190px] sm:block" delayMs={1100} floatDelayMs={1400}>
        <div className="flex items-center gap-1.5">
          <KeyRound className="size-3 text-[var(--tone-ink)]" aria-hidden />
          <p className="text-[11px] font-semibold text-foreground">{t('Recovery codes')}</p>
        </div>
        <div dir="ltr" className="mt-2 grid grid-cols-2 gap-1">
          {['a8f2-k9m1', 'p3q7-r2n8', 'x5y1-z4w6', 'h7j3-l0c9'].map((code) => (
            <span key={code} className="rounded bg-muted/50 px-1.5 py-0.5 text-center font-mono text-[10px] text-muted-foreground">
              {code}
            </span>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="bottom-[12%] end-0 hidden w-[210px] sm:block" delayMs={1300} floatDelayMs={300}>
        <div className="flex items-start justify-between gap-2">
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Require MFA for sensitive actions')}</p>
            <p className="mt-0.5 text-[10px] leading-4 text-muted-foreground">
              {t('Extra verification before account changes')}
            </p>
          </div>
          <Switch checked disabled className="mt-0.5 scale-75 data-[state=checked]:bg-foreground/80" aria-hidden />
        </div>
      </ArtChip>
    </div>
  )
}
