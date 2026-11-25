/**
 * Starting points offered when a project has no monitors. Each fills the
 * create form; nothing is saved until the monitor is created.
 */

import type { RuleForm } from './RuleFields'

export type AlertPreset = {
  id: string
  title: string
  description: string
  form: Partial<RuleForm>
}

export const PRESETS: AlertPreset[] = [
  {
    id: 'egress-spike',
    title: 'Egress spike',
    description: 'Outbound bandwidth above 10 GB in an hour.',
    form: {
      name: 'Egress spike',
      metric: 'network.outbound',
      comparator: 'greaterThan',
      threshold: 10,
      byteUnit: 'GB',
      window: 3600,
    },
  },
  {
    id: 'read-surge',
    title: 'Read surge',
    description: 'More than a million database reads in an hour.',
    form: {
      name: 'Read surge',
      metric: 'databases.operations.reads',
      comparator: 'greaterThan',
      threshold: 1_000_000,
      window: 3600,
    },
  },
  {
    id: 'storage-used',
    title: 'Storage used',
    description: 'Files, databases and deployments grow past 50 GB.',
    form: {
      name: 'Storage used',
      metric: 'storage',
      comparator: 'greaterThan',
      threshold: 50,
      byteUnit: 'GB',
    },
  },
  {
    id: 'webhook-failures',
    title: 'Webhook failures',
    description: 'More than 10 webhook deliveries fail in an hour.',
    form: {
      name: 'Webhook failures',
      metric: 'webhooks.events.failed',
      comparator: 'greaterThan',
      threshold: 10,
      window: 3600,
    },
  },
]
