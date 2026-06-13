import { Clock, Database, Globe, Webhook, Zap } from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { StoryAnimated, StoryAnimatedLogs } from '@/components/pages/products/product-story/StoryAnimated'
import {
  ProductStoryGrid,
  type ProductStoryFeature,
} from '@/components/pages/products/product-story/ProductStoryGrid'
import { StoryCodeBlock } from '@/components/pages/products/product-story/StoryCodeBlock'
import { StoryField, StoryOptionCard } from '@/components/pages/products/product-story/shared'

const EXECUTION_LOGS = [
  { time: '09:14:02', message: 'POST /v1/stripe/webhook' },
  { time: '09:14:02', message: 'Verified Stripe signature', delayMs: 450 },
  { time: '09:14:03', message: 'Updated subscription in Database', tone: 'success' as const, delayMs: 900 },
  { time: '09:14:03', message: 'Execution finished in 182ms', tone: 'success' as const, delayMs: 1350 },
]

const HANDLER_CODE = [
  { text: "export default async ({ req, res, log }) => {", tone: 'plain' as const },
  { text: '  const databases = new Databases(client);', tone: 'plain' as const },
  { text: '  // Verify webhook + update subscription row', tone: 'comment' as const },
  { text: '  return res.json({ ok: true });', tone: 'keyword' as const },
  { text: '};', tone: 'plain' as const },
]

const FEATURES: ProductStoryFeature[] = [
  {
    id: 'runtimes',
    title: 'Language runtimes',
    description: 'Pick from 15 isolated runtimes including Node.js, Python, Go, and Rust.',
    icon: Zap,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-3">
        <StoryAnimated delayMs={0}>
          <StoryField label="Name" value="stripe-webhooks" active />
        </StoryAnimated>
        <StoryAnimated delayMs={120}>
          <div className="space-y-1.5">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              Runtime
            </p>
            <div className="flex items-center gap-2 rounded-md border border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] bg-[color-mix(in_srgb,var(--brand-cta)_6%,var(--background))] px-3 py-2">
              <RuntimeIcon runtime="node-22" className="size-4" />
              <span className="text-[12px] font-medium text-foreground">Node.js 22</span>
            </div>
          </div>
        </StoryAnimated>
        <StoryAnimated delayMs={240}>
          <StoryField label="Entrypoint" value="src/main.js" mono />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'deploy',
    title: 'Git & CLI deploys',
    description: 'Connect a repository or upload deployments from the CLI with the same runtime.',
    icon: Globe,
    className: 'lg:col-span-6',
    content: (
      <div className="space-y-2">
        <StoryAnimated delayMs={0}>
          <StoryOptionCard
            selected
            title="Deploy from Git"
            description="Build on every push to your function repository."
            icon={<Globe className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={120}>
          <StoryField label="Build command" value="npm install" mono active />
        </StoryAnimated>
        <StoryAnimated delayMs={220}>
          <StoryField label="Execute command" value="node src/main.js" mono />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'triggers',
    title: 'Triggers',
    description: 'Invoke via HTTP endpoints, cron schedules, or platform event subscriptions.',
    icon: Webhook,
    className: 'lg:col-span-4',
    content: (
      <div className="grid gap-2">
        <StoryAnimated delayMs={0}>
          <StoryOptionCard
            selected
            title="HTTP endpoint"
            description="/v1/stripe/webhook with automatic TLS."
            icon={<Webhook className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={100}>
          <StoryOptionCard
            title="Schedule"
            description="Nightly cleanup jobs with cron."
            icon={<Clock className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
        <StoryAnimated delayMs={200}>
          <StoryOptionCard
            title="Database event"
            description="React when rows are created or updated."
            icon={<Database className="size-4 text-muted-foreground" aria-hidden />}
          />
        </StoryAnimated>
      </div>
    ),
  },
  {
    id: 'executions',
    title: 'Execution logs',
    description: 'Inspect request streams, timings, and failures from the console.',
    icon: Clock,
    className: 'lg:col-span-6',
    content: <StoryAnimatedLogs lines={EXECUTION_LOGS} />,
  },
  {
    id: 'handler',
    title: 'Function handler',
    description: 'Access Appwrite services from server-side runtimes with the node-appwrite SDK.',
    icon: Database,
    className: 'lg:col-span-6',
    content: (
      <StoryCodeBlock title="src/main.js" language="JavaScript" lines={HANDLER_CODE} lineDelayMs={40} />
    ),
  },
]

export function FunctionsStory() {
  return <ProductStoryGrid features={FEATURES} />
}
