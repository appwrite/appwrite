import { AtSign, Bell, Mail, Smartphone, Users } from 'lucide-react'
import { StoryAnimated, StoryAnimatedProgress } from '@/components/pages/products/product-story/StoryAnimated'
import {
  ProductStoryGrid,
  type ProductStoryFeature,
} from '@/components/pages/products/product-story/ProductStoryGrid'
import { StoryCodeBlock } from '@/components/pages/products/product-story/StoryCodeBlock'
import { StoryField, StoryOptionCard } from '@/components/pages/products/product-story/shared'

const MESSAGING_CODE = [
  { text: 'await messaging.createEmail({', tone: 'keyword' as const },
  { text: "  subject: 'Your invoice for June is ready',", tone: 'string' as const },
  { text: "  topics: ['billing_alerts'],", tone: 'string' as const },
  { text: '});', tone: 'plain' as const },
]

const FEATURES: ProductStoryFeature[] = [
  {
    id: 'topics',
    title: 'Topics & audiences',
    description: 'Group subscribers into topics so you can target messages without manual lists.',
    icon: Users,
    className: 'lg:col-span-6',
    content: (
      <div className="grid gap-3 lg:grid-cols-2">
        <div className="space-y-3">
          <StoryAnimated delayMs={0}>
            <StoryField label="Topic name" value="billing-alerts" active />
          </StoryAnimated>
          <StoryAnimated delayMs={120}>
            <StoryField label="Topic ID" value="billing_alerts" mono />
          </StoryAnimated>
        </div>
        <StoryAnimated delayMs={200}>
          <StoryOptionCard
            selected
            title="Audience"
            description="1,284 subscribed users with billing role"
            icon={<Users className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'channels',
    title: 'Email, SMS & push',
    description: 'Reach users on every channel from one messaging API and console.',
    icon: Mail,
    className: 'lg:col-span-6',
    content: (
      <div className="grid gap-2 sm:grid-cols-3">
        <StoryAnimated delayMs={0}>
          <StoryOptionCard
            selected
            title="Email"
            description="Invoice ready notification"
            icon={<Mail className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={100}>
          <StoryOptionCard
            title="SMS"
            description="Payment failed alert"
            icon={<Smartphone className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryOptionCard
            title="Push"
            description="Trial ending reminder"
            icon={<Bell className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'compose',
    title: 'Message composer',
    description: 'Draft subjects and body content, then send to a topic or target list.',
    icon: AtSign,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <StoryField label="Subject" value="Your invoice for June is ready" active />
        </StoryAnimated>
        <StoryAnimated delayMs={120}>
          <div className="rounded-lg border border-border bg-muted/10 p-3 text-[11px] leading-5 text-muted-foreground">
            Hi Alex, your invoice is available in the billing portal. Review charges and download the PDF.
          </div>
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'providers',
    title: 'Delivery providers',
    description: 'Connect SMTP, SendGrid, Twilio, FCM, APNS, and more with your own credentials.',
    icon: Smartphone,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <div className="flex items-center justify-between text-[12px]">
            <span className="text-foreground">Delivering messages</span>
            <span className="text-muted-foreground">842 / 1,284</span>
          </div>
        </StoryAnimated>
        <StoryAnimatedProgress target={66} durationMs={2800} />
        <div className="grid gap-2 sm:grid-cols-3">
          <StoryAnimated delayMs={120}>
            <StoryField label="SMTP" value="Connected" />
          </StoryAnimated>
          <StoryAnimated delayMs={200}>
            <StoryField label="SMS" value="Connected" active />
          </StoryAnimated>
          <StoryAnimated delayMs={280}>
            <StoryField label="Push" value="Connected" />
          </StoryAnimated>
        </div>
      </div>
    ),
  },
  {
    id: 'delivery',
    title: 'Delivery reports',
    description: 'Track sent, delivered, and failed counts with per-message inspection.',
    icon: Bell,
    className: 'lg:col-span-6',
    content: (
      <div className="grid gap-2 sm:grid-cols-3">
        {[
          ['1,284', 'Sent'],
          ['1,241', 'Delivered'],
          ['43', 'Failed'],
        ].map(([value, label], index) => (
          <StoryAnimated key={label} delayMs={index * 120}>
            <div className="rounded-lg border border-border bg-muted/10 p-3 text-center">
              <p className="text-[18px] font-semibold text-foreground">{value}</p>
              <p className="text-[11px] text-muted-foreground">{label}</p>
            </div>
          </StoryAnimated>
        ))}
      </div>
    ),
  },
  {
    id: 'sdk',
    title: 'Send from Functions',
    description: 'Trigger email, SMS, and push from event handlers with the node-appwrite SDK.',
    icon: Mail,
    className: 'lg:col-span-6',
    content: (
      <StoryCodeBlock title="createEmail" language="TypeScript" lines={MESSAGING_CODE} lineDelayMs={40} />
    ),
  },
]

export function MessagingStory() {
  return <ProductStoryGrid features={FEATURES} />
}
