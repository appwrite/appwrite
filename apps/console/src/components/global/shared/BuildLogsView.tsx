/**
 * BuildLogsView – shared build logs with line numbers and ANSI syntax highlighting.
 * Used in deployment details and create-site deploying wizard.
 */

import { useMemo } from 'react'
import * as React from 'react'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Strip ANSI SGR sequences for clipboard text. */
export function stripAnsiForClipboard(text: string): string {
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
  /** Log line numbers (1-based) to highlight as selected. */
  selectedLines?: ReadonlySet<number> | null
  /** When set, rows are selectable on click. */
  onLineClick?: (lineNumber: number, event: React.MouseEvent<HTMLDivElement>) => void
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
   * When set, replaces default horizontal padding on each row (use `ps-0 pe-0` when a parent
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
  selectedLines = null,
  onLineClick,
  lineRefs,
  emptyMessage = 'No build logs available.',
  className = '',
  fontSizeClass = 'text-[11px] sm:text-[12px]',
  highlightLineOnHover = false,
  trailingPadding = true,
  lineHorizontalPaddingClass,
}: BuildLogsViewProps) {
  const t = useT()
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
      `ps-4 sm:ps-6 ${trailingPadding ? 'pe-4 sm:pe-6' : 'pe-0'}`

    const gridStyle: React.CSSProperties = {
      gridTemplateColumns: `${lineNumberWidth} minmax(0, 1fr)`,
    }

    const gutterSpacerRow = (position: 'top' | 'bottom') => (
      <div
        key={`__gutter-spacer-${position}`}
        className={`grid min-h-5 min-w-0 items-stretch gap-4 ${rowPadding}`}
        style={gridStyle}
        aria-hidden
      >
        <div className="border-e border-border/50 pe-2" />
        <div className="ps-1" />
      </div>
    )

    const rows = linesToDisplay.map(({ line, originalLineNumber }, displayIndex) => {
      const parsedLine = parseAnsiLogs(line, term || undefined)
      const isSelected =
        selectedLines != null && selectedLines.has(originalLineNumber)
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
          className={`grid min-w-0 items-stretch gap-4 transition-colors ${rowPadding} ${
            isSelected
              ? 'bg-yellow-100/50 dark:bg-yellow-900/20'
              : showHover
                ? 'hover:bg-muted/30'
                : ''
          }`}
          style={gridStyle}
          title={
            highlightLineOnHover
              ? `${t('Line')} ${originalLineNumber}`
              : undefined
          }
        >
          <div
            onClick={
              isClickable
                ? (e) => {
                    e.preventDefault()
                    onLineClick(originalLineNumber, e)
                  }
                : undefined
            }
            className={`flex select-none items-start justify-end border-e border-border/50 pe-2 font-mono tabular-nums transition-colors ${fontSizeClass} ${
              isSelected
                ? 'text-yellow-600 dark:text-yellow-400 font-semibold'
                : 'text-muted-foreground'
            } ${isClickable ? 'cursor-pointer' : ''}`}
            title={
              isClickable
                ? `${t('Select line')} ${originalLineNumber} ${t('(Shift for range, ⌘/Ctrl to toggle)')}`
                : undefined
            }
          >
            {originalLineNumber}
          </div>
          <div
            className={`min-w-0 break-all ps-1 font-mono ${fontSizeClass}`}
          >
            {parsedLine}
          </div>
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
    selectedLines,
    onLineClick,
    lineRefs,
    fontSizeClass,
    highlightLineOnHover,
    trailingPadding,
    lineHorizontalPaddingClass,
    t,
  ])

  if (!buildLogs) {
    return (
      <div
        className={`py-4 text-[12px] sm:text-[13px] text-muted-foreground ${
          lineHorizontalPaddingClass !== undefined
            ? 'px-0'
            : trailingPadding
              ? 'px-4 sm:px-6'
              : 'ps-4 sm:ps-6 pe-0'
        } ${className}`}
      >
        {typeof emptyMessage === 'string' ? t(emptyMessage) : emptyMessage}
      </div>
    )
  }

  return (
    <div className={cn('min-w-0', className)}>
      <div
        className={`${fontSizeClass} font-mono text-foreground flex min-w-0 max-w-full flex-col overflow-x-auto break-all`}
      >
        {parsedLogs}
      </div>
    </div>
  )
}
