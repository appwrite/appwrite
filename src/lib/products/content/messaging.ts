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
      { value: '3', label: 'Channels in one API' },
      { value: 'Topics', label: 'Broadcast messaging' },
      { value: '11', label: 'Delivery providers' },
      { value: 'Targets', label: 'Auth user delivery' },
      { value: 'Schedule', label: 'Compose and delivery logs' },
    ],
  },
  faq: [
    {
      question: 'Do I need separate vendor integrations for email, SMS, and push?',
      answer:
        'No. You connect your own provider credentials once in the Console, then send on every channel through one Messaging API and SDK. Pick a vendor per channel (Resend for email, Twilio for SMS, FCM for push, and so on) without maintaining three separate integrations or delivery logs.',
      links: [
        { label: 'Messaging overview', href: '/docs/products/messaging' },
        { label: 'Providers', href: '/docs/products/messaging/providers' },
      ],
    },
    {
      question: 'How do topics and targets work together?',
      answer:
        'Targets are the ways a user can be reached: email addresses, phone numbers, and push device tokens. Subscribe targets to a topic to broadcast the same message to every subscriber, or address specific users and targets when you need private, one-to-one delivery. Topics fit newsletters and announcements; sensitive content like chat should go to individual targets.',
      links: [
        { label: 'Topics', href: '/docs/products/messaging/topics' },
        { label: 'Targets', href: '/docs/products/messaging/targets' },
      ],
    },
    {
      question: 'Which delivery providers are supported?',
      answer:
        'Email: Resend, SendGrid, Mailgun, and SMTP. SMS: Twilio, Vonage, MSG91, Telesign, and Textmagic. Push: APNS and FCM. Configure multiple providers per channel and choose which one to use when sending. Discord and Slack chat integrations are coming soon.',
      links: [{ label: 'Providers', href: '/docs/products/messaging/providers' }],
    },
    {
      question: 'How are targets linked to Auth users?',
      answer:
        'Each Auth user can have multiple targets registered to your project. Verified emails from email/password, magic URL, and email OTP sign-up create email targets automatically. Verified phone numbers from SMS OTP sign-up create SMS targets. Push targets are added from your client app after the user grants notification permission. Inspect and manage targets from the Targets tab on user detail in Auth.',
      links: [
        { label: 'Targets', href: '/docs/products/messaging/targets' },
        { label: 'Send push notifications', href: '/docs/products/messaging/send-push-notifications' },
      ],
    },
    {
      question: 'Can I schedule messages and track delivery?',
      answer:
        'Yes. Compose email, SMS, and push from the Console Messages tab or call createEmail, createSms, and createPush from the Server SDK. Send immediately, save as a draft, or pass scheduledAt for later delivery. Every message appears in the Messages tab with status (draft, scheduled, processing, failed, or success) and delivery timestamps.',
      links: [
        { label: 'Messages', href: '/docs/products/messaging/messages' },
        { label: 'Send email messages', href: '/docs/products/messaging/send-email-messages' },
      ],
    },
    {
      question: 'Can I send messages from Functions or my backend?',
      answer:
        'Yes. Use the Server SDK from Functions, your API server, or any trusted backend with a project API key. This is the standard pattern for transactional flows such as OTP verification, password reset, order receipts, and inventory alerts triggered by platform events or custom logic.',
      links: [
        { label: 'Send SMS messages', href: '/docs/products/messaging/send-sms-messages' },
        { label: 'Send push notifications', href: '/docs/products/messaging/send-push-notifications' },
      ],
    },
    {
      question: 'When should I use email, SMS, or push?',
      answer:
        'Push and SMS work well for time-sensitive alerts users see within minutes. Email suits rich HTML content like receipts, newsletters, and promotions. SMS reaches phones even without internet. Push drives re-engagement with deep links back into your app. Most production apps combine all three depending on urgency and content.',
      links: [
        {
          label: 'Choosing a message type',
          href: '/docs/products/messaging/messages#choosing-a-message-type',
        },
      ],
    },
  ],
  cta: {
    title: 'Start building with Messaging',
    description: 'Configure a provider, create a topic, and send your first message from the console or SDK.',
  },
}
