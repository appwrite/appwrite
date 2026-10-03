import { useMemo, type CSSProperties, type ReactNode } from 'react'
import {
  parseCueMarkup,
  type CueColorClass,
  type CueMarkupElement,
  type CueMarkupNode,
} from '@/lib/videos/subtitle-cues'

const CUE_COLORS: Record<string, string> = {
  white: '#ffffff',
  lime: '#00ff00',
  cyan: '#00ffff',
  red: '#ff0000',
  yellow: '#ffff00',
  magenta: '#ff00ff',
  blue: '#0000ff',
  black: '#000000',
}

function elementStyle(element: CueMarkupElement): CSSProperties | undefined {
  const style: CSSProperties = {}
  for (const name of element.classes as CueColorClass[]) {
    if (name.startsWith('bg_')) style.backgroundColor = CUE_COLORS[name.slice(3)]
    else style.color = CUE_COLORS[name]
  }
  if (element.color) style.color = element.color
  return Object.keys(style).length > 0 ? style : undefined
}

function renderNodes(nodes: CueMarkupNode[], path: string): ReactNode[] {
  return nodes.map((node, index) => {
    const key = `${path}.${index}`
    if (typeof node === 'string') return node
    const children = renderNodes(node.children, key)
    const style = elementStyle(node)
    switch (node.tag) {
      case 'i':
        return <i key={key} style={style}>{children}</i>
      case 'b':
        return <b key={key} style={style}>{children}</b>
      case 'u':
        return <u key={key} style={style}>{children}</u>
      case 'ruby':
        return <ruby key={key} style={style}>{children}</ruby>
      case 'rt':
        return <rt key={key} style={style}>{children}</rt>
      case 'v':
        return (
          <span key={key} style={style} title={node.voice}>
            {children}
          </span>
        )
      case 'lang':
        return (
          <span key={key} style={style} lang={node.lang}>
            {children}
          </span>
        )
      default:
        return (
          <span key={key} style={style}>
            {children}
          </span>
        )
    }
  })
}

/**
 * Renders WebVTT/SRT cue markup as React elements. Text is always escaped by
 * React; only allowlisted tags, standard color classes and validated colors
 * reach the DOM.
 */
export function CueMarkup({ text }: { text: string }) {
  const nodes = useMemo(() => renderNodes(parseCueMarkup(text), 'cue'), [text])
  return <>{nodes}</>
}
