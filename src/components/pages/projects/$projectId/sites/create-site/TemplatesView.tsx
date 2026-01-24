/**
 * Templates View Component
 *
 * Browse and search through available site templates.
 * Allows filtering by framework and use case.
 */

import { useState, useMemo, useEffect } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  Search,
  X,
  LayoutTemplate,
  ExternalLink,
  GitBranch,
  ArrowRight,
  Loader2,
} from 'lucide-react'
import { useSiteTemplates } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'
import type { Models } from '@appwrite.io/console'

/**
 * Helper to safely extract a framework string from template.frameworks
 * The API might return strings or objects with a 'key' or 'name' property
 */
function getFrameworkString(framework: unknown): string {
  if (!framework) return ''
  if (typeof framework === 'string') return framework
  if (typeof framework === 'object' && framework !== null) {
    const obj = framework as Record<string, unknown>
    if (typeof obj.key === 'string') return obj.key
    if (typeof obj.name === 'string') return obj.name
    if (typeof obj.id === 'string') return obj.id
  }
  return ''
}

export function TemplatesView() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { theme, resolvedTheme } = useTheme()
  const { frameworks, setCurrentPath, updateFormData } = useWizard()

  const [searchValue, setSearchValue] = useState('')
  const [selectedFrameworks, setSelectedFrameworks] = useState<string[]>([])
  const [selectedUseCases, setSelectedUseCases] = useState<string[]>([])
  const [page, setPage] = useState(0)
  const [pageSize, setPageSize] = useState(9)

  // Set current path
  useEffect(() => {
    setCurrentPath('template')
  }, [setCurrentPath])

  // Determine theme for screenshots
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true
    return (
      resolvedTheme === 'dark' ||
      (resolvedTheme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches) ||
      theme === 'dark'
    )
  }, [theme, resolvedTheme])

  // Fetch templates
  const {
    templates,
    total,
    isLoading,
    data,
  } = useSiteTemplates(
    projectId,
    selectedFrameworks.length > 0 ? selectedFrameworks : undefined,
    selectedUseCases.length > 0 ? selectedUseCases : undefined,
    pageSize,
    page * pageSize,
  )

  // Client-side search filter
  const filteredTemplates = useMemo(() => {
    if (!searchValue.trim()) return templates
    const searchLower = searchValue.toLowerCase()
    return templates.filter(
      (t) =>
        t.name.toLowerCase().includes(searchLower) ||
        (t.tagline && t.tagline.toLowerCase().includes(searchLower)),
    )
  }, [templates, searchValue])

  // Get unique use cases from templates
  const availableUseCases = useMemo(() => {
    const useCases = new Set<string>()
    templates.forEach((t) => {
      t.useCases?.forEach((uc) => useCases.add(uc))
    })
    return Array.from(useCases).sort()
  }, [templates])

  // Helper to get screenshot URL - templates include full URLs
  const getScreenshotUrl = (template: Models.TemplateSite) => {
    return isDark ? template.screenshotDark : template.screenshotLight
  }

  const handleSelectTemplate = (template: Models.TemplateSite) => {
    updateFormData({
      templateId: template.key,
      template,
      siteName: template.name,
      framework: getFrameworkString(template.frameworks?.[0]),
    })

    navigate({
      to: '/projects/$projectId/sites/create-site/templates/$template',
      params: {
        projectId: projectId!,
        template: encodeURIComponent(template.key),
      },
    })
  }

  const toggleFramework = (framework: string) => {
    setSelectedFrameworks((prev) =>
      prev.includes(framework)
        ? prev.filter((f) => f !== framework)
        : [...prev, framework],
    )
    setPage(0)
  }

  const toggleUseCase = (useCase: string) => {
    setSelectedUseCases((prev) =>
      prev.includes(useCase)
        ? prev.filter((uc) => uc !== useCase)
        : [...prev, useCase],
    )
    setPage(0)
  }

  const clearFilters = () => {
    setSelectedFrameworks([])
    setSelectedUseCases([])
    setSearchValue('')
    setPage(0)
  }

  const hasActiveFilters =
    selectedFrameworks.length > 0 ||
    selectedUseCases.length > 0 ||
    searchValue.length > 0

  const sidebarContent = (
    <div className="space-y-6">
      {/* Search */}
      <div className="relative">
        <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={searchValue}
          onChange={(e) => {
            setSearchValue(e.target.value)
            setPage(0)
          }}
          placeholder="Search templates..."
          className="h-9 pl-8 text-[13px]"
        />
        {searchValue && (
          <button
            onClick={() => setSearchValue('')}
            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>

      {/* Frameworks filter */}
      <div>
        <h4 className="mb-3 text-[12px] font-medium uppercase tracking-wide text-muted-foreground">
          Frameworks
        </h4>
        <div className="space-y-2 max-h-[200px] overflow-y-auto">
          {frameworks.map((framework) => (
            <label
              key={framework.key}
              className="flex cursor-pointer items-center gap-2"
            >
              <Checkbox
                checked={selectedFrameworks.includes(framework.key)}
                onCheckedChange={() => toggleFramework(framework.key)}
                className="h-4 w-4"
              />
              <FrameworkIcon framework={framework.key} size="sm" />
              <span className="text-[13px] text-foreground">
                {framework.name}
              </span>
            </label>
          ))}
        </div>
      </div>

      {/* Use cases filter */}
      {availableUseCases.length > 0 && (
        <div>
          <h4 className="mb-3 text-[12px] font-medium uppercase tracking-wide text-muted-foreground">
            Use cases
          </h4>
          <div className="space-y-2 max-h-[200px] overflow-y-auto">
            {availableUseCases.map((useCase) => (
              <label
                key={useCase}
                className="flex cursor-pointer items-center gap-2"
              >
                <Checkbox
                  checked={selectedUseCases.includes(useCase)}
                  onCheckedChange={() => toggleUseCase(useCase)}
                  className="h-4 w-4"
                />
                <span className="text-[13px] text-foreground">{useCase}</span>
              </label>
            ))}
          </div>
        </div>
      )}

      {/* Clear filters */}
      {hasActiveFilters && (
        <button
          onClick={clearFilters}
          className="flex w-full items-center justify-center gap-1.5 rounded-md border border-border px-3 py-2 text-[12px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
        >
          <X className="h-3 w-3" />
          Clear all filters
        </button>
      )}

      {/* Other options */}
      <div className="pt-4 border-t border-border">
        <h4 className="mb-3 text-[12px] font-medium uppercase tracking-wide text-muted-foreground">
          Other options
        </h4>
        <div className="space-y-2">
          <Link
            to="/projects/$projectId/sites/create-site/repositories"
            params={{ projectId: projectId! }}
            className="flex items-center gap-2 text-[12px] text-muted-foreground hover:text-foreground transition-colors"
          >
            <GitBranch className="h-3.5 w-3.5" />
            Import from Git
          </Link>
          <Link
            to="/projects/$projectId/sites/create-site/manual"
            params={{ projectId: projectId! }}
            className="flex items-center gap-2 text-[12px] text-muted-foreground hover:text-foreground transition-colors"
          >
            <ArrowRight className="h-3.5 w-3.5" />
            Upload manually
          </Link>
        </div>
      </div>
    </div>
  )

  return (
    <WizardLayout
      title="Create site"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      sidebar={sidebarContent}
      useSidebar={true}
    >
      {/* Active filters pills */}
      {hasActiveFilters && (
        <div className="mb-4 flex flex-wrap items-center gap-2">
          {searchValue && (
            <button
              onClick={() => setSearchValue('')}
              className="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-[12px] font-medium text-primary hover:bg-primary/20"
            >
              "{searchValue}"
              <X className="h-3 w-3" />
            </button>
          )}
          {selectedFrameworks.map((framework) => {
            const f = frameworks.find((fr) => fr.key === framework)
            return (
              <button
                key={framework}
                onClick={() => toggleFramework(framework)}
                className="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-[12px] font-medium text-primary hover:bg-primary/20"
              >
                {f?.name || framework}
                <X className="h-3 w-3" />
              </button>
            )
          })}
          {selectedUseCases.map((useCase) => (
            <button
              key={useCase}
              onClick={() => toggleUseCase(useCase)}
              className="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-[12px] font-medium text-primary hover:bg-primary/20"
            >
              {useCase}
              <X className="h-3 w-3" />
            </button>
          ))}
        </div>
      )}

      {/* Templates grid */}
      {isLoading && !data ? (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : filteredTemplates.length > 0 ? (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {filteredTemplates.map((template) => {
              const screenshotUrl = getScreenshotUrl(template)
              return (
                <button
                  key={template.key}
                  onClick={() => handleSelectTemplate(template)}
                  className="group text-left rounded-lg border border-border bg-card overflow-hidden transition-all hover:border-border hover:bg-accent/50"
                >
                  {/* Screenshot with framework icon overlay */}
                  <div className="relative">
                    {screenshotUrl ? (
                      <div className="aspect-video w-full overflow-hidden bg-muted">
                        <img
                          src={screenshotUrl}
                          alt={`${template.name} preview`}
                          className="h-full w-full object-cover transition-opacity"
                        />
                      </div>
                    ) : (
                      <div className="aspect-video w-full flex items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20 border-b border-border/50">
                        <LayoutTemplate className="h-8 w-8 text-muted-foreground/30" />
                      </div>
                    )}
                    {/* Framework icon - floating bottom left */}
                    <div className="absolute bottom-2 left-2 flex h-8 w-8 items-center justify-center rounded-lg bg-background/90 backdrop-blur-sm border border-border/50 shadow-sm">
                      <FrameworkIcon
                        framework={getFrameworkString(template.frameworks?.[0])}
                        size="sm"
                      />
                    </div>
                  </div>
                  
                  {/* Content */}
                  <div className="p-4">
                    <div className="min-w-0">
                      <h3 className="truncate text-[13px] font-medium text-foreground group-hover:text-primary transition-colors">
                        {template.name}
                      </h3>
                      {template.tagline && (
                        <p className="mt-0.5 text-[11px] text-muted-foreground line-clamp-2">
                          {template.tagline}
                        </p>
                      )}
                    </div>
                  </div>
                </button>
              )
            })}
          </div>

          {/* Pagination */}
          <Pagination
            currentPage={page + 1}
            totalItems={total}
            pageSize={pageSize}
            pageSizeOptions={[9, 18, 36]}
            onPageChange={(p) => setPage(p - 1)}
            onPageSizeChange={(size) => {
              setPageSize(size)
              setPage(0)
            }}
            itemLabel="templates"
            className="mt-4"
          />
        </>
      ) : (
        <div className="py-12">
          <EmptyState
            icon={LayoutTemplate}
            title="No templates found"
            description={
              hasActiveFilters
                ? 'Try adjusting your filters'
                : 'No templates available'
            }
            isEmpty={true}
            hasFilters={hasActiveFilters}
          />
        </div>
      )}
    </WizardLayout>
  )
}
