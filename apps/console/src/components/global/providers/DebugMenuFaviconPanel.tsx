import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useParams, useRouterState } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { useFavicon } from '@/hooks/use-favicon'
import { useProject } from '@/lib/react-query/hooks'
import {
  FAVICON_SOURCE_LABELS,
  FAVICON_VARIANT_LABELS,
  getFaviconStatus,
  isBlueFaviconVariant,
  isStatusFaviconVariant,
  resolveFaviconHref,
  subscribeFaviconStatus,
  type FaviconStatus,
  type FaviconVariant,
} from '@/lib/favicon'

const MANUAL_VARIANTS: ReadonlyArray<{
  label: string
  value: FaviconVariant
}> = [
  { label: 'Default', value: 'default' },
  { label: 'Green', value: 'green' },
  { label: 'Blue', value: 'blue' },
  { label: 'Red', value: 'red' },
  { label: 'Theme', value: 'theme' },
  { label: 'Theme + Green', value: 'theme-green' },
  { label: 'Theme + Blue', value: 'theme-blue' },
  { label: 'Theme + Red', value: 'theme-red' },
]

function variantToneClass(variant: FaviconVariant): string {
  if (variant === 'blue' || variant === 'theme-blue') {
    return 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300'
  }
  if (variant === 'green' || variant === 'theme-green') {
    return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
  }
  if (variant === 'red' || variant === 'theme-red') {
    return 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
  }
  return 'border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40 text-foreground'
}

function FaviconPreview({
  variant,
  size = 'md',
  className,
  alt,
}: {
  variant: FaviconVariant
  size?: 'sm' | 'md' | 'lg'
  className?: string
  alt?: string
}) {
  const { href } = resolveFaviconHref(variant)
  const frameClass =
    size === 'lg'
      ? 'h-12 w-12 p-2'
      : size === 'sm'
        ? 'h-7 w-7 p-1.5'
        : 'h-9 w-9 p-2'

  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center justify-center rounded-md border border-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--border))] bg-[repeating-conic-gradient(#80808014_0%_25%,transparent_0%_50%)] bg-[length:8px_8px] bg-background',
        frameClass,
        className,
      )}
      aria-hidden={alt ? undefined : true}
    >
      <img
        src={href}
        alt={alt ?? ''}
        className="h-full w-full object-contain"
        draggable={false}
      />
    </span>
  )
}

function StatusRow({
  label,
  value,
  mono = false,
  leading,
}: {
  label: string
  value: string
  mono?: boolean
  leading?: ReactNode
}) {
  return (
    <div className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-3 px-3 py-2">
      <dt className="text-[11px] font-medium text-[var(--network-globe-edge)]/80">
        {label}
      </dt>
      <dd
        className={cn(
          'flex min-w-0 items-center gap-2 break-all text-[12px] leading-relaxed text-foreground',
          mono && 'font-mono text-[11px]',
        )}
      >
        {leading}
        <span className="min-w-0">{value}</span>
      </dd>
    </div>
  )
}

function Section({
  title,
  children,
}: {
  title: string
  children: ReactNode
}) {
  return (
    <section className="space-y-1.5">
      <h3 className="px-1 text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80">
        {title}
      </h3>
      <div className="overflow-hidden rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--border))] bg-muted/20">
        {children}
      </div>
    </section>
  )
}

