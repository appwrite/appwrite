import { Link } from '@tanstack/react-router'
import { ArrowLeft, BadgeCheck } from 'lucide-react'
import { IntegrationPartnerNote } from './IntegrationPartnerNote'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'
import { IntegrationCard } from './IntegrationCard'
import { IntegrationGallery } from './IntegrationGallery'
import { IntegrationIcon } from './IntegrationIcon'
import { IntegrationMarkdown } from './IntegrationMarkdown'
import { IntegrationPill } from './IntegrationPill'
import { Button } from '@/components/ui/button'
import { getIntegrationCategoryHeading } from '@/lib/integrations/categories'
import { getRelatedIntegrations } from '@/lib/integrations/content'
import type { Integration } from '@/lib/integrations/types'
import { useT } from '@/lib/i18n/translate'

type DetailViewProps = {
  integration: Integration
}

export function DetailView({ integration }: DetailViewProps) {
  const t = useT()
  const related = getRelatedIntegrations(integration.slug)
  const images =
    integration.images.length > 0
      ? integration.images
      : integration.cover
        ? [integration.cover]
        : []

  return (
    <div className="relative bg-background">
      <section className="border-b border-border py-8 sm:py-10">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <Button variant="ghost" size="sm" className="mb-6 h-9 px-0 text-[13px]" asChild>
            <Link to="/integrations">
              <ArrowLeft className="me-1.5 size-4" aria-hidden />
              {t('Back to catalog')}
            </Link>
          </Button>

          <Breadcrumb>
            <BreadcrumbList>
              <BreadcrumbItem>
                <BreadcrumbLink asChild>
                  <Link to="/integrations" className="cursor-pointer">
                    {t('Integrations')}
                  </Link>
                </BreadcrumbLink>
              </BreadcrumbItem>
              <BreadcrumbSeparator />
              <BreadcrumbItem>
                <BreadcrumbPage>{integration.title}</BreadcrumbPage>
              </BreadcrumbItem>
            </BreadcrumbList>
          </Breadcrumb>

          <div className="mt-8 flex items-start gap-4">
            <IntegrationIcon
              slug={integration.slug}
              vendor={integration.product.vendor}
              alt={integration.product.vendor}
              size="lg"
            />
            <div className="min-w-0">
              <h1 className="font-aeonik-pro text-[28px] font-normal leading-tight text-foreground sm:text-[32px]">
                {integration.title}
              </h1>
              <p className="mt-2 text-[14px] text-muted-foreground">
                {integration.description}
              </p>
            </div>
          </div>
        </div>
      </section>

      {images.length > 0 ? (
        <section className="border-b border-border bg-muted/20 py-8 sm:py-10">
          <div className="mx-auto max-w-7xl px-4 sm:px-6">
            <IntegrationGallery images={images} />
          </div>
        </section>
      ) : null}

      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_280px] lg:gap-12">
            <article className="min-w-0">
              <IntegrationMarkdown content={integration.content} />
            </article>

            <aside className="lg:sticky lg:top-24 lg:self-start">
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-5 py-4">
                  <h2 className="text-[15px] font-semibold text-foreground">{t('Details')}</h2>
                </div>
                <div className="border-t border-border" />
                <dl className="space-y-4 px-5 py-4 text-[13px]">
                  <div>
                    <dt className="text-muted-foreground">{t('Vendor')}</dt>
                    <dd className="mt-1 font-medium text-foreground">
                      {integration.product.vendor}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-muted-foreground">{t('Category')}</dt>
                    <dd className="mt-1">
                      <IntegrationPill>
                        {t(getIntegrationCategoryHeading(integration.category))}
                      </IntegrationPill>
                    </dd>
                  </div>
                  <div>
                    <dt className="text-muted-foreground">{t('Platform')}</dt>
                    <dd className="mt-2 flex flex-wrap gap-2">
                      {integration.platform.map((platform) => (
                        <IntegrationPill key={platform}>{platform}</IntegrationPill>
                      ))}
                    </dd>
                  </div>
                  {integration.isPartner ? (
                    <div>
                      <dt className="text-muted-foreground">{t('Verified')}</dt>
                      <dd className="mt-1">
                        <IntegrationPill variant="success">
                          <BadgeCheck aria-hidden />
                          {t('Verified')}
                        </IntegrationPill>
                      </dd>
                    </div>
                  ) : null}
                </dl>
              </div>
            </aside>
          </div>
        </div>
      </section>

      {related.length > 0 ? (
        <section className="border-b border-border py-10 sm:py-14">
          <div className="mx-auto max-w-7xl px-4 sm:px-6">
            <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">
              {t('Related integrations')}
            </h2>
            <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {related.map((item) => (
                <IntegrationCard key={item.slug} integration={item} />
              ))}
            </div>
          </div>
        </section>
      ) : null}

      <IntegrationPartnerNote />
    </div>
  )
}
