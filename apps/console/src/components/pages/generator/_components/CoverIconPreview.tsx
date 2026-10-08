import { createElement, useEffect, useState } from 'react'
import { useTheme } from 'next-themes'
import {
  getCachedCoverLucideIconNode,
  loadCoverLucideIconNode,
  type CoverLucideIconNode,
} from '@/lib/cover-generator/lucide-icon-svg'
import {
  isCoverLucideIconValue,
  parseCoverLucideIconName,
} from '@/lib/cover-generator/lucide-icon-utils'
import {
  getCoverIconPreviewClassesForFamily,
  getCoverLucideIconStrokeColorForFamily,
  getCoverIconPreviewClasses,
} from '@/lib/cover-generator/cover-icon-tone'
import type { CoverThemeFamily, CoverThemeId } from '@/lib/cover-generator/themes'
import { getCoverTheme, resolveCoverThemeId } from '@/lib/cover-generator/themes'
import { cn } from '@/lib/utils'

type CoverIconPreviewProps = {
  src: string
  /** Cover template theme; used when colorMode is "cover" (default). */
  themeId?: CoverThemeId | null
  /** "app" ties icon tint to the console UI theme; "cover" uses the template theme. */
  colorMode?: 'cover' | 'app'
  className?: string
  size?: number
}

function useCoverIconPreviewFamily(
  colorMode: 'cover' | 'app',
  themeId: CoverThemeId | null | undefined,
): CoverThemeFamily {
  const { resolvedTheme } = useTheme()

  if (colorMode === 'app') {
    return resolvedTheme === 'dark' ? 'dark' : 'light'
  }

  return getCoverTheme(resolveCoverThemeId(themeId)).family
}

function CoverLucideIconPreview({
  iconNode,
  stroke,
  size,
  className,
}: {
  iconNode: CoverLucideIconNode
  stroke: string
  size: number
  className?: string
}) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke={stroke}
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={cn('shrink-0', className)}
      aria-hidden
    >
      {iconNode.map(([tag, attrs], index) => {
        const { key: reactKey, ...rest } = attrs

        return createElement(tag, {
          ...rest,
          key: reactKey ?? index,
          fill: 'none',
          stroke,
          strokeWidth: 2,
          strokeLinecap: 'round',
          strokeLinejoin: 'round',
        })
      })}
    </svg>
  )
}

function CoverLucideIconPreviewLoader({
  name,
  family,
  size,
  className,
}: {
  name: string
  family: CoverThemeFamily
  size: number
  className?: string
}) {
  const [iconNode, setIconNode] = useState<CoverLucideIconNode | null>(() =>
    getCachedCoverLucideIconNode(name),
  )
  const stroke = getCoverLucideIconStrokeColorForFamily(family)
  const previewClasses = getCoverIconPreviewClassesForFamily(family)

  useEffect(() => {
    let cancelled = false

    void loadCoverLucideIconNode(name).then((node) => {
      if (cancelled) return
      setIconNode(node)
    })

    return () => {
      cancelled = true
    }
  }, [name])

  if (!iconNode) {
    return (
      <span
        aria-hidden
        className={cn('inline-block shrink-0 rounded bg-muted/40', className)}
        style={{ width: size, height: size }}
      />
    )
  }

  return (
    <CoverLucideIconPreview
      iconNode={iconNode}
      stroke={stroke}
      size={size}
      className={cn(previewClasses, className)}
    />
  )
}

export function CoverIconPreview({
  src,
  themeId,
  colorMode = 'cover',
  className,
  size = 32,
}: CoverIconPreviewProps) {
  const family = useCoverIconPreviewFamily(colorMode, themeId)

  if (isCoverLucideIconValue(src)) {
    const name = parseCoverLucideIconName(src)
    if (!name) return null

    return (
      <CoverLucideIconPreviewLoader
        name={name}
        family={family}
        size={size}
        className={className}
      />
    )
  }

  const previewClasses =
    colorMode === 'app'
      ? getCoverIconPreviewClassesForFamily(family)
      : getCoverIconPreviewClasses(resolveCoverThemeId(themeId))

  return (
    <img
      src={src}
      alt=""
      draggable={false}
      className={cn('shrink-0 object-contain', previewClasses, className)}
      style={{ width: size, height: size }}
    />
  )
}
