import {
  memo,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  AlertCircle,
  AlertTriangle,
  Bold,
  Captions,
  ChevronLeft,
  ChevronRight,
  Combine,
  Italic,
  MoveHorizontal,
  Play,
  Plus,
  Trash2,
  type LucideIcon,
} from 'lucide-react'
import { TimestampInput } from './TimestampInput'
import { usePlaybackValue } from './playback-clock'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  activeSubtitleCues,
  cueCharsPerSecond,
  formatEditorTimestamp,
  stripCueMarkup,
  SUBTITLE_CUE_ISSUE_LABELS,
  SUBTITLE_MAX_CHARS_PER_SECOND,
  SUBTITLE_MAX_LINE_LENGTH,
  SUBTITLE_MIN_CUE_DURATION,
  subtitleTextDirection,
  type SubtitleCue,
  type SubtitleCueIssue,
} from '@/lib/videos/subtitle-cues'

export type CuePatch = Partial<Pick<SubtitleCue, 'start' | 'end' | 'text'>>

export const SUBTITLE_CUE_TEXT_INPUT_ID = 'subtitle-cue-text'

export function SubtitleCuePanel({
  cues,
  issues,
  selectedIds,
  primaryId,
  languageCode,
  getPlayhead,
  onSelect,
  onChangeCue,
  onDelete,
  onMergeNext,
  onShift,
  onPrevious,
  onNext,
  onAddCue,
}: {
  cues: SubtitleCue[]
  issues: Map<string, SubtitleCueIssue[]>
  selectedIds: ReadonlySet<string>
  primaryId: string | null
  languageCode: string
  getPlayhead: () => number
  onSelect: (id: string, modifiers: { additive: boolean; range: boolean }) => void
  onChangeCue: (id: string, patch: CuePatch, coalesceKey?: string) => void
  onDelete: (ids: string[]) => void
  onMergeNext: (id: string) => void
  onShift: () => void
  onPrevious: () => void
  onNext: () => void
  onAddCue: () => void
}) {
  const t = useT()
  const [search, setSearch] = useState('')
  const [issuesOnly, setIssuesOnly] = useState(false)
  const indexById = useMemo(
    () => new Map(cues.map((cue, index) => [cue.id, index])),
    [cues],
  )
  const primary = primaryId ? cues[indexById.get(primaryId) ?? -1] : undefined
  const primaryIndex = primary ? (indexById.get(primary.id) ?? 0) : -1

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase()
    return cues.filter((cue) => {
      if (issuesOnly && !issues.has(cue.id)) return false
      if (!query) return true
      return stripCueMarkup(cue.text).toLowerCase().includes(query)
    })
  }, [cues, issues, issuesOnly, search])

  const issueCount = issues.size

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div data-subtitle-keep-selection className="shrink-0 border-b border-border p-4">
        {selectedIds.size > 1 || !primary ? (
          <ReservedInspectorSpace>
            {selectedIds.size > 1 ? (
              <MultiSelection
                count={selectedIds.size}
                onShift={onShift}
                onDelete={() => onDelete([...selectedIds])}
              />
            ) : (
              <div className="flex h-full items-center justify-center gap-3 rounded-lg border border-dashed border-border px-4 py-3">
                <Captions className="h-4 w-4 shrink-0 text-muted-foreground" />
                <p className="text-[12px] text-muted-foreground">
                  {cues.length === 0
                    ? t('No cues yet. Play the video and press N to add a cue at the playhead, or import a WebVTT or SRT file.')
                    : t('Select a cue in the list or on the timeline to update it.')}
                </p>
              </div>
            )}
          </ReservedInspectorSpace>
        ) : (
          <CueInspector
            key={primary.id}
            cue={primary}
            index={primaryIndex}
            total={cues.length}
            hasNext={primaryIndex < cues.length - 1}
            issues={issues.get(primary.id) ?? []}
            languageCode={languageCode}
            getPlayhead={getPlayhead}
            onChange={(patch, key) => onChangeCue(primary.id, patch, key)}
            onDelete={() => onDelete([primary.id])}
            onMergeNext={() => onMergeNext(primary.id)}
            onPrevious={onPrevious}
            onNext={onNext}
          />
        )}
      </div>

      <div className="flex shrink-0 items-center gap-2 px-4 py-2">
        <Input
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder={t('Search cues...')}
          className="h-8 min-w-0 flex-1 text-[12px]"
        />
        <label className="flex shrink-0 items-center gap-1.5 text-[12px] text-muted-foreground">
          <Switch
            checked={issuesOnly}
            onCheckedChange={setIssuesOnly}
            aria-label={t('Only cues with issues')}
          />
          {t('Issues')}
          <span className="tabular-nums">({issueCount})</span>
        </label>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="icon"
              data-subtitle-keep-selection
              className="h-8 w-8 shrink-0"
              aria-label={t('Add cue')}
              onClick={onAddCue}
            >
              <Plus className="h-3.5 w-3.5" />
            </Button>
          </TooltipTrigger>
          <TooltipContent className="text-[12px]">{t('Add cue')}</TooltipContent>
        </Tooltip>
      </div>

      <CueList
        cues={filtered}
        indexById={indexById}
        allCues={cues}
        issues={issues}
        selectedIds={selectedIds}
        primaryId={primaryId}
        languageCode={languageCode}
        onSelect={onSelect}
        emptyLabel={
          cues.length === 0
            ? t('No cues')
            : t('No cues match your search')
        }
      />
    </div>
  )
}

