'use client'

import { useEffect, useRef, useState } from 'react'
import {
  ImagePreviewGalleryDialog,
  type ImagePreviewGalleryItem,
} from '@/components/global/shared/ImagePreviewGallery'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'
import {
  BLOG_BODY_TEXT_CLASS,
  BLOG_PROSE_DETAIL_CLASSES,
} from '@/lib/blog/prose-typography'
import { CHANGELOG_RESOURCE_LINK_GROUP_CLASSES } from '@/components/pages/changelog/ChangelogLinks'

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

export function BlogMarkdownBody({ html, className }: BlogMarkdownBodyProps) {
  const rootRef = useRef<HTMLDivElement>(null)
  const [galleryItems, setGalleryItems] = useState<ImagePreviewGalleryItem[]>(
    [],
  )
  const [activeIndex, setActiveIndex] = useState<number | null>(null)

  useEffect(() => {
    const root = rootRef.current
    if (!root) return

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
            setActiveIndex(index)
            return current
          }
          setActiveIndex(current.length)
          return [...current, item]
        })
        return
      }

      const youtube = target.closest<HTMLElement>('[data-blog-youtube]')
      if (youtube) {
        event.preventDefault()
        const embed = youtube.getAttribute('data-blog-youtube')
        if (embed) window.open(embed, '_blank', 'noopener,noreferrer')
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
        dangerouslySetInnerHTML={{ __html: html }}
      />
      <ImagePreviewGalleryDialog
        items={galleryItems}
        activeIndex={activeIndex}
        onActiveIndexChange={setActiveIndex}
      />
    </>
  )
}
