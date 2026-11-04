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
  'Add all repositories': 'הוספת כל המאגרים',
  'Adding repositories...': 'מוסיף מאגרים...',
  'repositories added': 'מאגרים נוספו',
  'repositories already on': 'מאגרים כבר פעילים',
  'repositories failed': 'מאגרים נכשלו',
  'Some repositories could not be added': 'לא ניתן היה להוסיף חלק מהמאגרים',
  'Counting repositories...': 'סופר מאגרים...',
  'Turn on code review for every repository in the installation?':
    'להפעיל סקירת קוד לכל המאגרים בהתקנה?',
  'Add all': 'הוספת הכל',
  Trace: 'מעקב',
  'No steps recorded yet.': 'עדיין לא נרשמו שלבים.',
  // Trace
  'Review started': 'הסקירה התחילה',
  'Merge only': 'מיזוג בלבד',
  'Model submitted': 'המודל הגיש',
  'Review completed': 'הסקירה הושלמה',
  'Posted to GitHub': 'פורסם ב-GitHub',
  'Invalid config': 'תצורה לא תקינה',
  'Sandbox ready': 'ארגז החול מוכן',
  'Sandbox setup': 'הגדרת ארגז החול',
  'No sandbox': 'ללא ארגז חול',
  'Sandbox failed': 'ארגז החול נכשל',
  'Files filtered': 'הקבצים סוננו',
  'Prompt files': 'קבצים בהנחיה',
  'Read file': 'קריאת קובץ',
  'AST search': 'חיפוש AST',
  'File history': 'היסטוריית קובץ',
  'List refs': 'רשימת הפניות',
  'Path in refs': 'נתיב בהפניות',
  'List files': 'רשימת קבצים',
  'Run command': 'הרצת פקודה',
  'Subagent done': 'סוכן המשנה סיים',
  'Subagent failed': 'סוכן המשנה נכשל',
  'Subagent skipped': 'סוכן המשנה דולג',
  Placement: 'מיקום',
  Relocated: 'הועבר',
  Anchoring: 'עיגון',
  'Suggestion removed': 'ההצעה הוסרה',
  Remembered: 'נשמר בזיכרון',
  'Marked finding': 'הממצא סומן',
  'tier {tier}': 'דרגה {tier}',
  '{count} open finding': 'ממצא פתוח {count}',
  '{count} open findings': '{count} ממצאים פתוחים',
  '{count} learning': 'למידה {count}',
  '{count} learnings': '{count} למידות',
  'failed: {name}': 'נכשל: {name}',
  'Merge-only update since {sha}': 'עדכון מיזוג בלבד מאז {sha}',
  'Incremental review since {sha}': 'סקירה מצטברת מאז {sha}',
  'Full review from base {sha}': 'סקירה מלאה מהבסיס {sha}',
  'Only {ref} was merged in; previous review carried over':
    'רק {ref} מוזג; הסקירה הקודמת הועברה',
  'Only the base branch was merged in; previous review carried over':
    'רק ענף הבסיס מוזג; הסקירה הקודמת הועברה',
  '{count} finding ({subagents} from subagents)':
    'ממצא {count} ({subagents} מסוכני משנה)',
  '{count} findings ({subagents} from subagents)':
    '{count} ממצאים ({subagents} מסוכני משנה)',
  '{count} finding': 'ממצא {count}',
  '{count} findings': '{count} ממצאים',
  '{count} resolved': '{count} נפתרו',
  '{count} comment posted, {dropped} dropped, {open} still open':
    'תגובה {count} פורסמה, {dropped} הושמטו, {open} עדיין פתוחים',
  '{count} comments posted, {dropped} dropped, {open} still open':
    '{count} תגובות פורסמו, {dropped} הושמטו, {open} עדיין פתוחים',
  'Review posted': 'הסקירה פורסמה',
  'review submitted': 'הסקירה הוגשה',
  'comment only': 'תגובה בלבד',
  'head moved': 'ה-head זז',
  '{count} dismissed': '{count} נדחו',
  'The repository config is invalid': 'תצורת המאגר אינה תקינה',
  'Started in {time}': 'הופעל תוך {time}',
  '{count} file omitted': 'קובץ {count} הושמט',
  '{count} files omitted': '{count} קבצים הושמטו',
  '{count} file to review, {excluded} excluded':
    'קובץ {count} לסקירה, {excluded} הוחרגו',
  '{count} files to review, {excluded} excluded':
    '{count} קבצים לסקירה, {excluded} הוחרגו',
  '{count} file in the prompt': 'קובץ {count} בהנחיה',
  '{count} files in the prompt': '{count} קבצים בהנחיה',
  '{count} too large to show': '{count} גדולים מכדי להציג',
  base: 'בסיס',
  'last {count}': '{count} אחרונים',
  'with patches': 'עם תיקונים',
  'all refs': 'כל ההפניות',
  '{count} ref': 'הפניה {count}',
  '{count} refs': '{count} הפניות',
  'Kept {kept} of {count} finding': 'נשמרו {kept} מתוך ממצא {count}',
  'Kept {kept} of {count} findings': 'נשמרו {kept} מתוך {count} ממצאים',
  kept: 'נשמר',
  'lines {from} → {to}': 'שורות {from} ← {to}',
  '{unattached} of {count} finding could not be placed on the diff':
    'לא ניתן היה למקם {unattached} מתוך ממצא {count} על ה-diff',
  '{unattached} of {count} findings could not be placed on the diff':
    'לא ניתן היה למקם {unattached} מתוך {count} ממצאים על ה-diff',
  'skipped: {reason}': 'דולג: {reason}',
  'timed out': 'תם הזמן',
  'exit {code}': 'קוד יציאה {code}',
  'Subagent {agent}': 'סוכן משנה {agent}',
  '{count} step': 'שלב {count}',
  '{count} steps': '{count} שלבים',
  running: 'פועל',
}
