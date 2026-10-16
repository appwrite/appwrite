import { Check } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import {
  AppwriteMark,
  ComparisonHeading,
  ComparisonSection,
} from '@/components/pages/alternative-to/$competitor/_components/ComparisonParts'
import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductToneBackdrop } from '@/components/pages/products/_components/ProductTone'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { ENTERPRISE_FORM_ID, enterpriseFormBullets } from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'

export function EnterpriseContactCta() {
  const t = useT()
  const { isAuthenticated } = useAuth()

  return (
    <ComparisonSection
      id={ENTERPRISE_FORM_ID}
      className="scroll-mt-28"
      backdrop={<ProductToneBackdrop variant="hero" />}
    >
      <div className="grid min-w-0 gap-12 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] lg:items-center lg:gap-20">
        <div>
          <ComparisonHeading
            eyebrow="Enterprise"
            title="Talk to our enterprise team"
            description="Ready to explore a custom plan? Share your requirements and one of our experts will follow up with a tailored proposal."
          />
          <ul className="mt-8 space-y-3.5">
            {enterpriseFormBullets.map((item, index) => (
              <li
                key={item}
                className="product-hero-rise flex items-start gap-3 text-[14px] leading-6 text-foreground/90"
                style={riseStyle(120 + index * 80)}
              >
                <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]">
                  <Check className="size-3" strokeWidth={3} aria-hidden />
                </span>
                {t(item)}
              </li>
            ))}
          </ul>
        </div>

        <div className="product-hero-rise relative isolate" style={riseStyle(260)}>
          <span
            className="pointer-events-none absolute -inset-10 -z-10 bg-[radial-gradient(ellipse_at_center,rgb(var(--tone-rgb)/0.2),transparent_70%)]"
            aria-hidden
          />
          <div className="relative border-t border-[var(--tone-ink)] pt-8">
            <span className="flex size-12 items-center justify-center rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background shadow-[0_12px_30px_-14px_rgb(var(--tone-rgb)/0.8)] dark:bg-card">
              <AppwriteMark className="size-6" />
            </span>
            <h3 className="mt-6 font-aeonik-pro text-[26px] leading-tight tracking-tight text-foreground">
              {isAuthenticated ? t('Contact sales') : t('Sign in to contact sales')}
            </h3>
            <p className="mt-3 text-[14px] leading-7 text-muted-foreground">
              {isAuthenticated
                ? t('You are signed in. Open the sales inquiry form to share your requirements with our enterprise team.')
                : t(
                    'Create a free account or sign in to submit an enterprise inquiry. This helps us reduce spam and connect your request to your Appwrite account.',
                  )}
            </p>
            <Button variant="brandCta" size="lg" className="mt-7 h-10 text-[14px]" asChild>
              <ContactSalesLink {...analyticsAttrs('enterprise-contact-sales')}>
                {isAuthenticated ? t('Open sales form') : t('Sign in to contact sales')}
              </ContactSalesLink>
            </Button>
          </div>
        </div>
      </div>
    </ComparisonSection>
  )
}
