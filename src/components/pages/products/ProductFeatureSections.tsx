import { ProductFeatureSection } from '@/components/pages/products/ProductFeatureSection'
import { DatabasesEnginesCatalog } from '@/components/pages/products/features/databases/DatabasesEnginesCatalog'
import { DatabasesOrmCatalog } from '@/components/pages/products/features/databases/DatabasesOrmCatalog'
import { FirewallActionsCatalog } from '@/components/pages/products/features/firewall/FirewallActionsCatalog'
import { FirewallMonitorSection } from '@/components/pages/products/features/firewall/FirewallMonitorSection'
import { MessagingProviderCatalog } from '@/components/pages/products/features/messaging/MessagingProviderCatalog'
import { SitesDeployOptions } from '@/components/pages/products/features/sites/SitesDeployOptions'
import { SitesDomainManagement } from '@/components/pages/products/features/sites/SitesDomainManagement'
import { SitesGitProviders } from '@/components/pages/products/features/sites/SitesGitProviders'
import { SitesNetworkSection } from '@/components/pages/products/features/sites/SitesNetworkSection'
import { getProductFeatures } from '@/lib/products/features'
import { getProductFeatureVisual } from '@/lib/products/features/visuals'
import type { ProductId } from '@/lib/products/types'

type ProductFeatureSectionsProps = {
  productId: ProductId
}

export function ProductFeatureSections({ productId }: ProductFeatureSectionsProps) {
  const features = getProductFeatures(productId)
  if (!features?.length) return null

  return (
    <>
      {features.map((feature, index) => {
        const Visual = getProductFeatureVisual(productId, feature.id)
        const companion =
          productId === 'databases' && feature.id === 'engines' ? (
            <DatabasesEnginesCatalog />
          ) : productId === 'databases' && feature.id === 'tooling' ? (
            <DatabasesOrmCatalog />
          ) : productId === 'firewall' && feature.id === 'actions' ? (
            <FirewallActionsCatalog />
          ) : productId === 'firewall' && feature.id === 'monitor' ? (
            <FirewallMonitorSection />
          ) : productId === 'messaging' && feature.id === 'providers' ? (
            <MessagingProviderCatalog />
          ) : productId === 'sites' && feature.id === 'git-previews' ? (
            <SitesGitProviders />
          ) : productId === 'sites' && feature.id === 'deploy-methods' ? (
            <SitesDeployOptions />
          ) : productId === 'sites' && feature.id === 'domains' ? (
            <SitesDomainManagement />
          ) : productId === 'sites' && feature.id === 'network' ? (
            <SitesNetworkSection />
          ) : undefined

        if (!Visual && !companion && !feature.hideVisual) return null

        return (
          <ProductFeatureSection
            key={feature.id}
            feature={feature}
            index={index}
            visual={Visual ? <Visual /> : undefined}
            companion={companion}
          />
        )
      })}
    </>
  )
}
