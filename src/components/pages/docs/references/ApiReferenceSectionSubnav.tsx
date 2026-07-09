'use client'

import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, Menu } from 'lucide-react'
import { ApiExplorerPlatformToggle } from '@/components/global/api-explorer'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { Button } from '@/components/ui/button'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import type { ApiExplorerProjectPlatform } from '@/lib/api-explorer/types'
import {
  getSpecMode,
  type ReferencePlatform,
  type ReferenceService,
  type ReferenceVersion,
} from '@/lib/docs/references/constants'
import {
  buildReferenceNavProductGroups,
  findFirstReferenceNavService,
  findReferenceNavProductGroupForService,
  parseApiReferencePath,
  resolveReferenceVersionFromPath,
  type ReferenceNavProductGroup,
} from '@/lib/docs/references/reference-nav'
import { getApiReferencePlatformForMode } from '@/lib/docs/references/api-reference-ui-prefs'
import { useApiReferenceUiPrefs } from '@/lib/docs/references/ApiReferenceUiPrefsProvider'
import { loadReferenceNavServiceCountsFn } from '@/server/functions/api-reference'
import type { DocsNavParent } from '@/lib/docs/types'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  DOCS_NAV_SCROLL_CLASS,
  DOCS_SECTION_HEADER_CLASS,
  docsSidebarNavLinkClassName,
} from '@/lib/docs/nav-styles'
import {
  REFERENCE_SECTION_SUBNAV_DESKTOP_CLASS,
} from './explorer-styles'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '../DocsRouteLink'
import { ApiReferenceSidebarSelectors } from './ApiReferenceSelectors'
import { ApiReferenceOpenApiSpecDownloadFooter } from './ApiReferenceOpenApiSpecDownloadFooter'

const DOCS_MENU_ICON_STROKE = 1.25

async function fetchReferenceNavServiceCounts(
  version: ReferenceVersion,
  mode: ApiExplorerProjectPlatform,
): Promise<Map<ReferenceService, number>> {
  const entries = await loadReferenceNavServiceCountsFn({
    data: { version, mode },
  })
  return new Map(entries)
}

function SectionParentLink({
  parent,
  onNavigate,
}: {
  parent: DocsNavParent
  onNavigate?: () => void
}) {
  return (
    <DocsRouteLink
      href={parent.href}
      onClick={onNavigate}
      className="flex items-center gap-1.5 px-2 py-1.5 text-[13px] font-medium text-foreground transition-colors hover:text-foreground/80"
    >
      <ChevronLeft className="size-3.5" strokeWidth={DOCS_MENU_ICON_STROKE} />
      {parent.label}
    </DocsRouteLink>
  )
}

function isReferenceModelPath(pathname: string): boolean {
  return /^\/docs\/references\/[^/]+\/models\/[^/]+$/.test(
    pathname.replace(/\/+$/, '') || '/',
  )
}

type ApiReferenceSectionSubnavShellProps = {
  parent: DocsNavParent | null
  onNavigate?: () => void
  className?: string
}

