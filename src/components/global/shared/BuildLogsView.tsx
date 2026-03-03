/**
 * BuildLogsView – shared build logs with line numbers and ANSI syntax highlighting.
 * Used in deployment details and create-site deploying wizard.
 */

import { useMemo } from 'react'
import * as React from 'react'

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
          className="inline-block h-[1em] w-[1em] align-middle"
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
  /** Callback when a line is clicked (e.g. to sync URL) */
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
}: BuildLogsViewProps) {
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

    return linesToDisplay.map(({ line, originalLineNumber }, displayIndex) => {
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
          className={`flex items-start gap-4 group transition-colors pl-4 sm:pl-6 pr-4 sm:pr-6 ${
            isClickable ? 'cursor-pointer' : ''
          } ${
            isSelected
              ? 'bg-yellow-100/50 dark:bg-yellow-900/20'
              : showHover
                ? 'hover:bg-muted/30'
                : ''
          }`}
          title={
            isClickable
              ? `Click to highlight and copy "Line ${originalLineNumber}"`
              : highlightLineOnHover
                ? `Line ${originalLineNumber}`
                : undefined
          }
        >
          <span
            className={`${fontSizeClass} font-mono select-none shrink-0 text-right tabular-nums pr-2 mr-3 transition-colors ${
              isSelected
                ? 'text-yellow-600 dark:text-yellow-400 font-semibold'
                : 'text-muted-foreground'
            }`}
            style={{ width: lineNumberWidth, minWidth: lineNumberWidth }}
          >
            {originalLineNumber}
          </span>
          <span className="flex-1 min-w-0 break-all pl-1">{parsedLine}</span>
        </div>
      )
    })
  }, [
    buildLogs,
    searchTerm,
    selectedLine,
    onLineClick,
    lineRefs,
    fontSizeClass,
    highlightLineOnHover,
  ])

  if (!buildLogs) {
    return (
      <div
        className={`px-4 sm:px-6 py-4 text-[12px] sm:text-[13px] text-muted-foreground ${className}`}
      >
        {emptyMessage}
      </div>
    )
  }

  const allLines = buildLogs.split('\n')
  const maxLineNumber = allLines.length
  const lineNumberDigits = maxLineNumber.toString().length
  const lineNumberWidth = `${lineNumberDigits + 3}ch`

  return (
    <div className={`py-4 min-w-0 min-h-full relative ${className}`}>
      {/* Vertical border between line numbers and content */}
      <>
        <div
          className="absolute top-0 bottom-0 border-r border-border/50 pointer-events-none sm:hidden"
          style={{ left: `calc(1rem + ${lineNumberWidth})` }}
        />
        <div
          className="absolute top-0 bottom-0 border-r border-border/50 pointer-events-none hidden sm:block"
          style={{ left: `calc(1.5rem + ${lineNumberWidth})` }}
        />
      </>
      <div
        className={`${fontSizeClass} font-mono text-foreground break-all overflow-x-auto max-w-full min-w-0 relative`}
      >
        {parsedLogs}
      </div>
    </div>
  )
}
