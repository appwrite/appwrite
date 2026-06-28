import type { LucideIcon } from 'lucide-react'
import {
  Database,
  Folder,
  Globe,
  Globe2,
  MessageSquare,
  Radio,
  ScanSearch,
  Shield,
  Users,
  Zap,
} from 'lucide-react'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'

export type PricingService = {
  name: string
  icon: LucideIcon
}

const ALL_PRICING_SERVICES: readonly PricingService[] = [
  { name: 'Auth', icon: Users },
  { name: 'Databases', icon: Database },
  { name: 'Storage', icon: Folder },
  { name: 'Functions', icon: Zap },
  { name: 'Messaging', icon: MessageSquare },
  { name: 'Realtime', icon: Radio },
  { name: 'Sites', icon: Globe },
  { name: 'Network', icon: Globe2 },
  { name: 'Firewall', icon: Shield },
  { name: 'Advisor', icon: ScanSearch },
]

function isPricingServiceComingSoon(name: string) {
  const navItem = Object.values(PRODUCT_NAV_REGISTRY).find((item) => item.name === name)
  return navItem?.comingSoon === true
}

export const pricingServices: readonly PricingService[] = ALL_PRICING_SERVICES.filter(
  (service) => !isPricingServiceComingSoon(service.name),
)

export function formatPricingServiceList() {
  const names = pricingServices.map((service) => service.name)
  if (names.length === 0) return ''
  if (names.length === 1) return names[0]
  return `${names.slice(0, -1).join(', ')}, and ${names[names.length - 1]}`
}
