import { KeyRound, ShieldCheck } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { MockSwitchRow } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { useT } from '@/lib/i18n/translate'

export function AuthMfaVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame title={t('Multi-factor authentication')} eyebrow={t('Security')}>
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">
              {t('Authenticator app')}
            </p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('TOTP codes from Google Authenticator, 1Password, and similar apps.')}
            </p>
          </div>
          <Badge variant="success" className="text-[10px] shrink-0">
            {t('MFA enabled')}
          </Badge>
        </div>

        <div className="grid gap-3 sm:grid-cols-[minmax(0,7rem)_minmax(0,1fr)]">
          <div className="mx-auto flex aspect-square w-full max-w-[7rem] items-center justify-center rounded-lg border border-border bg-muted/30 p-3 sm:mx-0">
            <div className="grid grid-cols-5 gap-0.5">
              {Array.from({ length: 25 }).map((_, index) => (
                <span
                  key={index}
                  className="size-2 rounded-[1px] bg-foreground/70"
                  aria-hidden
                />
              ))}
            </div>
          </div>

          <div className="space-y-3">
            <div>
              <p className="text-[11px] font-medium text-foreground">
                {t('Enter verification code')}
              </p>
              <div className="mt-2 flex gap-1.5">
                {['4', '8', '2', '1', '9', '0'].map((digit, index) => (
                  <span
                    key={index}
                    className="flex size-8 items-center justify-center rounded-md border border-border bg-background text-[13px] font-medium text-foreground"
                  >
                    {digit}
                  </span>
                ))}
              </div>
            </div>

            <MockSwitchRow
              label={t('Require MFA for sensitive actions')}
              description={t('Extra verification before account changes')}
              checked
              icon={ShieldCheck}
            />
          </div>
        </div>

        <div className="rounded-lg border border-border bg-background/80 p-3">
          <div className="flex items-center gap-2">
            <KeyRound className="size-3.5 text-muted-foreground" aria-hidden />
            <p className="text-[11px] font-semibold text-foreground">
              {t('Recovery codes')}
            </p>
          </div>
          <div className="mt-2.5 grid grid-cols-2 gap-1.5 sm:grid-cols-4">
            {['a8f2-k9m1', 'p3q7-r2n8', 'x5y1-z4w6', 'h7j3-l0c9'].map((code) => (
              <span
                key={code}
                className="rounded border border-border bg-muted/30 px-2 py-1 font-mono text-[10px] text-muted-foreground"
              >
                {code}
              </span>
            ))}
          </div>
          <p className="mt-2 text-[10px] leading-5 text-muted-foreground">
            {t('One-time backup codes when authenticator access is unavailable.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