const PLACEHOLDER_CUE: SubtitleCue = { id: '', start: 0, end: 1, text: '' }
const noop = () => {}

/** Sizes its content to an invisible cue inspector so swapping them never shifts the layout. */
function ReservedInspectorSpace({ children }: { children: ReactNode }) {
  return (
    <div className="grid">
      <div aria-hidden className="invisible [grid-area:1/1]">
        <CueInspector
          ghost
          cue={PLACEHOLDER_CUE}
          index={0}
          total={1}
          hasNext={false}
          issues={[]}
          languageCode=""
          getPlayhead={() => 0}
          onChange={noop}
          onDelete={noop}
          onMergeNext={noop}
          onPrevious={noop}
          onNext={noop}
        />
      </div>
      <div className="min-h-0 [grid-area:1/1]">{children}</div>
    </div>
  )
}

function MultiSelection({
  count,
  onShift,
  onDelete,
}: {
  count: number
  onShift: () => void
  onDelete: () => void
}) {
  const t = useT()
  return (
    <div className="space-y-3">
      <p className="text-[13px] font-medium text-foreground">
        {t('{count} cues selected').replace('{count}', String(count))}
      </p>
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 gap-1.5 text-[12px]"
          onClick={onShift}
        >
          <MoveHorizontal className="h-3.5 w-3.5" />
          {t('Shift timings')}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 gap-1.5 text-[12px]"
          onClick={onDelete}
        >
          <Trash2 className="h-3.5 w-3.5" />
          {t('Delete')}
        </Button>
      </div>
    </div>
  )
}

