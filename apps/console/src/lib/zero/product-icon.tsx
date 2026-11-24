import type { SVGProps } from 'react'
import type { ProductIcon } from '@/lib/products/types'

/**
 * A slashed zero, the way code fonts draw it: the command a developer or agent
 * types to get a project. Drawn on lucide's 24px grid so it sits with the other
 * product icons and takes the same `strokeWidth`.
 */
function ZeroIcon({ strokeWidth = 2, ...props }: SVGProps<SVGSVGElement>) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width={24}
      height={24}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      {...props}
    >
      <rect x="5.5" y="2.5" width="13" height="19" rx="6.5" />
      <path d="M9.5 15.5 14.5 8.5" />
    </svg>
  )
}

/** Icon for Appwrite Zero everywhere it appears: the page badge, the Products menu and the footer. */
export const ZERO_PRODUCT_ICON: ProductIcon = ZeroIcon
