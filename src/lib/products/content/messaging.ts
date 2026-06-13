import {
  AtSign,
  Bell,
  Mail,
  MessageSquare,
  Smartphone,
  Timer,
  Users,
} from 'lucide-react'
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
  capabilities: {
    title: 'One service for product notifications',
    description:
      'From transactional email to mobile push, Messaging keeps channels consistent and observable in the same console.',
    items: [
      {
        title: 'Email delivery',
        description:
          'Send transactional and lifecycle email through configured SMTP or provider integrations.',
        icon: Mail,
      },
      {
        title: 'SMS messages',
        description:
          'Deliver verification codes and alerts with supported SMS providers.',
        icon: Smartphone,
      },
      {
        title: 'Push notifications',
        description:
          'Reach iOS and Android users with push providers like APNS and FCM.',
        icon: Bell,
      },
      {
        title: 'Topics and targets',
        description:
          'Group recipients into topics and manage device targets from the console.',
        icon: Users,
      },
      {
        title: 'Scheduled sends',
        description:
          'Queue messages for later delivery when campaigns or reminders need timing control.',
        icon: Timer,
      },
      {
        title: 'Message history',
        description:
          'Inspect delivery status and troubleshoot failures from the project console.',
        icon: MessageSquare,
      },
    ],
  },
  visual: {
    title: 'Compose once, deliver everywhere',
    description:
      'Define topics, attach provider credentials, and send messages from SDKs or Functions with the same project context.',
  },
  uniqueSections: [
    {
      type: 'feature-grid',
      title: 'Channels',
      description:
        'Pick the channels your product needs today and add more as you grow.',
      items: [
        {
          title: 'Email',
          description: 'Password resets, receipts, digests, and lifecycle campaigns.',
          icon: Mail,
        },
        {
          title: 'SMS',
          description: 'OTP codes, alerts, and time-sensitive updates.',
          icon: Smartphone,
        },
        {
          title: 'Push',
          description: 'Mobile re-engagement and real-time product notifications.',
          icon: Bell,
        },
        {
          title: 'Cross-channel API',
          description: 'Use the same topics and targets model across every channel.',
          icon: AtSign,
        },
      ],
      columns: 2,
    },
    {
      type: 'feature-grid',
      title: 'Audiences and providers',
      description:
        'Connect your delivery providers and organize recipients without custom infrastructure.',
      items: [
        {
          title: 'Provider integrations',
          description: 'Configure SMTP, SMS gateways, and push credentials per project.',
          icon: AtSign,
        },
        {
          title: 'Subscriber topics',
          description: 'Let users opt into product areas like billing, security, or marketing.',
          icon: Users,
        },
        {
          title: 'Device targets',
          description: 'Register push tokens and contact endpoints for each user.',
          icon: Smartphone,
        },
        {
          title: 'Functions integration',
          description: 'Send messages from event handlers when product state changes.',
          icon: MessageSquare,
        },
      ],
      columns: 2,
      muted: true,
    },
  ],
  integrations: {
    title: 'Works with the Appwrite platform',
    description:
      'Messaging fits naturally into auth flows, backend automation, and user lifecycle events.',
    items: [
      {
        productId: 'auth',
        title: 'Verified users',
        description: 'Send email and SMS verification through Auth-managed identities.',
      },
      {
        productId: 'functions',
        title: 'Event-driven sends',
        description: 'Trigger messages from Functions when business events occur.',
      },
      {
        productId: 'databases',
        title: 'Personalized content',
        description: 'Pull row data into message templates for tailored notifications.',
      },
      {
        productId: 'sites',
        title: 'Product-led growth',
        description: 'Notify users about activity in apps deployed with Sites.',
      },
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
      question: 'Is Messaging available on self-hosted Appwrite?',
      answer:
        'Messaging availability depends on your deployment profile. Check the docs for supported channels on Cloud and self-hosted.',
    },
  ],
  cta: {
    title: 'Start building with Messaging',
    description: 'Configure a provider, create a topic, and send your first message from the console or SDK.',
  },
}
