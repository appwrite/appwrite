import { cn } from '@/lib/utils'

type TrustedByLogoProps = {
  src: string
  alt: string
  width: number
  height: number
  /** Use SVG as a luminance mask tinted with foreground (e.g. K-Collect). */
  mask?: boolean
  /** Separate mask asset when the display src differs from the mask (e.g. GM, Decathlon). */
  maskSrc?: string
  /** Mask uses inverse treatment for logos that need it on light backgrounds. */
  inverseMask?: boolean
  /** Match hover styling (e.g. open accordion panel). */
  emphasized?: boolean
  /** When false, logo stays static (no scale/opacity hover). */
  interactive?: boolean
  className?: string
}

const maskLogoClassName = (emphasized: boolean, interactive: boolean) =>
  cn(
    'block bg-foreground/75 dark:bg-muted-foreground',
    interactive &&
      'transition duration-200 group-hover:scale-105 group-hover:bg-foreground dark:group-hover:bg-foreground',
    emphasized && 'bg-foreground opacity-100 dark:bg-foreground',
  )

const imageLogoClassName = (emphasized: boolean, interactive: boolean) =>
  cn(
    'max-h-10 max-w-full object-contain opacity-90 [filter:brightness(0.42)] dark:opacity-80 dark:[filter:none]',
    interactive &&
      'transition duration-200 group-hover:scale-105 group-hover:opacity-100 group-hover:[filter:brightness(0)] dark:group-hover:opacity-100 dark:group-hover:[filter:none]',
    emphasized &&
      'opacity-100 [filter:brightness(0)] dark:opacity-100 dark:[filter:none]',
  )

const maskStyle = (maskUrl: string, width: number, height: number) =>
  ({
    width,
    height,
    maskImage: `url(${maskUrl})`,
    maskPosition: 'center',
    maskRepeat: 'no-repeat',
    maskSize: 'contain',
    WebkitMaskImage: `url(${maskUrl})`,
    WebkitMaskPosition: 'center',
    WebkitMaskRepeat: 'no-repeat',
    WebkitMaskSize: 'contain',
  }) as const

export function TrustedByLogo({
  src,
  alt,
  width,
  height,
  mask = false,
  maskSrc,
  inverseMask = false,
  emphasized = false,
  interactive = true,
  className,
}: TrustedByLogoProps) {
  const resolvedMaskSrc = maskSrc ?? src

  if (inverseMask) {
    return (
      <span
        role="img"
        aria-label={alt}
        className={cn(maskLogoClassName(emphasized, interactive), className)}
        style={maskStyle(resolvedMaskSrc, width, height)}
      />
    )
  }

  if (mask) {
    return (
      <span
        role="img"
        aria-label={alt}
        className={cn(maskLogoClassName(emphasized, interactive), className)}
        style={maskStyle(resolvedMaskSrc, width, height)}
      />
    )
  }

  return (
    <img
      src={src}
      alt={alt}
      width={width}
      height={height}
      loading="lazy"
      className={cn(imageLogoClassName(emphasized, interactive), className)}
    />
  )
}
