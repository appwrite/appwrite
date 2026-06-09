export type MarketingProductToolkitItem = {
  label: string
  href: string
}

export const marketingProductToolkit = {
  build: [
    { label: 'Auth', href: 'https://appwrite.io/products/auth' },
    { label: 'Databases', href: 'https://appwrite.io/products/databases' },
    { label: 'Storage', href: 'https://appwrite.io/products/storage' },
    { label: 'Functions', href: 'https://appwrite.io/products/functions' },
    { label: 'Messaging', href: 'https://appwrite.io/products/messaging' },
    { label: 'Realtime', href: 'https://appwrite.io/docs/apis/realtime' },
  ],
  deploy: [{ label: 'Sites', href: 'https://appwrite.io/products/sites' }],
  protect: [
    { label: 'Firewall', href: 'https://appwrite.io/docs/products/network/firewall' },
    { label: 'Advisor', href: 'https://appwrite.io/docs/products/network' },
  ],
} as const satisfies {
  build: readonly MarketingProductToolkitItem[]
  deploy: readonly MarketingProductToolkitItem[]
  protect: readonly MarketingProductToolkitItem[]
}