function ApiReferenceSectionSubnavShell({
  parent,
  onNavigate,
  className,
}: ApiReferenceSectionSubnavShellProps) {
  const pathname = useLocation().pathname
  const navigate = useNavigate()
  const parsedPath = parseApiReferencePath(pathname)
  const versionFromPath = resolveReferenceVersionFromPath(pathname)
  const isServiceRoute = Boolean(parsedPath?.service)
  const {
    prefs,
    updatePrefs,
    setPlatformMode,
    setClientPlatform,
    setServerPlatform,
    setVersion,
  } = useApiReferenceUiPrefs()

  const platformModeFromPath: ApiExplorerProjectPlatform | null =
    parsedPath?.platform && getSpecMode(parsedPath.platform) === 'server'
      ? 'server'
      : parsedPath?.platform
        ? 'client'
        : null

  const platformMode = platformModeFromPath ?? prefs.platformMode
  const version =
    isServiceRoute || isReferenceModelPath(pathname)
      ? versionFromPath
      : prefs.version
  const platform =
    parsedPath?.platform ?? getApiReferencePlatformForMode(prefs, platformMode)

  useEffect(() => {
    if (!parsedPath?.platform) return

    const mode = getSpecMode(parsedPath.platform)
    if (mode !== 'client' && mode !== 'server') return

    const patch: Partial<typeof prefs> = {}
    if (mode !== prefs.platformMode) {
      patch.platformMode = mode
    }
    if (mode === 'client' && parsedPath.platform !== prefs.clientPlatform) {
      patch.clientPlatform = parsedPath.platform
    }
    if (mode === 'server' && parsedPath.platform !== prefs.serverPlatform) {
      patch.serverPlatform = parsedPath.platform
    }
    if (
      (isServiceRoute || isReferenceModelPath(pathname)) &&
      versionFromPath !== prefs.version
    ) {
      patch.version = versionFromPath
    }

    if (Object.keys(patch).length > 0) {
      updatePrefs(patch)
    }
  }, [
    isServiceRoute,
    parsedPath?.platform,
    pathname,
    prefs.clientPlatform,
    prefs.platformMode,
    prefs.serverPlatform,
    prefs.version,
    updatePrefs,
    versionFromPath,
  ])

  const { features } = useConsoleProfile()

  const { data: serviceCounts, isLoading } = useQuery({
    queryKey: ['api-reference-nav-services', version, platformMode],
    queryFn: () => fetchReferenceNavServiceCounts(version, platformMode),
    staleTime: 5 * 60 * 1000,
  })

  const navProductGroups = useMemo(
    () =>
      serviceCounts
        ? buildReferenceNavProductGroups(
            version,
            platform,
            serviceCounts,
            features,
          )
        : [],
    [
      serviceCounts,
      version,
      platform,
      features.dedicatedDbsDocumentsDB,
      features.dedicatedDbsVectorsDB,
      features.nativeDbsPostgres,
      features.nativeDbsMySQL,
      features.nativeDbsMongo,
    ],
  )

  const handlePlatformModeChange = async (nextMode: ApiExplorerProjectPlatform) => {
    setPlatformMode(nextMode)
    const nextPlatform =
      nextMode === 'client' ? prefs.clientPlatform : prefs.serverPlatform

    if (!parsedPath?.service) return

    const nextCounts = await fetchReferenceNavServiceCounts(version, nextMode)
    const nextGroups = buildReferenceNavProductGroups(
      version,
      nextPlatform,
      nextCounts,
      features,
    )
    const nextServices = nextGroups.flatMap((group) => group.services)

    const keepService = nextServices.some(
      (service) => service.id === parsedPath.service,
    )

    if (keepService && parsedPath.service) {
      void navigate({
        to: '/docs/references/$version/$platform/$service',
        params: {
          version,
          platform: nextPlatform,
          service: parsedPath.service,
        },
        hash: typeof window !== 'undefined' ? window.location.hash : undefined,
        replace: true,
      })
      return
    }

    const firstService = findFirstReferenceNavService(nextServices)
    if (firstService) {
      void navigate({
        to: '/docs/references/$version/$platform/$service',
        params: {
          version,
          platform: nextPlatform,
          service: firstService.id,
        },
        replace: true,
      })
    }
  }

  const handlePlatformChange = (nextPlatform: ReferencePlatform) => {
    if (platformMode === 'client') {
      setClientPlatform(nextPlatform)
    } else {
      setServerPlatform(nextPlatform)
    }
    if (!parsedPath?.service) return

    void navigate({
      to: '/docs/references/$version/$platform/$service',
      params: {
        version,
        platform: nextPlatform,
        service: parsedPath.service,
      },
      hash: typeof window !== 'undefined' ? window.location.hash : undefined,
      replace: true,
    })
  }

  const handleVersionChange = (nextVersion: ReferenceVersion) => {
    if (parsedPath?.service) {
      void navigate({
        to: '/docs/references/$version/$platform/$service',
        params: {
          version: nextVersion,
          platform,
          service: parsedPath.service,
        },
        hash: typeof window !== 'undefined' ? window.location.hash : undefined,
        replace: true,
      })
      return
    }

    if (isReferenceModelPath(pathname) && parsedPath?.version) {
      const modelId = pathname.split('/').pop()
      if (modelId) {
        void navigate({
          to: '/docs/references/$version/models/$model',
          params: { version: nextVersion, model: modelId },
          replace: true,
        })
      }
      return
    }

    setVersion(nextVersion)
  }

  return (
    <div className={cn('flex min-h-0 flex-1 flex-col', className)}>
      <div
        className={cn(
          'min-h-0 flex-1 overflow-y-auto px-3 py-4',
          DOCS_NAV_SCROLL_CLASS,
        )}
      >
        <div className="space-y-4">
          {parent ? <SectionParentLink parent={parent} onNavigate={onNavigate} /> : null}

          <div className="px-1">
            <ApiExplorerPlatformToggle
              value={platformMode}
              onChange={handlePlatformModeChange}
              className="w-full [&>button]:flex-1"
            />
          </div>

          <ApiReferenceSidebarSelectors
            version={version}
            platform={platform}
            platformMode={platformMode}
            onVersionChange={handleVersionChange}
            onPlatformChange={handlePlatformChange}
          />

          <ReferenceServicesNav
            productGroups={navProductGroups}
            activeServiceId={parsedPath?.service}
            pathname={pathname}
            isLoading={isLoading}
            onNavigate={onNavigate}
          />
        </div>
      </div>
      <ApiReferenceOpenApiSpecDownloadFooter version={version} mode={platformMode} />
    </div>
  )
}

