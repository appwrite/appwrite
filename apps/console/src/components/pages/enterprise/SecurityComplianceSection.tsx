import { ArrowRight, Check, Lock, Server, Shield } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import {
  ComparisonHeading,
  ComparisonSection,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  enterpriseComplianceFrameworks,
  enterpriseSecurityControls,
  enterpriseSecuritySection,
} from '@/lib/enterprise/content'
import type { EnterpriseSecurityControl } from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'

const CONTROL_ICONS: Record<EnterpriseSecurityControl['icon'], LucideIcon> = {
  shield: Shield,
  lock: Lock,
  server: Server,
}

function SecuritySectionLinks() {
  const t = useT()
  return (
    <div className="flex flex-wrap items-center justify-center gap-2">
      <Button variant="outline" size="lg" className="h-10 bg-background/60 text-[14px]" asChild>
        <DocsRouteLink href="/docs/advanced/security">
          {t('Security docs')}
          <ArrowRight className="ms-1.5 size-3.5 rtl:-scale-x-100" aria-hidden />
        </DocsRouteLink>
      </Button>
      <Button variant="ghost" size="lg" className="h-10 text-[14px] text-muted-foreground" asChild>
        <ContactSalesLink {...analyticsAttrs('enterprise-contact-sales')}>{t('Contact sales')}</ContactSalesLink>
      </Button>
    </div>
  )
}

/** Trust center: compliance frameworks as open seals, then the controls behind them. */
export function SecurityComplianceSection() {
  const t = useT()
  return (
    <ComparisonSection backdrop={<ProductToneBackdrop variant="cta" />}>
      <ComparisonHeading
        align="center"
        eyebrow={enterpriseSecuritySection.eyebrow}
        title={enterpriseSecuritySection.title}
        description={enterpriseSecuritySection.description}
      />
      <div className="mt-8">
        <SecuritySectionLinks />
      </div>

      <ul className="mx-auto mt-14 grid max-w-4xl grid-cols-2 gap-x-6 gap-y-10 sm:grid-cols-4">
        {enterpriseComplianceFrameworks.map((framework, index) => (
          <li
            key={framework.name}
            className="product-hero-rise flex flex-col items-center text-center"
            style={riseStyle(120 + index * 110)}
          >
            <span className="relative flex size-28 items-center justify-center">
              <span
                className="absolute -inset-4 rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.25),transparent_70%)]"
                aria-hidden
              />
              <span
                className="absolute inset-0 rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.45)]"
                aria-hidden
              />
              <span className="relative flex size-[5.5rem] items-center justify-center rounded-full border border-[rgb(var(--tone-rgb)/0.5)] bg-background px-2 shadow-[0_14px_36px_-18px_rgb(var(--tone-rgb)/0.9)] dark:bg-card">
                <span className="font-aeonik-pro text-[15px] leading-tight tracking-tight text-foreground">
                  {framework.name}
                </span>
              </span>
            </span>
            {framework.summary ? (
              <span className="mt-4 text-[12px] text-muted-foreground">{t(framework.summary)}</span>
            ) : null}
          </li>
        ))}
      </ul>

      <div className="mx-auto mt-16 grid max-w-5xl gap-x-10 gap-y-10 sm:grid-cols-3">
        {enterpriseSecurityControls.map((control, index) => {
          const Icon = CONTROL_ICONS[control.icon]
          return (
            <div key={control.title} className="product-hero-rise" style={riseStyle(480 + index * 110)}>
              <div className="relative flex items-center gap-2.5 border-t border-foreground/15 pt-5">
                <span className="absolute -top-px start-0 h-px w-12 bg-[var(--tone-ink)]" aria-hidden />
                <Icon className="size-[18px] text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
                <h3 className="text-[16px] font-medium text-foreground">{t(control.title)}</h3>
              </div>
              <ul className="mt-4 space-y-2.5">
                {control.items.map((item) => (
                  <li key={item} className="flex items-center gap-2.5 text-[13px] text-foreground/85">
                    <Check className="size-3.5 shrink-0 text-[var(--tone-ink)]" strokeWidth={2.5} aria-hidden />
                    {t(item)}
                  </li>
                ))}
              </ul>
            </div>
          )
        })}
      </div>
    </ComparisonSection>
  )
}
