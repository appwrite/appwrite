import { useEffect, useMemo, useState } from 'react'
import { ChevronsUpDown, Search } from 'lucide-react'
import { useTheme } from 'next-themes'
import type { Models } from '@appwrite.io/console'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { Pagination } from '@/components/global/shared/Pagination'
import {
  SiteTemplateCard,
  SiteTemplateCardSkeleton,
} from '@/components/pages/projects/$projectId/sites/_components/SiteTemplateCard'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Input } from '@/components/ui/input'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  useSiteFrameworks,
  useSiteTemplates,
} from '@/lib/react-query/hooks'
import {
  SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE,
  SITE_TEMPLATE_USE_CASE_OPTIONS,
  buildSiteTemplateFrameworkOptions,
} from '@/lib/sites/site-template-wizard'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const DEFAULT_PAGE_SIZE_OPTIONS = [9, 12, 18, 36] as const

const GRID_COLUMNS_CLASS = {
  3: 'grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4',
  4: 'grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-3',
} as const

type SiteTemplateGalleryProps = {
  projectId: string
  onSelectTemplate?: (template: Models.TemplateSite) => void
  defaultPageSize?: number
  pageSizeOptions?: readonly number[]
  columns?: 3 | 4
  compact?: boolean
  scrollToTopOnPageChange?: boolean
  /** Pad the grid to `pageSize` slots so height stays fixed when fewer templates are shown */
  maintainGridHeight?: boolean
  className?: string
}

