/**
 * Blog entries - search blog posts and articles
 */

import { FileText } from 'lucide-react'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const BLOG: CommandEntry[] = [
  {
    id: 'blog.action.search',
    scopes: ['account', 'organization', 'project', 'docs'],
    kind: 'action',
    group: 'Search',
    label: 'Search blog',
    description: 'Find blog posts and articles',
    icon: FileText,
    keywords: ['blog', 'search', 'articles', 'posts'],
    perform: (ctx) => ctx.openBlogSearchPage?.(),
  },
]

registerCommands(BLOG)
