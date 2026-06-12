export type ChangelogFrontmatter = {
  title: string
  date: string
  description?: string
  cover?: string
}

export type ChangelogEntryMeta = ChangelogFrontmatter & {
  slug: string
  href: string
}

export type ChangelogEntry = ChangelogEntryMeta & {
  content: string
}
