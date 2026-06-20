'use client'

import Markdoc from '@markdoc/markdoc'
import React, { useMemo, type ReactNode } from 'react'
import { MarkdocAccordion, MarkdocAccordionItem } from '@/components/pages/docs/markdoc/Accordion'
import { Cards, CardsItem } from '@/components/pages/docs/markdoc/Cards'
import { Fence } from '@/components/pages/docs/markdoc/Fence'
import { MarkdocIcon, MarkdocIconImage } from '@/components/pages/docs/markdoc/Icon'
import { Info } from '@/components/pages/docs/markdoc/Info'
import { MultiCode } from '@/components/pages/docs/markdoc/MultiCode'
import { DocsImage } from '@/components/pages/docs/markdoc/DocsImage'
import {
  Blockquote,
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
} from '@/components/pages/docs/markdoc/Nodes'
import { Tabs, TabsItem } from '@/components/pages/docs/markdoc/Tabs'
import { MarkdocYoutube } from '@/components/pages/docs/markdoc/Youtube'
import { docsMarkdocConfig } from '@/lib/docs/markdoc-config'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import {
  BLOG_BODY_TEXT_SIZE_CLASS,
  BLOG_PROSE_DETAIL_CLASSES,
  type MarkdocProseVariant,
} from '@/lib/blog/prose-typography'
import {
  DOCS_BODY_TEXT_CLASS,
  DOCS_PROSE_DETAIL_CLASSES,
} from '@/lib/docs/prose-typography'
import { MARKETING_SITE_ORIGIN } from '@/lib/marketing/urls'
import { ImagePreviewGalleryProvider } from '@/components/global/shared/ImagePreviewGallery'
import { cn } from '@/lib/utils'
import {
  CHANGELOG_RESOURCE_LINK_GROUP_CLASSES,
  ChangelogArrowLink,
  ChangelogLink,
} from './ChangelogLinks'

const markdocComponents = {
  MultiCode,
  Fence,
  Info,
  Tabs,
  TabsItem,
  Cards,
  CardsItem,
  MarkdocIcon,
  MarkdocIconImage,
  Heading,
  Link: ChangelogLink,
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
  Accordion: MarkdocAccordion,
  AccordionItem: MarkdocAccordionItem,
  Section: () => null,
  ArrowLink: ({ href, children }: { href?: string; children?: ReactNode }) => (
    <ChangelogArrowLink href={href}>{children}</ChangelogArrowLink>
  ),
  CallToAction: ({ href, title }: { href?: string; title?: string }) => (
    <div className="not-prose my-6">
      <a
        href={href?.startsWith('/') ? `${MARKETING_SITE_ORIGIN}${href}` : href}
        className="inline-flex items-center rounded-lg bg-[var(--brand-cta)] px-4 py-2 text-[13px] font-medium text-white hover:opacity-90"
        target={href?.startsWith('/') ? '_blank' : undefined}
        rel={href?.startsWith('/') ? 'noopener noreferrer' : undefined}
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

type ChangelogMarkdownProps = {
  content: string
  className?: string
  bodyTextClass?: string
  linkClassName?: string
  arrowLinkTextClass?: string
  proseVariant?: MarkdocProseVariant
}

export function ChangelogMarkdown({
  content,
  className,
  bodyTextClass,
  linkClassName,
  arrowLinkTextClass,
  proseVariant = 'docs',
}: ChangelogMarkdownProps) {
  const proseBodyClass = bodyTextClass ?? DOCS_BODY_TEXT_CLASS
  const proseLinkClass = linkClassName ?? DOCS_PROSE_LINK_CLASS
  const proseDetailClasses =
    proseVariant === 'blog' ? BLOG_PROSE_DETAIL_CLASSES : DOCS_PROSE_DETAIL_CLASSES

  const rendered = useMemo(() => {
    const ast = Markdoc.parse(content)
    const transformed = Markdoc.transform(ast, docsMarkdocConfig)
    return Markdoc.renderers.react(transformed, React, {
      components: {
        ...markdocComponents,
        Link: (props: { href?: string; children?: ReactNode; className?: string }) => (
          <ChangelogLink {...props} className={props.className ?? proseLinkClass} />
        ),
        ArrowLink: ({ href, children }: { href?: string; children?: ReactNode }) => (
          <ChangelogArrowLink href={href} textClassName={arrowLinkTextClass}>
            {children}
          </ChangelogArrowLink>
        ),
        Heading: (props: { level?: number; id?: string; children?: ReactNode }) => (
          <Heading {...props} proseVariant={proseVariant} />
        ),
        Blockquote: (props: { children?: ReactNode }) => (
          <Blockquote {...props} proseVariant={proseVariant} />
        ),
        Info: (props: { title: string; children?: ReactNode }) => (
          <Info {...props} proseVariant={proseVariant} />
        ),
        Tabs: (props: { children?: ReactNode }) => (
          <Tabs {...props} proseVariant={proseVariant} />
        ),
        MarkdocTableCell: (props: { children?: ReactNode }) => (
          <MarkdocTableCell {...props} proseVariant={proseVariant} />
        ),
        AccordionItem: ({
          title,
          children: itemChildren,
        }: {
          title?: string
          children?: React.ReactNode
        }) => (
          <AccordionItem value={title ?? 'item'} className="rounded-lg border border-border px-4">
            <AccordionTrigger className={cn(proseBodyClass, 'font-medium hover:no-underline')}>
              {title}
            </AccordionTrigger>
            <AccordionContent className={proseBodyClass}>{itemChildren}</AccordionContent>
          </AccordionItem>
        ),
        CallToAction: ({ href, title }: { href?: string; title?: string }) => (
          <div className="not-prose my-6">
            <a
              href={href?.startsWith('/') ? `${MARKETING_SITE_ORIGIN}${href}` : href}
              className={cn(
                'inline-flex items-center rounded-lg bg-[var(--brand-cta)] px-4 py-2 font-medium text-white hover:opacity-90',
                proseVariant === 'blog'
                  ? BLOG_BODY_TEXT_SIZE_CLASS
                  : 'text-[13px]',
              )}
              target={href?.startsWith('/') ? '_blank' : undefined}
              rel={href?.startsWith('/') ? 'noopener noreferrer' : undefined}
            >
              {title}
            </a>
          </div>
        ),
      },
    })
  }, [content, proseBodyClass, proseLinkClass, arrowLinkTextClass, proseVariant])

  return (
    <ImagePreviewGalleryProvider>
      <div
        className={cn(
          'docs-prose',
          proseBodyClass,
          ...proseDetailClasses,
          ...CHANGELOG_RESOURCE_LINK_GROUP_CLASSES,
          className,
        )}
      >
        {rendered}
      </div>
    </ImagePreviewGalleryProvider>
  )
}
