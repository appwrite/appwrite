import { Check, Copy, Download } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { PolicyToc } from '@/components/pages/legal/PolicyToc'
import { PricingSectionHeading } from '@/components/pages/pricing/_components/PricingSectionHeading'
import { Button } from '@/components/ui/button'
import {
  assetsTocSections,
  brandColors,
  productVisuals,
} from '@/lib/assets/brand-data'
import { cn } from '@/lib/utils'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'

const assetCardClassName =
  'overflow-hidden rounded-xl border border-border bg-card/50'

function AssetDownloadButtons({
  svgHref,
  rasterHref,
  className,
}: {
  svgHref: string
  rasterHref: string
  className?: string
}) {
  return (
    <div
      className={cn(
        'flex gap-2 opacity-0 transition-opacity group-hover:opacity-100',
        className,
      )}
    >
      <Button variant="secondary" size="sm" className="h-8 gap-1.5 text-[12px]" asChild>
        <a href={svgHref} download>
          <Download className="h-3.5 w-3.5" />
          SVG
        </a>
      </Button>
      <Button variant="secondary" size="sm" className="h-8 gap-1.5 text-[12px]" asChild>
        <a href={rasterHref} download>
          <Download className="h-3.5 w-3.5" />
          PNG
        </a>
      </Button>
    </div>
  )
}

function ColorSwatch({
  name,
  hex,
  backgroundClassName,
}: {
  name: string
  hex: string
  textClassName: string
  backgroundClassName: string
}) {
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(hex)
      setCopied(true)
      toast.success('Copied to clipboard')
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      toast.error('Failed to copy color')
    }
  }

  return (
    <div className={assetCardClassName}>
      <div className={cn('aspect-[5/3] w-full', backgroundClassName)} />
      <div className="flex items-center justify-between gap-3 border-t border-border bg-muted/30 px-4 py-3">
        <div className="min-w-0">
          <p className="truncate text-[13px] font-medium text-foreground">{name}</p>
          <p className="font-mono text-[12px] text-muted-foreground">{hex}</p>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 shrink-0 gap-1.5 px-2.5 text-[12px]"
          onClick={handleCopy}
          aria-label={`Copy ${hex}`}
        >
          {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
          {copied ? 'Copied' : 'Copy'}
        </Button>
      </div>
    </div>
  )
}

function LogoPreview({
  src,
  alt,
  label,
  previewClassName,
  svgHref,
  rasterHref,
}: {
  src: string
  alt: string
  label: string
  previewClassName: string
  svgHref: string
  rasterHref: string
}) {
  return (
    <div className={cn(assetCardClassName, 'group')}>
      <div className="border-b border-border bg-muted/20 p-4 sm:p-5">
        <div
          className={cn(
            'flex min-h-[240px] items-center justify-center rounded-lg border border-border/70 p-8 shadow-sm',
            previewClassName,
          )}
        >
          <img src={src} alt={alt} className="max-h-16 w-full max-w-[240px] object-contain" />
        </div>
      </div>
      <div className="flex items-center justify-between gap-3 bg-muted/30 px-4 py-3">
        <p className="text-[13px] font-medium text-foreground">{label}</p>
        <AssetDownloadButtons svgHref={svgHref} rasterHref={rasterHref} className="opacity-100 sm:opacity-0 sm:group-hover:opacity-100" />
      </div>
    </div>
  )
}

function CoBrandPreview({
  src,
  alt,
  label,
}: {
  src: string
  alt: string
  label: string
}) {
  return (
    <div className={assetCardClassName}>
      <div className="border-b border-border bg-muted/20 p-4 sm:p-5">
        <div className="flex min-h-[220px] items-center justify-center rounded-lg border border-border/70 bg-[#19191D] p-8 shadow-sm">
          <img src={src} alt={alt} className="max-h-20 w-full max-w-md object-contain" />
        </div>
      </div>
      <div className="bg-muted/30 px-4 py-3">
        <p className="text-[13px] font-medium text-foreground">{label}</p>
      </div>
    </div>
  )
}

function ProductVisualCard({
  title,
  imageSrc,
  downloadHref,
}: {
  title: string
  imageSrc: string
  downloadHref: string
}) {
  return (
    <div className={cn(assetCardClassName, 'group')}>
      <div className="border-b border-border bg-muted/20 p-4 sm:p-5">
        <div className="overflow-hidden rounded-lg border border-border/70 bg-background shadow-sm">
          <img
            src={imageSrc}
            alt={title}
            className="aspect-video w-full object-cover"
          />
        </div>
      </div>
      <div className="flex items-center justify-between gap-3 bg-muted/30 px-4 py-3">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <Button
          variant="outline"
          size="sm"
          className="h-8 gap-1.5 text-[12px] opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
          asChild
        >
          <a href={downloadHref} download>
            <Download className="h-3.5 w-3.5" />
            Download
          </a>
        </Button>
      </div>
    </div>
  )
}

