/**
 * BuildLogsView – shared build logs with line numbers and ANSI syntax highlighting.
 * Used in deployment details and create-site deploying wizard.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import * as React from 'react'
import { Check, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

/** Strip ANSI SGR sequences for clipboard text. */
function stripAnsiForClipboard(text: string): string {
  return text.replace(/\x1b\[[0-9;]*m/g, '')
}

// ANSI color code mapping – light mode uses dark text, dark mode uses light text for readability
const ANSI_COLORS: Record<number, string> = {
  30: 'text-gray-800 dark:text-gray-200', // Black
  31: 'text-red-600 dark:text-red-400', // Red
  32: 'text-green-700 dark:text-green-400', // Green
  33: 'text-yellow-700 dark:text-yellow-400', // Yellow
  34: 'text-blue-600 dark:text-blue-400', // Blue
  35: 'text-purple-600 dark:text-purple-400', // Magenta
  36: 'text-cyan-600 dark:text-cyan-400', // Cyan
  37: 'text-gray-900 dark:text-gray-100', // White
  90: 'text-gray-600 dark:text-gray-400', // Bright Black (Gray)
  91: 'text-red-600 dark:text-red-400', // Bright Red
  92: 'text-green-600 dark:text-green-400', // Bright Green
  93: 'text-yellow-600 dark:text-yellow-400', // Bright Yellow
  94: 'text-blue-600 dark:text-blue-400', // Bright Blue
  95: 'text-purple-600 dark:text-purple-400', // Bright Magenta
  96: 'text-cyan-600 dark:text-cyan-400', // Bright Cyan
  97: 'text-gray-900 dark:text-gray-100', // Bright White
}

function extractTextFromReactNode(node: React.ReactNode): string {
  if (typeof node === 'string') return node
  if (typeof node === 'number') return String(node)
  if (React.isValidElement(node)) {
    const children = (node.props as unknown)?.children
    if (typeof children === 'string') return children
    if (Array.isArray(children)) {
      return children.map(extractTextFromReactNode).join('')
    }
  }
  return ''
}

function replaceVercelTriangle(
  text: string,
  currentColor: string | null,
  baseKey: number,
): React.ReactNode[] {
  if (!text.includes('▲')) {
    return [
      currentColor ? (
        <span key={baseKey} className={currentColor}>
          {text}
        </span>
      ) : (
        text
      ),
    ]
  }

  const parts: React.ReactNode[] = []
  const segments = text.split('▲')
  let keyCounter = baseKey

  for (let i = 0; i < segments.length; i++) {
    if (segments[i]) {
      parts.push(
        currentColor ? (
          <span key={keyCounter++} className={currentColor}>
            {segments[i]}
          </span>
        ) : (
          segments[i]
        ),
      )
    }
    if (i < segments.length - 1) {
      parts.push(
        <img
          key={keyCounter++}
          src="/icons/appwrite.svg"
          alt="Appwrite"
          className={`inline-block h-[1em] w-[1em] align-middle ${PUBLIC_ICON_MUTED_CLASSES}`}
        />,
      )
    }
  }

  return parts
}

function parseAnsiLogsWithoutHighlight(text: string): React.ReactNode[] {
  const ansiRegex = /\x1b\[(\d+(?:;\d+)*)?m/g
  const parts: React.ReactNode[] = []
  let lastIndex = 0
  let currentColor: string | null = null
  let match
  let keyCounter = 0

  while ((match = ansiRegex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      const textBefore = text.substring(lastIndex, match.index)
      if (textBefore) {
        const processedText = replaceVercelTriangle(
          textBefore,
          currentColor,
          keyCounter,
        )
        parts.push(...processedText)
        keyCounter += processedText.length
      }
    }

    const code = match[1]
    if (!code || code === '0') {
      currentColor = null
    } else {
      const codes = code.split(';').map(Number)
      const colorCode = codes.find((c) => ANSI_COLORS[c])
      if (colorCode) {
        currentColor = ANSI_COLORS[colorCode]
      }
    }

    lastIndex = match.index + match[0].length
  }

  if (lastIndex < text.length) {
    const remainingText = text.substring(lastIndex)
    if (remainingText) {
      const processedText = replaceVercelTriangle(
        remainingText,
        currentColor,
        keyCounter,
      )
      parts.push(...processedText)
    }
  }

  return parts.length > 0 ? parts : [text]
}

function highlightText(
  text: string,
  color: string | null,
  searchTerm: string,
  baseKey: number,
): React.ReactNode[] {
  const searchLower = searchTerm.toLowerCase()
  const lowerText = text.toLowerCase()
  const parts: React.ReactNode[] = []
  let lastIndex = 0
  let keyCounter = baseKey

  while (true) {
    const searchIndex = lowerText.indexOf(searchLower, lastIndex)
    if (searchIndex === -1) {
      if (lastIndex < text.length) {
        const remaining = text.substring(lastIndex)
        if (remaining) {
          const processedText = replaceVercelTriangle(
            remaining,
            color,
            keyCounter,
          )
          parts.push(...processedText)
          keyCounter += processedText.length
        }
      }
      break
    }

    if (searchIndex > lastIndex) {
      const beforeMatch = text.substring(lastIndex, searchIndex)
      if (beforeMatch) {
        const processedText = replaceVercelTriangle(
          beforeMatch,
          color,
          keyCounter,
        )
        parts.push(...processedText)
        keyCounter += processedText.length
      }
    }

    const matchText = text.substring(
      searchIndex,
      searchIndex + searchTerm.length,
    )
    const processedMatch = replaceVercelTriangle(matchText, color, keyCounter)
    parts.push(
      <mark
        key={keyCounter++}
        className="bg-yellow-200 dark:bg-yellow-900/50 text-foreground"
      >
        {processedMatch}
      </mark>,
    )
    keyCounter += processedMatch.length

    lastIndex = searchIndex + searchTerm.length
  }

  return parts.length > 0 ? parts : [text]
}

function parseAnsiLogs(text: string, searchTerm?: string): React.ReactNode[] {
  if (!searchTerm || !searchTerm.trim()) {
    return parseAnsiLogsWithoutHighlight(text)
  }

  const coloredSegments = parseAnsiLogsWithoutHighlight(text)
  const highlightedParts: React.ReactNode[] = []
  let keyCounter = 0

  coloredSegments.forEach((segment) => {
    if (typeof segment === 'string') {
      highlightedParts.push(
        ...highlightText(segment, null, searchTerm, keyCounter),
      )
      keyCounter += 1000
    } else if (React.isValidElement(segment) && segment.type === 'span') {
      const textContent = extractTextFromReactNode(segment)
      const className = (segment.props as unknown)?.className || null
      highlightedParts.push(
        ...highlightText(textContent, className, searchTerm, keyCounter),
      )
      keyCounter += 1000
    } else {
      highlightedParts.push(segment)
    }
  })

  return highlightedParts.length > 0 ? highlightedParts : [text]
}

export interface BuildLogsViewProps {
  /** Raw build log text (may contain ANSI codes) */
  buildLogs: string
  /** Optional search term to filter and highlight lines */
  searchTerm?: string
  /** Currently selected line number (highlighted row) */
  selectedLine?: number | null
  /** When set, rows are selectable on click and a per-row copy control is shown. */
  onLineClick?: (lineNumber: number) => void
  /** Ref map for line elements (e.g. for scroll-into-view) */
  lineRefs?: React.MutableRefObject<Map<number, HTMLDivElement>>
  /** Message when buildLogs is empty */
  emptyMessage?: React.ReactNode
  /** Class name for the scrollable container */
  className?: string
  /** Font size class (e.g. text-[11px] sm:text-[12px]) */
  fontSizeClass?: string
  /** Show hover highlight on lines (e.g. in wizard without click-to-select) */
  highlightLineOnHover?: boolean
  /**
   * When false, omits right padding on log rows so the scrollbar sits flush with content
   * (e.g. split-pane deployment layout with no outer pr on the scroll region).
   */
  trailingPadding?: boolean
  /**
   * When set, replaces default horizontal padding on each row (use `pl-0 pr-0` when a parent
   * supplies symmetric `px-*` so the scrollbar sits on the pane edge).
   */
  lineHorizontalPaddingClass?: string
}

/**
 * Renders build logs with line numbers and ANSI syntax highlighting.
 * Reused in deployment details and create-site deploying wizard.
 */
export function BuildLogsView({
  buildLogs,
  searchTerm = '',
  selectedLine = null,
  onLineClick,
  lineRefs,
  emptyMessage = 'No build logs available.',
  className = '',
  fontSizeClass = 'text-[11px] sm:text-[12px]',
  highlightLineOnHover = false,
  trailingPadding = true,
  lineHorizontalPaddingClass,
}: BuildLogsViewProps) {
  const [copiedLineNumber, setCopiedLineNumber] = useState<number | null>(null)
  /** Copy stays hidden after a copy until the pointer leaves this line's row (then hover shows it again). */
  const [copyHiddenUntilLeaveLine, setCopyHiddenUntilLeaveLine] = useState<
    number | null
  >(null)
  const prevSearchTermRef = useRef(searchTerm)
  const prevBuildLogsRef = useRef<string | undefined>(undefined)

  useEffect(() => {
    const searchChanged = prevSearchTermRef.current !== searchTerm
    if (searchChanged) prevSearchTermRef.current = searchTerm

    const logs = buildLogs ?? ''
    const logsChanged = prevBuildLogsRef.current !== logs
    if (logsChanged) prevBuildLogsRef.current = logs

    if (searchChanged || logsChanged) {
      setCopiedLineNumber(null)
      setCopyHiddenUntilLeaveLine(null)
    }
  }, [buildLogs, searchTerm])

  useEffect(() => {
    if (copiedLineNumber == null) return
    const id = window.setTimeout(() => setCopiedLineNumber(null), 1800)
    return () => clearTimeout(id)
  }, [copiedLineNumber])

  const parsedLogs = useMemo(() => {
    if (!buildLogs) return null

    const term = searchTerm.trim()
    const allLines = buildLogs.split('\n')

    let linesToDisplay: Array<{ line: string; originalLineNumber: number }>

    if (term) {
      const searchLower = term.toLowerCase()
      linesToDisplay = allLines
        .map((line, index) => ({ line, originalLineNumber: index + 1 }))
        .filter(({ line }) => line.toLowerCase().includes(searchLower))
    } else {
      linesToDisplay = allLines.map((line, index) => ({
        line,
        originalLineNumber: index + 1,
      }))
    }

    const maxLineNumber = allLines.length
    const lineNumberDigits = maxLineNumber.toString().length
    const lineNumberWidth = `${lineNumberDigits + 3}ch`

    const rowPadding =
      lineHorizontalPaddingClass ??
      `pl-4 sm:pl-6 ${trailingPadding ? 'pr-4 sm:pr-6' : 'pr-0'}`

    const showLineActions = !!onLineClick

    const gridStyle: React.CSSProperties = showLineActions
      ? {
          gridTemplateColumns: `${lineNumberWidth} minmax(0, 1fr) auto`,
        }
      : {
          gridTemplateColumns: `${lineNumberWidth} minmax(0, 1fr)`,
        }

    const gutterSpacerRow = (position: 'top' | 'bottom') => (
      <div
        key={`__gutter-spacer-${position}`}
        className={`grid min-h-5 min-w-0 items-stretch gap-4 ${rowPadding}`}
        style={gridStyle}
        aria-hidden
      >
        <div className="border-r border-border/50 pr-2" />
        <div className="pl-1" />
        {showLineActions ? <div className="w-5 shrink-0" /> : null}
      </div>
    )

    const rows = linesToDisplay.map(({ line, originalLineNumber }, displayIndex) => {
      const parsedLine = parseAnsiLogs(line, term || undefined)
      const isSelected = selectedLine === originalLineNumber
      const isClickable = !!onLineClick
      const showHover = isClickable || highlightLineOnHover

      return (
        <div
          key={`${originalLineNumber}-${displayIndex}`}
          ref={(el) => {
            if (el && lineRefs) {
              lineRefs.current.set(originalLineNumber, el)
            } else if (lineRefs) {
              lineRefs.current.delete(originalLineNumber)
            }
          }}
          onClick={
            isClickable
              ? (e) => {
                  e.preventDefault()
                  onLineClick(originalLineNumber)
                }
              : undefined
          }
          onMouseLeave={
            showLineActions
              ? () => {
                  setCopyHiddenUntilLeaveLine((blocked) =>
                    blocked === originalLineNumber ? null : blocked,
                  )
                }
              : undefined
          }
          className={`grid min-w-0 items-stretch gap-4 group transition-colors ${rowPadding} ${
            isClickable ? 'cursor-pointer' : ''
          } ${
            isSelected
              ? 'bg-yellow-100/50 dark:bg-yellow-900/20'
              : showHover
                ? 'hover:bg-muted/30'
                : ''
          }`}
          style={gridStyle}
          title={
            isClickable
              ? `Select line ${originalLineNumber}`
              : highlightLineOnHover
                ? `Line ${originalLineNumber}`
                : undefined
          }
        >
          <div
            className={`flex select-none items-start justify-end border-r border-border/50 pr-2 font-mono tabular-nums transition-colors ${fontSizeClass} ${
              isSelected
                ? 'text-yellow-600 dark:text-yellow-400 font-semibold'
                : 'text-muted-foreground'
            }`}
          >
            {originalLineNumber}
          </div>
          <div
            className={`min-w-0 break-all pl-1 font-mono ${fontSizeClass}`}
          >
            {parsedLine}
          </div>
          {showLineActions ? (
            <div
              className={cn(
                'flex shrink-0 items-start justify-end self-start transition-opacity duration-150',
                copiedLineNumber === originalLineNumber
                  ? 'pointer-events-none opacity-100'
                  : copyHiddenUntilLeaveLine === originalLineNumber
                    ? 'pointer-events-none opacity-0'
                    : 'pointer-events-none opacity-0 group-hover:pointer-events-auto group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:opacity-100',
              )}
            >
              {copiedLineNumber === originalLineNumber ? (
                <div
                  className="flex h-5 w-5 items-center justify-center text-green-600 dark:text-green-500"
                  aria-label="Copied"
                  role="status"
                >
                  <Check className="h-3 w-3" strokeWidth={2.5} />
                </div>
              ) : (
                <Button
                  type="button"
                  variant="ghost"
                  className="h-5 w-5 min-h-0 shrink-0 p-0 text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                  aria-label={`Copy line ${originalLineNumber}`}
                  title="Copy line"
                  onClick={async (e) => {
                    e.stopPropagation()
                    try {
                      await navigator.clipboard.writeText(
                        stripAnsiForClipboard(line),
                      )
                      setCopyHiddenUntilLeaveLine(originalLineNumber)
                      setCopiedLineNumber(originalLineNumber)
                      toast.success(`Line ${originalLineNumber} copied`)
                    } catch {
                      toast.error('Failed to copy')
                    }
                  }}
                >
                  <Copy className="h-2.5 w-2.5" />
                </Button>
              )}
            </div>
          ) : null}
        </div>
      )
    })

    return (
      <>
        {gutterSpacerRow('top')}
        {rows}
        {gutterSpacerRow('bottom')}
      </>
    )
  }, [
    buildLogs,
    searchTerm,
    selectedLine,
    copiedLineNumber,
    copyHiddenUntilLeaveLine,
    onLineClick,
    lineRefs,
    fontSizeClass,
    highlightLineOnHover,
    trailingPadding,
    lineHorizontalPaddingClass,
  ])

  if (!buildLogs) {
    return (
      <div
        className={`py-4 text-[12px] sm:text-[13px] text-muted-foreground ${
          lineHorizontalPaddingClass !== undefined
            ? 'px-0'
            : trailingPadding
              ? 'px-4 sm:px-6'
              : 'pl-4 sm:pl-6 pr-0'
        } ${className}`}
      >
        {emptyMessage}
      </div>
    )
  }

  return (
    <div className={`min-h-full min-w-0 ${className}`}>
      <div
        className={`${fontSizeClass} font-mono text-foreground flex min-w-0 max-w-full flex-col overflow-x-auto break-all`}
      >
        {parsedLogs}
      </div>
    </div>
  )
}
