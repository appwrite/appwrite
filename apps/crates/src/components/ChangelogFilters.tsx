import { Link } from '@tanstack/react-router'
import { X } from 'lucide-react'

const chip =
  'inline-flex h-8 items-center rounded-md border px-2.5 text-[12.5px] transition-colors aria-pressed:border-foreground aria-pressed:bg-accent aria-pressed:text-foreground'

type PackageOption = { slug: string; label: string }

export function ChangelogFilters({
  options,
  selected,
}: {
  options: PackageOption[]
  selected: string | undefined
}) {
  if (!options.length) return null

  return (
    <div className="grid gap-3">
      <div className="flex h-8 items-center justify-between gap-3">
        <h2 className="label text-muted-foreground">Packages</h2>
        {selected ? (
          <Link
            to="/changelog"
            search={{}}
            replace
            resetScroll={false}
            className="inline-flex h-8 items-center gap-1.5 text-[12.5px] text-muted-foreground transition-colors hover:text-foreground"
          >
            <X className="size-3.5" aria-hidden />
            Clear
          </Link>
        ) : null}
      </div>
      <div className="flex flex-wrap gap-2">
        {options.map(({ slug, label }) => (
          <Link
            key={slug}
            to="/changelog"
            search={{ package: slug }}
            replace
            resetScroll={false}
            aria-pressed={selected === slug}
            className={`${chip} border-border text-muted-foreground hover:border-foreground/20 hover:text-foreground`}
          >
            {label}
          </Link>
        ))}
      </div>
    </div>
  )
}
