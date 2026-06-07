import type { PlanId } from './types'

export const CONTACT_ENTERPRISE_URL = 'https://appwrite.io/contact-us/enterprise'

export const PRICING_PLAN_COLUMNS: readonly {
  id: PlanId
  label: string
}[] = [
  { id: 'free', label: 'Free' },
  { id: 'pro', label: 'Pro' },
  { id: 'enterprise', label: 'Enterprise' },
]