export function View() {
  return (
    <div className="relative">
      <HomeSoftLights variant="pricing" className="pointer-events-none opacity-70" />

      <div className="relative mx-auto w-full max-w-7xl px-4 pb-16 pt-12 sm:px-6 sm:pb-20 sm:pt-16">
        <div className="grid items-start gap-10 lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-16 xl:grid-cols-[240px_minmax(0,1fr)]">
          <header className="space-y-4 lg:col-span-2">
            <PricingSectionHeading
              as="h1"
              align="left"
              title="Brand assets"
              description="Resources for presenting the Appwrite brand to maintain consistency while using our logos, colors, and other brand elements across various platforms and materials."
              className="max-w-2xl"
            />
            <Button variant="brandCta" className="gap-1.5" asChild>
              <a href="/assets.zip" download>
                <Download className="h-4 w-4" />
                Download assets
              </a>
            </Button>
          </header>

          <PolicyToc items={assetsTocSections} />

          <div className="min-w-0 space-y-12 overflow-x-hidden">
            <section id="naming" className="scroll-mt-24 space-y-3">
              <h2 className="font-aeonik-pro text-[24px] font-normal text-foreground">
                Naming
              </h2>
              <p className="text-[14px] leading-7 text-muted-foreground">
                Write &apos;Appwrite,&apos; with a lowercase &apos;w&apos; and no space between the
                two words. Please refrain from using variations like &apos;AppWrite&apos;
                or &apos;App Write&apos;.
              </p>
            </section>

            <section id="logotype" className="scroll-mt-24 space-y-4">
              <h2 className="font-aeonik-pro text-[24px] font-normal text-foreground">
                Logotype
              </h2>
              <p className="text-[14px] leading-7 text-muted-foreground">
                The Appwrite logo stands as a prominent symbol of our brand&apos;s
                identity. Refrain from altering our logo and preferably use our
                logo on a neutral background.
              </p>
              <div className="grid gap-4 md:grid-cols-2">
                <LogoPreview
                  src="/assets/logotype/white.svg"
                  alt="Appwrite logo with black text"
                  label="Light background"
                  previewClassName="bg-[#EDEDF0]"
                  svgHref="/assets/logotype/white.svg"
                  rasterHref="/assets/logotype/white.avif"
                />
                <LogoPreview
                  src="/assets/logotype/black.svg"
                  alt="Appwrite logo with white text"
                  label="Dark background"
                  previewClassName="bg-[#19191D]"
                  svgHref="/assets/logotype/black.svg"
                  rasterHref="/assets/logotype/black.avif"
                />
              </div>

              <div className="space-y-3 pt-2">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Co-branding logotypes
                </h3>
                <p className="text-[14px] leading-7 text-muted-foreground">
                  Spacing is determined by the Appwrite mark. Unless otherwise
                  noted by partner brands, each logo is optically equal as a
                  collection of shapes.
                </p>
                <CoBrandPreview
                  src="/assets/logotype/co-brand.svg"
                  alt="Appwrite logo besides a generic logo"
                  label="Co-branding logotype example"
                />
              </div>
            </section>

            <section id="logomark" className="scroll-mt-24 space-y-4">
              <h2 className="font-aeonik-pro text-[24px] font-normal text-foreground">
                Logomark
              </h2>
              <p className="text-[14px] leading-7 text-muted-foreground">
                While prioritizing recognizability, the logotype is the
                recommended choice. Using the Appwrite logomark is suitable for
                situations where space constraints make it challenging to
                showcase the complete logotype.
              </p>
              <div className="grid gap-4 md:grid-cols-2">
                <LogoPreview
                  src="/assets/logomark/logo.svg"
                  alt="Appwrite logomark"
                  label="Light background"
                  previewClassName="bg-[#EDEDF0]"
                  svgHref="/assets/logomark/logo.svg"
                  rasterHref="/assets/logomark/logo.avif"
                />
                <LogoPreview
                  src="/assets/logomark/logo.svg"
                  alt="Appwrite logomark on dark background"
                  label="Dark background"
                  previewClassName="bg-[#19191D]"
                  svgHref="/assets/logomark/logo.svg"
                  rasterHref="/assets/logomark/logo.avif"
                />
              </div>

              <div className="space-y-3 pt-2">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Co-branding lockups
                </h3>
                <p className="text-[14px] leading-7 text-muted-foreground">
                  Spacing is determined by the Appwrite mark. Unless otherwise
                  noted by partner brands, each logo is optically equal as a
                  collection of shapes.
                </p>
                <CoBrandPreview
                  src="/assets/logomark/co-brand.svg"
                  alt="Logomark cobrand example"
                  label="Co-branding lockup example"
                />
              </div>
            </section>

            <section id="brand-colors" className="scroll-mt-24 space-y-4">
              <h2 className="font-aeonik-pro text-[24px] font-normal text-foreground">
                Brand colors
              </h2>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {brandColors.map((color) => (
                  <ColorSwatch key={color.hex} {...color} />
                ))}
              </div>
            </section>

            <section id="product-visuals" className="scroll-mt-24 space-y-4">
              <h2 className="font-aeonik-pro text-[24px] font-normal text-foreground">
                Product visuals
              </h2>
              <p className="text-[14px] leading-7 text-muted-foreground">
                Use these product visuals to enhance your articles,
                presentations, and content related to Appwrite.
              </p>
              <div className="grid gap-4 sm:grid-cols-2">
                {productVisuals.map((visual) => (
                  <ProductVisualCard key={visual.title} {...visual} />
                ))}
              </div>
            </section>

            <section id="contact-us" className="scroll-mt-24 space-y-3">
              <h2 className="font-aeonik-pro text-[24px] font-normal text-foreground">
                Contact us
              </h2>
              <p className="text-[14px] leading-7 text-muted-foreground">
                Should you require further assistance or have specific needs beyond
                what&apos;s presented on this page, please don&apos;t hesitate to{' '}
                <MarketingSiteLink className="link-neutral" href="/enterprise">
                  contact us
                </MarketingSiteLink>
                .
              </p>
            </section>
          </div>
        </div>
      </div>
    </div>
  )
}