function CueInspector({
  cue,
  index,
  total,
  hasNext,
  issues,
  languageCode,
  getPlayhead,
  onChange,
  onDelete,
  onMergeNext,
  onPrevious,
  onNext,
  ghost = false,
}: {
  cue: SubtitleCue
  index: number
  total: number
  hasNext: boolean
  issues: SubtitleCueIssue[]
  languageCode: string
  getPlayhead: () => number
  onChange: (patch: CuePatch, coalesceKey?: string) => void
  onDelete: () => void
  onMergeNext: () => void
  onPrevious: () => void
  onNext: () => void
  /** Invisible copy used only to reserve layout space. */
  ghost?: boolean
}) {
  const t = useT()
  const idPrefix = ghost ? 'ghost-' : ''
  const textareaRef = useRef<HTMLTextAreaElement | null>(null)
  const plain = stripCueMarkup(cue.text)
  const lines = plain.split('\n')
  const longestLine = Math.max(0, ...lines.map((line) => line.length))
  const cps = cueCharsPerSecond(cue)
  const duration = cue.end - cue.start
  const invalidTiming = issues.some((issue) => issue.kind === 'invalid-timing')

  const wrapSelection = (tag: 'i' | 'b') => {
    const el = textareaRef.current
    if (!el) return
    const { selectionStart, selectionEnd, value } = el
    const selected = value.slice(selectionStart, selectionEnd)
    const next = `${value.slice(0, selectionStart)}<${tag}>${selected}</${tag}>${value.slice(selectionEnd)}`
    onChange({ text: next })
    requestAnimationFrame(() => {
      el.focus()
      const offset = tag.length + 2
      el.setSelectionRange(selectionStart + offset, selectionEnd + offset)
    })
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-1">
        <IconAction
          icon={ChevronLeft}
          iconClassName="rtl:rotate-180"
          label={t('Previous cue')}
          disabled={index === 0}
          onClick={onPrevious}
        />
        <p className="min-w-0 truncate text-[13px] font-medium tabular-nums text-foreground">
          {t('Cue {index} of {total}')
            .replace('{index}', String(index + 1))
            .replace('{total}', String(total))}
        </p>
        <IconAction
          icon={ChevronRight}
          iconClassName="rtl:rotate-180"
          label={t('Next cue')}
          disabled={!hasNext}
          onClick={onNext}
        />
        <div className="ms-auto flex items-center gap-1">
          <IconAction
            icon={Italic}
            label={t('Italic')}
            onClick={() => wrapSelection('i')}
          />
          <IconAction
            icon={Bold}
            label={t('Bold')}
            onClick={() => wrapSelection('b')}
          />
          <IconAction
            icon={Combine}
            label={t('Merge with next cue')}
            disabled={!hasNext}
            onClick={onMergeNext}
          />
          <IconAction icon={Trash2} label={t('Delete cue')} onClick={onDelete} />
        </div>
      </div>
      <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,0.75fr)] gap-2">
        <TimestampInput
          id={`${idPrefix}subtitle-cue-start`}
          label={t('Start')}
          value={cue.start}
          invalid={invalidTiming}
          onChange={(start) => onChange({ start }, `start:${cue.id}`)}
          onUsePlayhead={() => onChange({ start: getPlayhead() })}
          usePlayheadLabel={t('Set start to playhead')}
        />
        <TimestampInput
          id={`${idPrefix}subtitle-cue-end`}
          label={t('End')}
          value={cue.end}
          invalid={invalidTiming}
          onChange={(end) => onChange({ end }, `end:${cue.id}`)}
          onUsePlayhead={() => onChange({ end: getPlayhead() })}
          usePlayheadLabel={t('Set end to playhead')}
        />
        <TimestampInput
          id={`${idPrefix}subtitle-cue-duration`}
          label={t('Duration')}
          mode="duration"
          min={SUBTITLE_MIN_CUE_DURATION}
          value={Math.max(0, duration)}
          onChange={(value) =>
            onChange(
              { end: cue.start + Math.max(SUBTITLE_MIN_CUE_DURATION, value) },
              `end:${cue.id}`,
            )
          }
        />
      </div>
      <Textarea
        ref={textareaRef}
        id={ghost ? undefined : SUBTITLE_CUE_TEXT_INPUT_ID}
        value={cue.text}
        dir={subtitleTextDirection(languageCode)}
        lang={languageCode || undefined}
        rows={3}
        aria-label={t('Cue text')}
        placeholder={t('Cue text')}
        onChange={(event) =>
          onChange({ text: event.target.value }, `text:${cue.id}`)
        }
        className="min-h-[72px] resize-y text-[13px] leading-snug"
      />
      <div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] tabular-nums text-muted-foreground">
        <span
          className={cn(
            cps > SUBTITLE_MAX_CHARS_PER_SECOND &&
              'text-amber-600 dark:text-amber-400',
          )}
        >
          {t('{value} chars/sec').replace('{value}', cps.toFixed(1))}
        </span>
        <span
          className={cn(
            longestLine > SUBTITLE_MAX_LINE_LENGTH &&
              'text-amber-600 dark:text-amber-400',
          )}
        >
          {t('Longest line: {value}').replace('{value}', String(longestLine))}
        </span>
        <span>
          {t('Lines: {value}').replace('{value}', String(plain ? lines.length : 0))}
        </span>
      </div>
      <CueIssueLine issues={issues} />
    </div>
  )
}

/** One fixed-height line so cues with and without issues share the same layout. */
function CueIssueLine({ issues }: { issues: SubtitleCueIssue[] }) {
  const t = useT()
  const sorted = [...issues].sort(
    (a, b) => Number(b.severity === 'error') - Number(a.severity === 'error'),
  )
  const first = sorted[0]
  const rest = sorted.slice(1)
  return (
    <div className="flex h-[18px] items-center gap-1.5 text-[12px]">
      {first ? (
        <>
          <span
            className={cn(
              'flex min-w-0 items-center gap-1.5',
              first.severity === 'error'
                ? 'text-destructive'
                : 'text-amber-600 dark:text-amber-400',
            )}
          >
            {first.severity === 'error' ? (
              <AlertCircle className="h-3.5 w-3.5 shrink-0" />
            ) : (
              <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
            )}
            <span className="truncate">{t(SUBTITLE_CUE_ISSUE_LABELS[first.kind])}</span>
          </span>
          {rest.length > 0 ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="shrink-0 rounded px-1 text-[11px] font-medium text-muted-foreground hover:text-foreground"
                >
                  {t('+{count} more').replace('{count}', String(rest.length))}
                </button>
              </TooltipTrigger>
              <TooltipContent className="max-w-[280px] text-[12px]">
                <ul className="space-y-1">
                  {rest.map((issue) => (
                    <li key={issue.kind}>{t(SUBTITLE_CUE_ISSUE_LABELS[issue.kind])}</li>
                  ))}
                </ul>
              </TooltipContent>
            </Tooltip>
          ) : null}
        </>
      ) : null}
    </div>
  )
}

