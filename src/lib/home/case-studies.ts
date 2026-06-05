export type HomeCaseStudy = {
  id: string
  logo: string
  logoWidth: number
  logoHeight: number
  /** K-Collect and similar logos use a foreground mask in light/dark mode. */
  logoMask?: boolean
  headline: string
  blurb: string
  name: string
  title: string
  company: string
  avatar: string
  storyUrl: string
}

/** Featured customer stories for the home page testimonials accordion. */
export const homeCaseStudies: HomeCaseStudy[] = [
  {
    id: 'myshoefitter',
    logo: '/images/logos/trusted-by/myshoefitter.svg',
    logoWidth: 197,
    logoHeight: 32,
    headline: 'mySHOEFITTER sized 12,000+ feet accurately for major EU retailers',
    blurb:
      'The integrated user authentication and the ease of creating data structures have undoubtedly saved us several weeks’ worth of time.',
    name: 'Marius Bolik',
    title: 'CTO',
    company: 'mySHOEFITTER',
    avatar: '/images/testimonials/marius-bolik2.avif',
    storyUrl: 'https://appwrite.io/blog/post/customer-stories-myshoefitter',
  },
  {
    id: 'devkind',
    logo: '/images/logos/trusted-by/devkind.svg',
    logoWidth: 107,
    logoHeight: 32,
    headline: 'DevKind reduced development time by 60% and lowered server costs by 40%',
    blurb:
      'A special thanks to Appwrite for providing robust features and seamless functionality.',
    name: 'Hassan Ahmed',
    title: 'Software Engineer',
    company: 'DevKind',
    avatar: '/images/testimonials/hassan.avif',
    storyUrl: 'https://appwrite.io/blog/post/customer-story-storealert',
  },
  {
    id: 'k-collect',
    logo: '/images/logos/trusted-by/k-collect.svg',
    logoWidth: 110,
    logoHeight: 35,
    logoMask: true,
    headline: 'K-Collect reduced infrastructure costs by 700%',
    blurb: 'A major impact that Appwrite made was the amount of time and stress saved.',
    name: "Ryan O'Connor",
    title: 'Founder',
    company: 'K-Collect',
    avatar: '/images/testimonials/ryan.avif',
    storyUrl: 'https://appwrite.io/blog/post/customer-stories-kcollect',
  },
]
