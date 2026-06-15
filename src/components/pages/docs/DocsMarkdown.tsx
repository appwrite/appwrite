'use client'

import Markdoc from '@markdoc/markdoc'
import React, { useMemo } from 'react'
import { docsMarkdocConfig } from '@/lib/docs/markdoc-config'
import {
  DOCS_BODY_TEXT_CLASS,
  DOCS_PROSE_DETAIL_CLASSES,
  DOCS_PROSE_WRAPPER_CLASS,
} from '@/lib/docs/prose-typography'
import { ImagePreviewGalleryProvider } from '@/components/global/shared/ImagePreviewGallery'
import { cn } from '@/lib/utils'
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
import { Tabs, TabsItem } from './markdoc/Tabs'
import { MarkdocYoutube } from './markdoc/Youtube'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'

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
  Video: ({ src, title }: { src?: string; title?: string }) => (
    <div className="not-prose my-6 overflow-hidden rounded-xl border border-border">
      <video src={src} controls className="w-full" title={title} />
    </div>
  ),
  Youtube: MarkdocYoutube,
}

function createMarkdocComponents(compact: boolean) {
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
    Accordion: ({ children }: { children?: React.ReactNode }) => (
      <Accordion type="single" collapsible className="not-prose my-6">
        {children}
      </Accordion>
    ),
    AccordionItem: ({ title, children }: { title?: string; children?: React.ReactNode }) => (
      <AccordionItem value={title ?? 'item'} className="rounded-lg border border-border px-4">
        <AccordionTrigger
          className={
            compact
              ? 'text-[13px] font-medium hover:no-underline @[480px]:text-[14px]'
              : cn(DOCS_BODY_TEXT_CLASS, 'font-medium hover:no-underline')
          }
        >
          {title}
        </AccordionTrigger>
        <AccordionContent
          className={cn(
            compact
              ? 'text-[13px] leading-[1.6] text-muted-foreground @[480px]:text-[14px]'
              : DOCS_BODY_TEXT_CLASS,
          )}
        >
          {children}
        </AccordionContent>
      </AccordionItem>
    ),
  }
}

type DocsMarkdownProps = {
  content: string
  compact?: boolean
}

export function DocsMarkdown({ content, compact = false }: DocsMarkdownProps) {
  const rendered = useMemo(() => {
    const ast = Markdoc.parse(content)
    const transformed = Markdoc.transform(ast, docsMarkdocConfig)
    return Markdoc.renderers.react(transformed, React, {
      components: createMarkdocComponents(compact),
    })
  }, [content, compact])

  return (
    <ImagePreviewGalleryProvider>
      <div
        className={cn(
          DOCS_PROSE_WRAPPER_CLASS,
          ...DOCS_PROSE_DETAIL_CLASSES,
          compact && 'text-[14px] leading-[1.65] @[480px]:text-[15px]',
        )}
      >
        {rendered}
      </div>
    </ImagePreviewGalleryProvider>
  )
}
