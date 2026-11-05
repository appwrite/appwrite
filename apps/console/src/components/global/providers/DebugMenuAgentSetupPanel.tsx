import { useMemo, useState } from 'react'
import { Check, ChevronRight, Copy, ExternalLink } from 'lucide-react'
import { MarkdownContent } from '@/components/global/shared/MarkdownContent'
import {
  AGENT_SETUP_PATH,
  generateAgentSetupMarkdown,
} from '@/lib/seo/agent-setup'
import { cn } from '@/lib/utils'

type SetupSubsection = { id: string; title: string; body: string }

type SetupSection = {
  id: string
  title: string
  body: string
  subsections: SetupSubsection[]
}

type ParsedSetup = { title: string; intro: string; sections: SetupSection[] }

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

/** Splits on `#`, `##`, and `###` headings outside fenced code blocks. */
function parseSetupMarkdown(markdown: string): ParsedSetup {
  const parsed: ParsedSetup = { title: '', intro: '', sections: [] }
  let inFence = false
  let buffer: string[] = []
  let section: SetupSection | null = null
  let subsection: SetupSubsection | null = null

  const flush = () => {
    const text = buffer.join('\n').trim()
    buffer = []
    if (subsection) subsection.body = text
    else if (section) section.body = text
    else parsed.intro = text
  }

  for (const line of markdown.split('\n')) {
    if (line.trimStart().startsWith('```')) inFence = !inFence

    const heading = inFence ? null : /^(#{1,3}) (.+)$/.exec(line)
    if (!heading) {
      buffer.push(line)
      continue
    }

    const [, hashes, title] = heading
    if (hashes.length === 1) {
      parsed.title = title
      continue
    }

    flush()
    if (hashes.length === 2) {
      section = { id: `setup-${slugify(title)}`, title, body: '', subsections: [] }
      subsection = null
      parsed.sections.push(section)
    } else if (section) {
      subsection = { id: `${section.id}-${slugify(title)}`, title, body: '' }
      section.subsections.push(subsection)
    }
  }
  flush()

  return parsed
}

/**
 * Debug-only (English + LTR): review the agent-facing `/setup.md` guide with a
 * table of contents and collapsible sections.
 */
export function DebugMenuAgentSetupPanel() {
  const markdown = useMemo(() => generateAgentSetupMarkdown(), [])
  const setup = useMemo(() => parseSetupMarkdown(markdown), [markdown])
  const [expanded, setExpanded] = useState<Set<string>>(() => new Set())
  const [copied, setCopied] = useState(false)

  const allExpanded = expanded.size === setup.sections.length

  const toggleSection = (id: string) => {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const jumpTo = (sectionId: string, targetId: string) => {
    setExpanded((prev) => new Set(prev).add(sectionId))
    requestAnimationFrame(() => {
      document
        .getElementById(targetId)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    })
  }

  const copyMarkdown = async () => {
    await navigator.clipboard.writeText(markdown)
    setCopied(true)
    setTimeout(() => setCopied(false), 1500)
  }

  return (
    <div
      className="space-y-4 px-1"
      aria-label="Agent setup guide"
      dir="ltr"
      lang="en"
    >
      <div className="flex items-start justify-between gap-3 px-1">
        <div className="min-w-0">
          <p className="text-[12px] font-semibold text-foreground">
            {setup.title}
          </p>
          <p className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
            Rendered from the same source as {AGENT_SETUP_PATH} (
            {markdown.length.toLocaleString()} characters).
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-1">
          <button
            type="button"
            onClick={copyMarkdown}
            className="inline-flex h-7 items-center gap-1.5 rounded-md border border-border/60 px-2 text-[11px] text-muted-foreground hover:bg-muted/50 hover:text-foreground"
          >
            {copied ? (
              <Check className="h-3 w-3" />
            ) : (
              <Copy className="h-3 w-3" />
            )}
            Copy markdown
          </button>
          <a
            href={AGENT_SETUP_PATH}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex h-7 items-center gap-1.5 rounded-md border border-border/60 px-2 text-[11px] text-muted-foreground hover:bg-muted/50 hover:text-foreground"
          >
            <ExternalLink className="h-3 w-3" />
            Open raw
          </a>
        </div>
      </div>

      <nav
        aria-label="Table of contents"
        className="rounded-lg border border-border/60 bg-muted/20 px-3 py-2.5"
      >
        <div className="mb-1.5 flex items-center justify-between">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            Contents
          </p>
          <button
            type="button"
            onClick={() =>
              setExpanded(
                allExpanded
                  ? new Set()
                  : new Set(setup.sections.map((section) => section.id)),
              )
            }
            className="text-[11px] text-muted-foreground hover:text-foreground"
          >
            {allExpanded ? 'Collapse all' : 'Expand all'}
          </button>
        </div>
        <ol className="space-y-0.5 text-[12px]">
          {setup.sections.map((section) => (
            <li key={section.id}>
              <button
                type="button"
                onClick={() => jumpTo(section.id, section.id)}
                className="text-start text-foreground hover:text-[var(--network-globe-edge)]"
              >
                {section.title}
              </button>
              {section.subsections.length > 0 ? (
                <ol className="mt-0.5 space-y-0.5 border-s border-border/60 ps-3">
                  {section.subsections.map((subsection) => (
                    <li key={subsection.id}>
                      <button
                        type="button"
                        onClick={() => jumpTo(section.id, subsection.id)}
                        className="text-start text-[11px] text-muted-foreground hover:text-foreground"
                      >
                        {subsection.title}
                      </button>
                    </li>
                  ))}
                </ol>
              ) : null}
            </li>
          ))}
        </ol>
      </nav>

      {setup.intro ? (
        <MarkdownContent
          content={setup.intro}
          className="px-1 text-[12px] leading-relaxed text-muted-foreground"
        />
      ) : null}

      <div className="space-y-2">
        {setup.sections.map((section) => {
          const isOpen = expanded.has(section.id)
          return (
            <section
              key={section.id}
              id={section.id}
              className="scroll-mt-2 overflow-hidden rounded-lg border border-border/60"
            >
              <button
                type="button"
                aria-expanded={isOpen}
                onClick={() => toggleSection(section.id)}
                className="flex w-full items-center gap-2 bg-muted/30 px-3 py-2 text-start text-[12px] font-semibold text-foreground hover:bg-muted/50"
              >
                <ChevronRight
                  className={cn(
                    'h-3 w-3 shrink-0 text-muted-foreground transition-transform',
                    isOpen && 'rotate-90',
                  )}
                />
                <span className="min-w-0 flex-1">{section.title}</span>
                {section.subsections.length > 0 ? (
                  <span className="text-[10px] font-normal tabular-nums text-muted-foreground">
                    {section.subsections.length} subsections
                  </span>
                ) : null}
              </button>
              {isOpen ? (
                <div className="space-y-3 border-t border-border/60 px-3 py-2.5 text-[12px] leading-relaxed text-muted-foreground">
                  {section.body ? (
                    <MarkdownContent content={section.body} />
                  ) : null}
                  {section.subsections.map((subsection) => (
                    <div
                      key={subsection.id}
                      id={subsection.id}
                      className="scroll-mt-2"
                    >
                      <p className="mb-1 text-[12px] font-semibold text-foreground">
                        {subsection.title}
                      </p>
                      <MarkdownContent content={subsection.body} />
                    </div>
                  ))}
                </div>
              ) : null}
            </section>
          )
        })}
      </div>
    </div>
  )
}
