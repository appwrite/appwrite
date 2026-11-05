import type { ProductFeatureContent } from '@/lib/products/features/types'

export const messagingProductFeatures: ProductFeatureContent[] = [
  {
    id: 'unified-api',
    title: 'Unified email, SMS, and push API',
    description:
      'Send on every channel from one Messaging service and SDK. Use createEmail, createSms, and createPush for transactional mail, OTP codes, and mobile alerts without wiring three separate vendor integrations.',
    docsHref: '/docs/products/messaging',
    docsLabel: 'Messaging docs',
  },
  {
    id: 'topics',
    title: 'Topics for group and broadcast messaging',
    description:
      'Create topics from the Console Topics tab and subscribe targets for newsletters, product announcements, and security alerts. Broadcast to every subscriber in a topic or combine topics with direct targets when you need finer control.',
    docsHref: '/docs/products/messaging/topics',
    docsLabel: 'Topics docs',
  },
  {
    id: 'providers',
    title: 'Bring your own providers',
    description:
      'Connect the email, SMS, and push providers you already use. Add credentials once in the Console, pick a vendor per channel, and route every message through the stack you operate.',
    docsHref: '/docs/products/messaging/providers',
    docsLabel: 'Providers docs',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
  },
  {
    id: 'targets',
    title: 'Targets linked to Auth users',
    description:
      'Each user can have email, phone, and push device targets registered to your project. Inspect and manage them from the Targets tab on user detail in Auth, then subscribe those targets to topics or address them directly in a message.',
    docsHref: '/docs/products/messaging/targets',
    docsLabel: 'Targets docs',
  },
  {
    id: 'compose',
    title: 'Compose and schedule messages',
    description:
      'Draft email, SMS, and push from the Console Messages tab with channel-specific fields, topic and target selection, and delivery logs. Schedule sends for later or fire transactional flows such as OTP verification and account alerts from Functions or your backend.',
    docsHref: '/docs/products/messaging/messages',
    docsLabel: 'Messages docs',
    layout: 'stacked',
    dottedBackground: true,
  },
]
