'use client'

import { startTransition, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  ImagePreviewGalleryDialog,
  type ImagePreviewGalleryItem,
} from '@/components/global/shared/ImagePreviewGallery'
import { YoutubePlayerDialog } from '@/components/global/shared/YoutubePlayerDialog'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'
import {
  BLOG_BODY_TEXT_CLASS,
  BLOG_PROSE_DETAIL_CLASSES,
} from '@/lib/blog/prose-typography'
import { CHANGELOG_RESOURCE_LINK_GROUP_CLASSES } from '@/components/pages/changelog/ChangelogLinks'
import {
  BlogMultiCodeSelect,
  type BlogMultiCodeLanguage,
} from './BlogMultiCodeSelect'

type BlogMarkdownBodyProps = {
  html: string
  className?: string
}

function itemFromButton(button: HTMLElement): ImagePreviewGalleryItem | null {
  const src = button.getAttribute('data-blog-image-src')
  if (!src) return null
  return {
    src,
    alt: button.getAttribute('data-blog-image-alt') ?? '',
  }
}

type MultiCodeMount = {
  key: number
  element: HTMLElement
  group: HTMLElement
  languages: BlogMultiCodeLanguage[]
}

function multiCodeMounts(root: HTMLElement): MultiCodeMount[] {
  return [
    ...root.querySelectorAll<HTMLElement>('[data-blog-multicode-select]'),
  ].flatMap((element, key) => {
    const group = element.closest<HTMLElement>('[data-blog-multicode]')
    if (!group) return []
    try {
      const languages = JSON.parse(
        element.getAttribute('data-blog-multicode-select') ?? '[]',
      ) as BlogMultiCodeLanguage[]
      if (languages.length === 0) return []
      element.textContent = ''
      return [{ key, element, group, languages }]
    } catch {
      return []
    }
  })
}

const TAB_ACTIVE_CLASSES = ['border-white', 'text-foreground']
const TAB_INACTIVE_CLASSES = [
  'border-transparent',
  'text-muted-foreground',
  'hover:text-foreground',
]

function selectBlogTab(group: HTMLElement, tabId: string) {
  for (const button of group.querySelectorAll<HTMLElement>('[data-blog-tab]')) {
    if (button.closest('[data-blog-tabs]') !== group) continue
    const active = button.getAttribute('data-blog-tab') === tabId
    button.setAttribute('aria-selected', String(active))
    button.classList.remove(...TAB_ACTIVE_CLASSES, ...TAB_INACTIVE_CLASSES)
    button.classList.add(...(active ? TAB_ACTIVE_CLASSES : TAB_INACTIVE_CLASSES))
  }
  for (const panel of group.querySelectorAll<HTMLElement>(
    '[data-blog-tab-panel]',
  )) {
    if (panel.closest('[data-blog-tabs]') !== group) continue
    panel.hidden = panel.getAttribute('data-blog-tab-panel') !== tabId
  }
}

export function BlogMarkdownBody({ html, className }: BlogMarkdownBodyProps) {
  const rootRef = useRef<HTMLDivElement>(null)
  const [galleryItems, setGalleryItems] = useState<ImagePreviewGalleryItem[]>(
    [],
  )
  const [activeIndex, setActiveIndex] = useState<number | null>(null)
  const [multiCode, setMultiCode] = useState<MultiCodeMount[]>([])
  const [youtube, setYoutube] = useState<{ embed: string; title: string } | null>(
    null,
  )
  const [youtubeOpen, setYoutubeOpen] = useState(false)
  // React rewrites innerHTML whenever this object changes identity, which
  // would detach the portal targets below on every state update.
  const innerHtml = useMemo(() => ({ __html: html }), [html])

  useEffect(() => {
    const root = rootRef.current
    if (!root) return

    setMultiCode(multiCodeMounts(root))

    const imageButtons = [
      ...root.querySelectorAll<HTMLElement>('[data-blog-image-src]'),
    ]
    setGalleryItems(
      imageButtons
        .map(itemFromButton)
        .filter((item): item is ImagePreviewGalleryItem => Boolean(item)),
    )

    const onClick = (event: MouseEvent) => {
      const target = event.target
      if (!(target instanceof Element)) return

      const copyButton = target.closest<HTMLElement>('[data-blog-copy]')
      if (copyButton) {
        event.preventDefault()
        const encoded = copyButton.getAttribute('data-blog-copy') ?? ''
        void copyToClipboard('Code', decodeURIComponent(encoded))
        return
      }

      const tabButton = target.closest<HTMLElement>('[data-blog-tab]')
      if (tabButton) {
        const group = tabButton.closest<HTMLElement>('[data-blog-tabs]')
        if (!group) return
        event.preventDefault()
        selectBlogTab(group, tabButton.getAttribute('data-blog-tab') ?? '')
        return
      }

      const headingCopy = target.closest<HTMLElement>('[data-blog-heading-copy]')
      if (headingCopy) {
        event.preventDefault()
        const id = headingCopy.getAttribute('data-blog-heading-copy')
        if (!id) return
        void copyToClipboard(
          'Link',
          `${window.location.origin}${window.location.pathname}${window.location.search}#${id}`,
        )
        return
      }

      const imageButton = target.closest<HTMLElement>('[data-blog-image-src]')
      if (imageButton) {
        event.preventDefault()
        const item = itemFromButton(imageButton)
        if (!item) return
        setGalleryItems((current) => {
          const index = current.findIndex(
            (entry) => entry.src === item.src && entry.alt === item.alt,
          )
          if (index >= 0) {
            startTransition(() => setActiveIndex(index))
            return current
          }
          startTransition(() => setActiveIndex(current.length))
          return [...current, item]
        })
        return
      }

      const youtubeButton = target.closest<HTMLElement>('[data-blog-youtube]')
      if (youtubeButton) {
        event.preventDefault()
        const embed = youtubeButton.getAttribute('data-blog-youtube')
        if (!embed) return
        startTransition(() => {
          setYoutube({
            embed,
            title:
              youtubeButton.getAttribute('data-blog-youtube-title') ??
              'YouTube video',
          })
          setYoutubeOpen(true)
        })
      }
    }

    root.addEventListener('click', onClick)
    return () => root.removeEventListener('click', onClick)
  }, [html])

  return (
    <>
      <div
        ref={rootRef}
        className={cn(
          'docs-prose',
          BLOG_BODY_TEXT_CLASS,
          ...BLOG_PROSE_DETAIL_CLASSES,
          ...CHANGELOG_RESOURCE_LINK_GROUP_CLASSES,
          className,
        )}
        dangerouslySetInnerHTML={innerHtml}
      />
      {multiCode.map(({ key, element, group, languages }) =>
        createPortal(
          <BlogMultiCodeSelect group={group} languages={languages} />,
          element,
          `multicode-${key}`,
        ),
      )}
      <ImagePreviewGalleryDialog
        items={galleryItems}
        activeIndex={activeIndex}
        onActiveIndexChange={setActiveIndex}
      />
      <YoutubePlayerDialog
        open={youtubeOpen}
        onOpenChange={setYoutubeOpen}
        embed={youtube?.embed ?? null}
        title={youtube?.title}
      />
    </>
  )
}
