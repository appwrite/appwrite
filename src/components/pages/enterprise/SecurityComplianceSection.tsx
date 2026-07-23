import { ArrowRight } from 'lucide-react'
import { SectionSoftLight } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import {
  enterpriseComplianceFrameworks,
  enterpriseSecurityControls,
  enterpriseSecuritySection,
} from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { analyticsAttrs } from '@/lib/analytics-actions'

type SecurityComplianceSectionProps = {
  onContactSales?: () => void
}

function SecuritySectionLinks({ onContactSales }: SecurityComplianceSectionProps) {
  const t = useT()
  return (
    <div className="flex flex-wrap items-center justify-center gap-3">
      <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
        <DocsRouteLink href="/docs/advanced/security">
          {t('Security docs')}
          <ArrowRight className="ms-1.5 size-3.5" aria-hidden />
        </DocsRouteLink>
      </Button>
      {onContactSales ? (
        <Button
          variant="ghost"
          size="sm"
          className="h-9 text-[13px] text-muted-foreground"
          onClick={onContactSales}
          {...analyticsAttrs('enterprise-contact-sales')}
        >
          {t('Contact sales')}
        </Button>
      ) : null}
    </div>
  )
}

export function SecurityComplianceSection({ onContactSales }: SecurityComplianceSectionProps) {
  const t = useT()
  return (
    <section className="relative isolate overflow-hidden border-b border-border py-16 sm:py-20">
      <SectionSoftLight tone="purple" position="left" />
      <div className="relative z-[1] mx-auto max-w-7xl px-4 sm:px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="text-[11px] font-semibold uppercase tracking-[0.22em] text-muted-foreground">
            {t(enterpriseSecuritySection.eyebrow)}
            <span className="text-[var(--brand-cta)]">_</span>
          </p>
          <h2 className="mt-3 font-aeonik-pro text-balance text-[30px] font-normal leading-none tracking-tight text-foreground sm:text-[36px]">
            {t(enterpriseSecuritySection.title)}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mt-4 text-[14px] leading-7 text-muted-foreground sm:text-[15px]">
            {t(enterpriseSecuritySection.description)}
          </p>
          <div className="mt-6">
            <SecuritySectionLinks onContactSales={onContactSales} />
          </div>
        </div>

        <ul className="mt-12 flex flex-wrap items-center justify-center gap-2.5">
          {enterpriseComplianceFrameworks.map((framework) => (
            <li key={framework.name}>
              <span
                title={framework.summary ? t(framework.summary) : undefined}
                className="inline-flex items-center rounded-full border border-border/80 bg-muted/25 px-4 py-2 text-[13px] font-medium text-foreground transition-colors hover:bg-muted/40"
              >
                {framework.name}
              </span>
            </li>
          ))}
        </ul>

        <div className="mt-14 grid overflow-hidden rounded-xl border border-border sm:grid-cols-3">
          {enterpriseSecurityControls.map((control, index) => (
            <div
              key={control.title}
              className={cn(
                index > 0 && 'border-t border-border sm:border-t-0 sm:border-s sm:border-border',
              )}
            >
              <div className="border-b border-border bg-muted/15 px-4 py-3">
                <h3 className="text-[12px] font-semibold uppercase tracking-[0.14em] text-foreground">
                  {t(control.title)}
                </h3>
              </div>
              <ul className="space-y-2 px-4 py-4">
                {control.items.map((item) => (
                  <li key={item} className="text-[13px] leading-5 text-muted-foreground">
                    {t(item)}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </div>
    </section>
  )
}
