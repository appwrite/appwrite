import { useEffect, useMemo, useState } from 'react'
import { Search } from 'lucide-react'
import { CoverIconPreview } from '@/components/pages/generator/_components/CoverIconPreview'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  COVER_BUILT_IN_ICON_CATEGORIES,
  getCoverBuiltInIconEntry,
  searchCoverBuiltInIcons,
  type CoverBuiltInIconEntry,
} from '@/lib/cover-generator/built-in-icons'
import {
  formatCoverLucideIconLabel,
  formatCoverLucideIconValue,
  getCoverLucideIconSearchMeta,
  isCoverLucideIconValue,
  parseCoverLucideIconName,
  searchCoverLucideIcons,
} from '@/lib/cover-generator/lucide-icon-utils'
import { loadCoverLucideIconNames } from '@/lib/cover-generator/lucide-icon-svg'
import { cn } from '@/lib/utils'

type CoverIconSource = 'brand' | 'lucide'

type CoverBuiltInIconPickerProps = {
  id: string
  label: string
  description?: string
  value: string
  isCustomImage: boolean
  onSelectBuiltIn: (path: string) => void
}

function IconPickerGrid({
  visibleIcons,
  selectedPath,
  isCustomImage,
  onSelect,
}: {
  visibleIcons: CoverBuiltInIconEntry[]
  selectedPath: string | null
  isCustomImage: boolean
  onSelect: (path: string) => void
}) {
  if (visibleIcons.length === 0) {
    return (
      <p className="px-2 py-10 text-center text-[13px] text-muted-foreground">
        No icons match your search.
      </p>
    )
  }

  return (
    <div className="grid grid-cols-4 gap-2 sm:grid-cols-5">
      {visibleIcons.map((icon) => {
        const selected = !isCustomImage && selectedPath === icon.path

        return (
          <button
            key={icon.path}
            type="button"
            title={icon.label}
            aria-label={icon.label}
            aria-pressed={selected}
            onClick={() => onSelect(icon.path)}
            className={cn(
              'flex aspect-square items-center justify-center rounded-md border p-2 transition-colors',
              selected
                ? 'border-foreground/30 bg-accent'
                : 'border-transparent hover:border-border hover:bg-accent/50',
            )}
          >
            <CoverIconPreview src={icon.path} colorMode="app" size={32} />
          </button>
        )
      })}
    </div>
  )
}

function LucideIconPickerGrid({
  visibleIcons,
  selectedName,
  isCustomImage,
  onSelect,
}: {
  visibleIcons: string[]
  selectedName: string | null
  isCustomImage: boolean
  onSelect: (value: string) => void
}) {
  if (visibleIcons.length === 0) {
    return (
      <p className="px-2 py-10 text-center text-[13px] text-muted-foreground">
        No Lucide icons match your search.
      </p>
    )
  }

  return (
    <div className="grid grid-cols-4 gap-2 sm:grid-cols-5">
      {visibleIcons.map((iconName) => {
        const selected = !isCustomImage && selectedName === iconName
        const value = formatCoverLucideIconValue(iconName)

        return (
          <button
            key={iconName}
            type="button"
            title={formatCoverLucideIconLabel(iconName)}
            aria-label={formatCoverLucideIconLabel(iconName)}
            aria-pressed={selected}
            onClick={() => onSelect(value)}
            className={cn(
              'flex aspect-square items-center justify-center rounded-md border p-2 transition-colors',
              selected
                ? 'border-foreground/30 bg-accent'
                : 'border-transparent hover:border-border hover:bg-accent/50',
            )}
          >
            <CoverIconPreview src={value} colorMode="app" size={32} />
          </button>
        )
      })}
    </div>
  )
}