function CueList({
  cues,
  allCues,
  indexById,
  issues,
  selectedIds,
  primaryId,
  languageCode,
  onSelect,
  emptyLabel,
}: {
  cues: SubtitleCue[]
  allCues: SubtitleCue[]
  indexById: Map<string, number>
  issues: Map<string, SubtitleCueIssue[]>
  selectedIds: ReadonlySet<string>
  primaryId: string | null
  languageCode: string
  onSelect: (id: string, modifiers: { additive: boolean; range: boolean }) => void
  emptyLabel: string
}) {
  const listRef = useRef<HTMLDivElement | null>(null)
  const activeKey = usePlaybackValue((clock) =>
    activeSubtitleCues(allCues, clock.time)
      .map((cue) => cue.id)
      .join(','),
  )
  const activeIds = useMemo(
    () => new Set(activeKey.split(',').filter(Boolean)),
    [activeKey],
  )
  const textDir = subtitleTextDirection(languageCode)

  useEffect(() => {
    if (!primaryId) return
    listRef.current
      ?.querySelector<HTMLElement>(`[data-cue-row="${primaryId}"]`)
      ?.scrollIntoView({ block: 'nearest' })
  }, [primaryId])

  if (cues.length === 0) {
    return (
      <p className="px-4 py-6 text-center text-[12px] text-muted-foreground">
        {emptyLabel}
      </p>
    )
  }

  return (
    <div
      ref={listRef}
      role="listbox"
      aria-multiselectable
      className="min-h-0 flex-1 overflow-y-auto border-t border-border"
    >
      {cues.map((cue) => {
        const cueIssues = issues.get(cue.id)
        return (
          <CueRow
            key={cue.id}
            cue={cue}
            index={indexById.get(cue.id) ?? 0}
            selected={selectedIds.has(cue.id)}
            active={activeIds.has(cue.id)}
            textDir={textDir}
            lang={languageCode || undefined}
            severity={
              cueIssues?.some((issue) => issue.severity === 'error')
                ? 'error'
                : cueIssues?.length
                  ? 'warning'
                  : null
            }
            onSelect={onSelect}
          />
        )
      })}
    </div>
  )
}

const CueRow = memo(function CueRow({
  cue,
  index,
  selected,
  active,
  textDir,
  lang,
  severity,
  onSelect,
}: {
  cue: SubtitleCue
  index: number
  selected: boolean
  active: boolean
  textDir: 'rtl' | 'auto'
  lang: string | undefined
  severity: 'error' | 'warning' | null
  onSelect: (id: string, modifiers: { additive: boolean; range: boolean }) => void
}) {
  const t = useT()
  return (
    <button
      type="button"
      role="option"
      aria-selected={selected}
      data-cue-row={cue.id}
      data-subtitle-keep-selection
      onClick={(event) =>
        onSelect(cue.id, {
          additive: event.metaKey || event.ctrlKey,
          range: event.shiftKey,
        })
      }
      className={cn(
        'relative flex w-full items-start gap-3 border-b border-border px-4 py-2 text-start [contain-intrinsic-size:auto_52px] [content-visibility:auto]',
        selected ? 'bg-accent' : 'hover:bg-muted/50',
      )}
    >
      {selected ? (
        <span
          aria-hidden
          className="absolute inset-y-0 start-0 w-[3px] bg-foreground"
        />
      ) : null}
      <span
        className={cn(
          'mt-0.5 flex w-7 shrink-0 items-center font-mono text-[11px] tabular-nums text-muted-foreground',
          selected && 'font-semibold text-foreground',
        )}
      >
        {active ? (
          <Play
            className="h-3 w-3 fill-current text-foreground"
            aria-label={t('Showing now')}
          />
        ) : (
          index + 1
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span
          className={cn(
            'block font-mono text-[11px] tabular-nums',
            active ? 'text-foreground' : 'text-muted-foreground',
          )}
          dir="ltr"
        >
          {formatEditorTimestamp(cue.start)} → {formatEditorTimestamp(cue.end)}
        </span>
        <span
          dir={textDir}
          lang={lang}
          className={cn(
            'mt-0.5 line-clamp-2 whitespace-pre-line text-[12px] text-foreground',
            !stripCueMarkup(cue.text).trim() && 'italic text-muted-foreground',
          )}
        >
          {stripCueMarkup(cue.text).trim() || t('Empty cue')}
        </span>
      </span>
      {severity ? (
        severity === 'error' ? (
          <AlertCircle
            className="mt-0.5 h-3.5 w-3.5 shrink-0 text-destructive"
            aria-label={t('Has errors')}
          />
        ) : (
          <AlertTriangle
            className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500"
            aria-label={t('Has warnings')}
          />
        )
      ) : null}
    </button>
  )
})

function IconAction({
  icon: Icon,
  label,
  onClick,
  disabled = false,
  iconClassName,
}: {
  icon: LucideIcon
  label: string
  onClick: () => void
  disabled?: boolean
  iconClassName?: string
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-7 w-7"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
          >
            <Icon className={cn('h-3.5 w-3.5', iconClassName)} />
          </Button>
        </span>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]">{label}</TooltipContent>
    </Tooltip>
  )
}
