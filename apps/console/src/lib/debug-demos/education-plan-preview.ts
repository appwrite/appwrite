export const EDUCATION_PLAN_PREVIEW_VIEWS = [
  'reminder',
  'last-day',
  'ended',
  'ended-single-org',
] as const

export type EducationPlanPreviewView =
  (typeof EDUCATION_PLAN_PREVIEW_VIEWS)[number]
