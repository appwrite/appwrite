import { Link } from '@tanstack/react-router'
import { MarketingFaqSection } from '@/components/pages/marketing/MarketingFaqSection'
import { ProductExploreSection } from '@/components/pages/products/ProductExploreSection'
import { ProductFeatureSections } from '@/components/pages/products/ProductFeatureSections'
import { ProductToolsSection } from '@/components/pages/products/ProductToolsSection'
import { ProductCtaSection } from '@/components/pages/products/_components/ProductCtaSection'
import { ProductHero } from '@/components/pages/products/_components/ProductHero'
import { ProductHeroLogoStrip } from '@/components/pages/products/_components/ProductHeroLogoStrip'
import { ProductHeroIcon } from '@/components/pages/products/_components/ProductHeroIcon'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { PRODUCT_HERO_LOGO_STRIPS } from '@/lib/products/hero-logo-strip'
import { PRODUCT_HERO_ART, PRODUCT_HERO_SCATTER } from '@/lib/products/hero-visuals'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import { getProductTheme, productToneAttrs } from '@/lib/products/theme'
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
  const theme = getProductTheme(content.id)
  const ProductIcon = product.icon
  const heroLogoStrip = PRODUCT_HERO_LOGO_STRIPS[content.id]
  const heroScatter = PRODUCT_HERO_SCATTER[content.id]
  const productName = productNamesCopy[content.id] ?? product.name

  return (
    <div className="relative min-w-0 bg-background" {...productToneAttrs(theme)}>
      <ProductHero
        layout={theme.heroLayout}
        name={productName}
        badge={<ProductHeroIcon icon={ProductIcon} name={productName} />}
        title={content.hero.title}
        description={content.hero.description}
        art={PRODUCT_HERO_ART[content.id]}
        scatter={heroScatter}
        stats={content.hero.stats}
        footer={
          heroLogoStrip ? (
            <ProductHeroLogoStrip config={heroLogoStrip} className={heroScatter ? 'lg:hidden' : undefined} />
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
        <Button variant="outline" size="lg" className="h-10 bg-background/60 text-[14px]" asChild>
          <a href={product.docsPath} {...analyticsAttrs('product-view-docs')}>
            {pageLayoutCopy.viewDocs}
          </a>
        </Button>
      </ProductHero>

      <ProductFeatureSections productId={content.id} />

      <ProductToolsSection productId={content.id} />

      <MarketingFaqSection items={content.faq} />

      <ProductCtaSection
        icon={ProductIcon}
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
        <Button variant="outline" size="lg" className="h-10 bg-background/60 text-[14px]" asChild>
          <Link to="/pricing" {...analyticsAttrs('product-view-pricing')}>
            {pageLayoutCopy.viewPricing}
          </Link>
        </Button>
      </ProductCtaSection>

      <ProductExploreSection currentProductId={content.id} />
    </div>
  )
}
