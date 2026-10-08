/**
 * Docs-scope entries (Appwrite documentation at /docs).
 */

import { BookOpen, Code, Cog, Play } from 'lucide-react'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const DOCS: CommandEntry[] = [
  {
    id: 'docs.action.search',
    scopes: ['docs', 'project', 'organization', 'account'],
    kind: 'action',
    group: 'Search',
    label: 'Search documentation',
    description: 'Find guides, API references, and tutorials',
    icon: BookOpen,
    keywords: ['search', 'find', 'docs', 'documentation', 'pages'],
    perform: (ctx) => ctx.openDocsSearchPage?.(),
  },
  {
    id: 'docs.nav.home',
    scopes: ['docs'],
    kind: 'navigation',
    label: 'Docs home',
    description: 'Appwrite documentation home',
    icon: BookOpen,
    keywords: ['home', 'docs', 'documentation'],
    to: () => '/docs',
  },
  {
    id: 'docs.nav.quick-starts',
    scopes: ['docs'],
    kind: 'navigation',
    label: 'Quick starts',
    description: 'Get started with Appwrite in minutes',
    icon: Play,
    keywords: ['quick start', 'tutorial', 'setup'],
    to: () => '/docs/quick-starts',
  },
  {
    id: 'docs.nav.references',
    scopes: ['docs'],
    kind: 'navigation',
    label: 'API references',
    description: 'Browse API references documentation',
    icon: Code,
    keywords: ['api', 'reference', 'sdk'],
    to: () => '/docs/references',
  },
  {
    id: 'docs.nav.sdks',
    scopes: ['docs'],
    kind: 'navigation',
    label: 'SDKs',
    description: 'Client and server SDK documentation',
    icon: Cog,
    keywords: ['sdk', 'client', 'server'],
    to: () => '/docs/sdks',
  },
]

registerCommands(DOCS)
