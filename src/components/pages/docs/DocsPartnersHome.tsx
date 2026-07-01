import type { ReactNode } from 'react'
import { DocsHomeSectionHeading } from './_components/DocsHomeSectionHeading'
import { DocsPartnersHubBento } from './_components/DocsPartnersHubBento'
import { DocsPartnersCardIcon } from './_components/DocsPartnersCardIcon'
import {
  docsContentPaddingX,
  docsGridTwoCol,
  docsPreviewSectionPaddingY,
  docsSectionPaddingY,
} from '@/lib/docs/docs-container'
import {
  DOCS_PARTNERS_HOME_APIS,
  DOCS_PARTNERS_HOME_AUDIENCES,
  DOCS_PARTNERS_HOME_GUIDES,
  DOCS_PARTNERS_HOME_INTEGRATIONS,
} from '@/lib/docs/partners-home-content'
import { cn } from '@/lib/utils'
import { DocsPartnersHeroSection } from './DocsPartnersHeroSection'
import { DocsPartnersPreviewHeroSection } from './DocsPartnersPreviewHeroSection'
import { DocsRouteLink } from './DocsRouteLink'

const TEXT_CARD_CLASS =
  'group block h-full rounded-xl border border-border bg-card/45 p-5 transition-colors hover:bg-accent/15'

type DocsPartnersHomeSectionProps = {
  title: string
  description?: ReactNode
  children: ReactNode
  className?: string
  variant?: 'page' | 'preview'
}

function DocsPartnersHomeSection({
  title,
  description,
  children,
  className,
  variant = 'page',
}: DocsPartnersHomeSectionProps) {
  const sectionPaddingY =
    variant === 'preview' ? docsPreviewSectionPaddingY : docsSectionPaddingY

  return (
    <section className={cn('border-b border-border', sectionPaddingY, className)}>
      <div className={cn('mx-auto w-full max-w-6xl', docsContentPaddingX)}>
        <DocsHomeSectionHeading
          title={title}
          description={description}
          variant={variant}
        />
        <div className="mt-8">{children}</div>
      </div>
    </section>
  )
}

type DocsPartnersHomeProps = {
  variant?: 'page' | 'preview'
}

export function DocsPartnersHome({ variant = 'page' }: DocsPartnersHomeProps) {
  return (
    <>
      {variant === 'preview' ? (
        <DocsPartnersPreviewHeroSection />
      ) : (
        <DocsPartnersHeroSection />
      )}

      <DocsPartnersHomeSection
        variant={variant}
        title="Who is this for?"
        description="Appwrite partner APIs help you provision and orchestrate backends for vibe coding products, AI agents, multi-tenant SaaS, and embedded developer experiences."
      >
        <div className={cn('grid gap-4', docsGridTwoCol)}>
          {DOCS_PARTNERS_HOME_AUDIENCES.map((item) => (
            <div key={item.title} className={TEXT_CARD_CLASS}>
              <h3 className="text-[13px] font-medium text-foreground">{item.title}</h3>
              <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                {item.description}
              </p>
            </div>
          ))}
        </div>
      </DocsPartnersHomeSection>

      <DocsPartnersHomeSection
        variant={variant}
        title="Choose an integration model"
        description="Connect to existing Appwrite accounts with OAuth, or provision resources in your own organization with org API keys. Many platforms combine both."
      >
        <div className={cn('grid gap-4', docsGridTwoCol)}>
          {DOCS_PARTNERS_HOME_INTEGRATIONS.map((item) => (
            <DocsRouteLink key={item.href} href={item.href} className={TEXT_CARD_CLASS}>
              <div className="flex items-start gap-3">
                <DocsPartnersCardIcon item={item} className="size-8" />
                <div>
                    <h3 className="text-[13px] font-medium text-foreground">
                      {item.title}
                      {item.new ? (
                        <span className="ms-2 text-[10px] font-semibold uppercase tracking-wide text-[var(--brand-cta)]">
                          New
                        </span>
                      ) : null}
                    </h3>
                    <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                      {item.description}
                    </p>
                </div>
              </div>
            </DocsRouteLink>
          ))}
        </div>
      </DocsPartnersHomeSection>

      <DocsPartnersHomeSection
        variant={variant}
        title="Partners APIs"
        description="Use the Console SDK to manage infrastructure-level resources across organizations and projects."
      >
        <DocsPartnersHubBento items={DOCS_PARTNERS_HOME_APIS} />
      </DocsPartnersHomeSection>

      <DocsPartnersHomeSection
        variant={variant}
        title="Guides for your use case"
        description="Follow end-to-end workflows for provisioning, app marketplaces, and multi-tenant platform design."
        className="border-b-0"
      >
        <DocsPartnersHubBento items={DOCS_PARTNERS_HOME_GUIDES} />
      </DocsPartnersHomeSection>
    </>
  )
}
