export const COMPANY_SECTION_IDS = {
  story: 'story',
  founder: 'founder',
  team: 'team',
  investors: 'investors',
  careers: 'careers',
} as const

export type CompanyPageSection = {
  id: (typeof COMPANY_SECTION_IDS)[keyof typeof COMPANY_SECTION_IDS]
  label: string
}

export const companyPageSections: readonly CompanyPageSection[] = [
  { id: COMPANY_SECTION_IDS.story, label: 'Our story' },
  { id: COMPANY_SECTION_IDS.team, label: 'Team' },
  { id: COMPANY_SECTION_IDS.founder, label: 'Founder' },
  { id: COMPANY_SECTION_IDS.investors, label: 'Investors' },
  { id: COMPANY_SECTION_IDS.careers, label: 'Careers' },
] as const
