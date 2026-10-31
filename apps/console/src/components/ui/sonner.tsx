'use client'

import { useTheme } from 'next-themes'
import { Toaster as Sonner, type ToasterProps } from 'sonner'
import { usePageDirection } from '@/lib/layout/page-direction'
import { PROGRESS_PANEL_OFFSET_VAR } from '@/lib/layout/progress-panel-offset'

/** Matches Tailwind `end-4` and the project progress panel offset. */
const TOAST_INLINE_END_OFFSET = '1rem'

/** Sonner's own viewport offset below 600px. */
const TOAST_MOBILE_OFFSET = '16px'

/**
 * The progress panel (uploads, environment copies, CSV) sits in the same
 * corner on its own fixed layer; its height lifts the toasts above it.
 */
const toastBottomOffset = (base: string) =>
  `calc(${base} + var(${PROGRESS_PANEL_OFFSET_VAR}, 0px))`

const toasterInlineEndStyle = {
  right: 'auto',
  left: 'auto',
  insetInlineStart: 'auto',
  insetInlineEnd: TOAST_INLINE_END_OFFSET,
} as const satisfies React.CSSProperties

const toastInlineEndStyle = {
  right: 'auto',
  left: 'auto',
  insetInlineStart: 'auto',
  insetInlineEnd: 0,
} as const satisfies React.CSSProperties

const Toaster = ({
  style,
  toastOptions,
  dir,
  offset,
  mobileOffset,
  expand,
  ...props
}: ToasterProps) => {
  const { theme = 'system' } = useTheme()
  const pageDirection = usePageDirection()

  return (
    <Sonner
      theme={theme as ToasterProps['theme']}
      dir={dir ?? pageDirection}
      className="toaster toaster-at-inline-end group"
      // Collapsed, toasts fired together pile up with the newest covering the rest
      expand={expand ?? true}
      offset={
        offset ?? {
          right: TOAST_INLINE_END_OFFSET,
          bottom: toastBottomOffset(TOAST_INLINE_END_OFFSET),
        }
      }
      mobileOffset={
        mobileOffset ?? {
          right: TOAST_MOBILE_OFFSET,
          bottom: toastBottomOffset(TOAST_MOBILE_OFFSET),
        }
      }
      style={
        {
          ...toasterInlineEndStyle,
          '--normal-bg': 'var(--popover)',
          '--normal-text': 'var(--popover-foreground)',
          '--normal-border': 'var(--border)',
          ...style,
        } as React.CSSProperties
      }
      toastOptions={{
        ...toastOptions,
        style: {
          ...toastInlineEndStyle,
          ...toastOptions?.style,
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
