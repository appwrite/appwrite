export type ChangelogTag =
  // Products
  | 'auth'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'messaging'
  | 'sites'
  | 'domains'
  | 'realtime'
  | 'firewall'
  // Developer Tools
  | 'console'
  | 'mcp'
  | 'cli'
  | 'sdk'
  | 'api'
  // More
  | 'performance'
  | 'security'
  | 'infrastructure'
  | 'integrations'
  | 'programs'

export type ChangelogFrontmatter = {
  title: string
  date: string
  description?: string
  cover?: string
  tags?: string
}

export type ChangelogEntryMeta = ChangelogFrontmatter & {
  slug: string
  href: string
  parsedTags: ChangelogTag[]
}

export type ChangelogEntry = ChangelogEntryMeta & {
  content: string
}
