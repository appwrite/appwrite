import { KeyRound, Mail, Smartphone } from 'lucide-react'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { MockSwitchRow } from '@/components/pages/products/features/_components/ProductFeatureMockParts'
import { Syn } from '@/components/pages/home/product-bento/MockSyntax'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const PASSWORDLESS_METHODS = [
  { label: 'Magic URL', icon: KeyRound, detail: 'Email link sign-in' },
  { label: 'Email OTP', icon: Mail, detail: '6-digit code' },
  { label: 'Phone SMS', icon: Smartphone, detail: 'Text verification' },
] as const

export function AuthPasswordlessVisual() {
  const t = useT()
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <ProductFeatureVisualFrame title={t('Auth settings')} contentClassName="space-y-2">
        <MockSwitchRow label={t('Magic URL')} checked highlightOnHover />
        <MockSwitchRow label={t('Email OTP')} checked highlightOnHover />
        <MockSwitchRow label={t('Phone SMS')} checked highlightOnHover />
        <MockSwitchRow label={t('Email / Password')} description={t('Optional fallback')} />
      </ProductFeatureVisualFrame>

      <ProductFeatureVisualFrame
        eyebrow={t('Sign in')}
        title={t('Choose a method')}
        contentClassName="space-y-3"
      >
        <div className="space-y-2">
          {PASSWORDLESS_METHODS.map((method, index) => {
            const Icon = method.icon
            return (
              <div
                key={method.label}
                className={cn(
                  'flex items-center gap-2.5 rounded-lg border border-border bg-background/80 px-3 py-2.5 transition-[border-color,background-color,transform] duration-300',
                  index === 0 && 'border-foreground/10 bg-muted/40 group-hover/visual:translate-x-0',
                )}
                style={{
                  transform: index === 0 ? 'translateX(6px)' : undefined,
                  transitionDelay: `${120 + index * 80}ms`,
                }}
              >
                <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                  <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                </span>
                <div className="min-w-0">
                  <p className="text-[12px] font-medium text-foreground">{t(method.label)}</p>
                  <p className="text-[11px] text-muted-foreground">{t(method.detail)}</p>
                </div>
              </div>
            )
          })}
        </div>

        <div className="overflow-hidden rounded-md border border-border bg-muted/25 p-2.5 font-mono text-[10px] leading-relaxed opacity-80 transition-opacity duration-300 group-hover/visual:opacity-100 motion-reduce:opacity-100">
          <div>
            <Syn tone="keyword">await</Syn> <Syn tone="identifier">account</Syn>
          </div>
          <div className="ps-3">
            <Syn tone="punctuation">.</Syn>
            <Syn tone="function">createMagicURLToken</Syn>
            <Syn tone="punctuation">(</Syn>
          </div>
          <div className="ps-6">
            <Syn tone="string">&apos;paige@acme.io&apos;</Syn>
            <Syn tone="punctuation">)</Syn>
          </div>
        </div>
      </ProductFeatureVisualFrame>
    </div>
  )
}
