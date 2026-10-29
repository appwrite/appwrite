import { cn } from '@/lib/utils'

/**
 * Solid cloud silhouette (Heroicons 24/solid Cloud - MIT).
 * Same mark as {@link ConsoleHeaderLogo} cloud face; uses `currentColor`.
 */
export function CloudMarkIcon({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="currentColor"
      className={cn('h-6 w-6 shrink-0', className)}
      aria-hidden
    >
      <path
        fillRule="evenodd"
        d="M4.5 9.75a6 6 0 0 1 11.573-2.226 3.75 3.75 0 0 1 4.133 4.303A4.5 4.5 0 0 1 18 20.25H6.75a5.25 5.25 0 0 1-2.23-10.004 6.072 6.072 0 0 1-.02-.496Z"
        clipRule="evenodd"
      />
    </svg>
  )
}
