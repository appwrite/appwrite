import type { ProductPageContent } from '@/lib/products/types'

/**
 * Monitors product page copy. Plan limits follow Cloud's plans (Free 3 usage
 * monitors per project checked every 15 minutes, no endpoint checks; Pro 25
 * per project checked every minute, 10 endpoint checks per organization then
 * $2 a month each; Scale 100 per project, 50 checks, 30-second checks).
 * Thresholds are absolute values; critical alerts ring through silent mode
 * on Android only until Beep's iOS entitlement ships.
 */
export const monitorsProductContent: ProductPageContent = {
  id: 'monitors',
  metaTitle:
    'Monitors: usage alerts and endpoint checks for your Appwrite project',
  metaDescription:
    'Appwrite Monitors watch your project usage and your endpoints and alert you by email, in the Console, and on your phone when a metric crosses your threshold or a check fails. Escalate, acknowledge, and act on alerts with webhooks, functions, and MCP.',
  hero: {
    title: 'Know when your project needs you',
    description:
      'Appwrite Monitors watch your project usage and your endpoints, and tell you the moment a metric crosses your threshold or a check fails, by email, in the Console, and on your phone with Appwrite Beep.',
    stats: [
      { value: '25+', label: 'Usage metrics across every service' },
      { value: '4 checks', label: 'HTTP, DNS, TCP, and SMTP endpoints' },
      { value: '1 minute', label: 'Between usage checks on Pro' },
      { value: 'Escalations', label: 'Until someone acknowledges' },
      { value: 'MCP', label: 'Ask your agent what is firing' },
    ],
  },
  faq: [
    {
      question: 'What can Appwrite Monitors watch?',
      answer:
        'Usage monitors compare one project metric with a threshold you set, such as outbound bandwidth, executions, database reads, storage, realtime connections, or webhook failures, for the whole project or a single resource. On Appwrite Cloud, endpoint checks also test an HTTP URL, a DNS record, a TCP port, or a mail server, and budget alerts watch your organization’s spend.',
    },
    {
      question: 'How are thresholds set?',
      answer:
        'A threshold is a value in the metric’s own unit, like 5 GB of outbound bandwidth or a million database reads, counted over a window from 5 minutes to 30 days or compared with the latest reading. The Console suggests one from your recent peak and shows how often it would have fired before you save.',
    },
    {
      question: 'Who gets notified when a monitor fires?',
      answer:
        'The organization roles a monitor notifies, Owners by default, by email, in the Console, and with a push on every phone signed in to Appwrite Beep. With an escalation, each step notifies its roles and people in turn until someone acknowledges the alert or it resolves. Members hear about an alert once when it fires and once when it resolves.',
      links: [{ label: 'Appwrite Beep', href: '/products/beep' }],
    },
    {
      question: 'What happens when usage recovers?',
      answer:
        'The alert resolves on its own when the condition stops holding or the next check passes, and everyone who was told it fired gets a resolved notice. Resolved alerts stay in the Alerts tab with how long they fired and how far past the threshold they went.',
    },
    {
      question: 'Can my code react to alerts?',
      answer:
        'Yes. Every alert fires monitors.[monitorId].alerts.[alertId].create when it opens and an update event when it is acknowledged or resolves. Deliver them to webhooks and function triggers, or start from the Alerts to chat or Pause on usage spike function templates.',
    },
    {
      question: 'Which plans include Monitors?',
      answer:
        'Free includes 3 usage monitors per project, checked every 15 minutes. Pro includes 25 usage monitors per project checked every minute, budget alerts, and 10 endpoint checks for your organization, with more at $2 a month each. Scale raises that to 100 usage monitors per project, 50 endpoint checks, and checks as often as every 30 seconds.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Do Monitors work on self-hosted Appwrite?',
      answer:
        'Yes, for usage monitors, which self-hosted Appwrite evaluates every minute with the same API, SDKs, and project events. Endpoint checks, budget alerts, and pushes to Appwrite Beep run on Appwrite Cloud.',
    },
  ],
  cta: {
    title: 'Know before your users do',
    description:
      'Start from a monitor suggested by your own usage, and get the alert by email, in the Console, and on your phone.',
  },
}
