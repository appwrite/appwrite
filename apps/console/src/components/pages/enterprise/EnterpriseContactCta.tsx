import { Link } from '@tanstack/react-router'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { AppwriteMark } from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { ENTERPRISE_FORM_ID } from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'

export function EnterpriseContactCta() {
  const t = useT()

  return (
    <section id={ENTERPRISE_FORM_ID} className="relative scroll-mt-28 border-t border-border px-4 py-16 sm:px-6 sm:py-24">
      <div className="relative isolate mx-auto max-w-5xl overflow-hidden rounded-3xl border border-border bg-card/40 px-6 py-14 text-center sm:px-12 sm:py-20">
        <ProductToneBackdrop variant="cta" />
        <div className="product-tone-hairline absolute inset-x-12 top-0 h-px" aria-hidden />
        <div className="relative z-[1]">
          <div className="flex justify-center">
            <span className="relative flex size-14 shrink-0 items-center justify-center rounded-2xl border border-border bg-gradient-to-b from-card to-muted/40 shadow-sm dark:from-muted/30 dark:to-background">
              <AppwriteMark className="size-6" />
            </span>
          </div>
          <h2 className="mx-auto mt-7 max-w-2xl text-balance font-aeonik-pro text-[32px] leading-none font-normal tracking-tight text-foreground sm:text-[44px]">
            {t('Talk to our enterprise team')}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-[15px] leading-7 text-muted-foreground">
            {t(
              'Ready to explore a custom plan? Share your requirements and one of our experts will follow up with a tailored proposal.',
            )}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-2">
            <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
              <ContactSalesLink {...analyticsAttrs('enterprise-contact-sales')}>{t('Contact sales')}</ContactSalesLink>
            </Button>
            <Button variant="outline" size="lg" className="h-10 bg-background/60 text-[14px]" asChild>
              <Link to="/pricing" {...analyticsAttrs('enterprise-compare-plans')}>
                {t('Compare plans')}
              </Link>
            </Button>
          </div>
        </div>
      </div>
    </section>
  )
}
