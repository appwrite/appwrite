/**
 * Hebrew translations for the Review product (AI code review of pull
 * requests: reviews list, review detail, and repositories).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heReviewsDictionary: Record<string, string> = {
  // Navigation and titles
  Review: 'סקירת קוד',
  Reviews: 'סקירות',
  reviews: 'סקירות',
  Repositories: 'Repos',
  'Back to reviews': 'חזרה לסקירות',
  'Review not found': 'הסקירה לא נמצאה',
  'This review may have been deleted or the link is incorrect.':
    'ייתכן שהסקירה נמחקה או שהקישור שגוי.',

  // Empty states
  'Review pull requests with AI': 'סקירת Pull requests עם AI',
  'Add a GitHub repository and every pull request gets a review with findings, a verdict, and a merge confidence grade. Mention the app in a comment to ask it anything.':
    'הוסיפו repo מ-GitHub וכל Pull request יקבל סקירה עם ממצאים, החלטה וציון ביטחון למיזוג. תייגו את האפליקציה בתגובה כדי לשאול אותה כל שאלה.',
  'No reviews yet': 'עדיין אין סקירות',
  'Reviews show up here when a pull request is opened or gets new commits in one of your repositories.':
    'סקירות יופיעו כאן כש-Pull request נפתח או מקבל קומיטים חדשים באחד מה-repos שלכם.',
  'View repositories': 'הצגת repos',

  // Review details
  Verdict: 'החלטה',
  Confidence: 'ביטחון',
  Finished: 'הסתיים',
  Findings: 'ממצאים',
  'Skipped because': 'דולג כי',
  'Failed because': 'נכשל כי',
  'The review found nothing to flag.': 'הסקירה לא מצאה דבר שדורש תשומת לב.',
  'View comment': 'הצגת התגובה',
  'Open on GitHub': 'פתיחה ב-GitHub',

  // Statuses and verdicts
  Reviewing: 'בסקירה',
  'Changes requested': 'נדרשים שינויים',
  Commented: 'נוספה תגובה',

  // Triggers
  'Pull request opened': 'Pull request נפתח',
  'New commits': 'קומיטים חדשים',
  'Ready for review': 'מוכן לסקירה',
  'Requested in a comment': 'התבקש בתגובה',

  // Finding severities
  Critical: 'קריטי',
  Major: 'משמעותי',
  Minor: 'קל',

  // Repositories
  Installation: 'התקנה',
  'Already added': 'כבר נוסף',
  'Remove repository': 'הסרת repo',
  'Pull requests in the repository are reviewed when they are opened or get new commits.':
    'Pull requests ב-repo ייסקרו כשהם נפתחים או מקבלים קומיטים חדשים.',
  'Pull requests in this repository will no longer be reviewed. Past reviews stay in the project.':
    'Pull requests ב-repo הזה לא ייסקרו יותר. סקירות קודמות יישארו בפרויקט.',
  'Connect a GitHub installation to the project to review its repositories.':
    'חברו התקנת GitHub לפרויקט כדי לסקור את ה-repos שלה.',
  'Code review turned on': 'סקירת הקוד הופעלה',
  'Code review turned off': 'סקירת הקוד כובתה',
  'Failed to turn on code review': 'הפעלת סקירת הקוד נכשלה',
  'Failed to turn off code review': 'כיבוי סקירת הקוד נכשל',
  "You don't have permission to add repositories.":
    'אין לכם הרשאה להוסיף repos.',
}
