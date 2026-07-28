import type { ReferenceService } from './constants'

export type ApiReferenceProductGroupDefinition = {
  id: string
  label: string
  services: readonly ReferenceService[]
}

/**
 * API reference sidebar service order.
 * Matches website/src/routes/docs/references/+layout.svelte.
 */
export const API_REFERENCE_NAV_SERVICE_ORDER = [
  'account',
  'users',
  'teams',
  'databases',
  'tablesDB',
  'documentsDB',
  'vectorsDB',
  'postgresql',
  'mysql',
  'mongo',
  'sites',
  'storage',
  'functions',
  'messaging',
  'tokens',
  'locale',
  'avatars',
  'presences',
  'project',
] as const satisfies readonly ReferenceService[]

/** Product groupings for the API reference sidebar (matches API explorer layout). */
export const API_REFERENCE_PRODUCT_GROUPS: ApiReferenceProductGroupDefinition[] =
  [
    {
      id: 'auth',
      label: 'Auth',
      services: ['account', 'users', 'teams', 'presences'],
    },
    {
      id: 'databases',
      label: 'Databases',
      services: [
        'databases',
        'tablesDB',
        'documentsDB',
        'vectorsDB',
        'postgresql',
        'mysql',
        'mongo',
      ],
    },
    {
      id: 'sites',
      label: 'Sites',
      services: ['sites'],
    },
    {
      id: 'storage',
      label: 'Storage',
      services: ['storage', 'tokens'],
    },
    {
      id: 'functions',
      label: 'Functions',
      services: ['functions'],
    },
    {
      id: 'messaging',
      label: 'Messaging',
      services: ['messaging'],
    },
    {
      id: 'platform',
      label: 'Platform',
      services: ['project'],
    },
    {
      id: 'utilities',
      label: 'Utilities',
      services: ['locale', 'avatars'],
    },
  ]
