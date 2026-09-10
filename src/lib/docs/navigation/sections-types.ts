import type { DocsNavParent, DocsNavTree } from '../types'

export type DocsSectionNavConfig = {
  prefix: string
  parent: DocsNavParent
  navigation: DocsNavTree
}