export function DebugMenuFaviconPanel() {
  const { setFavicon } = useFavicon()
  const params = useParams({ strict: false }) as {
    projectId?: string
    orgId?: string
    teamId?: string
  }
  const pathname = useRouterState({ select: (s) => s.location.pathname })
  const [status, setStatus] = useState<FaviconStatus>(() => getFaviconStatus())
  const [colorSchemeTick, setColorSchemeTick] = useState(0)

  useEffect(() => subscribeFaviconStatus(setStatus), [])

  // Theme status PNGs swap with prefers-color-scheme; re-render previews on change.
  useEffect(() => {
    if (typeof window === 'undefined') return
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const onChange = () => setColorSchemeTick((n) => n + 1)
    media.addEventListener('change', onChange)
    return () => media.removeEventListener('change', onChange)
  }, [])

  const routeProjectId = params.projectId?.trim() || undefined
  const statusProjectId = status.context?.projectId?.trim() || undefined
  const projectId = statusProjectId || routeProjectId
  const { project } = useProject(projectId)

  const projectName =
    status.context?.projectName?.trim() ||
    project?.name?.trim() ||
    undefined
  const organizationId =
    status.context?.organizationId?.trim() ||
    params.orgId?.trim() ||
    params.teamId?.trim() ||
    project?.teamId?.trim() ||
    undefined

  const updatedLabel = useMemo(() => {
    if (!status.updatedAt) return 'Never'
    return new Date(status.updatedAt).toLocaleString()
  }, [status.updatedAt])

  const isBlue = isBlueFaviconVariant(status.variant)
  const isStatus = isStatusFaviconVariant(status.variant)
  const resources = status.context?.resources ?? []
  const fields = status.context?.fields ?? []
  const currentHref = resolveFaviconHref(status.variant).href

  return (
    <div
      className="space-y-4 px-1 py-1"
      aria-label="Favicon status"
      // Force preview remount when OS color scheme flips theme status assets.
      key={`favicon-previews-${colorSchemeTick}`}
    >
      <div
        className={cn(
          'space-y-2 rounded-lg border px-3 py-3',
          variantToneClass(status.variant),
        )}
      >
        <div className="flex items-start gap-3">
          <FaviconPreview
            variant={status.variant}
            size="lg"
            alt={`${FAVICON_VARIANT_LABELS[status.variant]} favicon`}
            className="mt-0.5"
          />
          <div className="min-w-0 space-y-1">
            <p className="text-[13px] font-semibold">
              {FAVICON_VARIANT_LABELS[status.variant] ?? status.variant}
              {isBlue ? ' status dot' : isStatus ? ' status' : ''}
            </p>
            <p className="text-[12px] leading-relaxed opacity-90">
              {status.reason}
            </p>
            <p className="font-mono text-[10px] opacity-70">{currentHref}</p>
          </div>
        </div>
      </div>

      <Section title="Variants">
        <div className="grid grid-cols-2 gap-1.5 p-2 sm:grid-cols-4">
          {MANUAL_VARIANTS.map((opt) => {
            const active = status.variant === opt.value
            return (
              <button
                key={opt.value}
                type="button"
                onClick={() => {
                  setFavicon(opt.value, {
                    source: 'debug-menu',
                    reason: `Manually set to ${opt.label}`,
                    context: {
                      projectId,
                      projectName,
                      organizationId,
                      pathname,
                    },
                  })
                }}
                className={cn(
                  'flex flex-col items-center gap-2 rounded-md border px-2.5 py-3 text-[11px] font-medium transition-colors',
                  active
                    ? 'border-[var(--network-globe-edge)]/40 bg-[color-mix(in_srgb,var(--network-globe-edge)_14%,transparent)] text-foreground'
                    : 'border-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--border))] bg-background/40 text-[var(--network-globe-edge)] hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)] hover:text-foreground',
                )}
              >
                <FaviconPreview variant={opt.value} size="md" alt={opt.label} />
                <span className="text-center text-[10px]">{opt.label}</span>
              </button>
            )
          })}
        </div>
      </Section>

      <Section title="Status">
        <dl className="divide-y divide-[color-mix(in_srgb,var(--network-globe-edge)_12%,var(--border))]">
          <StatusRow
            label="Variant"
            value={FAVICON_VARIANT_LABELS[status.variant] ?? status.variant}
            leading={<FaviconPreview variant={status.variant} size="sm" />}
          />
          <StatusRow
            label="Source"
            value={FAVICON_SOURCE_LABELS[status.source] ?? status.source}
          />
          <StatusRow label="Trigger" value={status.reason} />
          <StatusRow label="Updated" value={updatedLabel} />
          <StatusRow label="Asset" value={currentHref} mono />
          {status.detail ? (
            <StatusRow label="Summary" value={status.detail} mono />
          ) : null}
        </dl>
      </Section>

      <Section title="Project">
        <dl className="divide-y divide-[color-mix(in_srgb,var(--network-globe-edge)_12%,var(--border))]">
          <StatusRow label="Name" value={projectName || '—'} />
          <StatusRow label="Project ID" value={projectId || '—'} mono />
          <StatusRow label="Organization" value={organizationId || '—'} mono />
          <StatusRow
            label="Route"
            value={status.context?.pathname || pathname || '—'}
            mono
          />
          {!statusProjectId && routeProjectId ? (
            <StatusRow
              label="Note"
              value="Project inferred from current route (trigger did not include project context)."
            />
          ) : null}
          {!projectId ? (
            <StatusRow
              label="Note"
              value="No project in favicon context or current route."
            />
          ) : null}
        </dl>
      </Section>

      {status.context?.conversationId || fields.length > 0 ? (
        <Section title="Conversation">
          <dl className="divide-y divide-[color-mix(in_srgb,var(--network-globe-edge)_12%,var(--border))]">
            {status.context?.conversationId ? (
              <StatusRow
                label="Conversation ID"
                value={status.context.conversationId}
                mono
              />
            ) : null}
            {fields.map((field) => (
              <StatusRow
                key={`${field.label}-${field.value}`}
                label={field.label}
                value={field.value}
                mono={/id|status|lock/i.test(field.label)}
              />
            ))}
          </dl>
        </Section>
      ) : null}

      {resources.length > 0 ? (
        <Section title={`Active resources (${resources.length})`}>
          <ul className="divide-y divide-[color-mix(in_srgb,var(--network-globe-edge)_12%,var(--border))]">
            {resources.map((resource) => (
              <li
                key={`${resource.type}-${resource.id}-${resource.deploymentId ?? ''}`}
                className="space-y-1 px-3 py-2.5"
              >
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className="rounded border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-background/60 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[var(--network-globe-edge)]">
                    {resource.type}
                  </span>
                  <span className="text-[12px] font-medium text-foreground">
                    {resource.name || resource.id}
                  </span>
                  {resource.status ? (
                    <span className="text-[11px] text-[var(--network-globe-edge)]/80">
                      {resource.status}
                    </span>
                  ) : null}
                </div>
                <div className="space-y-0.5 font-mono text-[11px] text-[var(--network-globe-edge)]/85">
                  <p>ID: {resource.id}</p>
                  {resource.deploymentId ? (
                    <p>Deployment: {resource.deploymentId}</p>
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        </Section>
      ) : null}
    </div>
  )
}
