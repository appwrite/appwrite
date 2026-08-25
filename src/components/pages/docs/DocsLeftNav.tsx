import { useLocation } from '@tanstack/react-router'
import { getDocsSlugFromPath } from '@/lib/docs/docs-slug'
import { getDocsSectionNav } from '@/lib/docs/navigation'
import { ApiReferenceSectionSubnavPanel } from '@/components/pages/docs/references/ApiReferenceSectionSubnav'
import { DocsGlobalSidebar } from './DocsGlobalSidebar'
import { DocsSectionSubnavPanel } from './DocsSectionSubnav'

type DocsLeftNavProps = {
  mobileOpen: boolean
  onMobileClose: () => void
}

function isReferencesDocsSlug(slug: string): boolean {
  return slug === 'references' || slug.startsWith('references/')
}

export function DocsLeftNav({ mobileOpen, onMobileClose }: DocsLeftNavProps) {
  const pathname = useLocation().pathname
  const slug = getDocsSlugFromPath(pathname)
  const { parent, navigation: sectionNav } = getDocsSectionNav(slug)
  const hasSectionNav = Boolean(sectionNav?.length)
  const isReferencesSection = isReferencesDocsSlug(slug)

  return (
    <div className="flex h-full min-h-0 shrink-0">
      <DocsGlobalSidebar mobileOpen={mobileOpen} onMobileClose={onMobileClose} />
      {isReferencesSection ? (
        <ApiReferenceSectionSubnavPanel parent={parent} />
      ) : hasSectionNav && sectionNav ? (
        <DocsSectionSubnavPanel navigation={sectionNav} parent={parent} />
      ) : null}
    </div>
  )
}
