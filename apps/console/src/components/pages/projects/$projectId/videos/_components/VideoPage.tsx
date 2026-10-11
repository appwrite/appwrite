import type { ComponentProps, ReactNode } from 'react'
import { useParams } from '@tanstack/react-router'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useProjectVideo } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'
import { VideoTermHint } from './VideoTermHint'

/**
 * Page shell for video subviews and encoding profiles. Uses `ServiceHeader`
 * like database table views: title row, then a toolbar row (search/filters left,
 * primary create + secondary actions right).
 */
export function VideoPage({
  title,
  term,
  showVideoId = false,
  toolbar,
  searchPlaceholder,
  searchValue,
  onSearchChange,
  showRefresh = false,
  onRefresh,
  isRefreshing = false,
  showFilters = false,
  filterTrigger,
  actions,
  createLabel,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
  children,
  className,
  contentClassName,
  spreadsheetContent = false,
  hideToolbar = false,
}: {
  title: ReactNode
  term?: VideoGlossaryTerm
  showVideoId?: boolean
  /** Extra controls before search (legacy; prefer search/filter props on ServiceHeader). */
  toolbar?: ReactNode
  searchPlaceholder?: string
  searchValue?: string
  onSearchChange?: (value: string) => void
  showRefresh?: boolean
  onRefresh?: () => void
  isRefreshing?: boolean
  showFilters?: boolean
  filterTrigger?: ReactNode
  /** Buttons before the primary create action. */
  actions?: ReactNode
  createLabel?: string
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
  children: ReactNode
  className?: string
  contentClassName?: string
  /** Full-bleed spreadsheet body (no padded scroll area). */
  spreadsheetContent?: boolean
  /** Hides search, filters, and actions (e.g. on a first-run empty state). */
  hideToolbar?: boolean
}) {
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId?: string
  }
  const { data: video } = useProjectVideo(
    projectId,
    showVideoId ? videoId : undefined,
  )

  const showToolbar =
    !hideToolbar &&
    Boolean(
      toolbar ||
        onSearchChange ||
        filterTrigger ||
        showFilters ||
        showRefresh ||
        actions ||
        (createLabel && onCreate),
    )

  const titleNode = (
    <div className="flex min-w-0 items-center gap-2">
      <span className="flex min-w-0 items-center gap-1.5">
        <span className="truncate">{title}</span>
        {term ? <VideoTermHint term={term} side="bottom" /> : null}
      </span>
      {showVideoId && video ? (
        <CopyableId
          id={video.$id}
          size="xs"
          className="hidden shrink-0 sm:inline-flex"
        />
      ) : null}
    </div>
  )

  return (
    <div
      className={cn(
        '@container flex h-full min-h-0 min-w-0 flex-1 flex-col',
        className,
      )}
    >
      <ServiceHeader
        fullWidthBorder
        fullWidth
        title={titleNode}
        beforeSearchButtons={toolbar}
        searchPlaceholder={searchPlaceholder}
        searchValue={searchValue}
        onSearchChange={onSearchChange}
        showRefresh={showRefresh}
        onRefresh={onRefresh}
        isRefreshing={isRefreshing}
        showFilters={showFilters || Boolean(filterTrigger)}
        filterTrigger={filterTrigger}
        beforeCreateButtons={actions}
        createLabel={createLabel}
        onCreate={onCreate}
        createDisabled={createDisabled}
        createDisabledTooltip={createDisabledTooltip}
        hideToolbar={hideToolbar}
        showToolbarBottomBorder={showToolbar && !spreadsheetContent}
      />
      <div
        className={cn(
          'min-h-0 min-w-0 flex-1',
          spreadsheetContent
            ? 'flex min-h-0 flex-col overflow-hidden'
            : 'overflow-x-hidden overflow-y-auto [container-type:size]',
        )}
      >
        <div
          className={cn(
            spreadsheetContent
              ? 'flex min-h-0 min-w-0 flex-1 flex-col p-0'
              : 'min-w-0 w-full max-w-full space-y-5 px-4 py-4 sm:px-6 sm:py-5',
            contentClassName,
          )}
        >
          {children}
        </div>
      </div>
    </div>
  )
}

/** Settings-style card: header, separator, body, and optional footer. */
export function VideoSectionCard({
  title,
  term,
  description,
  actions,
  footer,
  children,
  bodyClassName,
}: {
  title: ReactNode
  term?: VideoGlossaryTerm
  description?: ReactNode
  actions?: ReactNode
  footer?: ReactNode
  children?: ReactNode
  bodyClassName?: string
}) {
  return (
    <section className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex items-start justify-between gap-3 px-6 py-4">
        <div className="min-w-0">
          <h3 className="flex items-center gap-1.5 text-[15px] font-semibold text-foreground">
            {title}
            {term ? <VideoTermHint term={term} /> : null}
          </h3>
          {description ? (
            <p className="mt-2 text-[13px] text-muted-foreground">
              {description}
            </p>
          ) : null}
        </div>
        {actions ? (
          <div className="flex shrink-0 items-center gap-2">{actions}</div>
        ) : null}
      </div>
      {children ? (
        <>
          <div className="border-t border-border" />
          <div className={cn('px-6 py-4', bodyClassName)}>{children}</div>
        </>
      ) : null}
      {footer ? (
        <div className="border-t border-border bg-muted/30 px-6 py-4">
          {footer}
        </div>
      ) : null}
    </section>
  )
}

/** Button that stays visible but disabled, explaining why in a tooltip. */
export function VideoActionButton({
  disabledReason,
  disabled,
  ...props
}: ComponentProps<typeof Button> & { disabledReason?: string | null }) {
  const t = useT()
  if (disabledReason) {
    return (
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">
            <Button {...props} disabled />
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-[260px] text-[12px]">
          {t(disabledReason)}
        </TooltipContent>
      </Tooltip>
    )
  }
  return <Button {...props} disabled={disabled} />
}

/** Label/value pair used in metadata grids. */
export function VideoFact({
  label,
  term,
  value,
  mono = false,
  breakAll = false,
}: {
  label: ReactNode
  term?: VideoGlossaryTerm
  value: ReactNode
  mono?: boolean
  breakAll?: boolean
}) {
  return (
    <div className="min-w-0">
      <dt className="flex items-center gap-1 text-[12px] text-muted-foreground">
        {label}
        {term ? <VideoTermHint term={term} /> : null}
      </dt>
      <dd
        className={cn(
          'mt-0.5 text-[13px] text-foreground',
          breakAll ? 'break-all' : 'truncate',
          mono && 'font-mono text-[12px]',
        )}
      >
        {value === '' || value == null ? '-' : value}
      </dd>
    </div>
  )
}
