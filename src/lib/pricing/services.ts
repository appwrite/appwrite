import type { LucideIcon } from 'lucide-react'
import {
  Database,
  Folder,
  Globe,
  Globe2,
  MessageSquare,
  Radio,
  Shield,
  Users,
  Zap,
} from 'lucide-react'

export type PricingService = {
  name: string
  icon: LucideIcon
}

export const pricingServices: readonly PricingService[] = [
  { name: 'Auth', icon: Users },
  { name: 'Databases', icon: Database },
  { name: 'Storage', icon: Folder },
  { name: 'Functions', icon: Zap },
  { name: 'Messaging', icon: MessageSquare },
  { name: 'Realtime', icon: Radio },
  { name: 'Sites', icon: Globe },
  { name: 'Network', icon: Globe2 },
  { name: 'Firewall', icon: Shield },
]

export function formatPricingServiceList() {
  const names = pricingServices.map((service) => service.name)
  if (names.length === 0) return ''
  if (names.length === 1) return names[0]
  return `${names.slice(0, -1).join(', ')}, and ${names[names.length - 1]}`
}
