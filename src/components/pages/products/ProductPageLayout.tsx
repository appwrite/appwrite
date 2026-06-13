import { Link } from '@tanstack/react-router'
import { ArrowRight } from 'lucide-react'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import {
  MarketingCtaSection,
  MarketingFeatureGrid,
  MarketingHeroSection,
  MarketingHeroStats,
  MarketingSectionHeading,
} from '@/components/pages/marketing/MarketingSections'
import { ProductExploreSection } from '@/components/pages/products/ProductExploreSection'
import { ProductHeroLogoStrip } from '@/components/pages/products/_components/ProductHeroLogoStrip'
import { ProductHeroIcon } from '@/components/pages/products/_components/ProductHeroIcon'
import { ProductStorySection } from '@/components/pages/products/product-story/ProductStorySection'
import { ProductUniqueSections } from '@/components/pages/products/ProductUniqueSections'
import { Button } from '@/components/ui/button'
import { PRODUCT_HERO_LOGO_STRIPS } from '@/lib/products/hero-logo-strip'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductPageContent } from '@/lib/products/types'

type ProductPageLayoutProps = {
  content: ProductPageContent
}

export function ProductPageLayout({ content }: ProductPageLayoutProps) {
  const product = PRODUCT_REGISTRY[content.id]
  const ProductIcon = product.icon
  const heroLogoStrip = PRODUCT_HERO_LOGO_STRIPS[content.id]

  return (
    <div className="relative overflow-x-clip bg-background">
      <MarketingHeroSection
        leading={<ProductHeroIcon icon={ProductIcon} name={product.name} />}
        title={content.hero.title}
        description={content.hero.description}
        footer={
          content.hero.stats?.length || heroLogoStrip ? (
            <>
              {content.hero.stats?.length ? (
                <MarketingHeroStats items={content.hero.stats} />
              ) : null}
              {heroLogoStrip ? <ProductHeroLogoStrip config={heroLogoStrip} /> : null}
            </>
          ) : undefined
        }
      >
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            Start building
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <a href={product.docsPath}>View docs</a>
        </Button>
      </MarketingHeroSection>

      <ProductStorySection
        productId={content.id}
        title={content.visual.title}
        description={content.visual.description}
      />

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={content.capabilities.title}
            description={content.capabilities.description}
            size="md"
          />
          <div className="mt-10">
            <MarketingFeatureGrid items={content.capabilities.items} columns={3} />
          </div>
        </div>
      </section>

      <ProductUniqueSections sections={content.uniqueSections} />

      <section className="border-b border-border py-16 sm:py-20">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <MarketingSectionHeading
            title={content.integrations.title}
            description={content.integrations.description}
            size="md"
          />
          <div className="mt-10 grid gap-3 sm:grid-cols-2">
            {content.integrations.items.map((item) => {
              const relatedProduct = PRODUCT_REGISTRY[item.productId]
              const RelatedIcon = relatedProduct.icon

              return (
                <Link
                  key={item.productId}
                  to="/products/$productId"
                  params={{ productId: item.productId }}
                  className="group flex items-start gap-3 rounded-xl border border-border bg-card/45 p-5 transition-colors hover:bg-accent/15"
                >
                  <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                    <RelatedIcon className="size-4 text-muted-foreground" aria-hidden />
                  </span>
                  <div className="min-w-0 flex-1">
                    <h3 className="text-[14px] font-semibold text-foreground">
                      {item.title}
                    </h3>
                    <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                      {item.description}
                    </p>
                    <p className="mt-3 flex items-center gap-1 text-[12px] font-medium text-muted-foreground transition-colors group-hover:text-foreground">
                      Explore {relatedProduct.name}
                      <ArrowRight
                        className="size-3.5 transition-transform group-hover:translate-x-0.5"
                        aria-hidden
                      />
                    </p>
                  </div>
                </Link>
              )
            })}
          </div>
        </div>
      </section>

      <MarketingFaqSection items={content.faq} />

      <MarketingCtaSection
        title={content.cta.title}
        description={content.cta.description}
      >
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/sign-up" search={{ redirect: '/' }}>
            Start building
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing">View pricing</Link>
        </Button>
      </MarketingCtaSection>

      <ProductExploreSection currentProductId={content.id} />
    </div>
  )
}
