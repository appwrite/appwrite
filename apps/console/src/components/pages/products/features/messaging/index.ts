import type { ComponentType } from 'react'
import { MessagingComposeVisual } from '@/components/pages/products/features/messaging/MessagingComposeVisual'
import { MessagingTargetsVisual } from '@/components/pages/products/features/messaging/MessagingTargetsVisual'
import { MessagingTopicsVisual } from '@/components/pages/products/features/messaging/MessagingTopicsVisual'
import { MessagingUnifiedApiVisual } from '@/components/pages/products/features/messaging/MessagingUnifiedApiVisual'

export const MESSAGING_FEATURE_VISUALS: Record<string, ComponentType> = {
  'unified-api': MessagingUnifiedApiVisual,
  topics: MessagingTopicsVisual,
  targets: MessagingTargetsVisual,
  compose: MessagingComposeVisual,
}
