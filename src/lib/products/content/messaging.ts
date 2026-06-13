import type { ProductPageContent } from '@/lib/products/types'

export const messagingProductContent: ProductPageContent = {
  id: 'messaging',
  metaDescription:
    'Send email, SMS, and push notifications with Appwrite Messaging. Topics, targets, providers, and scheduling in one API.',
  hero: {
    title: 'Messaging across every channel',
    description:
      'Reach users on email, SMS, and push from a unified API. Manage providers, audiences, and delivery without stitching vendors together.',
    stats: [
      { value: '11', label: 'Delivery providers' },
      { value: '3', label: 'Message channels' },
      { value: 'Topics', label: 'Subscriber audiences' },
      { value: 'Logs', label: 'Delivery history' },
    ],
  },
  faq: [
    {
      question: 'Do I need separate vendors for email, SMS, and push?',
      answer:
        'You connect your own provider credentials to Appwrite Messaging. The platform gives you one API and console for every channel.',
    },
    {
      question: 'Can I send messages from Functions?',
      answer:
        'Yes. Functions can call the Messaging API when events fire, which is a common pattern for transactional notifications.',
    },
    {
      question: 'How do topics work?',
      answer:
        'Topics group subscribers so you can broadcast or target messages without managing recipient lists manually.',
    },
    {
      question: 'Can I schedule messages?',
      answer:
        'Yes. Messaging supports scheduled delivery so you can queue notifications for later.',
    },
  ],
  cta: {
    title: 'Start building with Messaging',
    description: 'Configure a provider, create a topic, and send your first message from the console or SDK.',
  },
}
