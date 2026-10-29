/**
 * Starting points offered when a project has no alert rules. Each fills the
 * create form; nothing is saved until the rule is created.
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
      metric: 'bandwidth.outbound',
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
      metric: 'databases.reads',
      comparator: 'greaterThan',
      threshold: 1_000_000,
      window: 3600,
    },
  },
  {
    id: 'database-storage',
    title: 'Database storage',
    description: 'Databases grow past 5 GB.',
    form: {
      name: 'Database storage',
      metric: 'databases.storage',
      comparator: 'greaterThan',
      threshold: 5,
      byteUnit: 'GB',
    },
  },
  {
    id: 'file-storage',
    title: 'File storage',
    description: 'Stored files grow past 50 GB.',
    form: {
      name: 'File storage',
      metric: 'files.storage',
      comparator: 'greaterThan',
      threshold: 50,
      byteUnit: 'GB',
    },
  },
]
