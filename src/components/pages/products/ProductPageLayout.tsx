import { Link } from '@tanstack/react-router'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import {
  MarketingCtaSection,
  MarketingHeroSection,
  MarketingHeroStats,
} from '@/components/pages/marketing/MarketingSections'
import { ProductExploreSection } from '@/components/pages/products/ProductExploreSection'
import { ProductFeatureSections } from '@/components/pages/products/ProductFeatureSections'
import { ProductToolsSection } from '@/components/pages/products/ProductToolsSection'
import { ProductHeroLogoStrip } from '@/components/pages/products/_components/ProductHeroLogoStrip'
import { ProductHeroIcon } from '@/components/pages/products/_components/ProductHeroIcon'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { PRODUCT_HERO_LOGO_STRIPS } from '@/lib/products/hero-logo-strip'
import { PRODUCT_HERO_VISUALS } from '@/lib/products/hero-visuals'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductPageContent } from '@/lib/products/types'
import { useI18n } from '@/lib/i18n'

type ProductPageLayoutProps = {
  content: ProductPageContent
}

export function ProductPageLayout({ content }: ProductPageLayoutProps) {
  const { catalog } = useI18n()
  const pageLayoutCopy = catalog.website.products.pageLayout
  const productNamesCopy = catalog.website.products.productNames
  const product = PRODUCT_REGISTRY[content.id]
  const ProductIcon = product.icon
  const heroLogoStrip = PRODUCT_HERO_LOGO_STRIPS[content.id]
  const HeroVisual = PRODUCT_HERO_VISUALS[content.id]
  const productName = productNamesCopy[content.id] ?? product.name
  const hasHeroFooter = Boolean(
    content.hero.stats?.length || heroLogoStrip || HeroVisual,
  )

  return (
    <div className="relative min-w-0 bg-background">
      <MarketingHeroSection
        leading={<ProductHeroIcon icon={ProductIcon} name={productName} />}
        title={content.hero.title}
        description={content.hero.description}
        wideFooter={Boolean(HeroVisual) || content.hero.stats?.length === 5}
        footer={
          hasHeroFooter ? (
            <>
              {HeroVisual ? <HeroVisual /> : null}
              {content.hero.stats?.length ? (
                <MarketingHeroStats items={content.hero.stats} />
              ) : null}
              {heroLogoStrip ? <ProductHeroLogoStrip config={heroLogoStrip} /> : null}
            </>
          ) : undefined
        }
      >
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link
            to="/sign-up"
            search={{ redirect: '/' }}
            {...analyticsAttrs('product-start-building')}
          >
            {pageLayoutCopy.startBuilding}
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <a href={product.docsPath} {...analyticsAttrs('product-view-docs')}>
            {pageLayoutCopy.viewDocs}
          </a>
        </Button>
      </MarketingHeroSection>

      <ProductFeatureSections productId={content.id} />

      <ProductToolsSection productId={content.id} />

      <MarketingFaqSection items={content.faq} />

      <MarketingCtaSection
        title={content.cta.title}
        description={content.cta.description}
      >
        <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
          <Link
            to="/sign-up"
            search={{ redirect: '/' }}
            {...analyticsAttrs('product-start-building')}
          >
            {pageLayoutCopy.startBuilding}
          </Link>
        </Button>
        <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
          <Link to="/pricing" {...analyticsAttrs('product-view-pricing')}>
            {pageLayoutCopy.viewPricing}
          </Link>
        </Button>
      </MarketingCtaSection>

      <ProductExploreSection currentProductId={content.id} />
    </div>
  )
}
