'use client'

import Markdoc from '@markdoc/markdoc'
import React from 'react'
import { docsMarkdocConfig } from '@/lib/docs/markdoc-config'
import { useDocsPrompt } from '@/components/pages/docs/DocsPromptContext'
import { Cards, CardsItem } from './markdoc/Cards'
import { Fence } from './markdoc/Fence'
import { MarkdocIcon, MarkdocIconImage } from './markdoc/Icon'
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
import { MarkdocAccordion, MarkdocAccordionItem } from './markdoc/Accordion'
import { Tabs, TabsItem } from './markdoc/Tabs'
import { MarkdocYoutube } from './markdoc/Youtube'

function PromptContentMarkdoc() {
  const { promptText } = useDocsPrompt()
  if (!promptText?.trim()) return null
  return renderDocsMarkdocReact(promptText, false)
}

const baseMarkdocComponents = {
  MultiCode,
  Fence,
  Tabs,
  TabsItem,
  MarkdocIcon,
  MarkdocIconImage,
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
  PromptContent: PromptContentMarkdoc,
  Video: ({ src, title }: { src?: string; title?: string }) => (
    <div className="not-prose my-6 overflow-hidden rounded-xl border border-border">
      <video src={src} controls className="w-full" title={title} />
    </div>
  ),
  Youtube: MarkdocYoutube,
}

export function createDocsMarkdocComponents(compact: boolean) {
  return {
    ...baseMarkdocComponents,
    Info: (props: { title: string; children?: React.ReactNode }) => (
      <Info {...props} compact={compact} />
    ),
    Cards,
    CardsItem: (props: React.ComponentProps<typeof CardsItem>) => (
      <CardsItem {...props} compact={compact} />
    ),
    Heading: (props: React.ComponentProps<typeof Heading>) => (
      <Heading {...props} compact={compact} />
    ),
    Accordion: MarkdocAccordion,
    AccordionItem: (props: React.ComponentProps<typeof MarkdocAccordionItem>) => (
      <MarkdocAccordionItem {...props} compact={compact} />
    ),
  }
}

export function renderDocsMarkdocReact(content: string, compact = false) {
  const ast = Markdoc.parse(content)
  const transformed = Markdoc.transform(ast, docsMarkdocConfig)
  return Markdoc.renderers.react(transformed, React, {
    components: createDocsMarkdocComponents(compact),
  })
}
