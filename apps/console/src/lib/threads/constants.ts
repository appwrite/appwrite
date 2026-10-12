export const THREADS_PAGE_SIZE = 25

export const THREADS_DISCORD_GUILD_ID = '564160730845151244'

export const THREADS_DEFAULT_DESCRIPTION =
  'Appwrite is an open-source platform for building applications at any scale, using your preferred programming languages and tools.'

export const THREADS_PRIMARY_TAGS = [
  'Web',
  'Flutter',
  'GraphQL',
  'Cloud',
  'Self Hosted',
] as const

export const THREADS_MORE_TAGS = [
  'Tools',
  'Accounts',
  'Users',
  'Teams',
  'Databases',
  'Storage',
  'Functions',
  'Realtime',
  'Locale',
  'Avatars',
  'Webhooks',
  'General',
  'REST API',
] as const

export const THREADS_ALL_TAGS = [
  ...THREADS_PRIMARY_TAGS,
  ...THREADS_MORE_TAGS,
] as const
