import type { CoverFieldDefinition } from '@/lib/cover-generator/types'

export const COVER_VERSION_DEFAULTS = {
  eyebrow: 'Release',
  version: '1.9.6',
  title: "What's new",
} as const

export const COVER_VERSION_NUMBER_FIELD_DEFINITIONS: CoverFieldDefinition[] = [
  {
    key: 'eyebrow',
    label: 'Eyebrow',
    type: 'text',
    placeholder: COVER_VERSION_DEFAULTS.eyebrow,
  },
  {
    key: 'version',
    label: 'Version',
    type: 'text',
    placeholder: COVER_VERSION_DEFAULTS.version,
    description: 'The release version number (e.g. 1.9.6, 2.0.0, 1.10.0-rc.1).',
  },
]

export const COVER_VERSION_TITLE_FIELD_DEFINITIONS: CoverFieldDefinition[] = [
  ...COVER_VERSION_NUMBER_FIELD_DEFINITIONS,
  {
    key: 'title',
    label: 'Title',
    type: 'textarea',
    placeholder: COVER_VERSION_DEFAULTS.title,
  },
]

export type CoverVersionNumberFields = {
  version: string
  eyebrow?: string
}

export type CoverVersionTitleFields = CoverVersionNumberFields & {
  title: string
}
