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
  'Get a review on every pull request': 'סקירה לכל Pull request',
  'Each new pull request and commit gets a summary, findings on the lines they concern, a verdict, and a merge confidence grade.':
    'כל Pull request וכל קומיט חדש מקבלים סיכום, ממצאים על השורות הרלוונטיות, החלטה וציון ביטחון למיזוג.',
  'Talk to the reviewer': 'שיחה עם הסוקר',
  'Mention the app in a comment to ask a question, request a new review, or have it remember a preference for later reviews.':
    'תייגו את האפליקציה בתגובה כדי לשאול שאלה, לבקש סקירה חדשה או לבקש ממנה לזכור העדפה לסקירות הבאות.',
  'No reviews match your filters': 'אין סקירות התואמות למסננים שלכם',
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
  'Filter findings by severity': 'סינון ממצאים לפי חומרה',

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
  'Code review turned on': 'סקירת הקוד הופעלה',
  'Code review turned off': 'סקירת הקוד כובתה',
  'Failed to turn on code review': 'הפעלת סקירת הקוד נכשלה',
  'Failed to turn off code review': 'כיבוי סקירת הקוד נכשל',
  "You don't have permission to add repositories.":
    'אין לכם הרשאה להוסיף repos.',
  'Add all repositories': 'הוספת כל המאגרים',
  'Adding repositories...': 'מוסיף מאגרים...',
  'Added: {count}': 'נוספו: {count}',
  'Already on: {count}': 'כבר פעילים: {count}',
  'Failed: {count}': 'נכשלו: {count}',
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

  // Repositories tab
  repositories: 'מאגרים',
  Reviewed: 'בסקירה',
  'All repositories': 'כל המאגרים',
  'Reviewed since': 'בסקירה מאז',
  'Review all': 'סקירת הכל',
  'Review all repositories': 'סקירת כל המאגרים',
  'Choose repositories': 'בחירת מאגרים',
  'Browse repositories': 'עיון במאגרים',
  'No repositories match your search': 'אין מאגרים שתואמים לחיפוש',
  'No repositories reviewed yet': 'עדיין אין מאגרים בסקירה',
  'No installation': 'אין התקנה',
  'Connect a Git provider to review pull requests':
    'חברו ספק Git כדי לסקור Pull requests',
  'Turn on code review for a repository and its pull requests are reviewed when they are opened or get new commits.':
    'הפעילו סקירת קוד למאגר, וה-Pull requests שלו ייסקרו כשהם נפתחים או מקבלים קומיטים חדשים.',
  'Turn on code review for every repository in {organization} ({count}).':
    'הפעלת סקירת קוד לכל המאגרים ב-{organization} ({count}).',
  'To review repositories added to the installation later, turn on Review new repositories.':
    'כדי לסקור מאגרים שיתווספו להתקנה בהמשך, הפעילו את סקירת מאגרים חדשים.',
  'Review pull requests in {name}': 'סקירת Pull requests ב-{name}',

  // Review strength
  Strength: 'עוצמה',
  Basic: 'בסיסית',
  Normal: 'רגילה',
  Expert: 'מומחה',
  'Cheaper models that read the code without running it':
    'מודלים זולים יותר שקוראים את הקוד בלי להריץ אותו',
  'The default models, running commands in a sandbox':
    'מודלי ברירת המחדל, עם הרצת פקודות בסביבה מבודדת',
  'Stronger models that split the work across subagents':
    'מודלים חזקים יותר שמחלקים את העבודה בין תת-סוכנים',
  '{credits} credits per review': '{credits} קרדיטים לכל סקירה',
  'Review strength updated': 'עוצמת הסקירה עודכנה',
  'Failed to update review strength': 'עדכון עוצמת הסקירה נכשל',
  'Review strength for {name}': 'עוצמת הסקירה עבור {name}',

  // New repositories
  'Review new repositories': 'סקירת מאגרים חדשים',
  'Turn on code review for repositories added to {organization} on GitHub later.':
    'הפעלת סקירת קוד למאגרים שיתווספו ל-{organization} ב-GitHub בהמשך.',
  'New repositories will be reviewed': 'מאגרים חדשים ייסקרו',
  'New repositories will not be reviewed': 'מאגרים חדשים לא ייסקרו',
  'Failed to update new repositories': 'עדכון ההגדרה למאגרים חדשים נכשל',

  // Learnings
  Learnings: 'למידות',
  learnings: 'למידות',
  Learning: 'למידה',
  'Asked by': 'התבקש על ידי',
  'Learning actions': 'פעולות למידה',
  'Learning has been deleted': 'הלמידה נמחקה',
  'Failed to delete learning': 'מחיקת הלמידה נכשלה',
  'No learnings yet': 'אין עדיין למידות',
  'When someone asks the reviewer to remember a preference in a pull request conversation, it shows up here. Later reviews of the repository follow it.':
    'כשמישהו מבקש מהסוקר לזכור העדפה בשיחה ב-Pull request, היא מופיעה כאן. הסקירות הבאות של ה-repo יפעלו לפיה.',
  'Delete learning': 'מחיקת למידה',
  'Later reviews and answers will no longer follow this learning. This action cannot be undone.':
    'סקירות ותשובות עתידיות לא יפעלו עוד לפי הלמידה הזו. לא ניתן לבטל פעולה זו.',

  // Performance
  Performance: 'ביצועים',
  'Unknown author': 'מחבר לא ידוע',
  Outcome: 'תוצאה',
  'Still open': 'עדיין פתוחים',
  'Reviews queued before authors were recorded':
    'סקירות שנכנסו לתור לפני שנשמרו מחברים',
  Bot: 'בוט',
  '{rate} first try': '{rate} בניסיון הראשון',
  '{count} critical': '{count} קריטיים',
  '{count} fixed': '{count} תוקנו',
  '{count} reviews': '{count} סקירות',
  'Date range': 'טווח תאריכים',
  Author: 'מחבר',
  'Pull requests': 'בקשות משיכה',
  'First try': 'בניסיון הראשון',
  'Findings / PR': 'ממצאים / PR',
  'Critical / major': 'קריטי / משמעותי',
  Fixed: 'תוקנו',
  'Last review': 'סקירה אחרונה',
  'Pull requests reviewed': 'בקשות משיכה שנסקרו',
  '{count} authors': '{count} מחברים',
  'Approval rate': 'שיעור אישור',
  '{rate} approved by the first review': '{rate} אושרו בסקירה הראשונה',
  'Findings per pull request': 'ממצאים לבקשת משיכה',
  '{critical} critical, {major} major': '{critical} קריטיים, {major} משמעותיים',
  'Fix rate': 'שיעור תיקון',
  '{count} findings fixed by a later commit':
    '{count} ממצאים תוקנו בקומיט מאוחר יותר',
  'No completed reviews in this range': 'אין סקירות שהושלמו בטווח הזה',
  'Performance shows how the authors of your pull requests fare in review once reviews complete. Try a longer range or another repository.':
    'כאן מוצג איך מחברי בקשות המשיכה שלכם מצליחים בסקירה, לאחר שהסקירות מושלמות. נסו טווח ארוך יותר או מאגר אחר.',
  'Counts completed reviews in the range. Approval and confidence go by each pull request’s latest review; fixed counts findings resolved by a later commit.':
    'נספרות סקירות שהושלמו בטווח. אישור וביטחון נקבעים לפי הסקירה האחרונה של כל בקשת משיכה; תוקנו סופר ממצאים שנפתרו בקומיט מאוחר יותר.',

  // Finding outcomes
  'Useful findings': 'ממצאים מועילים',
  '{resolved} fixed, {dismissed} dismissed':
    '{resolved} תוקנו, {dismissed} נדחו',
  Useful: 'מועילים',
  Dismissed: 'נדחו',
  'Findings by category': 'ממצאים לפי קטגוריה',
  'Categories that are often dismissed are candidates for a learning that tells the reviewer to flag them less.':
    'קטגוריות שנדחות לעיתים קרובות הן מועמדות ללמידה שתורה לסוקר לסמן אותן פחות.',
  'Useful findings is the share of settled findings that were fixed rather than dismissed; findings still open count for neither.':
    'ממצאים מועילים הם החלק מהממצאים שהוכרעו שתוקנו ולא נדחו; ממצאים שעדיין פתוחים אינם נספרים לאף צד.',
  // Functions and sites: code review of the connected repository
  'Code review': 'סקירת קוד',
  'Review pull requests': 'סקירת Pull requests',
  'Code review was not turned on': 'סקירת הקוד לא הופעלה',
  'Turn it on later from Settings > Git.':
    'אפשר להפעיל אותה בהמשך בהגדרות > Git.',
  'Code review is already on for this repository.':
    'סקירת הקוד כבר מופעלת למאגר הזה.',
  'Appwrite reviews each pull request when it is opened or gets new commits, and comments on it.':
    'Appwrite סוקר כל Pull request כשהוא נפתח או מקבל קומיטים חדשים, ומגיב בו.',
  "Reviews use your organization's review credits.":
    'הסקירות משתמשות בקרדיטי הסקירה של הארגון שלכם.',
  'Review pull requests to the connected repository and comment on them. The setting applies to the whole repository.':
    'סקירת Pull requests במאגר המחובר ותגובות בהם. ההגדרה חלה על המאגר כולו.',
  'Each pull request is reviewed when it is opened or gets new commits.':
    'כל Pull request נסקר כשהוא נפתח או מקבל קומיטים חדשים.',
  'Review strength': 'עוצמת הסקירה',
  'How hard pull requests are reviewed, and what each review costs.':
    'עד כמה לעומק נסקרים Pull requests, וכמה עולה כל סקירה.',
  'View reviews': 'צפייה בסקירות',
  'Connect a repository to this function to review its pull requests.':
    'חברו מאגר לפונקציה הזו כדי לסקור את ה-Pull requests שלו.',
  'Connect a repository to this site to review its pull requests.':
    'חברו מאגר לאתר הזה כדי לסקור את ה-Pull requests שלו.',
  'Code review does not run on this provider': 'סקירת קוד לא פועלת בספק הזה',
  'Code review runs on GitHub, GitLab, Gitea, Bitbucket and Origin repositories.':
    'סקירת קוד פועלת במאגרים של GitHub, GitLab, Gitea, Bitbucket ו-Origin.',
  'Code review is off': 'סקירת הקוד כבויה',
  'Turn on code review to have each pull request reviewed when it is opened or gets new commits.':
    'הפעילו סקירת קוד כדי שכל Pull request ייסקר כשהוא נפתח או מקבל קומיטים חדשים.',
  'Pull requests that change files in': 'Pull requests שמשנים קבצים בתיקייה',
  'Reviews show up here when a pull request that changes this directory is opened or gets new commits.':
    'סקירות יופיעו כאן כש-Pull request שמשנה את התיקייה הזו נפתח או מקבל קומיטים חדשים.',
  'Reviews show up here when a pull request is opened or gets new commits.':
    'סקירות יופיעו כאן כש-Pull request נפתח או מקבל קומיטים חדשים.',
  'No reviews of this directory among the latest ones.':
    'אין סקירות של התיקייה הזו בין הסקירות האחרונות.',

  // Row menus
  'Copy text': 'העתקת הטקסט',
  'Copy title': 'העתקת הכותרת',
  'Open profile on GitHub': 'פתיחת הפרופיל ב-GitHub',
  'Open file': 'פתיחת הקובץ',
  'Open comment': 'פתיחת התגובה',
}
