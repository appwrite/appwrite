export type BrandColor = {
  name: string
  hex: string
  textClassName: string
  backgroundClassName: string
}

export type ProductVisual = {
  title: string
  imageSrc: string
  downloadHref: string
}

export const brandColors: readonly BrandColor[] = [
  {
    name: 'Light Grey',
    hex: '#EDEDF0',
    textClassName: 'text-[#19191D]',
    backgroundClassName: 'bg-[#EDEDF0]',
  },
  {
    name: 'Dark Grey',
    hex: '#19191D',
    textClassName: 'text-[#EDEDF0]',
    backgroundClassName: 'bg-[#19191D]',
  },
  {
    name: 'Appwrite Pink',
    hex: '#FD366E',
    textClassName: 'text-white',
    backgroundClassName: 'bg-[#FD366E]',
  },
]

export const productVisuals: readonly ProductVisual[] = [
  {
    title: 'Dashboard',
    imageSrc: '/assets/visuals/dashboard.avif',
    downloadHref: '/assets/visuals/dashboard.avif',
  },
  {
    title: 'Appwrite Auth',
    imageSrc: '/assets/visuals/auth.avif',
    downloadHref: '/assets/visuals/auth.avif',
  },
  {
    title: 'Appwrite Databases',
    imageSrc: '/assets/visuals/databases.avif',
    downloadHref: '/assets/visuals/databases.avif',
  },
  {
    title: 'Appwrite Storage',
    imageSrc: '/assets/visuals/storage.avif',
    downloadHref: '/assets/visuals/storage.avif',
  },
  {
    title: 'Appwrite Functions',
    imageSrc: '/assets/visuals/functions.avif',
    downloadHref: '/assets/visuals/functions.avif',
  },
  {
    title: 'Appwrite Messaging',
    imageSrc: '/assets/visuals/messaging.avif',
    downloadHref: '/assets/visuals/messaging.avif',
  },
]

export const assetsTocSections = [
  { id: 'naming', label: 'Naming' },
  { id: 'logotype', label: 'Logotype' },
  { id: 'logomark', label: 'Logomark' },
  { id: 'brand-colors', label: 'Brand colors' },
  { id: 'product-visuals', label: 'Product visuals' },
  { id: 'contact-us', label: 'Contact us' },
] as const