type ReferenceServicesNavProps = {
  productGroups: ReferenceNavProductGroup[]
  activeServiceId?: ReferenceService
  pathname: string
  isLoading: boolean
  onNavigate?: () => void
}

function ReferenceServicesNav({
  productGroups,
  activeServiceId,
  pathname,
  isLoading,
  onNavigate,
}: ReferenceServicesNavProps) {
  const normalizedPath = pathname.replace(/\/+$/, '') || '/'
  const hasServices = productGroups.some((group) => group.services.length > 0)
  const [expandedGroupId, setExpandedGroupId] = useState('')

  useEffect(() => {
    if (productGroups.length === 0) return

    const activeGroup = findReferenceNavProductGroupForService(
      productGroups,
      activeServiceId,
    )
    if (activeGroup) {
      setExpandedGroupId(activeGroup.id)
      return
    }

    setExpandedGroupId((current) =>
      current && productGroups.some((group) => group.id === current)
        ? current
        : (productGroups[0]?.id ?? ''),
    )
  }, [productGroups, activeServiceId])

  return (
    <nav aria-label="API services">
      <p className="mb-1.5 px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        APIs
      </p>
      {isLoading ? (
        <p className="px-2 py-2 text-[13px] text-muted-foreground">Loading services…</p>
      ) : !hasServices ? (
        <p className="px-2 py-2 text-[13px] text-muted-foreground">
          No services available for this API.
        </p>
      ) : (
        <Accordion
          type="single"
          collapsible
          value={expandedGroupId}
          onValueChange={(value) => setExpandedGroupId(value)}
          className="w-full space-y-1 px-2"
        >
          {productGroups.map((group) => (
            <AccordionItem
              key={group.id}
              value={group.id}
              className="border-b border-border/50 pb-1 last:border-b-0 last:pb-0"
            >
              <AccordionTrigger className="gap-1.5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/70 hover:no-underline [&>svg]:size-3.5 [&>svg]:text-muted-foreground/70">
                <span className="min-w-0 flex-1 truncate text-start">{group.label}</span>
              </AccordionTrigger>
              <AccordionContent className="pb-2 pt-0">
                <ul className="space-y-0.5">
                  {group.services.map((service) => {
                    const isActive = normalizedPath === service.href
                    return (
                      <li key={service.id}>
                        <DocsRouteLink
                          href={service.href}
                          onClick={onNavigate}
                          className={docsSidebarNavLinkClassName(isActive)}
                        >
                          <span className="truncate">{service.label}</span>
                        </DocsRouteLink>
                      </li>
                    )
                  })}
                </ul>
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      )}
    </nav>
  )
}

type ApiReferenceSectionSubnavPanelProps = {
  parent: DocsNavParent | null
}

/** Desktop API reference section nav with client/server toggle and product-group accordion. */
export function ApiReferenceSectionSubnavPanel({
  parent,
}: ApiReferenceSectionSubnavPanelProps) {
  return (
    <aside
      className={cn(
        'relative z-10 hidden h-full w-[220px] shrink-0 flex-col overflow-hidden border-e border-border bg-background',
        REFERENCE_SECTION_SUBNAV_DESKTOP_CLASS,
      )}
      aria-label={
        parent?.label ? `${parent.label} section navigation` : 'API references navigation'
      }
    >
      {parent ? (
        <div className={cn(DOCS_SECTION_HEADER_CLASS, 'px-3')}>
          <SectionParentLink parent={parent} />
        </div>
      ) : null}
      <ApiReferenceSectionSubnavShell parent={null} />
    </aside>
  )
}

export function ApiReferenceSectionSubnavMobile({
  parent,
}: {
  parent: DocsNavParent | null
}) {
  const [sheetOpen, setSheetOpen] = useState(false)

  return (
    <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
      <SheetTrigger asChild>
        <Button
          variant="outline"
          size="icon"
          className="size-8 shrink-0"
          aria-label="Browse APIs"
        >
          <Menu className="size-3.5" strokeWidth={DOCS_MENU_ICON_STROKE} />
        </Button>
      </SheetTrigger>
      <SheetContent side="left" className="flex w-[min(100vw,320px)] flex-col p-0">
        <SheetHeader className="shrink-0 border-b border-border px-4 py-4 text-start">
          <SheetTitle className="text-[15px]">
            {parent?.label ?? 'API references'}
          </SheetTitle>
        </SheetHeader>
        <ApiReferenceSectionSubnavShell
          parent={parent}
          onNavigate={() => setSheetOpen(false)}
          className="min-h-0 flex-1"
        />
      </SheetContent>
    </Sheet>
  )
}
