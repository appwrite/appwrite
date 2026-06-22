import type { CoverFieldDefinition } from '@/lib/cover-generator/types'

export const COVER_MILESTONE_DEFAULTS = {
  eyebrow: 'Milestone',
  stat: '100K',
  statLabel: 'developers',
  title: 'Thank you for building with us',
  subtitle: 'And we are just getting started.',
  gradientStat: true,
} as const

export const COVER_MILESTONE_FIELD_DEFINITIONS: CoverFieldDefinition[] = [
  {
    key: 'eyebrow',
    label: 'Eyebrow',
    type: 'text',
    placeholder: COVER_MILESTONE_DEFAULTS.eyebrow,
  },
  {
    key: 'stat',
    label: 'Stat',
    type: 'text',
    placeholder: COVER_MILESTONE_DEFAULTS.stat,
    description: 'The milestone number or metric (e.g. 100K, 1M+, $10M).',
  },
  {
    key: 'statLabel',
    label: 'Stat label',
    type: 'text',
    placeholder: COVER_MILESTONE_DEFAULTS.statLabel,
    description: 'Short label under the stat (e.g. developers, GitHub stars).',
  },
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_MILESTONE_DEFAULTS.title,
  },
  {
    key: 'subtitle',
    label: 'Subtitle',
    type: 'textarea',
    placeholder: COVER_MILESTONE_DEFAULTS.subtitle,
  },
  {
    key: 'gradientStat',
    label: 'Gradient stat',
    type: 'boolean',
    description: 'Use the brand pink-to-purple gradient on the stat.',
  },
]

export type CoverMilestoneFields = {
  stat: string
  statLabel?: string
  title: string
  subtitle?: string
  eyebrow?: string
  gradientStat: boolean
}
