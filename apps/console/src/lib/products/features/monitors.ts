import type { ProductFeatureContent } from '@/lib/products/features/types'

/**
 * Monitors product page sections, in page order: set a monitor up from your
 * own usage, watch endpoints too, then follow an alert from the moment it
 * fires to the moment it is handled. Facts follow the Alerts module and
 * Cloud's plans: thresholds are absolute values in the metric's unit (never
 * a share of the plan), endpoint checks are Cloud only on paid plans, and
 * there are no keyword checks, uptime percentages, or multi-location checks.
 */
export const monitorsProductFeatures: ProductFeatureContent[] = [
  {
    id: 'suggestions',
    title: 'Start from your own usage',
    description:
      'Monitors suggests its first monitors from your last 7 days of usage: an egress spike, a surge in database reads, storage growth, and failing webhooks, each with a threshold set from what your project really does. Pick one and the form opens filled in.',
    brandLight: 'orange',
  },
  {
    id: 'threshold',
    title: 'Drag the line, see what would have fired',
    description:
      'Pick a metric and the Console charts its real history behind a dashed threshold. Drag the line, and before you save it tells you how often the monitor would have fired over the last 12 hours to 30 days, with every breach shaded red.',
  },
  {
    id: 'metrics',
    title: 'Watch any number your project produces',
    description:
      'Requests, bandwidth, executions, realtime connections, active users, database reads and writes, storage, messages, webhooks, and more. Watch the whole project or a single database, bucket, function, or site, counted over 5 minutes to 30 days.',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    brandLight: 'orange',
    dottedBackground: true,
  },
  {
    id: 'endpoints',
    title: 'Know when an endpoint stops answering',
    description:
      'On Appwrite Cloud, check an HTTP URL, a DNS record, a TCP port, or a mail server on the interval you choose. An HTTP check fails on any status outside 2xx, or when its certificate is about to expire, and you set the codes and the warning window.',
  },
  {
    id: 'alert',
    title: 'Every alert says how bad it is',
    description:
      'An alert keeps the latest reading, its peak, and how far past the threshold it went. Open it for the timeline from the first firing to recovery, and acknowledge it so your team knows you have it. It resolves on its own when usage recovers or the next check passes.',
  },
  {
    id: 'escalations',
    title: 'Escalate until someone answers',
    description:
      'Build an escalation once for your organization and pick it on any monitor. Each step notifies roles or named people, right away or after a wait, and repeats if nobody responds. It stops as soon as someone acknowledges the alert or it resolves.',
    brandLight: 'pink',
  },
  {
    id: 'notify',
    title: 'Email, the Console, and your phone',
    description:
      'The people a monitor notifies get an email with a button to pause it, a notification in the Console, and a push in Appwrite Beep with Acknowledge, Snooze, and Mute. Monitors set to Critical ring Android phones through silent mode.',
  },
  {
    id: 'events',
    title: 'Alerts your code can act on',
    description:
      'Every alert fires a project event when it opens and again when it is acknowledged or resolves. Send it to a webhook, trigger a function, or start from a template that posts to Slack, Discord, Microsoft Teams, or Google Chat, or pauses a function or site on a usage spike.',
  },
  {
    id: 'agents',
    title: 'Ask your agent what is firing',
    description:
      'Connect the Appwrite MCP server to Claude, Cursor, VS Code, or any MCP client. Your agent can create usage monitors and endpoint checks, list the alerts that are firing, and acknowledge them, and the same works from the server SDKs and the CLI.',
  },
  {
    id: 'budget',
    title: 'Budget alerts before the bill',
    description:
      'Set budget alerts in your organization’s billing settings and Monitors watches how much of your budget cap this cycle has used, with up to four thresholds and the cap itself at 100%. They reach you by email and on your phone.',
    brandLight: 'orange',
  },
]