export function SiteTemplateGallery({
  projectId,
  onSelectTemplate,
  defaultPageSize = SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE,
  pageSizeOptions = DEFAULT_PAGE_SIZE_OPTIONS,
  columns = 3,
  compact = false,
  scrollToTopOnPageChange = true,
  maintainGridHeight = false,
  className,
}: SiteTemplateGalleryProps) {
  const t = useT()
  const { theme, resolvedTheme } = useTheme()

  const [templateSearch, setTemplateSearch] = useState('')
  const [debouncedTemplateSearch, setDebouncedTemplateSearch] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(defaultPageSize)
  const [selectedFramework, setSelectedFramework] = useState('all')
  const [selectedUseCase, setSelectedUseCase] = useState('all')
  const [useCaseOpen, setUseCaseOpen] = useState(false)
  const [frameworkOpen, setFrameworkOpen] = useState(false)

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedTemplateSearch(templateSearch)
      setRequestedPage(1)
      setDisplayedPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [templateSearch])

  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true
    return resolvedTheme === 'dark' || theme === 'dark'
  }, [theme, resolvedTheme])

  const frameworkFilter =
    selectedFramework !== 'all' ? [selectedFramework] : undefined
  const useCaseFilter = selectedUseCase !== 'all' ? [selectedUseCase] : undefined

  const { data: frameworksData } = useSiteFrameworks(projectId)
  const frameworkOptions = useMemo(
    () => buildSiteTemplateFrameworkOptions(frameworksData?.frameworks),
    [frameworksData?.frameworks],
  )

  const { isLoading: templatesLoading, isFetching: templatesFetching } =
    useSiteTemplates(
      projectId,
      frameworkFilter,
      useCaseFilter,
      pageSize,
      (requestedPage - 1) * pageSize,
    )

  const {
    templates: displayedTemplates,
    total: templatesTotal,
    isLoading: templatesDisplayedLoading,
  } = useSiteTemplates(
    projectId,
    frameworkFilter,
    useCaseFilter,
    pageSize,
    (displayedPage - 1) * pageSize,
  )

  useEffect(() => {
    if (
      !templatesFetching &&
      requestedPage !== displayedPage &&
      !templatesLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [templatesFetching, templatesLoading, requestedPage, displayedPage])

  const showTemplatesLoading =
    templatesDisplayedLoading && displayedTemplates.length === 0

  const filteredTemplates = useMemo(() => {
    if (!debouncedTemplateSearch.trim()) return displayedTemplates
    const searchLower = debouncedTemplateSearch.toLowerCase()
    return displayedTemplates.filter(
      (template) =>
        template.name.toLowerCase().includes(searchLower) ||
        (template.tagline &&
          template.tagline.toLowerCase().includes(searchLower)),
    )
  }, [displayedTemplates, debouncedTemplateSearch])

  const gridClassName = GRID_COLUMNS_CLASS[columns]
  const cardHeightClass = compact ? 'h-[176px]' : 'h-[180px]'
  const emptyGridSlots =
    maintainGridHeight && !showTemplatesLoading
      ? Math.max(0, pageSize - filteredTemplates.length)
      : 0
  const showFixedHeightGrid =
    maintainGridHeight && !showTemplatesLoading
  const emptyMessage = templateSearch
    ? t('No templates found')
    : t('No templates available')

  return (
    <div className={cn('flex flex-col gap-4 p-1', className)}>
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[140px] flex-1">
          <Search
            className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden
          />
          <Input
            value={templateSearch}
            onChange={(event) => setTemplateSearch(event.target.value)}
            placeholder={t('Search templates...')}
            className="h-9 ps-9 text-[13px]"
          />
        </div>

        <Popover open={useCaseOpen} onOpenChange={setUseCaseOpen} modal>
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              role="combobox"
              aria-expanded={useCaseOpen}
              className="h-9 w-[150px] justify-between text-[13px] font-normal"
            >
              {t(
                SITE_TEMPLATE_USE_CASE_OPTIONS.find(
                  (option) => option.value === selectedUseCase,
                )?.label || 'All use cases',
              )}
              <ChevronsUpDown className="ms-2 size-3.5 shrink-0 opacity-50" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-[200px] p-0" align="start" sideOffset={4}>
            <Command>
              <CommandInput
                placeholder={t('Search use cases...')}
                className="h-9"
              />
              <CommandList>
                <CommandEmpty>{t('No use case found.')}</CommandEmpty>
                <CommandGroup>
                  {SITE_TEMPLATE_USE_CASE_OPTIONS.map((option) => (
                    <CommandItem
                      key={option.value}
                      value={option.value}
                      onSelect={() => {
                        setSelectedUseCase(option.value)
                        setRequestedPage(1)
                        setDisplayedPage(1)
                        setUseCaseOpen(false)
                      }}
                    >
                      {t(option.label)}
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>

        <Popover open={frameworkOpen} onOpenChange={setFrameworkOpen} modal>
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              role="combobox"
              aria-expanded={frameworkOpen}
              className="h-9 w-[160px] justify-between text-[13px] font-normal"
            >
              <span className="flex items-center gap-2 truncate">
                {selectedFramework !== 'all' ? (
                  <FrameworkIcon framework={selectedFramework} size="sm" />
                ) : null}
                <span className="truncate capitalize">
                  {selectedFramework === 'all'
                    ? t('All frameworks')
                    : frameworkOptions.find(
                        (option) => option.value === selectedFramework,
                      )?.label || t('All frameworks')}
                </span>
              </span>
              <ChevronsUpDown className="ms-2 size-3.5 shrink-0 opacity-50" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-[220px] p-0" align="start" sideOffset={4}>
            <Command>
              <CommandInput
                placeholder={t('Search frameworks...')}
                className="h-9"
              />
              <CommandList>
                <CommandEmpty>{t('No framework found.')}</CommandEmpty>
                <CommandGroup>
                  {frameworkOptions.map((option) => (
                    <CommandItem
                      key={option.value}
                      value={option.label}
                      onSelect={() => {
                        setSelectedFramework(option.value)
                        setRequestedPage(1)
                        setDisplayedPage(1)
                        setFrameworkOpen(false)
                      }}
                    >
                      {option.value !== 'all' ? (
                        <FrameworkIcon
                          framework={option.value}
                          size="sm"
                          className="me-2"
                        />
                      ) : null}
                      <span className="capitalize">
                        {option.value === 'all' ? t(option.label) : option.label}
                      </span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      </div>

      {showTemplatesLoading ? (
        <div className={cn('grid', gridClassName)}>
          {Array.from({ length: pageSize }).map((_, index) => (
            <SiteTemplateCardSkeleton key={index} compact={compact} />
          ))}
        </div>
      ) : showFixedHeightGrid ? (
        <div className="relative">
          <div
            className={cn(
              'grid',
              gridClassName,
              templatesFetching && 'pointer-events-none opacity-60',
            )}
          >
            {filteredTemplates.map((template) => (
              <SiteTemplateCard
                key={template.key}
                template={template}
                isDark={isDark}
                size={compact ? 'compact' : 'default'}
                interactive={!!onSelectTemplate}
                onSelect={
                  onSelectTemplate
                    ? () => onSelectTemplate(template)
                    : undefined
                }
              />
            ))}
            {Array.from({ length: emptyGridSlots }).map((_, index) => (
              <div
                key={`grid-placeholder-${index}`}
                className={cn(cardHeightClass, 'invisible pointer-events-none')}
                aria-hidden
              />
            ))}
          </div>
          {filteredTemplates.length === 0 ? (
            <div className="absolute inset-0 flex items-center justify-center">
              <p className="text-[12px] text-muted-foreground">{emptyMessage}</p>
            </div>
          ) : null}
        </div>
      ) : filteredTemplates.length > 0 ? (
        <div
          className={cn(
            'grid',
            gridClassName,
            templatesFetching && 'pointer-events-none opacity-60',
          )}
        >
          {filteredTemplates.map((template) => (
            <SiteTemplateCard
              key={template.key}
              template={template}
              isDark={isDark}
              size={compact ? 'compact' : 'default'}
              interactive={!!onSelectTemplate}
              onSelect={
                onSelectTemplate
                  ? () => onSelectTemplate(template)
                  : undefined
              }
            />
          ))}
        </div>
      ) : (
        <div className="py-8 text-center">
          <p className="text-[12px] text-muted-foreground">{emptyMessage}</p>
        </div>
      )}

      {templatesTotal > pageSize ? (
        <Pagination
          currentPage={displayedPage}
          totalItems={templatesTotal}
          pageSize={pageSize}
          pageSizeOptions={[...pageSizeOptions]}
          scrollToTopOnPageChange={scrollToTopOnPageChange}
          onPageChange={setRequestedPage}
          onPageSizeChange={(size) => {
            setPageSize(size)
            setRequestedPage(1)
            setDisplayedPage(1)
          }}
        />
      ) : null}
    </div>
  )
}
