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
  'Catch bugs and security issues before they merge. Choose the repositories to review, and every pull request in them gets a review on GitHub.':
    'אתרו באגים ובעיות אבטחה לפני המיזוג. בחרו את ה-repos לסקירה, וכל Pull request בהם יקבל סקירה ב-GitHub.',
  'Install the GitHub app on your account or organization, then turn on review for the repositories you pick.':
    'התקינו את אפליקציית GitHub בחשבון או בארגון שלכם, ואז הפעילו סקירה ל-repos שבחרתם.',
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
  'Open on GitHub': 'פתיחה ב-GitHub',
  'Compare on GitHub': 'השוואה ב-GitHub',
  'Open commit on GitHub': 'פתיחת הקומיט ב-GitHub',
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
  'Search GitHub repositories...': 'חיפוש מאגרים ב-GitHub...',
  'No repositories match your search': 'אין מאגרים שתואמים לחיפוש',
  'No repositories reviewed yet': 'עדיין אין מאגרים בסקירה',
  'No GitHub installation': 'אין התקנת GitHub',
  'Connect GitHub to review pull requests':
    'חברו את GitHub כדי לסקור Pull requests',
  'Install the GitHub app on your account or organization, then pick the repositories to review.':
    'התקינו את אפליקציית GitHub בחשבון או בארגון שלכם, ואז בחרו את המאגרים לסקירה.',
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
  'Open conversation on GitHub': 'פתיחת השיחה ב-GitHub',
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

  // Integration
  'Use Review from your coding agent': 'שימוש ב-Review מסוכן הקוד שלכם',
  'Install the Appwrite plugin in your coding agent. Its review skills read the review on a pull request, fix the findings until it grades Tier S, and teach the reviewer rules for later reviews.':
    'התקינו את התוסף של Appwrite בסוכן הקוד שלכם. ה-Skills של הסקירה קוראים את הסקירה של בקשת משיכה, מתקנים את הממצאים עד שהיא מקבלת דירוג Tier S, ומלמדים את הסוקר כללים לסקירות הבאות.',
  'In Codex, run /plugins, open the OpenAI Curated marketplace, and install Appwrite. Or install it from the terminal:':
    'ב-Codex, הריצו ‎/plugins, פתחו את המרקטפלייס OpenAI Curated והתקינו את Appwrite. או התקינו אותו מהטרמינל:',
  'Install the Appwrite plugin from the Cursor Marketplace.':
    'התקינו את התוסף של Appwrite מה-Cursor Marketplace.',
  'Open Cursor Marketplace': 'פתיחת Cursor Marketplace',
  'Any agent that loads skills can use them. Install one skill without a plugin:':
    'כל סוכן שטוען Skills יכול להשתמש בהם. התקינו Skill אחד בלי תוסף:',
  'Replace appwrite-review-loop with any skill below. The skills use git and the GitHub CLI; another GitHub client is fine when that is what the environment has.':
    'החליפו את appwrite-review-loop בכל Skill מהרשימה למטה. ה-Skills משתמשים ב-git וב-GitHub CLI; לקוח GitHub אחר מתאים גם הוא אם זה מה שקיים בסביבה.',
  'Pass a pull request number to work on that one. Otherwise a skill uses the pull request of the current branch. Each review the loop requests uses this project’s review credits, and it stops after 5 rounds.':
    'העבירו מספר של בקשת משיכה כדי לעבוד עליה. אחרת ה-Skill משתמש בבקשת המשיכה של הענף הנוכחי. כל סקירה שהלולאה מבקשת משתמשת בקרדיטים לסקירה של הפרויקט הזה, והיא נעצרת אחרי 5 סבבים.',
  'The repository needs review turned on in':
    'במאגר צריכה להיות סקירה מופעלת ב-',
  'Reads the current tier, the open findings, and the comments the review filtered out. It does not start a review.':
    'קורא את הדירוג הנוכחי, את הממצאים הפתוחים ואת ההערות שהסקירה סיננה. הוא לא מתחיל סקירה.',
  'Fixes the pull request until the review grades it Tier S and no findings are left open.':
    'מתקן את בקשת המשיכה עד שהסקירה מדרגת אותה Tier S ולא נשארים ממצאים פתוחים.',
  'Replies on a finding with a lasting rule, so later reviews of the repository follow it.':
    'משיב על ממצא עם כלל קבוע, כך שסקירות הבאות של המאגר פועלות לפיו.',
  'Writes the repository’s review config and opens a pull request onto the base branch.':
    'כותב את הגדרות הסקירה של המאגר ופותח בקשת משיכה לענף הבסיס.',
  'Keeps the agent from resolving review threads. The review clears a thread once a later review sees the fix.':
    'מונע מהסוכן לסגור שרשורי סקירה. הסקירה סוגרת שרשור ברגע שסקירה מאוחרת יותר רואה את התיקון.',
}