export function CoverBuiltInIconPicker({
  id,
  label,
  description,
  value,
  isCustomImage,
  onSelectBuiltIn,
}: CoverBuiltInIconPickerProps) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [categoryId, setCategoryId] = useState<string>('all')
  const [source, setSource] = useState<CoverIconSource>(() =>
    isCoverLucideIconValue(value) ? 'lucide' : 'brand',
  )
  const [lucideIconNames, setLucideIconNames] = useState<readonly string[] | null>(null)

  useEffect(() => {
    if (!open) return

    void loadCoverLucideIconNames().then((names) => {
      setLucideIconNames(names)
    })
  }, [open])

  const selectedIcon = isCustomImage ? null : getCoverBuiltInIconEntry(value)
  const selectedLucideName = isCustomImage ? null : parseCoverLucideIconName(value)

  const visibleBrandIcons = useMemo(() => {
    const searched = searchCoverBuiltInIcons(query)
    if (categoryId === 'all') return searched

    const category = COVER_BUILT_IN_ICON_CATEGORIES.find((item) => item.id === categoryId)
    if (!category) return searched

    const categoryPaths = new Set(category.icons.map((icon) => icon.path))
    return searched.filter((icon) => categoryPaths.has(icon.path))
  }, [categoryId, query])

  const visibleLucideIcons = useMemo(
    () => searchCoverLucideIcons(query, lucideIconNames),
    [lucideIconNames, query],
  )
  const lucideSearchMeta = useMemo(
    () => getCoverLucideIconSearchMeta(query, lucideIconNames),
    [lucideIconNames, query],
  )

  const handleOpenChange = (nextOpen: boolean) => {
    setOpen(nextOpen)
    if (nextOpen) {
      setSource(isCoverLucideIconValue(value) ? 'lucide' : 'brand')
      return
    }

    setQuery('')
    setCategoryId('all')
  }

  const handleSelect = (path: string) => {
    onSelectBuiltIn(path)
    handleOpenChange(false)
  }

  const selectedLabel =
    selectedLucideName != null
      ? formatCoverLucideIconLabel(selectedLucideName)
      : selectedIcon?.label
  const selectedPath =
    selectedLucideName != null
      ? formatCoverLucideIconValue(selectedLucideName)
      : selectedIcon?.path

  return (
    <div className="space-y-2">
      <div>
        <Label htmlFor={`${id}-choose`} className="text-[13px]">
          {label}
        </Label>
        {description ? (
          <p className="mt-1 text-[12px] text-muted-foreground">{description}</p>
        ) : null}
      </div>

      <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/20 p-3">
        {selectedPath && !isCustomImage ? (
          <CoverIconPreview src={selectedPath} colorMode="app" size={40} />
        ) : (
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-dashed border-border bg-background text-[11px] text-muted-foreground">
            None
          </div>
        )}
        <div className="min-w-0 flex-1">
          {selectedLabel && selectedPath && !isCustomImage ? (
            <>
              <p className="truncate text-[13px] font-medium text-foreground">
                {selectedLabel}
              </p>
              <p className="truncate text-[12px] text-muted-foreground">{selectedPath}</p>
            </>
          ) : (
            <p className="text-[13px] text-muted-foreground">No icon selected</p>
          )}
        </div>
        <Button
          id={`${id}-choose`}
          type="button"
          variant="outline"
          size="sm"
          className="h-8 shrink-0 text-[12px]"
          onClick={() => setOpen(true)}
        >
          Choose icon
        </Button>
      </div>

      <Dialog open={open} onOpenChange={handleOpenChange}>
        <DialogContent className="flex max-h-[min(85dvh,640px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{label}</DialogTitle>
            {description ? (
              <DialogDescription className="mt-2 text-[13px]">
                {description}
              </DialogDescription>
            ) : null}
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="space-y-3 overflow-y-auto px-6 py-4">
            <div className="flex flex-wrap gap-1.5">
              <SourceChip
                active={source === 'brand'}
                onClick={() => setSource('brand')}
                label="Brand icons"
              />
              <SourceChip
                active={source === 'lucide'}
                onClick={() => setSource('lucide')}
                label="Lucide"
              />
            </div>

            <div className="relative">
              <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                id={`${id}-search`}
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={
                  source === 'lucide' ? 'Search Lucide icons...' : 'Search brand icons...'
                }
                className="h-9 ps-8 text-[13px]"
              />
            </div>

            {source === 'brand' ? (
              <>
                <div className="flex flex-wrap gap-1.5">
                  <CategoryChip
                    active={categoryId === 'all'}
                    onClick={() => setCategoryId('all')}
                    label="All"
                  />
                  {COVER_BUILT_IN_ICON_CATEGORIES.map((category) => (
                    <CategoryChip
                      key={category.id}
                      active={categoryId === category.id}
                      onClick={() => setCategoryId(category.id)}
                      label={category.label}
                    />
                  ))}
                </div>

                <IconPickerGrid
                  visibleIcons={visibleBrandIcons}
                  selectedPath={selectedIcon?.path ?? null}
                  isCustomImage={isCustomImage}
                  onSelect={handleSelect}
                />
              </>
            ) : (
              <>
                {!query.trim() ? (
                  <p className="text-[12px] text-muted-foreground">
                    Popular Lucide icons. Search to browse the full library.
                  </p>
                ) : !lucideSearchMeta.isLibraryLoaded ? (
                  <p className="text-[12px] text-muted-foreground">
                    Searching popular icons while the full Lucide library loads.
                  </p>
                ) : lucideSearchMeta.isLimited ? (
                  <p className="text-[12px] text-muted-foreground">
                    Showing {visibleLucideIcons.length} of {lucideSearchMeta.totalMatches}{' '}
                    matches. Refine your search to narrow results.
                  </p>
                ) : null}

                <LucideIconPickerGrid
                  visibleIcons={visibleLucideIcons}
                  selectedName={selectedLucideName}
                  isCustomImage={isCustomImage}
                  onSelect={handleSelect}
                />
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function SourceChip({
  active,
  onClick,
  label,
}: {
  active: boolean
  onClick: () => void
  label: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'rounded-md border px-2.5 py-1 text-[11px] font-medium transition-colors',
        active
          ? 'border-foreground/30 bg-accent text-foreground'
          : 'border-border text-muted-foreground hover:bg-accent/50 hover:text-foreground',
      )}
    >
      {label}
    </button>
  )
}

function CategoryChip({
  active,
  onClick,
  label,
}: {
  active: boolean
  onClick: () => void
  label: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'rounded-md px-1.5 py-0.5 text-[10px] font-medium transition-colors',
        active
          ? 'bg-accent text-foreground'
          : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
      )}
    >
      {label}
    </button>
  )
}
