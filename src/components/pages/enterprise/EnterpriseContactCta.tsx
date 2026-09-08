import { Check } from 'lucide-react'
import { ContactSalesLink } from '@/components/global/shared/ContactSalesLink'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import {
  MarketingSectionHeading,
  marketingSplitLayoutClassName,
} from '@/components/pages/marketing/MarketingSections'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { enterpriseFormBullets } from '@/lib/enterprise/content'
import { useT } from '@/lib/i18n/translate'

export function EnterpriseContactCta() {
  const t = useT()
  const { isAuthenticated } = useAuth()

  return (
    <section className="relative scroll-mt-28 border-b border-border">
      <HomeSoftLights variant="testimonials" className="opacity-40" />
      <div className="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
        <div className={marketingSplitLayoutClassName({ align: 'start' })}>
          <div>
            <MarketingSectionHeading
              align="left"
              size="md"
              title={t('Talk to our enterprise team')}
              description={t(
                'Ready to explore a custom plan? Share your requirements and one of our experts will follow up with a tailored proposal.',
              )}
            />
            <ul className="mt-6 space-y-3">
              {enterpriseFormBullets.map((item) => (
                <li key={item} className="flex items-center gap-2 text-[13px] text-foreground">
                  <Check className="size-4 shrink-0 text-[var(--brand-cta)]" aria-hidden />
                  {t(item)}
                </li>
              ))}
            </ul>
          </div>

          <div className="rounded-xl border border-border bg-card/50 p-6 sm:p-8">
            {isAuthenticated ? (
              <>
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Contact sales')}
                </h3>
                <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
                  {t(
                    'You are signed in. Open the sales inquiry form to share your requirements with our enterprise team.',
                  )}
                </p>
                <Button
                  variant="brandCta"
                  size="lg"
                  className="mt-6 h-10 w-full text-[14px] sm:w-auto"
                  asChild
                >
                  <ContactSalesLink {...analyticsAttrs('enterprise-contact-sales')}>
                    {t('Open sales form')}
                  </ContactSalesLink>
                </Button>
              </>
            ) : (
              <>
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Sign in to contact sales')}
                </h3>
                <p className="mt-2 text-[13px] leading-relaxed text-muted-foreground">
                  {t(
                    'Create a free account or sign in to submit an enterprise inquiry. This helps us reduce spam and connect your request to your Appwrite account.',
                  )}
                </p>
                <div className="mt-6 flex flex-col gap-2 sm:flex-row">
                  <Button
                    variant="brandCta"
                    size="lg"
                    className="h-10 text-[14px]"
                    asChild
                  >
                    <ContactSalesLink {...analyticsAttrs('enterprise-contact-sales')}>
                      {t('Sign in to contact sales')}
                    </ContactSalesLink>
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </section>
  )
}
