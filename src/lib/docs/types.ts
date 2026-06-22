export type DocsPageMeta = {
  slug: string
  title: string
  description: string
  layout: string
  readingTimeMinutes: number
  step?: number
  category?: string
  framework?: string
  draft?: boolean
}

export type DocsNavLink = {
  label: string
  href: string
  icon?: string
  new?: boolean
  isParent?: boolean
  openInNewTab?: boolean
}

export type DocsNavGroup = {
  label?: string
  items: DocsNavLink[]
  collapsible?: boolean
  initiallyCollapsed?: boolean
}

export type DocsNavParent = {
  label: string
  href: string
}

export type DocsNavTree = Array<DocsNavGroup | DocsNavLink>

export type DocsTocItem = {
  id: string
  label: string
  level: number
  step?: number
}

export type DocsPageData = {
  meta: DocsPageMeta
  content: string
  rawContent: string
  toc: DocsTocItem[]
  /** Frontmatter `prompt` path (e.g. `/docs/quick-starts/react`) for prompt_content pages. */
  promptPath?: string
}
