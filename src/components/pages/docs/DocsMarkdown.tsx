'use client'

import Markdoc from '@markdoc/markdoc'
import React, { useMemo } from 'react'
import { docsMarkdocConfig } from '@/lib/docs/markdoc-config'
import {
  DOCS_BODY_TEXT_CLASS,
  DOCS_PROSE_DETAIL_CLASSES,
  DOCS_PROSE_WRAPPER_CLASS,
} from '@/lib/docs/prose-typography'
import { cn } from '@/lib/utils'
import { Cards, CardsItem } from './markdoc/Cards'
import { Fence } from './markdoc/Fence'
import { Info } from './markdoc/Info'
import { MultiCode } from './markdoc/MultiCode'
import { DocsImage } from './markdoc/DocsImage'
import {
  Blockquote,
  DocsLink,
  Heading,
  MarkdocTableBody,
  MarkdocTableCell,
  MarkdocTableHead,
  MarkdocTableHeader,
  MarkdocTableRoot,
  MarkdocTableRow,
  MarkdocTableTag,
  OnlyDark,
  OnlyLight,
} from './markdoc/Nodes'
import { Tabs, TabsItem } from './markdoc/Tabs'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'

const markdocComponents = {
  MultiCode,
  Fence,
  Info,
  Tabs,
  TabsItem,
  Cards,
  CardsItem,
  Heading,
  Link: DocsLink,
  Image: DocsImage,
  OnlyLight,
  OnlyDark,
  Blockquote,
  MarkdocTableTag,
  MarkdocTableRoot,
  MarkdocTableHeader,
  MarkdocTableBody,
  MarkdocTableRow,
  MarkdocTableHead,
  MarkdocTableCell,
  Accordion: ({ children }: { children?: React.ReactNode }) => (
    <Accordion type="single" collapsible className="not-prose my-6">
      {children}
    </Accordion>
  ),
  AccordionItem: ({ title, children }: { title?: string; children?: React.ReactNode }) => (
    <AccordionItem value={title ?? 'item'} className="rounded-lg border border-border px-4">
      <AccordionTrigger className={cn(DOCS_BODY_TEXT_CLASS, 'font-medium hover:no-underline')}>
        {title}
      </AccordionTrigger>
      <AccordionContent className={DOCS_BODY_TEXT_CLASS}>{children}</AccordionContent>
    </AccordionItem>
  ),
  Section: () => null,
  ArrowLink: ({ href, title }: { href?: string; title?: string }) => (
    <DocsLink href={href}>{title}</DocsLink>
  ),
  CallToAction: ({ href, title }: { href?: string; title?: string }) => (
    <div className="not-prose my-6">
      <a
        href={href}
        className="inline-flex items-center rounded-lg bg-[var(--brand-cta)] px-4 py-2 text-[13px] font-medium text-white hover:opacity-90"
      >
        {title}
      </a>
    </div>
  ),
  Video: ({ src, title }: { src?: string; title?: string }) => (
    <div className="not-prose my-6 overflow-hidden rounded-xl border border-border">
      <video src={src} controls className="w-full" title={title} />
    </div>
  ),
  Youtube: ({ id, title }: { id?: string; title?: string }) => (
    <div className="not-prose my-6 aspect-video overflow-hidden rounded-xl border border-border">
      <iframe
        src={`https://www.youtube.com/embed/${id}`}
        title={title ?? 'YouTube video'}
        className="h-full w-full"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowFullScreen
      />
    </div>
  ),
}

type DocsMarkdownProps = {
  content: string
}

export function DocsMarkdown({ content }: DocsMarkdownProps) {
  const rendered = useMemo(() => {
    const ast = Markdoc.parse(content)
    const transformed = Markdoc.transform(ast, docsMarkdocConfig)
    return Markdoc.renderers.react(transformed, React, { components: markdocComponents })
  }, [content])

  return (
    <div
      className={cn(DOCS_PROSE_WRAPPER_CLASS, ...DOCS_PROSE_DETAIL_CLASSES)}
    >
      {rendered}
    </div>
  )
}
