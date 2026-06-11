import { useLocation } from '@tanstack/react-router'
import { getDocsSlugFromPath } from '@/lib/docs/content'
import { getDocsSectionNav } from '@/lib/docs/navigation'
import { DocsGlobalSidebar } from './DocsGlobalSidebar'
import { DocsSectionSubnavPanel } from './DocsSectionSubnav'

type DocsLeftNavProps = {
  mobileOpen: boolean
  onMobileClose: () => void
}

export function DocsLeftNav({ mobileOpen, onMobileClose }: DocsLeftNavProps) {
  const pathname = useLocation().pathname
  const slug = getDocsSlugFromPath(pathname)
  const { parent, navigation: sectionNav } = getDocsSectionNav(slug)
  const hasSectionNav = Boolean(sectionNav?.length)

  return (
    <div className="flex h-full min-h-0 shrink-0">
      <DocsGlobalSidebar mobileOpen={mobileOpen} onMobileClose={onMobileClose} />
      {hasSectionNav && sectionNav ? (
        <DocsSectionSubnavPanel navigation={sectionNav} parent={parent} />
      ) : null}
    </div>
  )
}
