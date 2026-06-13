export type MarketingProductToolkitItem = {
  label: string
  href: string
}

export const marketingProductToolkit = {
  build: [
    { label: 'Auth', href: '/products/auth' },
    { label: 'Databases', href: '/products/databases' },
    { label: 'Storage', href: '/products/storage' },
    { label: 'Functions', href: '/products/functions' },
    { label: 'Messaging', href: '/products/messaging' },
    { label: 'Realtime', href: '/docs/apis/realtime' },
  ],
  deploy: [{ label: 'Sites', href: '/products/sites' }],
  protect: [
    { label: 'Firewall', href: '/docs/products/network/firewall' },
    { label: 'Advisor', href: '/docs/products/network' },
  ],
} as const satisfies {
  build: readonly MarketingProductToolkitItem[]
  deploy: readonly MarketingProductToolkitItem[]
  protect: readonly MarketingProductToolkitItem[]
}
