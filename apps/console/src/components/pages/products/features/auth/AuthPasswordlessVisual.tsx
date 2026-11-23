import { KeyRound, Mail, Smartphone } from 'lucide-react'
import { Switch } from '@/components/ui/switch'
import { ArtChip, ArtPanel, ArtToken, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const PASSWORDLESS_METHODS = [
  { label: 'Magic URL', icon: KeyRound, detail: 'Email link sign-in' },
  { label: 'Email OTP', icon: Mail, detail: '6-digit code' },
  { label: 'Phone SMS', icon: Smartphone, detail: 'Text verification' },
] as const

const SETTINGS = ['Magic URL', 'Email OTP', 'Phone SMS'] as const

export function AuthPasswordlessVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[400px] w-full max-w-[540px]">
      <ArtPanel
        className="absolute start-0 top-[12%] z-[1] w-[min(270px,72%)] sm:start-[4%]"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={60}
      >
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{t('Sign in')}</p>
        <p className="mt-0.5 text-[13px] font-semibold text-foreground">{t('Choose a method')}</p>
        <div className="mt-3 space-y-1.5">
          {PASSWORDLESS_METHODS.map((method, index) => {
            const Icon = method.icon
            return (
              <div
                key={method.label}
                className={cn(
                  'product-hero-rise flex items-center gap-2.5 rounded-lg border px-2.5 py-2',
                  index === 0
                    ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.07)]'
                    : 'border-border bg-muted/20',
                )}
                style={riseStyle(250 + index * 120)}
              >
                <span
                  className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-md',
                    index === 0
                      ? 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                      : 'border border-border bg-muted/40 text-muted-foreground',
                  )}
                >
                  <Icon className="size-3.5" aria-hidden />
                </span>
                <div className="min-w-0">
                  <p className="text-[12px] font-medium text-foreground">{t(method.label)}</p>
                  <p className="text-[11px] text-muted-foreground">{t(method.detail)}</p>
                </div>
              </div>
            )
          })}
        </div>
      </ArtPanel>

      <ArtChip className="end-0 top-0" delayMs={600}>
        <p className="text-[10px] font-medium text-muted-foreground">{t('6-digit code')}</p>
        <div dir="ltr" className="mt-1.5 flex gap-1">
          {['4', '8', '2', '9', '1', '3'].map((digit, index) => (
            <span
              key={index}
              className="product-hero-rise flex size-7 items-center justify-center rounded-md border border-border bg-muted/30 font-mono text-[13px] font-medium text-foreground"
              style={riseStyle(900 + index * 110)}
            >
              {digit}
            </span>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-[38%] hidden w-[190px] sm:end-[2%] sm:block" delayMs={800} floatDelayMs={900}>
        <p className="mb-1.5 text-[10px] font-medium text-muted-foreground">{t('Auth settings')}</p>
        <div className="space-y-1.5">
          {SETTINGS.map((label) => (
            <div key={label} className="flex items-center justify-between gap-2">
              <span className="text-[11px] text-foreground">{t(label)}</span>
              <Switch checked disabled className="scale-75 data-[state=checked]:bg-foreground/80" aria-hidden />
            </div>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 start-[18%] sm:start-[26%]" delayMs={1100} floatDelayMs={1500}>
        <div dir="ltr" className="font-mono text-[11px] leading-relaxed">
          <div>
            <ArtToken tone="keyword">await</ArtToken> <ArtToken tone="identifier">account</ArtToken>
            <ArtToken tone="punctuation">.</ArtToken>
            <ArtToken tone="function">createMagicURLToken</ArtToken>
            <ArtToken tone="punctuation">(</ArtToken>
          </div>
          <div className="ps-3">
            <ArtToken tone="string">&apos;paige@acme.io&apos;</ArtToken>
            <ArtToken tone="punctuation">)</ArtToken>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
