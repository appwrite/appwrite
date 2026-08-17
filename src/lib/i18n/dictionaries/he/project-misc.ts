/**
 * Hebrew translations for messaging, settings, api-keys, apps, usage, overview,
 * realtime, activity, and other project console areas.
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heProjectMiscDictionary: Record<string, string> = {
  // Common actions and labels
  Cancel: 'ביטול',
  Delete: 'מחיקה',
  Update: 'עדכון',
  Copy: 'העתקה',
  'Copy ID': 'העתקת מזהה',
  'Copy name': 'העתקת שם',
  'Copy as JSON': 'העתקה כ-JSON',
  Done: 'סיום',
  Name: 'שם',
  Never: 'אף פעם',
  'Copied to clipboard': 'הועתק ללוח',
  'Name is required': 'נדרש שם',
  'This action cannot be undone.': 'פעולה זו אינה ניתנת לביטול.',
  'Are you sure you want to delete': 'האם אתם בטוחים שברצונכם למחוק את',
  'Try a different search term.': 'נסו מונח חיפוש אחר.',

  // API keys
  'API key created successfully': 'מפתח ה-API נוצר בהצלחה',
  'Failed to create API key': 'יצירת מפתח ה-API נכשלה',
  'API key updated successfully': 'מפתח ה-API עודכן בהצלחה',
  'Failed to update API key': 'עדכון מפתח ה-API נכשל',
  'API key deleted successfully': 'מפתח ה-API נמחק בהצלחה',
  'Failed to delete API key': 'מחיקת מפתח ה-API נכשלה',
  'API key deleted': 'מפתח ה-API נמחק',
  'API keys': 'מפתחות API',
  'Search API keys...': 'חיפוש מפתחות API...',
  'Create API key': 'יצירת מפתח API',
  "You don't have permission to create API keys.":
    'אין לכם הרשאה ליצור מפתחות API.',
  'No API keys created': 'לא נוצרו מפתחות API',
  // pragma: allowlist secret
  'Create an API key to authenticate your applications and access Appwrite services. API keys provide secure access to your project resources.':
    'צרו מפתח API כדי לאמת את האפליקציות שלכם ולגשת לשירותי Appwrite. מפתחות API מספקים גישה מאובטחת למשאבי הפרויקט שלכם.', // pragma: allowlist secret
  'Get started with your language of choice': 'התחילו עם השפה לבחירתכם',
  'No API keys match your search': 'אין מפתחות API התואמים לחיפוש שלכם',
  'Delete API key': 'מחיקת מפתח API',
  'this API key': 'מפתח API זה',
  'API key created': 'מפתח ה-API נוצר',
  'Update API key': 'עדכון מפתח API',
  'Your new API key': 'מפתח ה-API החדש שלכם',
  'Copy and store it securely. You can view the full key anytime from the API keys list.':
    'העתיקו ושמרו אותו במקום מאובטח. ניתן לצפות במפתח המלא בכל עת מרשימת מפתחות ה-API.',
  'Enter API key name': 'הזינו שם למפתח ה-API',
  'Last used': 'שימוש אחרון',
  'API key': 'מפתח API',
  'Hide key': 'הסתרת מפתח',
  'Show key': 'הצגת מפתח',
  'Copy key': 'העתקת מפתח',
  "The full key value isn't shown here. Find this key in the API keys list to view and copy it.":
    'ערך המפתח המלא אינו מוצג כאן. מצאו את המפתח ברשימת מפתחות ה-API כדי לצפות בו ולהעתיק אותו.',
  'Expiration date': 'תאריך תפוגה',
  'Invalid date format': 'פורמט תאריך לא תקין',
  'Expiration date must be in the future': 'תאריך התפוגה חייב להיות בעתיד',
  '1 week': 'שבוע',
  '1 month': 'חודש',
  '6 months': 'שישה חודשים',
  '1 year': 'שנה',
  'Custom date': 'תאריך מותאם אישית',
  Scopes: 'הרשאות גישה',
  'Select the scopes this API key will have access to.':
    'בחרו את היקפי הגישה שאליהם יוכל מפתח ה-API הזה לגשת.',
  'Learn more about API key scopes': 'למידע נוסף על היקפי גישה של מפתחות API',

  // Explorer
  Explorer: 'אקספלורר',
  APIs: 'ממשקי API',
  Other: 'אחר',

  // Onboarding
  'Progress by product': 'התקדמות לפי מוצר',
  'Connect your app first.': 'חברו את האפליקציה שלכם תחילה.',
  'Connect this project, then complete each product area - one clear action at a time.':
    'חברו את הפרויקט הזה, ואז השלימו כל תחום מוצר, פעולה ברורה אחת בכל פעם.',
  "We're focused on building a product Appwriters love. The best way we grow is when the community helps spread the word.":
    'אנחנו מתמקדים בבניית מוצר ש-Appwriters אוהבים. הדרך הטובה ביותר שלנו לצמוח היא כשהקהילה עוזרת לספר עלינו.',
  Breakdown: 'פירוט',
  'Go to dashboard': 'מעבר ללוח הבקרה',
  Skipped: 'דולג',
  skipped: 'דולג',
  Skip: 'דילוג',
  'Not counted in overall progress': 'לא נספר בהתקדמות הכוללת',
  Open: 'פתיחה',
  'Collapse all': 'כיווץ הכול',
  'Expand all': 'הרחבת הכול',
  Soon: 'בקרוב',
  Connect: 'חיבור',
  // pragma: allowlist secret
  'Register where your app runs and add API credentials so your code can call Appwrite.':
    'רשמו היכן האפליקציה שלכם פועלת והוסיפו פרטי גישה ל-API כדי שהקוד שלכם יוכל לקרוא ל-Appwrite.', // pragma: allowlist secret
  'Register your app platform': 'רישום פלטפורמת האפליקציה',
  "Map your app's hostname or bundle ID so the SDK can reach this project.":
    'מפו את שם המארח או מזהה החבילה של האפליקציה כדי שה-SDK יוכל להגיע לפרויקט הזה.',
  'Add platform': 'הוספת פלטפורמה',
  Manage: 'ניהול',
  'Manage apps': 'ניהול אפליקציות',
  'Create a server API key': 'יצירת מפתח API לשרת',
  'Add a scoped secret for servers and CI; client apps use sessions instead.':
    'הוסיפו סוד עם היקף מוגדר לשרתים ול-CI; אפליקציות לקוח משתמשות בסשנים במקום.',
  'Add API key': 'הוספת מפתח API',
  'Manage keys': 'ניהול מפתחות',
  Auth: 'אימות',
  'Sign users in, organize teams, and control who can access each part of your product.':
    'אפשרו למשתמשים להתחבר, ארגנו צוותים ושלטו במי שיכול לגשת לכל חלק במוצר שלכם.',
  'Add your first user': 'הוספת המשתמש הראשון שלכם',
  'Register, import, or invite someone so Auth is in use.':
    'רשמו, ייבאו או הזמינו מישהו כדי להתחיל להשתמש ב-Auth.',
  'Add user': 'הוספת משתמש',
  'Manage users': 'ניהול משתמשים',
  'Create a team': 'יצירת צוות',
  'Group users and assign roles for access control.':
    'קבצו משתמשים והקצו תפקידים לבקרת גישה.',
  'Create team': 'יצירת צוות',
  'Manage teams': 'ניהול צוותים',
  Databases: 'מסדי נתונים',
  'Store and query structured data - add indexes and vector search when you need them.':
    'אחסנו נתונים מובנים והריצו עליהם שאילתות, והוסיפו אינדקסים וחיפוש וקטורי כשצריך.',
  'Create a database': 'יצירת מסד נתונים',
  'Spin up a database (tables or documents) for your app data.':
    'הקימו מסד נתונים (טבלאות או מסמכים) לנתוני האפליקציה שלכם.',
  'Create database': 'יצירת מסד נתונים',
  'Open databases': 'פתיחת מסדי נתונים',
  'Define tables and load data': 'הגדרת טבלאות וטעינת נתונים',
  'Add collections or tables, attributes, and indexes; then insert rows.':
    'הוסיפו אוספים או טבלאות, מאפיינים ואינדקסים; לאחר מכן הכניסו שורות.',
  'Set up schema': 'הגדרת סכימה',
  Storage: 'אחסון',
  'Upload files to buckets and serve or download them with secure, scoped access.':
    'העלו קבצים לבאקטים והגישו או הורידו אותם עם גישה מאובטחת ומוגדרת היקף.',
  'Create a bucket': 'יצירת באקט',
  'Add a bucket and set who can read or write files.':
    'הוסיפו באקט והגדירו מי יכול לקרוא או לכתוב קבצים.',
  'Create bucket': 'יצירת באקט',
  'Manage buckets': 'ניהול באקטים',
  'Upload a file': 'העלאת קובץ',
  'Put an object in a bucket; use signed URLs or previews as needed.':
    'הכניסו אובייקט לבאקט; השתמשו בכתובות URL חתומות או בתצוגות מקדימות לפי הצורך.',
  'Upload files': 'העלאת קבצים',
  'Open storage': 'פתיחת האחסון',
  Functions: 'פונקציות',
  'Run backend code on HTTP requests, schedules, or events from other services.':
    'הריצו קוד צד שרת בתגובה לבקשות HTTP, תזמונים או אירועים משירותים אחרים.',
  'Create a function': 'יצירת פונקציה',
  'Add serverless code and choose a runtime.':
    'הוסיפו קוד ללא שרת ובחרו סביבת ריצה.',
  'Create function': 'יצירת פונקציה',
  'Manage functions': 'ניהול פונקציות',
  'Ship a deployment': 'שחרור פריסה',
  'Deploy your code so executions can run.':
    'פרסו את הקוד שלכם כדי שההרצות יוכלו לפעול.',
  'Open deployments': 'פתיחת פריסות',
  'View function': 'צפייה בפונקציה',
  Messaging: 'הודעות',
  // pragma: allowlist secret
  'Send email, push, and SMS by routing messages through topics and providers.':
    'שלחו אימייל, פוש ו-SMS על ידי ניתוב הודעות דרך נושאים וספקים.',
  'Create a topic': 'יצירת נושא',
  'Add a channel for push, email, or SMS broadcasts.':
    'הוסיפו ערוץ לשידורי פוש, אימייל או SMS.',
  'Create topic': 'יצירת נושא',
  'Manage topics': 'ניהול נושאים',
  'Add a provider': 'הוספת ספק',
  // pragma: allowlist secret
  'Connect SMTP, FCM, APNS, or another provider to send messages.':
    'חברו SMTP, FCM, APNS או ספק אחר כדי לשלוח הודעות.',
  'Add provider': 'הוספת ספק',
  'Manage providers': 'ניהול ספקים',
  Sites: 'אתרים',
  'Connect a Git repo and ship your frontend with builds, deploys, and custom domains.':
    'חברו Git repo ושחררו את צד הלקוח שלכם עם בניות, פריסות ודומיינים מותאמים אישית.',
  'Create a site': 'יצירת אתר',
  'Connect a repository and configure your build.':
    'חברו repo והגדירו את הבנייה שלכם.',
  'Create site': 'יצירת אתר',
  'Manage sites': 'ניהול אתרים',
  'Run a production deploy': 'פריסה לסביבת ייצור',
  'Ship a build to production and tune environments.':
    'שלחו בנייה לייצור וכווננו סביבות.',
  'View site': 'צפייה באתר',
  Build: 'בנייה',
  Deploy: 'פריסה',

  // Onboarding encouragement headlines
  'Great start - every big app begins with step one.':
    'התחלה מצוינת, כל אפליקציה גדולה מתחילה בצעד הראשון.',
  'You\u2019re on your way - small steps add up fast.':
    'אתם בדרך, צעדים קטנים מצטברים מהר.',
  'Solid beginning. Keep the momentum going.': 'התחלה יציבה. שמרו על המומנטום.',
  'Nice - you\u2019re already moving.': 'יפה, אתם כבר בתנועה.',
  'This is how shipping starts - one checkbox at a time.':
    'כך מתחילים לשחרר, תיבת סימון אחת בכל פעם.',
  'Love the energy - keep stacking those wins.':
    'אנרגיה נהדרת, המשיכו לצבור ניצחונות.',
  'You\u2019re gaining steam - keep going.': 'אתם צוברים תאוצה, המשיכו כך.',
  'Nice progress - the foundation is taking shape.':
    'התקדמות יפה, היסודות מתגבשים.',
  'Momentum looks good from here.': 'המומנטום נראה טוב מכאן.',
  'Keep at it - you\u2019re building something real.':
    'המשיכו כך, אתם בונים משהו אמיתי.',
  'You\u2019re past the awkward early bit - nice.':
    'עברתם את השלב הראשוני המביך, יפה.',
  'Every checkbox is a vote for shipping - keep it up.':
    'כל תיבת סימון היא צעד לקראת שחרור, המשיכו כך.',
  'More than halfway - you\u2019re in the zone.': 'יותר מחצי הדרך, אתם בזרימה.',
  'Strong progress - the finish line is in sight.':
    'התקדמות חזקה, קו הסיום נראה באופק.',
  'You\u2019re past the halfway mark. Don\u2019t stop now.':
    'עברתם את נקודת האמצע. אל תעצרו עכשיו.',
  'This is where projects start to feel real.':
    'כאן פרויקטים מתחילים להרגיש אמיתיים.',
  'Huge progress - a few more wins to go.':
    'התקדמות עצומה, עוד כמה ניצחונות ומסיימים.',
  'Halfway there - and looking sharp.': 'חצי הדרך מאחוריכם, ונראה מעולה.',
  'So close - you\u2019re almost there.': 'כל כך קרוב, אתם כמעט שם.',
  'Final stretch - finish strong.': 'הישורת האחרונה, סיימו בגדול.',
  'Almost done - one last push.': 'כמעט סיימתם, דחיפה אחרונה.',
  'You\u2019re inches from the finish line.': 'אתם במרחק נגיעה מקו הסיום.',
  'The hard part\u2019s behind you - wrap it up.':
    'החלק הקשה מאחוריכם, סגרו את הפינה.',
  'Last lap - you\u2019ve got this.': 'הקפה אחרונה, אתם מסוגלים.',
  'Nice work - you\u2019re all set to build.': 'עבודה יפה, אתם מוכנים לבנות.',
  'Everything\u2019s wired. Time to ship something great.':
    'הכול מחובר. הגיע הזמן לשחרר משהו נהדר.',
  'Checklist complete. You\u2019ve got this.':
    'רשימת המשימות הושלמה. אתם מסוגלים.',
  'That\u2019s the full tour. Go build.': 'זה הסיור המלא. לכו לבנות.',
  'You did it - your stack is ready when you are.':
    'עשיתם זאת, הסטאק שלכם מוכן כשאתם מוכנים.',
  'All green - now go make something people love.':
    'הכול ירוק, עכשיו לכו ליצור משהו שאנשים יאהבו.',
  'You\u2019re ready - and the community helps Appwrite grow.':
    'אתם מוכנים, והקהילה עוזרת ל-Appwrite לצמוח.',

  // Imagine
  'Build something real': 'בנו משהו אמיתי',
  'Turn your ideas into functional products with the most complete AI builder ever made.':
    'הפכו את הרעיונות שלכם למוצרים פועלים עם בונה ה-AI השלם ביותר שנוצר אי פעם.',
  'Describe what you want to build...': 'תארו מה תרצו לבנות...',
  'Send message': 'שליחת הודעה',
  'Financial dashboard': 'לוח בקרה פיננסי',
  'Fitness tracker': 'מעקב כושר',
  'SaaS landing page': 'דף נחיתה ל-SaaS',
  'Visit Imagine.dev': 'מעבר ל-Imagine.dev',
  'Sign in': 'התחברות',
  'Sign up': 'הירשמו',
  'Community projects': 'פרויקטים של הקהילה',
  'View all': 'הצגת הכול',
  'Recipe manager': 'מנהל מתכונים',
  'Organize and discover recipes with AI suggestions':
    'ארגנו וגלו מתכונים עם הצעות AI',
  'Task flow': 'זרימת משימות',
  'Kanban-style project management with automations':
    'ניהול פרויקטים בסגנון קנבן עם אוטומציות',
  'Budget buddy': 'חבר תקציב',
  'Personal finance tracker with spending insights':
    'מעקב פיננסי אישי עם תובנות הוצאות',
  'Study notes': 'סיכומי לימוד',
  'AI-powered note taking with flashcard generation':
    'רישום הערות מבוסס AI עם יצירת כרטיסיות',
  by: 'מאת',
  'Imagine Logo': 'הלוגו של Imagine',
  'You are signed in as': 'אתם מחוברים בתור',
  'Sign out': 'התנתקות',
  'You are not signed in.': 'אינכם מחוברים.',

  // Firewall
  Firewall: 'חומת אש',
  Rules: 'כללים',
  Analytics: 'אנליטיקה',
  Logs: 'לוגים',
  'Search rules...': 'חיפוש כללים...',
  'Search logs...': 'חיפוש בלוגים...',
  'Create rule': 'יצירת כלל',
  'Apply as firewall rule': 'החלה ככלל חומת אש',
  "You don't have permission to create firewall rules.":
    'אין לכם הרשאה ליצור כללי חומת אש.',
  "You don't have permission to update firewall rules.":
    'אין לכם הרשאה לעדכן כללי חומת אש.',
  "You don't have permission to update firewall settings.":
    'אין לכם הרשאה לעדכן הגדרות חומת אש.',
  'No firewall rules': 'אין כללי חומת אש',
  'No API firewall rules': 'אין כללי חומת אש ל-API',
  'No function firewall rules': 'אין כללי חומת אש לפונקציות',
  'No site firewall rules': 'אין כללי חומת אש לאתרים',
  'Create your first firewall rule to protect your project from malicious requests.':
    'צרו את כלל חומת האש הראשון שלכם כדי להגן על הפרויקט מפני בקשות זדוניות.',
  'Create a firewall rule for your project API to protect it from malicious requests.':
    'צרו כלל חומת אש ל-API של הפרויקט כדי להגן עליו מפני בקשות זדוניות.',
  'Create a firewall rule scoped to a function to control how it handles requests.':
    'צרו כלל חומת אש לפונקציה כדי לשלוט באופן הטיפול בבקשות אליה.',
  'Create a firewall rule scoped to a site to control how it handles requests.':
    'צרו כלל חומת אש לאתר כדי לשלוט באופן הטיפול בבקשות אליו.',
  'Firewall rule scope': 'היקף כלל חומת האש',
  'Firewall resource': 'משאב חומת אש',
  'Search resources...': 'חיפוש משאבים...',
  'Showing first results. Refine your search to find more.':
    'מוצגות התוצאות הראשונות. צמצמו את החיפוש כדי למצוא עוד.',
  API: 'API',
  'Firewall protection': 'הגנת חומת אש',
  'Firewall is evaluating rules against incoming traffic for this project.':
    'חומת האש מעריכה כללים מול תעבורה נכנסת לפרויקט זה.',
  'Enable Firewall to start evaluating rules against incoming traffic.':
    'הפעילו את חומת האש כדי להתחיל להעריך כללים מול תעבורה נכנסת.',
  'Firewall enabled': 'חומת האש הופעלה',
  'Firewall disabled': 'חומת האש הושבתה',
  'Failed to update firewall settings': 'עדכון הגדרות חומת האש נכשל',
  Status: 'סטטוס',
  Rule: 'כלל',
  Action: 'פעולה',
  Priority: 'עדיפות',
  Conditions: 'תנאים',
  'Applies to': 'חל על',
  'Project-wide': 'כלל-פרויקט',
  'All requests': 'כל הבקשות',
  more: 'עוד',
  'IP:': 'IP:',
  'Path:': 'נתיב:',
  'Country:': 'מדינה:',
  'Rate limit:': 'מגבלת קצב:',
  total: 'סה"כ',
  blocked: 'נחסמו',
  allowed: 'הותרו',
  denied: 'נדחו',
  Disable: 'השבתה',
  Enable: 'הפעלה',
  Copied: 'הועתק',
  'Failed to copy': 'ההעתקה נכשלה',
  'Rule ID copied': 'מזהה הכלל הועתק',
  'Rule name copied': 'שם הכלל הועתק',
  'Link copied': 'הקישור הועתק',
  'Copied as JSON': 'הועתק כ-JSON',
  Block: 'חסימה',
  Allow: 'התרה',
  Challenge: 'אתגר',
  Deny: 'דחייה',
  Bypass: 'עקיפה',
  'Rate limit': 'מגבלת קצב',
  Redirect: 'הפניה',
  Denied: 'נדחו',
  Bypassed: 'עקפו',
  Passed: 'עברו',
  'Rate limited': 'הוגבלו בקצב',
  Redirected: 'הופנו',
  'Create firewall rule': 'יצירת כלל חומת אש',
  'Define a new rule to protect your project from malicious requests.':
    'הגדירו כלל חדש כדי להגן על הפרויקט שלכם מפני בקשות זדוניות.',
  'Define how Firewall should handle matching requests for this project.':
    'הגדירו כיצד חומת האש תטפל בבקשות תואמות בפרויקט זה.',
  'Rule name': 'שם הכלל',
  'e.g., Block suspicious IPs': 'לדוגמה: חסימת כתובות IP חשודות',
  'e.g., Deny suspicious IPs': 'לדוגמה: דחיית כתובות IP חשודות',
  Description: 'תיאור',
  'Optional description of what this rule does':
    'תיאור אופציונלי של מה שהכלל הזה עושה',
  'Reject matching requests before they reach your project.':
    'דחו בקשות תואמות לפני שהן מגיעות לפרויקט.',
  'Skip remaining firewall checks for matching requests.':
    'דלגו על בדיקות חומת האש הנותרות עבור בקשות תואמות.',
  'Throttle matching requests that exceed a request quota.':
    'הגבילו בקשות תואמות שחורגות ממכסת בקשות.',
  'Send matching requests to another location.':
    'שלחו בקשות תואמות למיקום אחר.',
  'Challenge matching requests before allowing them through.':
    'אתגרו בקשות תואמות לפני שתאפשרו להן לעבור.',
  'Lower numbers are evaluated first.': 'מספרים נמוכים יותר מוערכים קודם.',
  'Resource ID': 'מזהה משאב',
  'Function ID': 'מזהה פונקציה',
  'Site ID': 'מזהה אתר',
  'Request limit': 'מגבלת בקשות',
  'Interval (seconds)': 'מרווח (שניות)',
  'Fixed window': 'חלון קבוע',
  'Sliding window': 'חלון נע',
  'Token bucket': 'דלי טוקנים',
  'Max bucket size': 'גודל דלי מרבי',
  'The largest burst allowed. Defaults to the request limit when left unset.':
    'הפרץ הגדול ביותר המותר. כברירת מחדל שווה למגבלת הבקשות כשלא הוגדר.',
  'Rate limit strategy illustration': 'איור אסטרטגיית מגבלת קצב',
  'first request': 'בקשה ראשונה',
  per: 'לכל',
  'each request takes one token': 'כל בקשה צורכת טוקן אחד',
  'Windows align to the clock. Rate limit resets for everyone when the next time interval starts.':
    'החלונות מיושרים לפי השעון. מגבלת הקצב מתאפסת לכולם כשמרווח הזמן הבא מתחיל.',
  "Windows align to the user. Helps prevent traffic spikes since the reset doesn't occur for all users at the same time.":
    'החלונות מיושרים לפי המשתמש. כך נמנעות קפיצות בתנועה, מכיוון שהאיפוס אינו מתרחש אצל כל המשתמשים בו-זמנית.',
  'Windows align to human behaviour. Allows accumulated short bursts, and refills for sustained pace.':
    'החלונות מיושרים לפי התנהגות אנושית. מאפשר פרצים קצרים שנצברו, ומתמלא מחדש לקצב מתמשך.',
  'Redirect location': 'יעד הפניה',
  'Status code': 'קוד סטטוס',
  'Match requests when all conditions are true.':
    'התאימו בקשות כאשר כל התנאים מתקיימים.',
  'Add condition': 'הוספת תנאי',
  Attribute: 'מאפיין',
  Operator: 'אופרטור',
  Value: 'ערך',
  'Remove condition': 'הסרת תנאי',
  'IP address': 'כתובת IP',
  'Select method': 'בחרו מתודה',
  'e.g. curl/8.0': 'למשל curl/8.0',
  'Key, e.g. token': 'מפתח, למשל token',
  'Key, e.g. x-custom-header': 'מפתח, למשל x-custom-header',
  'e.g. 203.0.113.10': 'למשל 203.0.113.10',
  'e.g. 203.0.113.10 or CIDR range 203.0.113.0/24':
    'למשל 203.0.113.10 או טווח CIDR 203.0.113.0/24',
  'User agent': 'User agent',
  If: 'אם',
  Then: 'אז',
  And: 'וגם',
  'Choose which traffic this rule should evaluate.':
    'בחרו איזה תעבורה הכלל הזה אמור להעריך.',
  'All API requests for this project': 'כל בקשות ה-API של הפרויקט הזה',
  'Requests to a specific function': 'בקשות לפונקציה ספציפית',
  'Requests to a specific site': 'בקשות לאתר ספציפי',
  'Select function': 'בחרו פונקציה',
  'Select site': 'בחרו אתר',
  Equals: 'שווה ל-',
  'Not equal': 'שונה מ-',
  Contains: 'מכיל',
  equals: 'שווה ל-',
  'does not equal': 'שונה מ-',
  contains: 'מכיל',
  'Firewall rule created': 'כלל חומת האש נוצר',
  'Failed to create firewall rule': 'יצירת כלל חומת האש נכשלה',
  'Firewall rule updated': 'כלל חומת האש עודכן',
  'Failed to update firewall rule': 'עדכון כלל חומת האש נכשל',
  'Firewall rule deleted': 'כלל חומת האש נמחק',
  'Failed to delete firewall rule': 'מחיקת כלל חומת האש נכשלה',
  'Firewall rule disabled': 'כלל חומת האש הושבת',
  'Firewall rule enabled': 'כלל חומת האש הופעל',
  'Enable rule': 'הפעלת כלל',
  'Disable rule': 'השבתת כלל',
  'IP Address (optional)': 'כתובת IP (אופציונלי)',
  'Path (optional)': 'נתיב (אופציונלי)',
  'HTTP Method (optional)': 'מתודת HTTP (אופציונלי)',
  'Any method': 'כל מתודה',
  'Country (optional)': 'מדינה (אופציונלי)',
  'Rate limiting': 'הגבלת קצב',
  'Limit requests per time window': 'הגבלת בקשות לכל חלון זמן',
  Requests: 'בקשות',
  'Window (seconds)': 'חלון (שניות)',
  Enabled: 'מופעל',
  'Rule will be active immediately': 'הכלל יהיה פעיל באופן מיידי',
  'Update firewall rule': 'עדכון כלל חומת אש',
  'Modify the rule configuration and conditions.':
    'שנו את תצורת הכלל ואת התנאים.',
  'Update rule': 'עדכון כלל',
  'Delete firewall rule': 'מחיקת כלל חומת אש',
  'vs last period': 'לעומת התקופה הקודמת',
  'No analytics data': 'אין נתוני אנליטיקה',
  'Analytics data will appear here once your firewall rules start processing requests.':
    'נתוני אנליטיקה יופיעו כאן ברגע שכללי חומת האש שלכם יתחילו לעבד בקשות.',
  'Traffic overview': 'סקירת תעבורה',
  'Request volume and Firewall actions over the selected period.':
    'נפח בקשות ופעולות חומת אש בתקופה שנבחרה.',
  'Mock traffic behavior matching your Firewall rules. Live metrics will replace this soon.':
    'התנהגות תעבורה מדומה שתואמת את כללי חומת האש שלכם. מדדים חיים יחליפו זאת בקרוב.',
  'Estimated impact': 'השפעה משוערת',
  'Mock estimate of requests this rule would match over the last 24 hours.':
    'הערכה מדומה של בקשות שהכלל הזה היה מתאים ב-24 השעות האחרונות.',
  'Estimated requests this rule would match over the last 24 hours.':
    'הערכת בקשות שהכלל הזה היה מתאים להן ב-24 השעות האחרונות.',
  'Estimated requests this rule would match during the selected period.':
    'הערכת בקשות שהכלל הזה היה מתאים להן בתקופה שנבחרה.',
  'No traffic data for this period': 'אין נתוני תעבורה לתקופה זו',
  'Matched requests': 'בקשות תואמות',
  'Share of traffic': 'חלק מהתעבורה',
  'Total traffic': 'סך התעבורה',
  'Matched by rule': 'הותאם על ידי הכלל',
  'Add conditions to narrow which requests this rule matches.':
    'הוסיפו תנאים כדי לצמצם אילו בקשות הכלל מתאים.',
  'Matching estimate updates as you refine conditions.':
    'הערכת ההתאמה מתעדכנת כשאתם מחדדים תנאים.',
  'Total requests': 'סך הבקשות',
  Blocked: 'נחסמו',
  Allowed: 'הותרו',
  'Block rate': 'שיעור חסימה',
  'Deny rate': 'שיעור דחייה',
  'Request activity over time': 'פעילות בקשות לאורך זמן',
  'Real-time view of requests processed by firewall rules':
    'תצוגה בזמן אמת של בקשות שעובדו על ידי כללי חומת האש',
  'Traffic broken down by Firewall action':
    'תעבורה מפורקת לפי פעולת חומת האש',
  Challenged: 'אותגרו',
  'Challenge solves': 'פתרונות Challenge',
  'Avg solve time': 'זמן פתרון ממוצע',
  solved: 'נפתרו',
  'Top blocked IPs': 'כתובות ה-IP החסומות המובילות',
  'Top denied IPs': 'כתובות ה-IP שנדחו המובילות',
  'IP addresses with the most blocked requests':
    'כתובות IP עם הכי הרבה בקשות חסומות',
  'IP addresses with the most denied requests':
    'כתובות IP עם הכי הרבה בקשות שנדחו',
  'No blocked IPs in this period': 'אין כתובות IP חסומות בתקופה זו',
  'No denied IPs in this period': 'אין כתובות IP שנדחו בתקופה זו',
  'Top blocked countries': 'המדינות החסומות המובילות',
  'Top denied countries': 'המדינות שנדחו המובילות',
  'Countries with the most blocked requests':
    'מדינות עם הכי הרבה בקשות חסומות',
  'Countries with the most denied requests':
    'מדינות עם הכי הרבה בקשות שנדחו',
  'No blocked countries in this period': 'אין מדינות חסומות בתקופה זו',
  'No denied countries in this period': 'אין מדינות שנדחו בתקופה זו',
  'Requests throttled by rate limit rules':
    'בקשות שהוגבלו על ידי כללי מגבלת קצב',
  'Requests sent to a redirect location': 'בקשות שנשלחו ליעד הפניה',
  'Bypass share': 'שיעור עקיפה',
  'Share of traffic allowed past remaining rules':
    'חלק מהתעבורה שעברה את שאר הכללים',
  'Request logs': 'לוגי בקשות',
  'Mock Firewall decisions for recent requests. Live logs will replace this soon.':
    'החלטות חומת אש מדומות לבקשות אחרונות. לוגים חיים יחליפו זאת בקרוב.',
  'All actions': 'כל הפעולות',
  'All rules': 'כל הכללים',
  Refresh: 'רענון',
  Export: 'ייצוא',
  'No logs found': 'לא נמצאו לוגים',
  'No firewall logs': 'אין לוגי חומת אש',
  'Try adjusting or clearing filters': 'נסו להתאים או לנקות מסננים',
  'Firewall logs will appear here once rules start processing requests':
    'לוגי חומת האש יופיעו כאן ברגע שהכללים יתחילו לעבד בקשות',
  'Unknown rule': 'כלל לא ידוע',
  Timestamp: 'חותמת זמן',
  'IP Address': 'כתובת IP',
  Path: 'נתיב',
  Method: 'מתודה',
  Country: 'מדינה',

  // Stores (Distribution)
  Distribution: 'הפצה',
  'No distribution apps yet': 'אין עדיין אפליקציות הפצה',
  'Create an app to build and submit to Google Play, the App Store, and the Microsoft Store.':
    'צרו אפליקציה כדי לבנות ולהגיש ל-Google Play, ל-App Store ול-Microsoft Store.',
  'Search apps...': 'חיפוש אפליקציות...',
  'Create app': 'יצירת אפליקציה',
  'Loading apps...': 'טוען אפליקציות...',
  App: 'אפליקציה',
  Platforms: 'פלטפורמות',
  Identifier: 'מזהה',
  Updated: 'עודכן',
  apps: 'אפליקציות',
  Building: 'בבנייה',
  Ready: 'מוכן',
  Failed: 'נכשל',
  Canceled: 'בוטל',
  Queued: 'בתור',
  Processing: 'בעיבוד',
  'In review': 'בבדיקה',
  Approved: 'אושר',
  Published: 'פורסם',
  Rejected: 'נדחה',
  'Distribution app created': 'אפליקציית ההפצה נוצרה',
  'Failed to create distribution app': 'יצירת אפליקציית ההפצה נכשלה',
  'Create distribution app': 'יצירת אפליקציית הפצה',
  'Pick a framework and platforms to start shipping builds to the app stores.':
    'בחרו פריימוורק ופלטפורמות כדי להתחיל לשלוח בניות לחנויות האפליקציות.',
  'My app': 'האפליקציה שלי',
  Framework: 'Framework',
  'Identifier (optional)': 'מזהה (אופציונלי)',
  'Creating...': 'יוצר...',
  Create: 'יצירה',
  'App not found': 'האפליקציה לא נמצאה',
  'This distribution app does not exist or has been removed.':
    'אפליקציית הפצה זו אינה קיימת או שהוסרה.',
  'Back to Distribution': 'חזרה להפצה',
  Configuration: 'תצורה',
  'Default track': 'מסלול ברירת מחדל',
  'Auto submit': 'הגשה אוטומטית',
  Builds: 'בניות',
  'Loading builds...': 'טוען בניות...',
  'No builds yet': 'אין עדיין בניות',
  'Trigger a build to create an artifact for the stores.':
    'הפעילו בנייה כדי ליצור קובץ עבור החנויות.',
  Platform: 'פלטפורמה',
  Version: 'גרסה',
  Duration: 'משך',
  Created: 'נוצר',
  Submissions: 'הגשות',
  'Loading submissions...': 'טוען הגשות...',
  'No submissions yet': 'אין עדיין הגשות',
  'Submit a ready build to a store to track its review status here.':
    'הגישו בנייה מוכנה לחנות כדי לעקוב כאן אחרי סטטוס הבדיקה שלה.',
  Provider: 'ספק',
  Track: 'מסלול',
  Release: 'שחרור',
  Submitted: 'הוגש',

  // Activity
  Activity: 'פעילות',
  Event: 'אירוע',
  Actor: 'מבצע',
  Type: 'סוג',
  Resource: 'משאב',
  'IP address': 'כתובת IP',
  Time: 'זמן',
  'Loading activities': 'טוען פעילויות',
  'Activity log not found or unavailable.':
    'יומן הפעילות לא נמצא או שאינו זמין.',
  Retention: 'שמירת נתונים',
  '1 hour': 'שעה אחת',
  '30 days': '30 ימים',
  'Activity retention': 'שמירת נתוני פעילות',
  'Upgrade or contact sales for longer retention.':
    'שדרגו או פנו למכירות לשמירת נתונים ממושכת יותר.',
  'Limited activity history': 'היסטוריית פעילות מוגבלת',
  'of activity history.': 'של היסטוריית פעילות.',
  'of activity history. Upgrade for longer retention.':
    'של היסטוריית פעילות. שדרגו לשמירת נתונים ממושכת יותר.',
  '1 day': 'יום אחד',
  Upgrade: 'שדרוג',
  'Open activity details': 'פתיחת פרטי פעילות',
  Deleted: 'נמחק',
  Executed: 'הורץ',
  Uploaded: 'הועלה',
  'Logged in': 'התחבר',
  'Logged out': 'התנתק',
  Viewed: 'נצפה',
  User: 'משתמש',
  Admin: 'מנהל',
  Guest: 'אורח',
  'Project key': 'מפתח פרויקט',
  'Account key': 'מפתח חשבון',
  'Org key': 'מפתח ארגון',
  System: 'מערכת',
  'Project API key': 'מפתח API של פרויקט',
  'Account API key': 'מפתח API של חשבון',
  'Organization API key': 'מפתח API של ארגון',
  document: 'מסמך',
  collection: 'אוסף',
  database: 'מסד נתונים',
  file: 'קובץ',
  bucket: 'באקט',
  function: 'פונקציה',
  user: 'משתמש',
  team: 'צוות',
  site: 'אתר',
  rule: 'כלל',
  project: 'פרויקט',
  activities: 'פעילויות',
  'No activities yet': 'אין עדיין פעילויות',
  'Activity will appear here as you use your project':
    'פעילות תופיע כאן ככל שתשתמשו בפרויקט',
  'Activity log': 'יומן פעילות',
  'Details for activity': 'פרטים עבור פעילות',
  'Copy link to this activity': 'העתקת קישור לפעילות זו',
  'Link to this activity copied': 'הקישור לפעילות זו הועתק',
  'Could not copy link': 'לא ניתן היה להעתיק את הקישור',
  Email: 'אימייל',
  'Actor ID': 'מזהה מבצע',
  'Actor type': 'סוג מבצע',
  'Via MCP': 'דרך MCP',
  'Resource type (API)': 'סוג משאב (API)',
  'Resource path': 'נתיב משאב',
  'Resource parent': 'משאב אב',
  'Request context': 'הקשר הבקשה',
  Hostname: 'Hostname',
  'User agent': 'סוכן משתמש',
  'Client & device': 'לקוח ומכשיר',
  Client: 'לקוח',
  Engine: 'מנוע',
  'Operating system': 'מערכת הפעלה',
  Device: 'מכשיר',
  Location: 'מיקום',
  Scope: 'הרשאת גישה',
  'Project ID': 'מזהה פרויקט',
  'Team ID': 'מזהה צוות',
  'Event ID': 'מזהה אירוע',
  'Raw payload': 'נתונים גולמיים',
  'Location unknown': 'מיקום לא ידוע',
  Unknown: 'לא ידוע',
  'Recent Activity': 'פעילות אחרונה',
  Overview: 'סקירה כללית',
  'Copy link': 'העתקת קישור',
  'Open in new tab': 'פתיחה בכרטיסייה חדשה',
  'Open in new window': 'פתיחה בחלון חדש',
  Total: 'סה"כ',
  'Mock data': 'נתוני דמה',
  'Volume by resource': 'נפח לפי משאב',
  'Hide volume chart': 'הסתרת תרשים הנפח',
  'Show volume chart': 'הצגת תרשים הנפח',
  Hide: 'הסתרה',
  Show: 'הצגה',
  'No data for this date range': 'אין נתונים לטווח תאריכים זה',
  'Drag on the chart to select a date range': 'גררו על התרשים כדי לבחור טווח תאריכים',
  'Filter activities by': 'סינון פעילויות לפי',
  Database: 'מסד נתונים',
  Function: 'פונקציה',
  File: 'קובץ',
  Bucket: 'באקט',
  Document: 'מסמך',
  Collection: 'אוסף',
  Team: 'צוות',
  Site: 'אתר',
  Project: 'פרויקט',

  // Analytics
  'Linked to Appwrite Sites deployment': 'מקושר לפריסת Appwrite Sites', // pragma: allowlist secret
  'Human vs AI traffic (last 30 days)':
    'תנועה אנושית מול תנועת AI (30 הימים האחרונים)',
  Desktop: 'מחשב שולחני',
  Mobile: 'נייד',
  Tablet: 'טאבלט',
  'Search websites...': 'חיפוש אתרים...',
  'Add Website': 'הוספת אתר',
  'No data yet': 'אין נתונים עדיין',
  'Waiting for first visitor': 'ממתין למבקר הראשון',
  'No websites yet': 'אין אתרים עדיין',
  'Add your first website to start tracking analytics':
    'הוסיפו את האתר הראשון שלכם כדי להתחיל לעקוב אחר נתוני אנליטיקה',
  Website: 'אתר',
  Visitors: 'מבקרים',
  'Page Views': 'צפיות בדפים',
  'Avg. Duration': 'משך ממוצע',
  'Bounce Rate': 'שיעור נטישה',
  Traffic: 'תנועה',
  'Last Activity': 'פעילות אחרונה',
  websites: 'אתרים',
  Visit: 'מעבר לאתר',
  Remove: 'הסרה',
  Disabled: 'מושבת',
  'active visitors': 'מבקרים פעילים',
  'Traffic composition': 'הרכב התנועה',
  'Human visitors vs AI agents and crawlers':
    'מבקרים אנושיים מול סוכני AI וסורקים',
  Human: 'בני אדם',
  'AI agents': 'סוכני AI',
  visits: 'ביקורים',
  'AI discovery impact': 'השפעת גילוי באמצעות AI',
  'Human sign-ups attributed to AI agent discovery':
    'הרשמות אנושיות שמיוחסות לגילוי על ידי סוכני AI',
  'of human conversions came from users who discovered you via AI agents':
    'מההמרות האנושיות הגיעו ממשתמשים שגילו אתכם באמצעות סוכני AI',
  'Optimize docs and key pages for AI visibility to increase discovery-driven sign-ups':
    'בצעו אופטימיזציה של הדוקומנטציה ודפים מרכזיים לנראות AI כדי להגדיל הרשמות שמקורן בגילוי',
  'Top AI platforms crawling and accessing your site':
    'פלטפורמות AI מובילות שסורקות וניגשות לאתר שלכם',
  'Traffic sources': 'מקורות תנועה',
  Channels: 'ערוצים',
  Sources: 'מקורות',
  Campaigns: 'קמפיינים',
  'Organic Search': 'חיפוש אורגני',
  Social: 'רשתות חברתיות',
  Direct: 'ישיר',
  Referral: 'הפניה',
  Pages: 'דפים',
  'Top Pages': 'דפים מובילים',
  'Entry Pages': 'דפי כניסה',
  'Exit Pages': 'דפי יציאה',
  Locations: 'מיקומים',
  Map: 'מפה',
  Countries: 'מדינות',
  Regions: 'אזורים',
  Cities: 'ערים',
  unique: 'ייחודיים',
  Technology: 'טכנולוגיה',
  Browsers: 'דפדפנים',
  'Operating Systems': 'מערכות הפעלה',
  Devices: 'מכשירים',
  'Peak hours': 'שעות שיא',
  'Visitor types': 'סוגי מבקרים',
  'New visitors': 'מבקרים חדשים',
  'Returning visitors': 'מבקרים חוזרים',
  'Unique visitors': 'מבקרים ייחודיים',
  'Total visitors': 'סה"כ מבקרים',
  'Page views': 'צפיות בדפים',
  'Pages per visit': 'דפים לביקור',
  'Bounce rate': 'שיעור נטישה',
  'Visit duration': 'משך ביקור',
  visitors: 'מבקרים',
  Goals: 'יעדים',
  'Measure how often visitors complete specific actions. Goals allow you to track registrations, button clicks, form completions, external link clicks, file downloads, 404 error pages and more.':
    'מדדו באיזו תדירות מבקרים משלימים פעולות ספציפיות. יעדים מאפשרים לכם לעקוב אחר הרשמות, לחיצות על כפתורים, השלמות טפסים, לחיצות על קישורים חיצוניים, הורדות קבצים, דפי שגיאה 404 ועוד.',
  'Hide this report': 'הסתרת הדוח הזה',
  'Set up goals': 'הגדרת יעדים',
  Settings: 'הגדרות',
  'Settings content will be displayed here.': 'תוכן ההגדרות יוצג כאן.',
  'Show less': 'הצגת פחות',
  'Show more': 'הצגת עוד',
  more: 'נוספים',
  'Select date range': 'בחירת טווח תאריכים',
  'Quick select': 'בחירה מהירה',
  Days: 'ימים',
  'To date': 'עד היום',
  'Last hour': 'השעה האחרונה',
  'Last 6 hours': '6 השעות האחרונות',
  'Last 24 hours': '24 השעות האחרונות',
  Today: 'היום',
  'Last 7 days': '7 הימים האחרונים',
  'Last 30 days': '30 הימים האחרונים',
  'Week to date': 'מתחילת השבוע',
  'Month to date': 'מתחילת החודש',
  Clear: 'ניקוי',
  Apply: 'החלה',
  Compare: 'השוואה',
  'No comparison': 'ללא השוואה',
  'Show only current period': 'הצגת התקופה הנוכחית בלבד',
  'Previous period': 'תקופה קודמת',
  'Same length period before': 'תקופה באותו אורך לפני כן',
  'Previous year': 'שנה קודמת',
  'Same dates, previous year': 'אותם תאריכים, שנה קודמת',
  'Same period last year': 'אותה תקופה בשנה שעברה',
  'Same dates, one year ago': 'אותם תאריכים, לפני שנה',

  // Realtime
  Realtime: 'Realtime',
  Connected: 'מחובר',
  Connecting: 'מתחבר',
  'Connection failed': 'החיבור נכשל',
  Disconnected: 'מנותק',
  Offline: 'לא מקוון',
  'WebSocket URL copied': 'כתובת ה-WebSocket הועתקה',
  'Connection status': 'מצב חיבור',
  'Copy WebSocket URL': 'העתקת כתובת WebSocket',
  'No messages yet': 'אין הודעות עדיין', // pragma: allowlist secret
  'Connect as guest or a project user, then subscribe to channels to inspect WebSocket traffic. You can also insert sample frames to preview payload structure.':
    'התחברו כאורח או כמשתמש פרויקט, ואז הירשמו לערוצים כדי לבחון תעבורת WebSocket. אפשר גם להוסיף פריימים לדוגמה כדי לראות את מבנה הנתונים.',
  'Waiting for subscriptions': 'ממתין להרשמות',
  'Add a channel subscription to start receiving and logging Realtime frames.':
    'הוסיפו הרשמה לערוץ כדי להתחיל לקבל ולתעד פריימים של זמן אמת.',
  'Listening for traffic': 'מאזין לתעבורה',
  'Incoming and outgoing WebSocket frames will appear here as they arrive.':
    'פריימים נכנסים ויוצאים של WebSocket יופיעו כאן עם הגעתם.',
  'Select guest or a project user to connect as.':
    'בחרו אורח או משתמש פרויקט להתחבר בתור.',
  'No session or JWT': 'ללא סשן או JWT',
  'Act as': 'פעולה בתור',
  'Guest connections do not send a session or JWT. Subscribe only to channels with public read permissions.':
    'חיבורי אורח אינם שולחים סשן או JWT. הירשמו רק לערוצים עם הרשאות קריאה ציבוריות.',
  'User connections create a JWT for the selected project user when you connect.':
    'חיבורי משתמש יוצרים JWT עבור משתמש הפרויקט שנבחר בעת ההתחברות.',
  'Loading users…': 'טוען משתמשים…',
  'Select guest or user': 'בחירת אורח או משתמש',
  'Search users or select guest...': 'חיפוש משתמשים או בחירת אורח…',
  'No users found': 'לא נמצאו משתמשים',
  'SDK code': 'קוד SDK',
  Disconnect: 'ניתוק',
  Subscriptions: 'הרשמות',
  Messages: 'הודעות', // pragma: allowlist secret
  'Disconnect all': 'ניתוק הכול',
  Resume: 'המשך',
  Pause: 'השהיה',
  'No messages match your filters.': 'אין הודעות שתואמות למסננים שלכם.', // pragma: allowlist secret
  'Info message': 'הודעת מידע',
  'Incoming message': 'הודעה נכנסת',
  'Outgoing message': 'הודעה יוצאת',
  Sample: 'דוגמה',
  'Sample frame for reference only. Nothing was sent over the network and no project data was changed.':
    'פריים לדוגמה לעיון בלבד. שום דבר לא נשלח ברשת ונתוני הפרויקט לא השתנו.',
  'Sample frames are added locally for reference. Nothing is sent over the network.':
    'פריימים לדוגמה נוספים מקומית לעיון בלבד. שום דבר לא נשלח ברשת.',
  'Table row update': 'עדכון שורת טבלה',
  'Incoming event after a row is updated': 'אירוע נכנס לאחר עדכון שורה',
  'User update': 'עדכון משתמש',
  'Incoming event after a user record changes':
    'אירוע נכנס לאחר שינוי ברשומת משתמש',
  'File created': 'קובץ נוצר',
  'Incoming event after a storage file is uploaded':
    'אירוע נכנס לאחר העלאת קובץ אחסון',
  'Connected frame': 'פריים חיבור',
  'Server handshake after the WebSocket opens':
    'לחיצת יד של השרת לאחר פתיחת ה-WebSocket',
  'Add subscription': 'הוספת הרשמה',
  'Subscribe to a channel. Add query filters to each subscription separately.':
    'הירשמו לערוץ. הוסיפו מסנני שאילתה לכל הרשמה בנפרד.',
  'Configure a channel now. Add queries per subscription, then connect.':
    'הגדירו ערוץ עכשיו. הוסיפו שאילתות לכל הרשמה, ואז התחברו.',
  Suggested: 'הצעות',
  'Add a subscription to configure channels and query filters.':
    'הוסיפו הרשמה כדי להגדיר ערוצים ומסנני שאילתה.',
  Live: 'Live',
  Query: 'שאילתה',
  'Add query': 'הוספת שאילתה',
  'Filter events for this subscription only.': 'סינון אירועים להרשמה זו בלבד.',
  'Remove subscription': 'הסרת הרשמה',
  'Remove query': 'הסרת שאילתה',
  'Remove filter': 'הסרת מסנן',
  'Search messages...': 'חיפוש הודעות…', // pragma: allowlist secret
  'Clear search': 'ניקוי חיפוש',
  'Message direction': 'כיוון הודעה',
  All: 'הכול',
  In: 'נכנס',
  Out: 'יוצא',
  Filters: 'מסננים',
  'Message filters': 'מסנני הודעות',
  'Clear all': 'ניקוי הכול',
  'Hide ping/pong': 'הסתרת ping/pong',
  'Hide heartbeat frames from the log': 'הסתרת פריימים של פעימות לב מהיומן',
  'Frame types': 'סוגי פריימים',
  None: 'ללא',
  Search: 'חיפוש',
  'Incoming only': 'נכנסות בלבד',
  'Outgoing only': 'יוצאות בלבד',
  'frame types': 'סוגי פריימים',
  Value: 'ערך',
  True: 'True',
  False: 'False',
  'Select value': 'בחירת ערך',
  'Search...': 'חיפוש…',
  'No results': 'אין תוצאות',
  'Enter value': 'הזנת ערך',
  Attribute: 'מאפיין',
  'Value type': 'סוג ערך',
  'Search types...': 'חיפוש סוגים…',
  'No types found': 'לא נמצאו סוגים',
  Operator: 'אופרטור',
  'Search operators...': 'חיפוש אופרטורים…',
  'No operators found': 'לא נמצאו אופרטורים',
  String: 'מחרוזת',
  Integer: 'מספר שלם',
  Float: 'מספר עשרוני',
  Boolean: 'בוליאני',
  Datetime: 'תאריך ושעה',
  equal: 'שווה',
  'not equal': 'לא שווה',
  'less than': 'קטן מ-',
  'less than or equal': 'קטן או שווה',
  'greater than': 'גדול מ-',
  'greater than or equal': 'גדול או שווה',
  'is null': 'ריק (null)',
  'is not null': 'לא ריק (null)',
  'Copy JSON': 'העתקת JSON',
  Expand: 'הרחבה',
  Collapse: 'כיווץ',
  'Realtime connection code': 'קוד חיבור זמן אמת',
  'One subscribe call per configured subscription':
    'קריאת הרשמה אחת לכל הרשמה מוגדרת',
  for: 'עבור',
  'Query filters are included per subscription where configured.':
    'מסנני שאילתה כלולים בכל הרשמה שבה הוגדרו.',
  'Authenticated channels require an active session or JWT on the client.':
    'ערוצים מאומתים דורשים סשן פעיל או JWT בצד הלקוח.',
  'Add a subscription in the debugger to generate channel-specific subscribe calls. Add query filters on each subscription as needed.':
    'הוסיפו הרשמה בכלי הניפוי כדי ליצור קריאות הרשמה ייעודיות לערוץ. הוסיפו מסנני שאילתה לכל הרשמה לפי הצורך.',
  Close: 'סגירה',
  'Reconnecting…': 'מתחבר מחדש…',
  attempt: 'ניסיון',
  of: 'מתוך',
  'Connection lost. Reconnecting in': 'החיבור אבד. מתחבר מחדש בעוד',
  'Loading channels...': 'טוען ערוצים...',
  Channel: 'ערוץ',
  Subscribers: 'מנויים',
  'No channels found': 'לא נמצאו ערוצים',
  'Active realtime channels will appear here when they are created':
    'ערוצי זמן אמת פעילים יופיעו כאן כשייווצרו',
  channels: 'ערוצים',
  'Loading messages...': 'טוען הודעות...', // pragma: allowlist secret
  'Message ID': 'מזהה הודעה',
  Events: 'אירועים',
  'Payload Size': 'גודל נתונים',
  'No messages found': 'לא נמצאו הודעות', // pragma: allowlist secret
  // pragma: allowlist secret
  'Realtime messages will appear here when they are received':
    'הודעות זמן אמת יופיעו כאן כשיתקבלו',
  messages: 'הודעות', // pragma: allowlist secret
  Connections: 'חיבורים',
  'Concurrent Connections': 'חיבורים בו-זמניים',
  'Messages Throughput': 'קצב הודעות', // pragma: allowlist secret
  'Active Channels': 'ערוצים פעילים',
  connections: 'חיבורים',
  'messages/min': 'הודעות/דקה', // pragma: allowlist secret
  'No data available': 'אין נתונים זמינים',
  'Select a date range to view connection data':
    'בחרו טווח תאריכים להצגת נתוני חיבורים',
  // pragma: allowlist secret
  'Select a date range to view messages data':
    'בחרו טווח תאריכים להצגת נתוני הודעות',
  'Select a date range to view channels data':
    'בחרו טווח תאריכים להצגת נתוני ערוצים',
  'View channels': 'הצגת ערוצים',
  'View messages': 'הצגת הודעות', // pragma: allowlist secret
  'Real-time connection count over time. Shows the number of active WebSocket connections at any given moment.':
    'ספירת חיבורים בזמן אמת לאורך זמן. מציג את מספר חיבורי ה-WebSocket הפעילים בכל רגע נתון.',
  // pragma: allowlist secret
  'Messages per minute over time. Tracks the rate at which realtime messages are being sent through the system.':
    'הודעות לדקה לאורך זמן. עוקב אחר הקצב שבו הודעות זמן אמת נשלחות דרך המערכת.',
  'Number of active channels over time. Displays how many different realtime channels are currently being listened to.':
    'מספר ערוצים פעילים לאורך זמן. מציג לכמה ערוצי זמן אמת שונים מאזינים כרגע.',

  // Apps
  Apps: 'אפליקציות',
  'Add app': 'הוספת אפליקציה',
  "You don't have permission to add apps.": 'אין לכם הרשאה להוסיף אפליקציות.',
  'No apps connected': 'אין אפליקציות מחוברות',
  // pragma: allowlist secret
  'Connect your first app to start building with Appwrite. Add web apps, mobile apps, or server SDKs to get started.':
    'חברו את האפליקציה הראשונה שלכם כדי להתחיל לבנות עם Appwrite. הוסיפו אפליקציות ווב, אפליקציות מובייל או SDK של שרת כדי להתחיל.', // pragma: allowlist secret
  'Connect with your stack': 'התחברו עם הסטאק שלכם',
  'No apps match your search': 'אין אפליקציות שתואמות לחיפוש שלכם',
  'App deleted': 'האפליקציה נמחקה',
  'Failed to delete app': 'מחיקת האפליקציה נכשלה',
  'Copy identifier': 'העתקת מזהה',
  'Delete app': 'מחיקת אפליקציה',
  'this app': 'האפליקציה הזו',
  'App updated successfully': 'האפליקציה עודכנה בהצלחה',
  'Failed to update app': 'עדכון האפליקציה נכשל',
  'App deleted successfully': 'האפליקציה נמחקה בהצלחה',
  'Update app': 'עדכון אפליקציה',
  'App name': 'שם האפליקציה',
  'The domain your app makes requests from. Use':
    'הדומיין שממנו האפליקציה שולחת בקשות. השתמשו ב',
  'for development (no port or protocol). Add a separate platform for each origin (e.g. localhost and production).':
    'לפיתוח (ללא פורט או פרוטוקול). הוסיפו פלטפורמה נפרדת לכל מקור (למשל localhost וסביבת ייצור).',
  'Troubleshoot CORS errors': 'פתרון שגיאות CORS',
  'Bundle ID': 'מזהה Bundle',
  'Application ID': 'מזהה אפליקציה',
  'Package identifier': 'מזהה חבילה',
  'Package name': 'שם חבילה',
  'Remove this app from the project. This action cannot be undone.':
    'הסרת האפליקציה הזו מהפרויקט. פעולה זו אינה ניתנת לביטול.',
  'Enter an app name': 'הזינו שם אפליקציה',
  'Enter a valid hostname (e.g. localhost or app.example.com)':
    'הזינו שם מארח תקין (למשל localhost או app.example.com)',
  'Enter a valid bundle ID (e.g. com.example.app)':
    'הזינו מזהה Bundle תקין (למשל com.example.app)',
  'Enter a valid package name (e.g. com.example.app)':
    'הזינו שם חבילה תקין (למשל com.example.app)',
  'Failed to register app': 'רישום האפליקציה נכשל',
  'Choose target': 'בחירת יעד',
  'Where this Flutter app runs.': 'היכן אפליקציית ה-Flutter הזו רצה.',
  'Which Apple platform you are building for.':
    'לאיזו פלטפורמת Apple אתם בונים.',
  'Android or iOS for this React Native app.':
    'Android או iOS עבור אפליקציית ה-React Native הזו.',
  'Connect your app': 'חיבור האפליקציה שלכם',
  Continue: 'המשך',
  'Register and continue': 'רישום והמשך',
  'Add another app': 'הוספת אפליקציה נוספת',
  'Choose your platform': 'בחרו את הפלטפורמה שלכם',
  'Web, mobile, or desktop - pick what matches your project.':
    'ווב, מובייל או דסקטופ, בחרו מה שמתאים לפרויקט שלכם.',
  'Choose a web framework': 'בחירת פריימוורק ווב',
  'We match starters and AI prompts to the framework you pick.':
    'אנחנו מתאימים פרויקטי התחלה והנחיות AI לפריימוורק שתבחרו.',
  'App details': 'פרטי האפליקציה',
  'These values are sent to Appwrite when you register this app.':
    'הערכים האלה נשלחים ל-Appwrite כשאתם רושמים את האפליקציה הזו.', // pragma: allowlist secret
  // pragma: allowlist secret
  'Origin your app will call Appwrite from (no protocol or port). Use localhost for local development.':
    'המקור שממנו האפליקציה שלכם תקרא ל-Appwrite (ללא פרוטוקול או פורט). השתמשו ב-localhost לפיתוח מקומי.', // pragma: allowlist secret
  'App registered': 'האפליקציה נרשמה',
  'Your project is ready to accept traffic from this app.':
    'הפרויקט שלכם מוכן לקבל תעבורה מהאפליקציה הזו.',
  'Set up with AI': 'הגדרה עם AI',
  Recommended: 'מומלץ',
  'Hand off a ready-made prompt with your endpoint and project ID to your favourite AI tool, or copy it anywhere.':
    'העבירו הנחיה מוכנה מראש עם נקודת הקצה ומזהה הפרויקט שלכם לכלי ה-AI המועדף עליכם, או העתיקו אותה לכל מקום.',
  'Copy prompt': 'העתקת הנחיה',
  'Open in tool': 'פתיחה בכלי',
  Prompt: 'הנחיה',
  'Manual setup': 'הגדרה ידנית',
  'Clone the starter, drop in your credentials, then run the app and send a ping to confirm the link.':
    'שכפלו את פרויקט ההתחלה, הזינו את פרטי הגישה שלכם, ואז הריצו את האפליקציה ושלחו ping לאישור החיבור.',
  'Clone starter': 'שכפול פרויקט התחלה',
  'Install and run': 'התקנה והרצה',
  'Demo URL': 'כתובת הדגמה',
  'Send a ping': 'שליחת ping',
  'Ping received - your SDK reached Appwrite.':
    'ping התקבל, ה-SDK שלכם הגיע ל-Appwrite.', // pragma: allowlist secret
  'Waiting for client.ping() from your app...':
    'ממתין ל-client.ping()‎ מהאפליקציה שלכם...',
  'Why register an app?': 'למה לרשום אפליקציה?',
  // pragma: allowlist secret
  'Apps tell Appwrite which origins or bundle IDs are allowed to call your project API. Choose the kind of client you are building - you can register more apps later.':
    'אפליקציות מגדירות ל-Appwrite אילו מקורות או מזהי Bundle מורשים לקרוא ל-API של הפרויקט שלכם. בחרו את סוג הלקוח שאתם בונים, תוכלו לרשום אפליקציות נוספות מאוחר יותר.', // pragma: allowlist secret
  Web: 'ווב',
  'allowed hostnames (origins)': 'שמות מארח מורשים (מקורות)',
  'Mobile & desktop': 'מובייל ודסקטופ',
  'bundle ID or package name': 'מזהה Bundle או שם חבילה',
  Connection: 'חיבור',
  // pragma: allowlist secret
  'Your app talks to Appwrite from the hostname or bundle you register, using the project API endpoint.':
    'האפליקציה שלכם מתקשרת עם Appwrite מהשם מארח או ה-Bundle שרשמתם, דרך נקודת הקצה של ה-API של הפרויקט.', // pragma: allowlist secret
  'Waiting for your app to ping Appwrite…':
    'ממתין שהאפליקציה שלכם תשלח ping ל-Appwrite…', // pragma: allowlist secret
  'Connected - your SDK reached this project.':
    'מחובר, ה-SDK שלכם הגיע לפרויקט הזה.',
  // pragma: allowlist secret
  'Keep this tab open while your app is running so Appwrite can confirm the connection.':
    'השאירו את הלשונית הזו פתוחה בזמן שהאפליקציה שלכם רצה כדי ש-Appwrite יוכל לאשר את החיבור.', // pragma: allowlist secret
  'More information': 'מידע נוסף',
  Change: 'שינוי',
  'Choose platform': 'בחירת פלטפורמה',
  'Pick the client stack you are building.': 'בחרו את סטאק הלקוח שאתם בונים.',
  'Hostname or bundle ID and display name.':
    'שם מארח או מזהה Bundle ושם תצוגה.',
  'Connect locally': 'חיבור מקומי',
  'Run a starter or use AI, then verify with a ping.':
    'הריצו פרויקט התחלה או השתמשו ב-AI, ואז אמתו עם ping.',

  // Shared project components
  'Loading API keys...': 'טוען מפתחות API...',
  'No API keys found': 'לא נמצאו מפתחות API',
  'Create your first API key to authenticate your applications':
    'צרו את מפתח ה-API הראשון שלכם כדי לאמת את האפליקציות שלכם',
  Expired: 'פג תוקף',
  'Expires soon': 'התוקף יפוג בקרוב',
  'No scopes': 'ללא היקפי גישה',
  scopes: 'הרשאות גישה',
  scope: 'הרשאת גישה',
  'View key': 'הצגת מפתח',
  Expires: 'יפוג בתאריך',
  'No expiration': 'ללא תפוגה',
  'API Key': 'מפתח API',
  'Copy the full API key below. Keep it secure and never share it publicly.':
    'העתיקו את מפתח ה-API המלא למטה. שמרו עליו מאובטח ולעולם אל תשתפו אותו בפומבי.',
  'More info': 'מידע נוסף',
  Triggers: 'טריגרים',
  'Limit which pushes trigger deployments. Use globs; prefix with':
    'הגבילו אילו פעולות push מפעילות פריסות. השתמשו בתבניות glob; הוסיפו את התחילית',
  'to exclude.': 'כדי להחריג.',
  'Learn more': 'למדו עוד',
  'Connect a repository in': 'חברו repo ב',
  'Git settings': 'הגדרות Git',
  Preview: 'תצוגה מקדימה',
  'Current behavior': 'התנהגות נוכחית',
  Production: 'פרודקשן',
  'Empty = all branches. ! excludes.': 'ריק = כל הענפים. ! מחריג.',
  'Branch filters': 'מסנני ענפים',
  'Empty = all file changes. ! excludes.': 'ריק = כל שינויי הקבצים. ! מחריג.',
  'Path filters': 'מסנני נתיבים',
  Prefix: 'תחילית',
  Exclude: 'החרגה',
  Folder: 'תיקייה',
  Monorepo: 'מונו-ריפו',
  'Exclude files': 'החרגת קבצים',
  'This feature is coming soon': 'התכונה הזו תגיע בקרוב',
  'This section is under construction': 'האזור הזה בבנייה',
  'Connect to your project': 'התחברות לפרויקט שלכם',
  'SDK / Platform': 'SDK / פלטפורמה',
  Server: 'שרת',
  Using: 'באמצעות',
  'Package manager': 'מנהל חבילות',
  'Server and backend code need an API key with the right scopes. Create and manage keys in your project.':
    'קוד שרת ו-backend זקוק למפתח API עם ההרשאות המתאימות. צרו ונהלו מפתחות בפרויקט שלכם.',
  'View API keys': 'הצגת מפתחות API',
  'Server setup guide': 'מדריך הקמת שרת',
  'Read the docs': 'קריאת הדוקומנטציה',
  'Use the Appwrite CLI to manage your project from the terminal. Install the CLI, log in, then point it at this project.':
    'השתמשו ב-CLI של Appwrite כדי לנהל את הפרויקט מהטרמינל. התקינו את ה-CLI, התחברו, ואז כוונו אותו לפרויקט הזה.', // pragma: allowlist secret
  'Manage this project from the terminal. Use an interactive browser login on your machine, or an API key in CI/CD.':
    'נהלו את הפרויקט הזה מהטרמינל. השתמשו בהתחברות אינטראקטיבית בדפדפן במחשב שלכם, או במפתח API ב-CI/CD.',
  Interactive: 'אינטראקטיבי',
  'CI/CD': 'CI/CD',
  'Install the CLI': 'התקנת ה-CLI',
  'Install script': 'סקריפט התקנה',
  'Full installation guide': 'מדריך התקנה מלא',
  'Log in': 'התחברות',
  '2. Log in': '2. התחברות',
  Terminal: 'טרמינל',
  'Your email (blurred)': 'האימייל שלכם (מטושטש)',
  'Connect to this project': 'התחברות לפרויקט הזה',
  '3. Connect to this project': '3. התחברות לפרויקט הזה',
  'For non-interactive use (CI/CD), add':
    'לשימוש לא אינטראקטיבי (CI/CD), הוסיפו',
  'Create API keys in your project settings.':
    'צרו מפתחות API בהגדרות הפרויקט שלכם.',
  'Opens your browser to authorize the CLI with OAuth (device flow). No password in the terminal.':
    'פותח את הדפדפן כדי לאשר את ה-CLI עם OAuth (device flow). בלי סיסמה בטרמינל.',
  'Opens your browser for OAuth. No password in the terminal.':
    'פותח את הדפדפן ל-OAuth. בלי סיסמה בטרמינל.',
  'About device authorization': 'על אישור מכשיר',
  'Device auth': 'אישור מכשיר',
  'Interactive setup. Pick or create a project and write appwrite.config.json.':
    'הגדרה אינטראקטיבית. בחרו או צרו פרויקט וכתבו את appwrite.config.json.',
  'Interactive init. Writes appwrite.config.json.':
    'אתחול אינטראקטיבי. כותב את appwrite.config.json.',
  'Authenticate with an API key': 'אימות עם מפתח API',
  '2. Authenticate with an API key': '2. אימות עם מפתח API',
  'Non-interactive mode for CI/CD. Sets endpoint, project, and key for headless commands.':
    'מצב לא אינטראקטיבי ל-CI/CD. מגדיר endpoint, פרויקט ומפתח לפקודות ללא ממשק.',
  'Headless CI/CD auth: endpoint, project, and key.':
    'אימות CI/CD ללא ממשק: endpoint, פרויקט ומפתח.',
  'Non-interactive docs': 'דוקומנטציה לא אינטראקטיבית',
  'CI docs': 'דוקס CI',
  'Install guide': 'מדריך התקנה',
  'Try it': 'נסו',
  'Common interactive commands after login and init:':
    'פקודות אינטראקטיביות נפוצות אחרי התחברות ו-init:',
  'Common headless commands after configuring the client:':
    'פקודות headless נפוצות אחרי הגדרת ה-client:',
  'After login and init:': 'אחרי התחברות ו-init:',
  'After configuring the client:': 'אחרי הגדרת ה-client:',
  'Try in Appwrite Terminal': 'נסו ב-Appwrite Terminal',
  'Open Appwrite Terminal': 'פתיחת Appwrite Terminal',
  'Run CLI commands in your browser against this project. Your Console session is already connected.':
    'הריצו פקודות CLI בדפדפן מול הפרויקט הזה. סשן ה-Console שלכם כבר מחובר.',
  "You don't have permission to open the project terminal.":
    'אין לכם הרשאה לפתוח את טרמינל הפרויקט.',
  'For local workflows like pull, push, init, and run, use your machine terminal after install.':
    'לזרימות מקומיות כמו pull, push, init ו-run, השתמשו בטרמינל במחשב אחרי ההתקנה.',
  'Example commands': 'פקודות לדוגמה',
  'Pull resources': 'משיכת משאבים',
  'Push resources': 'דחיפת משאבים',
  'Init a function': 'אתחול פונקציה',
  'Run a function locally': 'הרצת פונקציה מקומית',
  'List users': 'הצגת משתמשים',
  'Create a team': 'יצירת צוות',
  'Create a function deployment': 'יצירת פריסת פונקציה',
  'CLI commands': 'פקודות CLI',
  'Give your AI agent accurate Appwrite SDK context: method signatures, patterns, and best practices for your language. Run the setup once; you choose project or global scope in the prompts. Works with Cursor, Claude Code, and other compatible tools.':
    'תנו לסוכן ה-AI שלכם הקשר מדויק ל-Appwrite SDK: חתימות מתודות, תבניות ושיטות עבודה מומלצות לשפה שלכם. הריצו את ההתקנה פעם אחת; תבחרו היקף פרויקט או גלובלי בפרומפטים. עובד עם Cursor, Claude Code וכלים תואמים נוספים.', // pragma: allowlist secret
  'Skills are available for': 'מיומנויות (Skills) זמינות עבור',
  'pick what you use during setup.': 'בחרו במה אתם משתמשים במהלך ההתקנה.',
  Docs: 'דוקומנטציה',
  'Install command': 'פקודת התקנה',
  "Run in project root. You'll pick SDKs, tools, and scope.":
    'הריצו בתיקיית השורש של הפרויקט. תבחרו SDK, כלים והיקף.',
  'Then the CLI will ask:': 'לאחר מכן ה-CLI ישאל:',
  Skills: 'מיומנויות',
  'which SDKs to install (e.g. TypeScript, Go).':
    'אילו SDK להתקין (למשל TypeScript, Go).',
  Tools: 'כלים',
  'which AI tools use them (Cursor, Claude, etc.).':
    'אילו כלי AI ישתמשו בהן (Cursor, Claude ועוד).',
  'project (this repo) or global.': 'פרויקט (ה-repo הזה) או גלובלי.',
  'prefer symlink so skills stay up to date.':
    'עדיף קישור סימבולי כדי שהמיומנויות יישארו מעודכנות.',
  'Skills help your agent write Appwrite code. Ask one of these after installing. For live project actions like listing users, use MCP.':
    'Skills עוזרות לסוכן שלכם לכתוב קוד Appwrite. בקשו אחת מהבקשות האלה אחרי ההתקנה. לפעולות חיות בפרויקט כמו הצגת משתמשים, השתמשו ב-MCP.',
  'SDK context for your AI agent: accurate methods, patterns, and best practices. For live project actions like listing users, use MCP.':
    'הקשר SDK לסוכן ה-AI שלכם: מתודות מדויקות, תבניות ושיטות עבודה מומלצות. לפעולות חיות בפרויקט כמו הצגת משתמשים, השתמשו ב-MCP.',
  'Available for TypeScript, Dart, Go, and more.':
    'זמין עבור TypeScript, Dart, Go ועוד.',
  'Run in project root. Pick SDKs, tools, scope, and method.':
    'הריצו בתיקיית השורש של הפרויקט. בחרו SDK, כלים, היקף ושיטת התקנה.',
  'Run in project root.': 'הריצו בתיקיית השורש של הפרויקט.',
  'Ask your agent to write Appwrite code:':
    'בקשו מהסוכן שלכם לכתוב קוד Appwrite:',
  'Implement email/password sign-in with the Appwrite SDK':
    'ממשו התחברות עם אימייל וסיסמה באמצעות Appwrite SDK',
  'Review my Appwrite auth implementation against best practices':
    'בדקו את מימוש האימות שלי ב-Appwrite מול שיטות עבודה מומלצות',
  'Add Realtime updates to my dashboard with the Appwrite SDK':
    'הוסיפו עדכוני Realtime ללוח הבקרה שלי עם Appwrite SDK',
  'Use a project-scoped HTTPS endpoint with SigV4-compatible signing to attach Storage to rclone, IaC, or custom pipelines. Copyable endpoint, access key, and secret will appear here when the integration is ready.':
    'השתמשו בנקודת קצה HTTPS ברמת הפרויקט עם חתימה תואמת SigV4 כדי לחבר את האחסון ל-rclone, ל-IaC או לצינורות מותאמים אישית. נקודת קצה, מפתח גישה וסוד הניתנים להעתקה יופיעו כאן כשהאינטגרציה תהיה מוכנה.',
  'Work in progress - nothing to copy yet.':
    'עבודה בתהליך, אין עדיין מה להעתיק.',
  'Create CLI deployment': 'יצירת פריסת CLI',
  "If it's your first time using the CLI, remember to":
    'אם זו הפעם הראשונה שלכם עם ה-CLI, זכרו',
  'install the CLI': 'להתקין את ה-CLI',
  and: 'וגם',
  'log in to your account': 'להתחבר לחשבון שלכם',
  'before running the deployment command.': 'לפני הרצת פקודת הפריסה.',
  'Create deployment': 'יצירת פריסה',
  Manual: 'ידני',
  'Deployment is in progress. It will be automatically activated after build step completes.':
    'הפריסה בתהליך. היא תופעל אוטומטית לאחר השלמת שלב הבנייה.',
  'Deployment is in progress. You can activate it after build step completes.':
    'הפריסה בתהליך. תוכלו להפעיל אותה לאחר השלמת שלב הבנייה.',
  'Failed to create deployment': 'יצירת הפריסה נכשלה',
  'Please select an installation and repository': 'בחרו התקנה ו-repo',
  'Please select a branch': 'בחרו ענף',
  'Create git deployment': 'יצירת פריסת Git',
  'Select a repository to deploy from. You can change it later in settings.':
    'בחרו repo לפריסה. אפשר לשנות אותו מאוחר יותר בהגדרות.',
  'Choose the production branch and whether to activate the deployment after the build completes.':
    'בחרו את ענף הייצור והאם להפעיל את הפריסה לאחר השלמת הבנייה.',
  Repository: 'Repo',
  'Last updated': 'עודכן לאחרונה',
  'Change repository': 'החלפת repo',
  'Production branch': 'Branch פרודקשן',
  'Select branch': 'בחירת Branch',
  'Activate deployment after build': 'הפעלת הפריסה לאחר הבנייה',
  'Deployment docs': 'דוקומנטציית פריסות',
  Back: 'חזרה',
  'Only .tar.gz files are allowed.': 'מותרים רק קובצי ‎.tar.gz.',
  'File size exceeds': 'גודל הקובץ חורג מ',
  'Deployment created successfully': 'הפריסה נוצרה בהצלחה',
  'Please select a .tar.gz file.': 'בחרו קובץ ‎.tar.gz.',
  'Create manual deployment': 'יצירת פריסה ידנית',
  'Upload a .tar.gz archive of your code. Maximum file size is':
    'העלו ארכיון ‎.tar.gz של הקוד שלכם. גודל הקובץ המקסימלי הוא',
  'Click to select a .tar.gz file': 'לחצו לבחירת קובץ ‎.tar.gz',
  'Uploading…': 'מעלה…',
  Redeploy: 'פריסה מחדש',
  Timeout: 'Timeout',
  Waiting: 'ממתין',
  'Control how long inactive deployments are kept before they are automatically deleted. Active deployments are always retained.':
    'קבעו כמה זמן פריסות לא פעילות נשמרות לפני מחיקתן האוטומטית. פריסות פעילות נשמרות תמיד.',
  'Keep deployments forever': 'שמירת פריסות לתמיד',
  'Retention period': 'תקופת שמירה',
  '1 Month': 'חודש',
  'Inactive deployments are deleted after': 'פריסות לא פעילות נמחקות לאחר',
  'Inactive deployments will not be automatically deleted.':
    'פריסות לא פעילות לא יימחקו אוטומטית.',
  '1 Week': 'שבוע',
  '3 Months': '3 חודשים',
  '6 Months': '6 חודשים',
  '1 Year': 'שנה',
  '2 Years': 'שנתיים',
  '5 Years': '5 שנים',
  '10 Years': '10 שנים',
  Target: 'יעד',
  'Active deployment': 'פריסה פעילה',
  'Serves the active deployment': 'מגיש את הפריסה הפעילה',
  'Connect repository first': 'חברו repo תחילה',
  Branch: 'Branch',
  'Serve a specific branch': 'הגשת ענף ספציפי',
  Redirect: 'הפניה מחדש',
  'Redirect to another URL': 'הפניה מחדש לכתובת אחרת',
  'Redirect URL': 'כתובת הפניה מחדש',
  'Status code': 'קוד סטטוס',
  '301/308 permanent, 302/307 temporary': '301/308 קבוע, 302/307 זמני',
  'Moved Permanently': 'הועבר לצמיתות',
  'Found (temporary)': 'נמצא (זמני)',
  'Temporary Redirect': 'הפניה מחדש זמנית',
  'Permanent Redirect': 'הפניה מחדש קבועה',
  'Your success team': 'צוות ההצלחה שלכם',
  Custom: 'מותאם אישית',
  'Dedicated support for your organization': 'תמיכה ייעודית לארגון שלכם',
  'Schedule a meeting': 'קביעת פגישה',
  'Your dedicated Slack channel is monitored during business hours (9am-6pm EST). For urgent issues, please use our':
    'ערוץ ה-Slack הייעודי שלכם מנוטר בשעות העבודה (9:00-18:00 EST). לנושאים דחופים, השתמשו ב',
  'priority support portal': 'פורטל התמיכה בעדיפות גבוהה',
  "Appwrite offers an MCP server that allows LLMs to interact with Appwrite's API and documentation. Install with a single click or view the": 'Appwrite מציעה שרת MCP שמאפשר למודלי שפה לתקשר עם ה-API והדוקומנטציה של Appwrite. התקינו בלחיצה אחת או עיינו ב', // pragma: allowlist secret
  'Run Appwrite MCP locally with uvx and a project API key. Replace YOUR_API_KEY, then see the':
    'הריצו את Appwrite MCP באופן מקומי עם uvx ומפתח API של הפרויקט. החליפו את YOUR_API_KEY, ואז עיינו ב',
  docs: 'דוקומנטציה',
  'for instructions.': 'להוראות.',
  'MCP server': 'שרת MCP',
  Install: 'התקנה',
  'MCP servers': 'שרתי MCP',
  '1. Install': '1. התקנה',
  'More tools in the docs': 'כלים נוספים בדוקס',
  '2. Try it': '2. נסו',
  'Continue to Try it': 'המשך ל-נסו',
  'Open your coding agent and ask one of these prompts to confirm Appwrite MCP is working.':
    'פתחו את סוכן הפיתוח שלכם ובקשו אחת מהבקשות האלה כדי לוודא ש-Appwrite MCP פועל.',
  'Use Appwrite MCP to list the databases in project {projectName}':
    'השתמשו ב-Appwrite MCP כדי להציג את מסדי הנתונים בפרויקט {projectName}',
  'Use Appwrite MCP to list the storage buckets in project {projectName}':
    'השתמשו ב-Appwrite MCP כדי להציג את באקטי האחסון בפרויקט {projectName}',
  'Use Appwrite MCP to list the users in project {projectName}':
    'השתמשו ב-Appwrite MCP כדי להציג את המשתמשים בפרויקט {projectName}',
  'I tried it': 'ניסיתי',
  'Marked as tried': 'סומן כנוסה',
  'Nice. Your agent is connected.': 'מעולה. הסוכן שלכם מחובר.',
  'Install Appwrite MCP': 'התקנת Appwrite MCP',
  'Build with an agent': 'בנו עם סוכן',
  'Connect your coding agent': 'חיבור סוכן הפיתוח שלכם',
  'Install Appwrite MCP in Cursor, Claude Code, Codex, or VS Code so your agent can manage this project.':
    'התקינו את Appwrite MCP ב-Cursor, Claude Code, Codex או VS Code כדי שהסוכן שלכם יוכל לנהל את הפרויקט.',
  'Install MCP': 'התקנת MCP',
  'Open MCP': 'פתיחת MCP',
  'Register where your app runs, add API credentials, and connect a coding agent with MCP.':
    'רשמו היכן האפליקציה פועלת, הוסיפו פרטי גישה ל-API וחברו סוכן פיתוח עם MCP.',
  "You've reached the limit of": 'הגעתם למגבלה של',
  'Approaching the limit for': 'מתקרבים למגבלה של',
  'Your plan': 'התוכנית שלכם',
  'includes up to': 'כוללת עד',
  'to unlock more capacity.': 'כדי לפתוח קיבולת נוספת.',
  'Remaining:': 'נותרו:',
  buckets: 'באקטים',
  databases: 'מסדי נתונים',
  domains: 'דומיינים',
  functions: 'פונקציות',
  sites: 'אתרים',
  'firewall rules': 'כללי חומת אש',
  'Firewall rules': 'כללי חומת אש',
  'Select project': 'בחירת פרויקט',
  'Loading...': 'טוען...',
  'Find Organization...': 'חיפוש ארגון...',
  Downgraded: 'שנמוך',
  'Create Organization': 'יצירת ארגון',
  'Find Project...': 'חיפוש פרויקט...',
  'No projects found': 'לא נמצאו פרויקטים',
  'Select an organization': 'בחרו ארגון',
  'Create Project': 'יצירת פרויקט',
  "You've reached the limit for this resource on your plan":
    'הגעתם למגבלה של המשאב הזה בתוכנית שלכם',
  Import: 'ייבוא',
  'Expand header': 'הרחבת כותרת',
  'Collapse header': 'כיווץ כותרת',
  'List view': 'תצוגת רשימה',
  'Grid view': 'תצוגת רשת',
  Memory: 'זיכרון',
  Availability: 'זמינות',
  'Select specification': 'בחירת מפרט',
  Available: 'זמין',
  'Plan limit': 'מגבלת תוכנית',
  'Infrastructure as code': 'תשתית כקוד',
  'The official Appwrite Terraform provider lets you create and update project resources from':
    'ספק ה-Terraform הרשמי של Appwrite מאפשר לכם ליצור ולעדכן משאבי פרויקט מקובצי', // pragma: allowlist secret
  'files instead of clicking through the console - ideal for staging and production parity, code review, and automated pipelines.':
    'במקום ללחוץ בקונסולה, אידיאלי לשמירה על אחידות בין סביבת בדיקה לסביבת ייצור, לסקירת קוד ולצינורות אוטומטיים.',
  'Use it when you want repeatable environments, documented changes in Git, or to wire Appwrite into a broader Terraform stack (VPC, DNS, functions, and more) in one workflow. The registry documents resources such as':
    'השתמשו בו כשאתם רוצים סביבות שניתן לשחזר, שינויים מתועדים ב-Git, או לחבר את Appwrite לסטאק Terraform רחב יותר (VPC, DNS, פונקציות ועוד) בזרימת עבודה אחת. הרישום מתעד משאבים כגון', // pragma: allowlist secret
  'and others, with full schemas and imports.':
    'ואחרים, עם סכימות מלאות וייבוא.',
  'Declare Appwrite resources in .tf files and apply them with Terraform. Use the examples for provider setup, TablesDB, and Functions.':
    'הגדירו משאבי Appwrite בקובצי .tf והחילו אותם עם Terraform. השתמשו בדוגמאות להגדרת הספק, TablesDB ופונקציות.',
  'Manage Appwrite resources as code. Copy an example on the right, then run terraform init and apply.':
    'נהלו משאבי Appwrite כקוד. העתיקו דוגמה מימין, ואז הריצו terraform init ו-apply.',
  'Required for apply. Never commit secrets to Git.':
    'נדרש עבור apply. לעולם אל תבצעו commit לסודות ב-Git.',
  'Name after TF_VAR_ must match the variable (usually lowercase). .env files are not loaded.':
    'השם אחרי TF_VAR_ חייב להתאים למשתנה (בדרך כלל באותיות קטנות). קובצי .env אינם נטענים.',
  'Terraform loads terraform.tfvars next to your .tf files automatically. Gitignore *.tfvars.':
    'Terraform טוען את terraform.tfvars ליד קובצי ה-.tf אוטומטית. הוסיפו *.tfvars ל-.gitignore.',
  'Pass your API key with TF_VAR_appwrite_api_key or the terraform.tfvars example. Never commit secrets to Git.':
    'העבירו את מפתח ה-API עם TF_VAR_appwrite_api_key או עם דוגמת terraform.tfvars. לעולם אל תבצעו commit לסודות ב-Git.',
  'Terraform needs an API key with scopes for the resources you manage. Pass it with':
    'Terraform זקוק למפתח API עם הרשאות למשאבים שאתם מנהלים. העבירו אותו באמצעות',
  'Terraform needs an API key with scopes for the resources you manage. Never commit secrets to Git.':
    'Terraform זקוק למפתח API עם הרשאות למשאבים שאתם מנהלים. לעולם אל תבצעו commit לסודות ב-Git.',
  or: 'או',
  'never commit secrets to Git.': 'לעולם אל תבצעו commit לסודות ב-Git.',
  'Environment variable': 'משתנה סביבה',
  'Terraform maps TF_VAR_<name> to variable "<name>". The name after TF_VAR_ must match exactly (usually lowercase), so for var.appwrite_api_key run:':
    'Terraform ממפה את TF_VAR_<name> ל-variable "<name>". השם אחרי TF_VAR_ חייב להתאים בדיוק (בדרך כלל באותיות קטנות), לכן עבור var.appwrite_api_key הריצו:',
  'A .env file is not read by Terraform. Export the variable in your shell (or use direnv) before terraform apply.':
    'קובץ .env אינו נקרא על ידי Terraform. ייצאו את המשתנה בטרמינל (או השתמשו ב-direnv) לפני terraform apply.',
  'Add a terraform.tfvars file next to your .tf files. Terraform loads it automatically. See the terraform.tfvars example tab, and gitignore *.tfvars.':
    'הוסיפו קובץ terraform.tfvars ליד קובצי ה-.tf. Terraform טוען אותו אוטומטית. ראו את לשונית הדוגמה terraform.tfvars, והוסיפו *.tfvars ל-.gitignore.',
  'Place next to your .tf files. Terraform loads terraform.tfvars automatically. Add *.tfvars to .gitignore so the API key is never committed.':
    'שימו ליד קובצי ה-.tf. Terraform טוען את terraform.tfvars אוטומטית. הוסיפו *.tfvars ל-.gitignore כדי שמפתח ה-API לא יישמר ב-repo.',
  'TablesDB example: database, table, columns, and index. Add alongside your provider configuration.':
    'דוגמת TablesDB: מסד נתונים, טבלה, עמודות ואינדקס. הוסיפו לצד תצורת הספק שלכם.',
  'TablesDB example: database, table, column, and index. Add alongside your provider configuration.':
    'דוגמת TablesDB: מסד נתונים, טבלה, עמודה ואינדקס. הוסיפו לצד תצורת הספק שלכם.',
  'Storage example: buckets with size limits, extensions, and image transformations.':
    'דוגמת אחסון: באקטים עם מגבלות גודל, סיומות והמרות תמונה.',
  'Storage example: buckets and a file upload from a local path on the machine running Terraform.':
    'דוגמת אחסון: באקטים והעלאת קובץ מנתיב מקומי במחשב שמריץ Terraform.',
  'Functions example: a basic function, an event-driven function, and an environment variable.':
    'דוגמת פונקציות: פונקציה בסיסית, פונקציה מונעת אירועים ומשתנה סביבה.',
  'Auth example: a team and a user. Pass passwords through variables (see terraform.tfvars), not hard-coded strings.':
    'דוגמת אימות: צוות ומשתמש. העבירו סיסמאות דרך משתנים (ראו terraform.tfvars), לא כמחרוזות מקודדות.',
  'Example database, table, columns, and index. Add alongside your provider configuration.':
    'דוגמה למסד נתונים, טבלה, עמודות ואינדקס. הוסיפו לצד תצורת הספק שלכם.',
  'Provider docs on Terraform Registry':
    'דוקומנטציית הספק ב-Terraform Registry',
  "Provider uses this project's endpoint and project ID. Run":
    'הספק משתמש בנקודת הקצה ובמזהה של הפרויקט הזה. הריצו',
  then: 'ואז',
  'Shell exports matching APPWRITE_* provider options. When set, you can skip duplicate fields in provider {}. Use secrets in CI, not committed files.':
    'ייצוא משתני מעטפת התואמים לאפשרויות הספק APPWRITE_*. כשהם מוגדרים, אפשר לדלג על שדות כפולים ב-provider {}. השתמשו בסודות ב-CI, לא בקבצים שנשמרים ב-repo.', // pragma: allowlist secret
  'Example database, table, columns, and index. Add alongside your provider configuration.':
    'דוגמה למסד נתונים, טבלה, עמודות ואינדקס. הוסיפו לצד תצורת הספק שלכם.',
  'Install the Web SDK': 'התקנת ה-SDK לווב',
  'Install the Node.js SDK': 'התקנת ה-SDK ל-Node.js',
  'Install the Deno SDK': 'התקנת ה-SDK ל-Deno',
  'Install the Flutter SDK': 'התקנת ה-SDK ל-Flutter',
  'Install the Apple SDK': 'התקנת ה-SDK ל-Apple',
  'Install the Android SDK': 'התקנת ה-SDK ל-Android',
  'Install the React Native SDK': 'התקנת ה-SDK ל-React Native',
  'Install the Python SDK': 'התקנת ה-SDK ל-Python',
  'Install the Dart SDK': 'התקנת ה-SDK ל-Dart',
  'Install the PHP SDK': 'התקנת ה-SDK ל-PHP',
  'Install the Ruby SDK': 'התקנת ה-SDK ל-Ruby',
  'Install the .NET SDK': 'התקנת ה-SDK ל-‎.NET',
  'Install the Go SDK': 'התקנת ה-SDK ל-Go',
  'Install the Swift SDK': 'התקנת ה-SDK ל-Swift',
  'Install the Kotlin SDK': 'התקנת ה-SDK ל-Kotlin',
  "Deploy your function using the Appwrite CLI by running the following command inside your function's folder.":
    'פרסו את הפונקציה שלכם באמצעות ה-CLI של Appwrite על ידי הרצת הפקודה הבאה בתוך תיקיית הפונקציה.', // pragma: allowlist secret
  "Deploy your site using the Appwrite CLI by running the following command inside your site's folder.":
    'פרסו את האתר שלכם באמצעות ה-CLI של Appwrite על ידי הרצת הפקודה הבאה בתוך תיקיית האתר.', // pragma: allowlist secret
  'Historic data is not available through the new usage API.':
    'נתונים היסטוריים אינם זמינים דרך ה-API החדש של נתוני שימוש.',
  'Common filename for provider {} blocks. Example: custom endpoint and self_signed when Appwrite is not at cloud.appwrite.io. Secrets stay in tfvars, env, or CI - not in .tf files. One required_providers block per root module (see main.tf).':
    'שם קובץ נפוץ לבלוקים של provider {}. דוגמה: נקודת קצה מותאמת ו-self_signed כאשר Appwrite אינו ב-cloud.appwrite.io. סודות נשארים ב-tfvars, במשתני סביבה או ב-CI, לא בקובצי ‎.tf. בלוק required_providers אחד לכל מודול שורש (ראו main.tf).', // pragma: allowlist secret
  'About GBH': 'אודות GBH',
  Bandwidth: 'רוחב פס',
  'Bandwidth over time': 'רוחב פס לאורך זמן',
  Buckets: 'באקטים',
  'Chart interval': 'מרווח תרשים',
  '15m': '15 דק׳',
  '1h': '1 שע׳',
  '1d': '1 יום',
  'Use a date range of': 'השתמשו בטווח תאריכים של',
  'hours or less for this interval.': 'שעות או פחות למרווח הזה.',
  'days or less for this interval.': 'ימים או פחות למרווח הזה.',
  Compute: 'מחשוב',
  'Compute over time': 'חישוב לאורך זמן',
  'Copy API endpoint': 'העתקת נקודת קצה של API',
  'Copy project ID': 'העתקת מזהה פרויקט',
  "Couldn't load bandwidth": 'לא ניתן היה לטעון נתוני רוחב פס',
  "Couldn't load compute": 'לא ניתן היה לטעון נתוני חישוב',
  "Couldn't load executions": 'לא ניתן היה לטעון הרצות',
  "Couldn't load requests": 'לא ניתן היה לטעון בקשות',
  "Couldn't load storage": 'לא ניתן היה לטעון נתוני אחסון',
  Deployments: 'פריסות',
  Executions: 'הרצות',
  'Executions over time': 'הרצות לאורך זמן',
  Files: 'קבצים',
  'GB hours (GBH). Compute time based on memory allocated to functions and sites multiplied by execution duration.':
    'שעות GB (GBH). זמן חישוב המבוסס על הזיכרון שהוקצה לפונקציות ולאתרים כפול משך ההרצה.',
  Inbound: 'נכנס',
  'Loading usage data': 'טוען נתוני שימוש',
  Outbound: 'יוצא',
  'Requests over time': 'בקשות לאורך זמן',
  'Storage breakdown type': 'סוג פילוח אחסון',
  'Storage over time': 'אחסון לאורך זמן',
  'Top bandwidth consumers': 'צרכני רוחב הפס המובילים',
  'Top compute consumers': 'צרכני החישוב המובילים',
  'Top consumers': 'הצרכנים המובילים',
  'Top requested endpoints': 'נקודות הקצה המבוקשות ביותר',
  'Top requests': 'הבקשות המובילות',
  'Try again': 'נסו שוב',
  'View all usage': 'הצגת כל השימוש',
  "We couldn't fetch usage data from the server. Check your connection and try again.":
    'לא הצלחנו לאחזר נתוני שימוש מהשרת. בדקו את החיבור ונסו שוב.',
  'vs last week': 'לעומת שבוע שעבר',
  // Usage section
  Reads: 'קריאות',
  'Read and write operations': 'פעולות קריאה וכתיבה',
  Writes: 'כתיבות',
  'API Requests': 'בקשות API',
  'Approaching limit': 'מתקרב למגבלה',
  'Export as CSV': 'ייצוא כ-CSV',
  'Export as JSON': 'ייצוא כ-JSON',
  'Exported as CSV': 'הייצוא ל-CSV הושלם',
  'Exported as JSON': 'הייצוא ל-JSON הושלם',
  'Failed to load usage data': 'טעינת נתוני השימוש נכשלה',
  'High usage': 'שימוש גבוה',
  'Loading breakdown': 'טוען פילוח',
  'No usage data available': 'אין נתוני שימוש זמינים',
  Retry: 'ניסיון חוזר',
  'Select category': 'בחירת קטגוריה',
  'Showing up to': 'מוצגים עד',
  Unlimited: 'ללא הגבלה',
  Usage: 'שימוש',
  'Usage Categories': 'קטגוריות שימוש',
  'Usage categories': 'קטגוריות שימוש',
  'Usage metrics will appear here once your project starts receiving traffic. Deploy your first function or create some data to get started.':
    'מדדי שימוש יופיעו כאן ברגע שהפרויקט יתחיל לקבל תעבורה. פרסו את הפונקציה הראשונה שלכם או צרו נתונים כדי להתחיל.',
  "We couldn't retrieve your usage metrics. This might be a temporary issue. Please try again.":
    'לא הצלחנו לאחזר את מדדי השימוש שלכם. ייתכן שזו בעיה זמנית. נסו שוב.',
  inbound: 'נכנס',
  items: 'פריטים',
  outbound: 'יוצא',
  requests: 'בקשות',
  used: 'בשימוש',
  'vs previous period': 'לעומת התקופה הקודמת',
  'Monthly active users': 'משתמשים פעילים חודשיים',
  'OTP attempts': 'ניסיונות OTP',
  'Sign-ups': 'הרשמות',
  'Screenshots generated': 'צילומי מסך שנוצרו',
  'Database reads': 'קריאות ממסד הנתונים',
  'Database writes': 'כתיבות למסד הנתונים',
  Collections: 'אוספים',
  'Total documents': 'סך הכול מסמכים',
  'Deployment storage': 'אחסון פריסות',
  'Build storage': 'אחסון בניות',
  'Image transformations': 'טרנספורמציות תמונה',
  'Concurrent connections': 'חיבורים בו-זמניים',
  'Messages sent': 'הודעות שנשלחו', // pragma: allowlist secret
  'Realtime bandwidth': 'רוחב פס של Realtime',
  Topics: 'נושאים',
  'SMS messages': 'הודעות SMS', // pragma: allowlist secret
  'Events sent': 'אירועים שנשלחו',
  'Events failed': 'אירועים שנכשלו',
  Webhooks: 'Webhooks',
  'Function executions': 'הרצות פונקציות',
  'Site executions': 'הרצות אתרים',
  'Function GB-hours': 'GB-hours של פונקציות',
  'Site GB-hours': 'GB-hours של אתרים',
  'GB-hours': 'GB-hours',
  users: 'משתמשים',
  attempts: 'ניסיונות',
  screenshots: 'צילומי מסך',
  reads: 'קריאות',
  writes: 'כתיבות',
  collections: 'אוספים',
  documents: 'מסמכים',
  bytes: 'בייטים',
  'origin images': 'תמונות מקור',
  topics: 'נושאים',
  events: 'אירועים',
  webhooks: 'webhooks',
  executions: 'הרצות',
  GBH: 'GBH',
  GB: 'GB',
  operations: 'פעולות',
  "Couldn't load auth usage": 'לא ניתן היה לטעון נתוני שימוש של אימות',
  "Couldn't load avatars usage": 'לא ניתן היה לטעון נתוני שימוש של אווטארים',
  "Couldn't load compute usage": 'לא ניתן היה לטעון נתוני שימוש של חישוב',
  "Couldn't load database usage":
    'לא ניתן היה לטעון נתוני שימוש של מסד הנתונים',
  "Couldn't load messaging usage": 'לא ניתן היה לטעון נתוני שימוש של הודעות',
  "Couldn't load realtime usage": 'לא ניתן היה לטעון נתוני שימוש של Realtime',
  "Couldn't load storage usage": 'לא ניתן היה לטעון נתוני שימוש של אחסון',
  "Couldn't load webhooks usage": 'לא ניתן היה לטעון נתוני שימוש של webhooks',
  "Couldn't load agent usage": 'לא ניתן היה לטעון נתוני שימוש של Agent',
  Conversations: 'שיחות',
  'Tool calls': 'קריאות לכלים',
  Memories: 'זיכרונות',
  Tokens: 'טוקנים',
  'Input tokens': 'טוקני קלט',
  'Output tokens': 'טוקני פלט',
  conversations: 'שיחות',
  automations: 'אוטומציות',
  tokens: 'טוקנים',
  'Agent runs finished during the selected period. Each completed, failed, or stopped LLM turn counts as one run.':
    'הרצות Agent שהסתיימו במהלך התקופה שנבחרה. כל סיבוב LLM שהושלם, נכשל או נעצר נספר כהרצה אחת.',
  'User messages created during the selected period. Each message you send to the agent counts as one.':
    'הודעות משתמש שנוצרו במהלך התקופה שנבחרה. כל הודעה שאתם שולחים לסוכן נספרת כאחת.',
  'Conversations created during the selected period. Each new agent conversation counts as one.':
    'שיחות שנוצרו במהלך התקופה שנבחרה. כל שיחת Agent חדשה נספרת כאחת.',
  'Tool calls finished during the selected period. Each completed tool invocation by the agent counts as one.':
    'קריאות לכלים שהסתיימו במהלך התקופה שנבחרה. כל הפעלת כלי שהסוכן השלים נספרת כאחת.',
  'Memories written during the selected period. Each memory the agent stores counts as one.':
    'זיכרונות שנכתבו במהלך התקופה שנבחרה. כל זיכרון שהסוכן שומר נספר כאחד.',
  'Automation runs started during the selected period. Each automation execution counts as one.':
    'הרצות אוטומציה שהתחילו במהלך התקופה שנבחרה. כל הרצת אוטומציה נספרת כאחת.',
  'Total tokens consumed by the agent during the selected period, including both input and output tokens.':
    'סך הטוקנים שנצרכו על ידי הסוכן במהלך התקופה שנבחרה, כולל טוקני קלט ופלט.',
  'Input tokens consumed by the agent during the selected period.':
    'טוקני קלט שנצרכו על ידי הסוכן במהלך התקופה שנבחרה.',
  'Output tokens generated by the agent during the selected period.':
    'טוקני פלט שנוצרו על ידי הסוכן במהלך התקופה שנבחרה.',
  'Agent runs, messages, conversations, tool calls, memories, automations, and token usage.':
    'הרצות Agent, הודעות, שיחות, קריאות לכלים, זיכרונות, אוטומציות ושימוש בטוקנים.',
  'Your personal Agent activity: runs, messages, conversations, tool calls, memories, automations, and tokens.':
    'פעילות ה-Agent האישית שלכם: הרצות, הודעות, שיחות, קריאות לכלים, זיכרונות, אוטומציות וטוקנים.',
  'Your personal Agent activity: tokens, runs, messages, conversations, tool calls, and automations.':
    'פעילות ה-Agent האישית שלכם: טוקנים, הרצות, הודעות, שיחות, קריאות לכלים ואוטומציות.',
  'Your personal Agent activity: tokens, messages, conversations, tool calls, and automations.':
    'פעילות ה-Agent האישית שלכם: טוקנים, הודעות, שיחות, קריאות לכלים ואוטומציות.',
  'Input and output tokens consumed by the agent during the selected period.':
    'טוקני קלט ופלט שנצרכו על ידי הסוכן במהלך התקופה שנבחרה.',
  'Messages, conversations, and tool calls during the selected period.':
    'הודעות, שיחות וקריאות לכלים במהלך התקופה שנבחרה.',
  'Input and output tokens': 'טוקני קלט ופלט',
  'Rolling monthly active user count over time. Each point is the MAU snapshot at that moment, not new users in that interval. MAU beyond your plan limit may incur additional charges.':
    'ספירת משתמשים פעילים חודשיים מתגלגלת לאורך זמן. כל נקודה היא תמונת מצב של MAU באותו רגע, לא משתמשים חדשים באותו מרווח. MAU מעבר למגבלת התוכנית עשוי לגרור חיובים נוספים.',
  'Phone OTP verification attempts during the selected period. Each SMS or voice OTP sent counts toward your plan limit.':
    'ניסיונות אימות OTP בטלפון במהלך התקופה שנבחרה. כל OTP שנשלח ב-SMS או בשיחה קולית נספר במגבלת התוכנית.',
  'New user registrations during the selected period. Net growth in total registered users (account deletions reduce this count).':
    'הרשמות משתמשים חדשים במהלך התקופה שנבחרה. גידול נטו בסך המשתמשים הרשומים (מחיקות חשבון מפחיתות ספירה זו).',
  'Webpage screenshots generated through the Avatars Screenshots API during the selected period. Each successful screenshot request counts toward your plan limit.':
    'צילומי מסך של דפי אינטרנט שנוצרו דרך ה-Avatars Screenshots API במהלך התקופה שנבחרה. כל בקשת צילום מסך מוצלחת נספרת במגבלת התוכנית.',
  'Document read operations across all databases. Each row returned counts as one read.':
    'פעולות קריאת מסמכים בכל מסדי הנתונים. כל שורה שמוחזרת נספרת כקריאה אחת.',
  'Create, update, and delete operations across all databases. Each mutation counts as one write.':
    'פעולות יצירה, עדכון ומחיקה בכל מסדי הנתונים. כל שינוי נספר ככתיבה אחת.',
  'Total collections (tables) across all databases in your project.':
    'סך האוספים (טבלאות) בכל מסדי הנתונים בפרויקט.',
  'Total rows stored across all collections in your project.':
    'סך השורות המאוחסנות בכל האוספים בפרויקט.',
  'Total bytes stored across all buckets, including uploaded files and versions. Counts toward your plan storage limit.':
    'סך הבייטים המאוחסנים בכל הבאקטים, כולל קבצים שהועלו וגרסאות. נספר במגבלת האחסון של התוכנית.',
  'Storage used by active function and site deployment artifacts. Deployment files count toward your plan storage limit.':
    'אחסון בשימוש על ידי פריסות פעילות של פונקציות ואתרים. קובצי פריסה נספרים במגבלת האחסון של התוכנית.',
  'Storage used by function and site build artifacts and caches. Build files count toward your plan storage limit.':
    'אחסון בשימוש על ידי תוצרי בנייה ומטמונים של פונקציות ואתרים. קובצי בנייה נספרים במגבלת האחסון של התוכנית.',
  'Unique origin images transformed during the selected period. Each origin image is billed once, regardless of how many variants you generate from it.':
    'תמונות מקור ייחודיות שעברו טרנספורמציה במהלך התקופה שנבחרה. כל תמונת מקור מחויבת פעם אחת, ללא קשר למספר הווריאציות שנוצרות ממנה.',
  'Peak concurrent WebSocket connections during the selected period. Each open client connection counts toward your plan limit.':
    'שיא חיבורי WebSocket מקבילים במהלך התקופה שנבחרה. כל חיבור לקוח פתוח נספר במגבלת התוכנית.',
  'Messages sent through the Realtime service during the selected period. Includes server events delivered to subscribed clients.':
    'הודעות שנשלחו דרך שירות ה-Realtime במהלך התקופה שנבחרה. כולל אירועי שרת שנמסרו ללקוחות רשומים.', // pragma: allowlist secret
  'Inbound and outbound data transferred through Realtime WebSocket connections during the selected period.':
    'נתונים נכנסים ויוצאים שהועברו דרך חיבורי WebSocket של Realtime במהלך התקופה שנבחרה.',
  'Messages sent across all channels (push, email, SMS) during the selected period. Each delivery to an end-user target counts as one message.':
    'הודעות שנשלחו בכל הערוצים (push, אימייל, SMS) במהלך התקופה שנבחרה. כל מסירה ליעד משתמש קצה נספרת כהודעה אחת.', // pragma: allowlist secret
  'Messaging topics in your project. Topics group subscribers for broadcast and targeted notifications.':
    'נושאי הודעות בפרויקט. נושאים מקבצים מנויים לצורך שידור והתראות ממוקדות.',
  'SMS messages sent during the selected period. Each SMS segment delivered to a phone target counts as one message.':
    'הודעות SMS שנשלחו במהלך התקופה שנבחרה. כל מקטע SMS שנמסר ליעד טלפון נספר כהודעה אחת.', // pragma: allowlist secret
  'Webhook events successfully delivered during the selected period. Each HTTP request sent to your endpoint counts as one event.':
    'אירועי webhook שנמסרו בהצלחה במהלך התקופה שנבחרה. כל בקשת HTTP שנשלחה לנקודת הקצה שלכם נספרת כאירוע אחד.',
  'Webhook delivery failures during the selected period. Failed attempts include non-2xx responses and connection errors.':
    'כשלי מסירת webhook במהלך התקופה שנבחרה. ניסיונות שנכשלו כוללים תגובות שאינן 2xx ושגיאות חיבור.',
  'Webhooks configured in your project. Each webhook subscribes to one or more Appwrite events.':
    'ה-webhooks שהוגדרו בפרויקט. כל webhook רשום לאירוע אחד או יותר של Appwrite.', // pragma: allowlist secret
  'Function and site executions during the selected period. Each HTTP trigger, schedule run, event invocation, or site request counts as one execution.':
    'הרצות פונקציות ואתרים במהלך התקופה שנבחרה. כל טריגר HTTP, ריצה מתוזמנת, הפעלת אירוע או בקשת אתר נספרים כהרצה אחת.',
  'Compute time during the selected period, measured in gigabyte-hours (GBH). Memory allocated to functions and sites multiplied by execution, request handling, and build duration.':
    "זמן חישוב במהלך התקופה שנבחרה, נמדד בג'יגה-בייט-שעות (GBH). זיכרון שהוקצה לפונקציות ולאתרים כפול משך ההרצה, הטיפול בבקשות והבנייה.",
  'Function executions during the selected period. Each HTTP trigger, schedule run, or event invocation counts as one execution.':
    'הרצות פונקציות במהלך התקופה שנבחרה. כל טריגר HTTP, ריצה מתוזמנת או הפעלת אירוע נספרים כהרצה אחת.',
  'Site executions during the selected period. Each HTTP request served by your site counts toward execution usage.':
    'הרצות אתרים במהלך התקופה שנבחרה. כל בקשת HTTP שהאתר שלכם משרת נספרת בשימוש בהרצות.',
  'Function compute time during the selected period, measured in gigabyte-hours (GBH). Memory allocated to functions multiplied by execution and build duration.':
    "זמן חישוב של פונקציות במהלך התקופה שנבחרה, נמדד בג'יגה-בייט-שעות (GBH). זיכרון שהוקצה לפונקציות כפול משך ההרצה והבנייה.",
  'Site compute time during the selected period, measured in gigabyte-hours (GBH). Memory allocated to sites multiplied by request handling and build duration.':
    "זמן חישוב של אתרים במהלך התקופה שנבחרה, נמדד בג'יגה-בייט-שעות (GBH). זיכרון שהוקצה לאתרים כפול משך הטיפול בבקשות והבנייה.",
  'Top storage buckets': 'באקטי האחסון המובילים',
  'Top buckets by origin images': 'באקטים מובילים לפי תמונות מקור',
  'Top executed functions': 'הפונקציות המורצות ביותר',
  'Top executed sites': 'האתרים המורצים ביותר',
  'Top function compute consumers': 'צרכני חישוב הפונקציות המובילים',
  'Top site compute consumers': 'צרכני חישוב האתרים המובילים',
  'Total inbound and outbound network traffic during the selected period. Includes API responses, file transfers, and function I/O.':
    'סך תעבורת הרשת הנכנסת והיוצאת במהלך התקופה שנבחרה. כולל תגובות API, העברות קבצים וקלט/פלט של פונקציות.',
  'Total API requests during the selected period. Each call to your project endpoint counts as one request.':
    'סך בקשות ה-API במהלך התקופה שנבחרה. כל קריאה לנקודת הקצה של הפרויקט נספרת כבקשה אחת.',
  Paths: 'נתיבים',
  'HTTP methods': 'מתודות HTTP',
  'Status codes': 'קודי סטטוס',
  Services: 'שירותים',
  Hostnames: 'שמות מארח',
  'IP addresses': 'כתובות IP',
  'Operating systems': 'מערכות הפעלה',
  'Client types': 'סוגי לקוח',
  Clients: 'לקוחות',
  Resources: 'משאבים',
  'Resource types': 'סוגי משאבים',
  'Resource ID': 'מזהה משאב',
  'Resource breakdown dimension': 'ממד פילוח משאבים',
  'Bandwidth (MB)': 'רוחב פס (MB)',
  'Messages/min': 'הודעות לדקה', // pragma: allowlist secret
  'API endpoint paths with the highest request volume.':
    'נתיבי נקודות קצה של API עם נפח הבקשות הגבוה ביותר.',
  'Request volume grouped by HTTP method.': 'נפח בקשות מקובץ לפי מתודת HTTP.',
  'Request volume grouped by HTTP response status.':
    'נפח בקשות מקובץ לפי סטטוס תגובת HTTP.',
  'Request volume grouped by Appwrite service segment.':
    'נפח בקשות מקובץ לפי מקטע שירות של Appwrite.', // pragma: allowlist secret
  'Request volume grouped by caller country.':
    'נפח בקשות מקובץ לפי מדינת המקור.',
  'Request volume grouped by caller city.':
    'נפח בקשות מקובץ לפי עיר המקור.',
  'Request volume grouped by caller hostname.':
    'נפח בקשות מקובץ לפי שם המארח של המקור.',
  'Request volume grouped by caller IP address.':
    'נפח בקשות מקובץ לפי כתובת IP של המקור.',
  'Request volume grouped by client operating system.':
    'נפח בקשות מקובץ לפי מערכת ההפעלה של הלקוח.',
  'Request volume grouped by client type.': 'נפח בקשות מקובץ לפי סוג הלקוח.',
  'Request volume grouped by client name.': 'נפח בקשות מקובץ לפי שם הלקוח.',
  'Request volume grouped by device classification.':
    'נפח בקשות מקובץ לפי סיווג המכשיר.',
  'Request volume grouped by SDK and version.':
    'נפח בקשות מקובץ לפי SDK וגרסה.',
  'Request volume grouped by resource ID.': 'נפח בקשות מקובץ לפי מזהה משאב.',
  'Request volume grouped by resource type.': 'נפח בקשות מקובץ לפי סוג משאב.',
  'Endpoint paths with the highest bandwidth consumption.':
    'נתיבי נקודות קצה עם צריכת רוחב הפס הגבוהה ביותר.',
  'Bandwidth grouped by HTTP method.': 'רוחב פס מקובץ לפי מתודת HTTP.',
  'Bandwidth grouped by HTTP response status.':
    'רוחב פס מקובץ לפי סטטוס תגובת HTTP.',
  'Bandwidth grouped by Appwrite service segment.':
    'רוחב פס מקובץ לפי מקטע שירות של Appwrite.', // pragma: allowlist secret
  'Bandwidth grouped by caller country.': 'רוחב פס מקובץ לפי מדינת המקור.',
  'Bandwidth grouped by caller city.': 'רוחב פס מקובץ לפי עיר המקור.',
  'Bandwidth grouped by caller hostname.':
    'רוחב פס מקובץ לפי שם המארח של המקור.',
  'Bandwidth grouped by caller IP address.':
    'רוחב פס מקובץ לפי כתובת IP של המקור.',
  'Bandwidth grouped by client operating system.':
    'רוחב פס מקובץ לפי מערכת ההפעלה של הלקוח.',
  'Bandwidth grouped by client type.': 'רוחב פס מקובץ לפי סוג הלקוח.',
  'Bandwidth grouped by client name.': 'רוחב פס מקובץ לפי שם הלקוח.',
  'Bandwidth grouped by device classification.':
    'רוחב פס מקובץ לפי סיווג המכשיר.',
  'Bandwidth grouped by SDK and version.': 'רוחב פס מקובץ לפי SDK וגרסה.',
  'Bandwidth grouped by resource ID.': 'רוחב פס מקובץ לפי מזהה משאב.',
  'Bandwidth grouped by resource type.': 'רוחב פס מקובץ לפי סוג משאב.',
  SDK: 'SDK',
  'SDK version': 'גרסת SDK',
  'Caller city': 'עיר מקור',
  'API paths': 'נתיבי API',
  'Operations grouped by database.': 'פעולות מקובצות לפי מסד נתונים.',
  'Operations grouped by resource.': 'פעולות מקובצות לפי משאב.',
  'Operations grouped by caller IP address.':
    'פעולות מקובצות לפי כתובת IP של המקור.',
  'Operations grouped by Appwrite database API (TablesDB, DocumentsDB, VectorsDB, legacy).':
    'פעולות מקובצות לפי Appwrite database API (TablesDB, DocumentsDB, VectorsDB, legacy).', // pragma: allowlist secret
  'API endpoint paths driving database operations.':
    'נתיבי נקודות קצה של API שמניעים פעולות מסד נתונים.',
  'API endpoint paths with the highest operation volume.':
    'נתיבי נקודות קצה של API עם נפח הפעולות הגבוה ביותר.',
  'Operations grouped by HTTP method.': 'פעולות מקובצות לפי מתודת HTTP.',
  'Reads breakdown': 'פירוט קריאות',
  'Writes breakdown': 'פירוט כתיבות',
  'Operations grouped by Appwrite database API (TablesDB, DocumentsDB, VectorsDB).':
    'פעולות מקובצות לפי Appwrite database API (TablesDB, DocumentsDB, VectorsDB).', // pragma: allowlist secret
  'Operations grouped by caller country.': 'פעולות מקובצות לפי מדינת המקור.',
  'Operations grouped by database or table.':
    'פעולות מקובצות לפי מסד נתונים או טבלה.',
  'Top databases and tables by operation volume. Click a row to filter the charts.':
    'מסדי הנתונים והטבלאות עם נפח הפעולות הגבוה ביותר. לחצו על שורה כדי לסנן את התרשימים.',
  'Top databases by operation volume. Click a row to filter the charts.':
    'מסדי הנתונים עם נפח הפעולות הגבוה ביותר. לחצו על שורה כדי לסנן את התרשימים.',
  'Top tables by operation volume. Click a row to filter the charts.':
    'הטבלאות עם נפח הפעולות הגבוה ביותר. לחצו על שורה כדי לסנן את התרשימים.',
  'Share of operations on databases versus tables (collections).':
    'חלק הפעולות על מסדי נתונים לעומת טבלאות (אוספים).',
  'Operations by HTTP method. For writes, this separates creates, updates, and deletes.':
    'פעולות לפי מתודת HTTP. בכתיבות, זה מפריד בין יצירות, עדכונים ומחיקות.',
  'API endpoint paths driving the most database operations.':
    'נתיבי נקודות קצה של API שמניעים הכי הרבה פעולות מסד נתונים.',
  'Operations by Appwrite database API (TablesDB, DocumentsDB, VectorsDB, legacy).':
    'פעולות לפי Appwrite database API (TablesDB, DocumentsDB, VectorsDB, legacy).', // pragma: allowlist secret
  'Caller IP addresses with the highest operation volume.':
    'כתובות IP של מקור עם נפח הפעולות הגבוה ביותר.',
  Products: 'מוצרים',
  Avatars: 'אווטארים',
  avatars: 'אווטארים',
  'API request volume and breakdowns across paths, methods, status codes, and client attributes.':
    'נפח בקשות API ופילוחים לפי נתיבים, מתודות, קודי סטטוס ומאפייני לקוח.',
  'Network bandwidth consumption with breakdowns across paths, services, and client attributes.':
    'צריכת רוחב פס ברשת עם פילוחים לפי נתיבים, שירותים ומאפייני לקוח.',
  'Database operations including reads, writes, and resource counts.':
    'פעולות מסד נתונים כולל קריאות, כתיבות וספירת משאבים.',
  'WebSocket connections, messages sent, and bandwidth for live data synchronization.':
    'חיבורי WebSocket, הודעות שנשלחו ורוחב פס לסנכרון נתונים בזמן אמת.', // pragma: allowlist secret
  'Authentication metrics including monthly active users, phone OTP usage, and sign-up activity.':
    'מדדי אימות כולל משתמשים פעילים חודשיים, שימוש ב-OTP בטלפון ופעילות הרשמה.',
  'Combined function and site executions plus compute time across your project.':
    'הרצות משולבות של פונקציות ואתרים וכן זמן חישוב בכל הפרויקט.',
  'Function executions and compute time during the selected period.':
    'הרצות פונקציות וזמן חישוב במהלך התקופה שנבחרה.',
  'Site executions and compute time during the selected period.':
    'הרצות אתרים וזמן חישוב במהלך התקופה שנבחרה.',
  'Avatars API usage for webpage screenshots and other generated assets.':
    'שימוש ב-Avatars API לצילומי מסך של דפי אינטרנט ונכסים מיוצרים נוספים.',
  'Push notifications, emails, and SMS messages sent through the messaging service.':
    'התראות push, אימיילים והודעות SMS שנשלחו דרך שירות ההודעות.', // pragma: allowlist secret
  'Webhook event deliveries and configured endpoints for Appwrite event notifications.':
    'מסירות אירועי webhook ונקודות קצה מוגדרות להתראות על אירועי Appwrite.', // pragma: allowlist secret
  'File, deployment, and build storage usage, plus billable image transformations.':
    'שימוש באחסון קבצים, פריסות ובניות, וכן טרנספורמציות תמונה בחיוב.',
  'Function Executions': 'הרצות פונקציות',
  'GB-Hours': 'GB-Hours',
  'Monthly Active Users': 'משתמשים פעילים חודשיים',
  'OTP Attempts': 'ניסיונות OTP',
  'Database Reads': 'קריאות ממסד הנתונים',
  'Database Writes': 'כתיבות למסד הנתונים',
  'Total Documents': 'סך הכול מסמכים',
  'Storage Used': 'אחסון בשימוש',
  'File Operations': 'פעולות קבצים',
  'Bandwidth Egress': 'רוחב פס יוצא',
  'Bandwidth Ingress': 'רוחב פס נכנס',
  'Messages Sent': 'הודעות שנשלחו', // pragma: allowlist secret
  'SMS Messages': 'הודעות SMS', // pragma: allowlist secret
  'Screenshots Generated': 'צילומי מסך שנוצרו',
  'Events Sent': 'אירועים שנשלחו',
  'Events Failed': 'אירועים שנכשלו',
  'Function executions and compute resources consumed by your serverless functions.':
    'הרצות פונקציות ומשאבי חישוב שנצרכו על ידי הפונקציות ללא שרת שלכם.',
  'Total number of function invocations during this billing cycle. Each time a function is triggered (via HTTP, schedule, or event), it counts as one execution. Executions beyond your plan limit are billed at $0.50 per 1,000 executions.':
    'סך ההרצות במחזור החיוב הנוכחי. בכל פעם שפונקציה מופעלת (דרך HTTP, תזמון או אירוע), היא נספרת כהרצה אחת. הרצות מעבר למגבלת התוכנית מחויבות ב-$0.50 לכל 1,000 הרצות.',
  'Compute time measured in gigabyte-hours. This represents the memory allocated to your functions multiplied by execution duration. A function using 512MB for 2 hours consumes 1 GB-hour. Additional GB-hours are billed at $0.15 per GB-hour.':
    "זמן חישוב הנמדד בג'יגה-בייט-שעות. מייצג את הזיכרון שהוקצה לפונקציות כפול משך ההרצה. פונקציה המשתמשת ב-512MB במשך שעתיים צורכת 1 GB-hour. GB-hours נוספים מחויבים ב-$0.15 לכל GB-hour.",
  'Authentication metrics including active users, OTP usage, and sign-up activity.':
    'מדדי אימות כולל משתמשים פעילים, שימוש ב-OTP ופעילות הרשמה.',
  'Unique users who have authenticated at least once during the billing cycle. This includes all authentication methods (email, OAuth, phone, etc.). MAU beyond your plan limit are billed at $0.02 per user.':
    'משתמשים ייחודיים שהתחברו לפחות פעם אחת במהלך מחזור החיוב. כולל את כל שיטות האימות (אימייל, OAuth, טלפון ועוד). MAU מעבר למגבלת התוכנית מחויבים ב-$0.02 למשתמש.',
  'One-time password verification attempts via SMS or email. Each OTP sent counts toward this limit. Additional OTP messages are billed at $0.05 per message for email and $0.10 for SMS.':
    'ניסיונות אימות סיסמה חד-פעמית ב-SMS או באימייל. כל OTP שנשלח נספר במגבלה זו. הודעות OTP נוספות מחויבות ב-$0.05 להודעת אימייל וב-$0.10 ל-SMS.', // pragma: allowlist secret
  'New user registrations during this billing cycle. This metric helps you track user growth and onboarding patterns. Sign-ups are not directly limited but contribute to your MAU count.':
    'הרשמות משתמשים חדשים במחזור החיוב הנוכחי. מדד זה עוזר לעקוב אחר צמיחת משתמשים ודפוסי קליטה. הרשמות אינן מוגבלות ישירות אך תורמות לספירת ה-MAU.',
  'Total document read operations across all databases. Each query that retrieves documents counts as reads (one per document returned). Reads beyond your plan limit are billed at $0.30 per 1,000,000 reads.':
    'סך פעולות קריאת מסמכים בכל מסדי הנתונים. כל שאילתה שמאחזרת מסמכים נספרת כקריאות (אחת לכל מסמך שהוחזר). קריאות מעבר למגבלת התוכנית מחויבות ב-$0.30 לכל 1,000,000 קריאות.',
  'Total document write operations (create, update, delete) across all databases. Each mutation counts as one write. Writes beyond your plan limit are billed at $1.00 per 1,000,000 writes.':
    'סך פעולות כתיבת מסמכים (יצירה, עדכון, מחיקה) בכל מסדי הנתונים. כל שינוי נספר ככתיבה אחת. כתיבות מעבר למגבלת התוכנית מחויבות ב-$1.00 לכל 1,000,000 כתיבות.',
  'Total number of collections (tables) across all databases. Collections define your data schema and indexes. Additional collections beyond your plan limit require a plan upgrade.':
    'סך האוספים (טבלאות) בכל מסדי הנתונים. אוספים מגדירים את סכימת הנתונים והאינדקסים. אוספים נוספים מעבר למגבלת התוכנית דורשים שדרוג תוכנית.',
  'Total number of documents stored across all collections. This represents your data volume. Document storage is not directly limited but contributes to your storage usage.':
    'סך המסמכים המאוחסנים בכל האוספים. מייצג את נפח הנתונים שלכם. אחסון מסמכים אינו מוגבל ישירות אך תורם לשימוש באחסון.',
  'File storage usage and operations for your storage buckets.':
    'שימוש באחסון קבצים ופעולות עבור באקטי האחסון שלכם.',
  'Total bytes stored across all buckets. This includes all uploaded files and their versions. Storage beyond your plan limit is billed at $0.03 per GB per month.':
    'סך הבייטים המאוחסנים בכל הבאקטים. כולל את כל הקבצים שהועלו וגרסאותיהם. אחסון מעבר למגבלת התוכנית מחויב ב-$0.03 ל-GB לחודש.',
  'Total file operations including uploads, downloads, and deletions. Each API call to the storage service counts as one operation. Additional operations are billed at $0.10 per 10,000 operations.':
    'סך פעולות הקבצים כולל העלאות, הורדות ומחיקות. כל קריאת API לשירות האחסון נספרת כפעולה אחת. פעולות נוספות מחויבות ב-$0.10 לכל 10,000 פעולות.',
  'Network bandwidth consumption for API requests and file transfers.':
    'צריכת רוחב פס ברשת עבור בקשות API והעברות קבצים.',
  'Data transferred out from Appwrite to your users. This includes API responses, file downloads, and function outputs. Egress beyond your plan limit is billed at $0.09 per GB.':
    'נתונים שהועברו החוצה מ-Appwrite למשתמשים שלכם. כולל תגובות API, הורדות קבצים ופלט פונקציות. תעבורה יוצאת מעבר למגבלת התוכנית מחויבת ב-$0.09 ל-GB.', // pragma: allowlist secret
  'Data transferred into Appwrite from your users. This includes API requests, file uploads, and function inputs. Ingress is typically unlimited and not billed separately.':
    'נתונים שהועברו אל Appwrite מהמשתמשים שלכם. כולל בקשות API, העלאות קבצים וקלט פונקציות. תעבורה נכנסת בדרך כלל אינה מוגבלת ואינה מחויבת בנפרד.', // pragma: allowlist secret
  'WebSocket connections for real-time data synchronization.':
    'חיבורי WebSocket לסנכרון נתונים בזמן אמת.',
  'Peak number of simultaneous WebSocket connections. This represents users actively subscribed to real-time updates. Connections beyond your plan limit may be queued or rejected.':
    'שיא מספר חיבורי ה-WebSocket הבו-זמניים. מייצג משתמשים הרשומים באופן פעיל לעדכונים בזמן אמת. חיבורים מעבר למגבלת התוכנית עשויים להמתין בתור או להידחות.',
  'Total messages sent across all channels (push, email, SMS). Each notification or message counts toward this limit. Additional messages are billed based on the channel type.':
    'סך ההודעות שנשלחו בכל הערוצים (push, אימייל, SMS). כל התראה או הודעה נספרת במגבלה זו. הודעות נוספות מחויבות לפי סוג הערוץ.', // pragma: allowlist secret
  'Number of messaging topics for organizing subscribers. Topics allow you to group users for targeted notifications. Additional topics beyond your plan limit require a plan upgrade.':
    'מספר נושאי ההודעות לארגון מנויים. נושאים מאפשרים לקבץ משתמשים להתראות ממוקדות. נושאים נוספים מעבר למגבלת התוכנית דורשים שדרוג תוכנית.',
  'SMS messages sent for authentication or notifications. SMS is billed separately at carrier rates. Each SMS segment (160 characters) counts as one message.':
    'הודעות SMS שנשלחו לאימות או להתראות. SMS מחויב בנפרד לפי תעריפי המפעיל. כל מקטע SMS (160 תווים) נספר כהודעה אחת.', // pragma: allowlist secret
  'Webpage screenshots captured through the Avatars Screenshots API. Each successful request counts toward your monthly plan limit. Additional screenshots are billed per capture on paid plans.':
    'צילומי מסך של דפי אינטרנט שנלכדו דרך ה-Avatars Screenshots API. כל בקשה מוצלחת נספרת במגבלת התוכנית החודשית. צילומי מסך נוספים מחויבים לפי לכידה בתוכניות בתשלום.',
  'Webhook events successfully delivered to your endpoints. Each HTTP request sent counts as one event.':
    'אירועי webhook שנמסרו בהצלחה לנקודות הקצה שלכם. כל בקשת HTTP שנשלחה נספרת כאירוע אחד.',
  'Webhook delivery failures including non-2xx responses and connection errors.':
    'כשלי מסירת webhook כולל תגובות שאינן 2xx ושגיאות חיבור.',
  'Number of webhooks configured in your project. Each webhook can subscribe to multiple Appwrite events.':
    'מספר ה-webhooks שהוגדרו בפרויקט. כל webhook יכול להירשם למספר אירועי Appwrite.', // pragma: allowlist secret
  // Settings (domains, webhooks, migrations, SMTP, overview, git)
  '(Database name, Username, Password). Admin Secret is used for files.':
    '(שם מסד נתונים, שם משתמש, סיסמה). ה-Admin Secret משמש לקבצים.',
  '(Endpoint and API key). Use the': '(נקודת קצה ומפתח API). השתמשו במפתח',
  '(Host, Port, Username, Password) and': '(מארח, פורט, שם משתמש, סיסמה) וכן',
  '(Region, Subdomain, Admin Secret) and':
    '(אזור, תת-דומיין, Admin Secret) וכן',
  'A list of domain providers and their DNS settings is available':
    'רשימת ספקי דומיינים והגדרות ה-DNS שלהם זמינה',
  'API Endpoint': 'נקודת קצה של API',
  'API credentials': 'פרטי גישה ל-API',
  Access: 'גישה',
  Account: 'חשבון',
  'Actions for': 'פעולות עבור',
  'Add API domain': 'הוספת דומיין API',
  'Add a Git installation to your project so you can connect repositories later through your function or site settings.':
    'הוסיפו התקנת Git לפרויקט כדי שתוכלו לחבר repos בהמשך דרך הגדרות הפונקציה או האתר.',
  'Add a custom domain to serve your Appwrite API on your own domain':
    'הוסיפו דומיין מותאם אישית כדי להגיש את ה-API של Appwrite מהדומיין שלכם', // pragma: allowlist secret
  'Add an installation to connect repositories': 'הוסיפו התקנה כדי לחבר repos',
  'Add installation': 'הוספת התקנה',
  'Add the following nameservers on your DNS provider. Note that DNS changes may take up to 48 hours to propagate fully.':
    'הוסיפו את שרתי השמות הבאים אצל ספק ה-DNS שלכם. שימו לב ששינויי DNS עשויים להימשך עד 48 שעות עד שיופצו במלואם.',
  'Add the following record(s) to your DNS provider. Note that DNS changes may take up to 48 hours to propagate fully.':
    'הוסיפו את הרשומות הבאות אצל ספק ה-DNS שלכם. שימו לב ששינויי DNS עשויים להימשך עד 48 שעות עד שיופצו במלואם.',
  'Adding...': 'מוסיף...',
  'Admin secret': 'Admin Secret',
  'All project protocols will be enabled.': 'כל הפרוטוקולים של הפרויקט יופעלו.',
  'All protocols for': 'כל הפרוטוקולים עבור',
  'All services for': 'כל השירותים עבור',
  'Are you sure you want to delete this domain? This action cannot be undone.':
    'האם אתם בטוחים שברצונכם למחוק את הדומיין הזה? לא ניתן לבטל פעולה זו.',
  'Are you sure you want to disable all protocols? This will disable client access over those protocols until they are re-enabled.':
    'האם אתם בטוחים שברצונכם להשבית את כל הפרוטוקולים? הדבר יחסום גישת לקוח דרך פרוטוקולים אלה עד שיופעלו מחדש.',
  'Are you sure you want to disconnect this git installation?':
    'האם אתם בטוחים שברצונכם לנתק את התקנת ה-Git הזו?',
  'Are you sure you want to disconnect this git installation? This will affect future deployments to the following sites and functions:':
    'האם אתם בטוחים שברצונכם לנתק את התקנת ה-Git הזו? הדבר ישפיע על פריסות עתידיות לאתרים ולפונקציות הבאים:',
  Authentication: 'אימות',
  'Before installing Git in a locally hosted Appwrite project, ensure your environment variables are configured.':
    'לפני התקנת Git בפרויקט Appwrite באירוח מקומי, ודאו שמשתני הסביבה שלכם מוגדרים.', // pragma: allowlist secret
  'Certificate verification (SSL/TLS)': 'אימות תעודה (SSL/TLS)',
  'Choose services you wish to enable or disable for the client API. When disabled, the services are not accessible to client SDKs but remain accessible to server SDKs.':
    'בחרו אילו שירותים להפעיל או להשבית עבור ה-API של הלקוח. כאשר שירות מושבת, הוא אינו נגיש ל-SDK של לקוח אך נשאר נגיש ל-SDK של שרת.',
  'Choose which resources to migrate. You do not need to keep the Console open; the migration continues in the background. After migrating, add platforms in Overview → Integrations → Platforms and set permissions on migrated resources.':
    'בחרו אילו משאבים להעביר. אין צורך להשאיר את הקונסולה פתוחה; המיגרציה ממשיכה ברקע. לאחר המיגרציה, הוסיפו פלטפורמות בסקירה → אינטגרציות → פלטפורמות והגדירו הרשאות למשאבים שהועברו.',
  Complete: 'הושלם',
  Configure: 'הגדרה',
  'Configure a custom SMTP server to send emails from your own domain. This allows you to customize email templates and prevents emails from being labeled as spam.':
    'הגדירו שרת SMTP מותאם אישית לשליחת אימיילים מהדומיין שלכם. כך תוכלו להתאים אישית תבניות אימייל ולמנוע סימון אימיילים כספאם.',
  'Configure security settings for your webhook':
    'הגדירו את הגדרות האבטחה של ה-webhook שלכם',
  'Connect to GitHub': 'התחברות ל-GitHub',
  'Connect to GitLab': 'התחברות ל-GitLab',
  'Connect with GitHub': 'התחברות עם GitHub',
  'Connect with GitLab': 'התחברות עם GitLab',
  'Add GitHub account': 'הוספת חשבון GitHub',
  'Add GitLab account': 'הוספת חשבון GitLab',
  'Switch Git Provider': 'החלפת ספק Git',
  'Consider the following before transferring your project:':
    'שימו לב לנקודות הבאות לפני העברת הפרויקט:',
  'Copy domain': 'העתקת דומיין',
  'Copy secret': 'העתקת הסוד',
  'Create webhook': 'יצירת webhook',
  'Custom SMTP is available on Appwrite Cloud Pro and higher plans.':
    'SMTP מותאם אישית זמין בתוכניות Appwrite Cloud Pro ומעלה.', // pragma: allowlist secret
  'Custom SMTP server': 'שרת SMTP מותאם אישית',
  'Custom domains': 'דומיינים מותאמים אישית',
  'DNS Records': 'רשומות DNS',
  'Database (optional)': 'מסד נתונים (אופציונלי)',
  'Database host': 'מארח מסד הנתונים',
  'Database settings': 'הגדרות מסד נתונים',
  Date: 'תאריך',
  'Defaults to subdomain': 'ברירת המחדל היא תת-הדומיין',
  'Delete Project': 'מחיקת פרויקט',
  'Delete domain': 'מחיקת דומיין',
  'Delete project': 'מחיקת פרויקט',
  'Delete webhook': 'מחיקת webhook',
  'Depending on your role in the target organization, your level of access may change after transfer.':
    'בהתאם לתפקידכם בארגון היעד, רמת הגישה שלכם עשויה להשתנות לאחר ההעברה.',
  Destination: 'יעד',
  'Disable all': 'השבתת הכול',
  'Disable all protocols': 'השבתת כל הפרוטוקולים',
  'Disconnect installation': 'ניתוק התקנה',
  'Domain added successfully': 'הדומיין נוסף בהצלחה',
  'Domain is required': 'נדרש דומיין',
  'Domain verification failed. Please check your domain settings or try again later.':
    'אימות הדומיין נכשל. בדקו את הגדרות הדומיין או נסו שוב מאוחר יותר.',
  'Domain verified successfully': 'הדומיין אומת בהצלחה',
  'Duplicate rows': 'שורות כפולות',
  'Enable all': 'הפעלת הכול',
  'Enable all protocols': 'הפעלת כל הפרוטוקולים',
  'Enable custom SMTP server': 'הפעלת שרת SMTP מותאם אישית',
  'Enable custom SMTP to send a test email.':
    'הפעילו SMTP מותאם אישית כדי לשלוח אימייל בדיקה.',
  Endpoint: 'נקודת קצה',
  'Enter a webhook URL.': 'הזינו כתובת URL של webhook.',
  'Enter a webhook name.': 'הזינו שם ל-webhook.',
  'Enter password': 'הזינו סיסמה',
  'Enter project name': 'הזינו שם פרויקט',
  'Enter the domain name you want to use for your API endpoint.':
    'הזינו את שם הדומיין שבו תרצו להשתמש עבור נקודת הקצה של ה-API.',
  'Enter the webhook name and URL': 'הזינו את שם ה-webhook ואת כתובת ה-URL',
  'Enter username': 'הזינו שם משתמש',
  'Enter webhook name': 'הזינו שם webhook',
  Fail: 'כשל',
  'Failed to create webhook': 'יצירת ה-webhook נכשלה',
  'Failed to delete domain': 'מחיקת הדומיין נכשלה',
  'Failed to delete project': 'מחיקת הפרויקט נכשלה',
  'Failed to delete webhook': 'מחיקת ה-webhook נכשלה',
  'Failed to disconnect installation': 'ניתוק ההתקנה נכשל',
  'Failed to load DNS instructions. Please try again.':
    'טעינת הוראות ה-DNS נכשלה. נסו שוב.',
  'Failed to load report': 'טעינת הדוח נכשלה',
  'Failed to retry verification': 'ניסיון האימות מחדש נכשל',
  'Failed to rotate secret': 'החלפת הסוד נכשלה',
  'Failed to send test email': 'שליחת אימייל הבדיקה נכשלה',
  'Failed to start migration': 'התחלת המיגרציה נכשלה',
  'Failed to transfer project': 'העברת הפרויקט נכשלה',
  'Failed to update SMTP settings': 'עדכון הגדרות ה-SMTP נכשל',
  'Failed to update project name': 'עדכון שם הפרויקט נכשל',
  'Failed to update protocol': 'עדכון הפרוטוקול נכשל',
  'Failed to update protocols': 'עדכון הפרוטוקולים נכשל',
  'Failed to update service': 'עדכון השירות נכשל',
  'Failed to update services': 'עדכון השירותים נכשל',
  'Failed to update webhook': 'עדכון ה-webhook נכשל',
  'Features and usage': 'יכולות ושימוש',
  'Fill in sender name, sender email, server host, port, and password (for new setups) before sending a test email.':
    'מלאו שם שולח, אימייל שולח, מארח שרת, פורט וסיסמה (בהגדרה חדשה) לפני שליחת אימייל בדיקה.',
  'Find these in your NHost project:': 'ניתן למצוא אותם בפרויקט ה-NHost שלכם:',
  'Git installation has been successfully updated': 'התקנת ה-Git עודכנה בהצלחה',
  'Git installation has imported to your project':
    'התקנת ה-Git יובאה לפרויקט שלכם',
  'Global variables': 'משתנים גלובליים',
  'GraphQL API access for queries and mutations.':
    'גישת API של GraphQL לשאילתות ולמוטציות.',
  'Manage project variables': 'ניהול משתני פרויקט',
  'Import all deployments that are not currently active.':
    'ייבוא כל הפריסות שאינן פעילות כרגע.',
  'Import all environment variables.': 'ייבוא כל משתני הסביבה.',
  'Import all rows inside tables.': 'ייבוא כל השורות בטבלאות.',
  'Import all teams and the team memberships of your users.':
    'ייבוא כל הצוותים והחברויות בצוותים של המשתמשים שלכם.',
  'Import data': 'ייבוא נתונים',
  'Import data from another platform or export your project data':
    'ייבאו נתונים מפלטפורמה אחרת או ייצאו את נתוני הפרויקט שלכם',
  'Import from': 'ייבוא מ-',
  'Import from Appwrite Cloud. Enter the endpoint (with region), project ID, and a server API key with read scopes for the resources you want to migrate.':
    'ייבוא מ-Appwrite Cloud. הזינו את נקודת הקצה (כולל אזור), את מזהה הפרויקט ומפתח API של שרת עם הרשאות קריאה למשאבים שברצונכם להעביר.', // pragma: allowlist secret
  'Import from a self-hosted Appwrite instance. Enter the endpoint, project ID, and a server API key with read scopes for the resources you want to migrate.':
    'ייבוא ממופע Appwrite באירוח עצמי. הזינו את נקודת הקצה, את מזהה הפרויקט ומפתח API של שרת עם הרשאות קריאה למשאבים שברצונכם להעביר.', // pragma: allowlist secret
  'In Supabase:': 'ב-Supabase:',
  'Include environment variables': 'כולל משתני סביבה',
  'Include inactive deployments': 'כולל פריסות לא פעילות',
  'Include rows': 'כולל שורות',
  'Include teams': 'כולל צוותים',
  'Installing Git on a self-hosted instance': 'התקנת Git במופע באירוח עצמי',
  'Invalid format': 'פורמט לא תקין',
  'It will stop receiving events immediately. This action cannot be undone.':
    'הוא יפסיק לקבל אירועים באופן מיידי. לא ניתן לבטל פעולה זו.',
  'Last deployed:': 'פריסה אחרונה:',
  'Leave blank to keep current password unchanged.':
    'השאירו ריק כדי לשמור על הסיסמה הנוכחית ללא שינוי.',
  'Leave blank to keep existing': 'השאירו ריק כדי לשמור על הקיים',
  'Leave empty to auto-generate': 'השאירו ריק ליצירה אוטומטית',
  'Leave empty to clear the whole project':
    'השאירו ריק כדי לנקות את כל הפרויקט',
  'Leave empty to clear the whole collection':
    'השאירו ריק כדי לנקות את כל האוסף',
  'Leave empty to block all': 'השאירו ריק כדי לחסום הכל',
  'Enter project ID': 'הזינו מזהה פרויקט',
  'Leave this empty to rotate the webhook secret automatically, or enter a value to set a custom secret.':
    'השאירו ריק כדי להחליף את סוד ה-webhook באופן אוטומטי, או הזינו ערך כדי להגדיר סוד מותאם אישית.',
  'Loading organizations...': 'טוען ארגונים...',
  Locale: 'Locale',
  'Maximum recipients reached.': 'הגעתם למספר הנמענים המרבי.',
  'Members who are not part of the destination organization will lose access and must be invited to the new organization to regain access.':
    'חברים שאינם חלק מארגון היעד יאבדו גישה ויהיה צורך להזמין אותם לארגון החדש כדי לקבל גישה מחדש.',
  'Migration ID': 'מזהה מיגרציה',
  'Migration details': 'פרטי מיגרציה',
  'Migration errors': 'שגיאות מיגרציה',
  'Migration started': 'המיגרציה החלה',
  Migrations: 'מיגרציות',
  'Migrations are non-destructive. $createdAt and $updatedAt may be set to the migration date.':
    'מיגרציות אינן הרסניות. הערכים $createdAt ו-$updatedAt עשויים להיקבע לתאריך המיגרציה.',
  'Migrations import users, databases, and storage from an external platform into this project. Data is not deleted from the source.':
    'מיגרציות מייבאות משתמשים, מסדי נתונים ואחסון מפלטפורמה חיצונית אל הפרויקט הזה. הנתונים אינם נמחקים מהמקור.',
  'Move this project to another organization in your account. Ownership updates immediately; no data is imported.':
    'העבירו את הפרויקט לארגון אחר בחשבונכם. הבעלות מתעדכנת מיידית; לא מיובאים נתונים.',
  'Move to': 'העברה אל',
  'Name must be between 1 and': 'השם חייב להכיל בין 1 ל-',
  'No events selected': 'לא נבחרו אירועים',
  'No installation was added to the project yet':
    'עדיין לא נוספה התקנה לפרויקט',
  'No installations found': 'לא נמצאו התקנות',
  'No logs available': 'אין לוגים זמינים',
  'No migrations yet': 'אין מיגרציות עדיין',
  'No other organizations available': 'אין ארגונים אחרים זמינים',
  'No status data yet': 'אין עדיין נתוני סטטוס',
  'No webhooks yet': 'אין webhooks עדיין',
  'Only Firestore is supported; Realtime Database is not. OAuth users and functions are not migrated automatically.':
    'רק Firestore נתמך; Realtime Database אינו נתמך. משתמשי OAuth ופונקציות אינם מועברים אוטומטית.',
  'Optional. Email address where replies will be sent.':
    'אופציונלי. כתובת האימייל שאליה יישלחו תשובות.',
  Or: 'או',
  Organization: 'ארגון',
  Overwrite: 'דריסה',
  Owner: 'בעלים',
  'POST URL': 'כתובת POST',
  'Paste the full service account JSON object...':
    'הדביקו את אובייקט ה-JSON המלא של חשבון השירות...',
  Pending: 'ממתין',
  'Permanently delete this project and all associated data. This action cannot be undone.':
    'מחיקה לצמיתות של הפרויקט הזה וכל הנתונים המשויכים אליו. לא ניתן לבטל פעולה זו.',
  'Permanently delete this webhook. It will stop receiving events immediately. This action cannot be undone.':
    'מחיקה לצמיתות של ה-webhook הזה. הוא יפסיק לקבל אירועים באופן מיידי. לא ניתן לבטל פעולה זו.',
  'Please fill endpoint, project ID, and API key':
    'מלאו נקודת קצה, מזהה פרויקט ומפתח API',
  'Please fill required NHost fields': 'מלאו את שדות ה-NHost הנדרשים',
  'Please fill required Supabase fields': 'מלאו את שדות ה-Supabase הנדרשים',
  'Please paste the service account JSON': 'הדביקו את ה-JSON של חשבון השירות',
  'PostgreSQL-specific features are not migrated. OAuth users and functions are not migrated automatically.':
    'יכולות ייחודיות ל-PostgreSQL אינן מועברות. משתמשי OAuth ופונקציות אינם מועברים אוטומטית.',
  'Press Enter, Space, or comma to add each address. You can paste multiple addresses separated by commas or spaces. Up to':
    'הקישו Enter, רווח או פסיק כדי להוסיף כל כתובת. אפשר להדביק כמה כתובות מופרדות בפסיקים או ברווחים. עד',
  Progress: 'התקדמות',
  'Project Settings → API': 'Project Settings → API',
  'Project Settings → Database': 'Project Settings → Database',
  'Project name has been updated': 'שם הפרויקט עודכן',
  'Project not found': 'הפרויקט לא נמצא',
  'Project variables': 'משתני פרויקט',
  Protocol: 'פרוטוקול',
  'Protocol settings control access through REST, GraphQL, and WebSocket APIs independently from service-level access.':
    'הגדרות הפרוטוקול שולטות בגישה דרך ממשקי REST, GraphQL ו-WebSocket ללא תלות בגישה ברמת השירות.',
  Protocols: 'פרוטוקולים',
  'Realtime subscriptions over WebSocket connections.':
    'הרשמות Realtime דרך חיבורי WebSocket.',
  Recipients: 'נמענים',
  'Region:': 'אזור:',
  'Reply to': 'כתובת למענה',
  'Resolve migration issues': 'פתרון בעיות במיגרציה',
  'Retry domain verification for': 'ניסיון חוזר לאימות הדומיין עבור',
  'Retry verification': 'ניסיון אימות מחדש',
  'Rotate secret': 'החלפת סוד',
  'Rotate webhook secret': 'החלפת סוד ה-webhook',
  SMTP: 'SMTP',
  'SMTP server has been disabled.': 'שרת ה-SMTP הושבת.',
  'SMTP server has been enabled.': 'שרת ה-SMTP הופעל.',
  'SSL certificate is being issued. This usually takes a couple of minutes - no action needed on your end.':
    'תעודת SSL בתהליך הנפקה. זה נמשך בדרך כלל כמה דקות, לא נדרשת פעולה מצדכם.',
  'Search domains...': 'חיפוש דומיינים...',
  'Search migrations...': 'חיפוש מיגרציות...',
  'Search webhooks...': 'חיפוש webhooks...',
  'Secure protocol': 'פרוטוקול מאובטח',
  'Select a different organization to transfer to.': 'בחרו ארגון אחר להעברה.',
  'Select at least one event': 'בחרו לפחות אירוע אחד',
  'Select at least one resource': 'בחרו לפחות משאב אחד',
  'Select destination': 'בחירת יעד',
  'Select events that will trigger your webhook.':
    'בחרו אירועים שיפעילו את ה-webhook שלכם.',
  'Select protocol': 'בחירת פרוטוקול',
  'Select the events that will trigger your webhook':
    'בחרו את האירועים שיפעילו את ה-webhook שלכם',
  Send: 'שליחה',
  'Send test email': 'שליחת אימייל בדיקה',
  'Sender email': 'אימייל השולח',
  'Sender information': 'פרטי השולח',
  'Sender name': 'שם השולח',
  'Sending test email…': 'שולח אימייל בדיקה…',
  'Server API key with read scopes': 'מפתח API של שרת עם הרשאות קריאה',
  'Server API key with read scopes for users, databases, storage, etc. The source project must be reachable from the internet.':
    'מפתח API של שרת עם הרשאות קריאה למשתמשים, מסדי נתונים, אחסון ועוד. פרויקט המקור חייב להיות נגיש מהאינטרנט.',
  'Server configuration': 'הגדרות שרת',
  'Server host': 'מארח השרת',
  'Server port': 'פורט השרת',
  'Service account JSON': 'JSON של חשבון שירות',
  'Service account must be valid JSON': 'חשבון השירות חייב להיות JSON תקין',
  'Set an optional basic HTTP authentication username and password to protect your endpoint from unauthorized access.':
    'הגדירו שם משתמש וסיסמה אופציונליים לאימות HTTP בסיסי כדי להגן על נקודת הקצה שלכם מפני גישה לא מורשית.',
  'Set custom secret': 'הגדרת סוד מותאם אישית',
  'Set the environment variables or secret keys that will be passed to all Functions and Sites within your project.':
    'הגדירו את משתני הסביבה או מפתחות הסוד שיועברו לכל הפונקציות והאתרים בפרויקט שלכם.',
  'Set the events that will trigger your webhook. Maximum':
    'הגדירו את האירועים שיפעילו את ה-webhook שלכם. מקסימום',
  'Set up webhooks to receive real-time notifications about events in your project':
    'הגדירו webhooks כדי לקבל התראות בזמן אמת על אירועים בפרויקט שלכם',
  'Shared with all Functions and Sites in this project.':
    'משותפים לכל הפונקציות והאתרים בפרויקט הזה.',
  'Showing first': 'מוצגות',
  Since: 'מכיוון ש-',
  'Some PostgreSQL features are not migrated. OAuth users and functions are not migrated automatically.':
    'חלק מהיכולות של PostgreSQL אינן מועברות. משתמשי OAuth ופונקציות אינם מועברים אוטומטית.',
  'Some entities failed to migrate. Check status counts above.':
    'העברת חלק מהישויות נכשלה. בדקו את ספירות הסטטוס למעלה.',
  'Source project ID': 'מזהה פרויקט המקור',
  'Standard HTTP API requests from client SDKs.':
    'בקשות HTTP API סטנדרטיות מ-SDK של לקוח.',
  'Start migration': 'התחלת מיגרציה',
  'Starting...': 'מתחיל...',
  Subdomain: 'תת-דומיין',
  Summary: 'סיכום',
  'Supabase endpoint': 'נקודת קצה של Supabase',
  TablesDB: 'TablesDB',
  Teams: 'צוותים',
  'Test email sent to': 'אימייל בדיקה נשלח אל',
  'The target organization’s pricing plan may limit features or usage (e.g. executions, storage, or team size) for this project.':
    'תוכנית התמחור של ארגון היעד עשויה להגביל יכולות או שימוש (למשל הרצות, אחסון או גודל צוות) עבור הפרויקט הזה.',
  'This secret is only shown once after webhook creation or secret rotation.':
    'הסוד הזה מוצג פעם אחת בלבד לאחר יצירת ה-webhook או החלפת הסוד.',
  'This secret is only shown once after webhook creation or secret rotation. Copy it now.':
    'הסוד הזה מוצג פעם אחת בלבד לאחר יצירת ה-webhook או החלפת הסוד. העתיקו אותו עכשיו.',
  'To transfer this project, you must be a member of both the current and target organization. Select a destination below.':
    'כדי להעביר את הפרויקט, עליכם להיות חברים גם בארגון הנוכחי וגם בארגון היעד. בחרו יעד למטה.',
  'Transfer between organizations': 'העברה בין ארגונים',
  'Transfer project': 'העברת פרויקט',
  'Transfer this project to the selected organization':
    'העברת הפרויקט לארגון שנבחר',
  'URL is required': 'נדרשת כתובת URL',
  'Update webhook': 'עדכון webhook',
  'Use a service account JSON key. In Firebase Console: Project Settings → Service Accounts → Create service account, then add keys and create a new JSON key. Required roles: Firebase Viewer (Database and Storage), Identity Toolkit Viewer (users).':
    'השתמשו במפתח JSON של חשבון שירות. ב-Firebase Console: Project Settings → Service Accounts → Create service account, ואז הוסיפו מפתחות וצרו מפתח JSON חדש. תפקידים נדרשים: Firebase Viewer (מסד נתונים ואחסון), Identity Toolkit Viewer (משתמשים).',
  'Used to validate incoming webhook payloads with the X-Appwrite-Webhook-Signature header.':
    'משמש לאימות תוכני webhook נכנסים באמצעות הכותרת X-Appwrite-Webhook-Signature.', // pragma: allowlist secret
  'Used to validate incoming webhook payloads.':
    'משמש לאימות תוכני webhook נכנסים.',
  Users: 'משתמשים',
  Verification: 'אימות',
  'Verify your SMTP configuration by sending a test email to one or more recipients.':
    'ודאו שתצורת ה-SMTP תקינה על ידי שליחת אימייל בדיקה לנמען אחד או יותר.',
  'View full list in Logs': 'צפייה ברשימה המלאה בלוגים',
  'Webhook ID': 'מזהה webhook',
  'Webhook created': 'ה-webhook נוצר',
  'Webhook created. Secret ready to copy.': 'ה-webhook נוצר. הסוד מוכן להעתקה.',
  'Webhook has been created': 'ה-webhook נוצר',
  'Webhook has been deleted': 'ה-webhook נמחק',
  'Webhook has been updated': 'ה-webhook עודכן',
  'Webhook secret': 'סוד ה-webhook',
  'Webhook secret rotated': 'סוד ה-webhook הוחלף',
  'Webhook secret rotated.': 'סוד ה-webhook הוחלף.',
  'Webhook secret updated.': 'סוד ה-webhook עודכן.',
  'When a row with an existing ID is encountered during import.':
    'כאשר במהלך הייבוא נמצאת שורה עם מזהה קיים.',
  'When enabled, all emails will be sent through your configured SMTP server.':
    'כאשר האפשרות מופעלת, כל האימיילים יישלחו דרך שרת ה-SMTP שהגדרתם.',
  'You do not have any organizations you can transfer this project to.':
    'אין לכם ארגונים שאליהם ניתן להעביר את הפרויקט הזה.',
  'You do not have any organizations you can transfer this project to. Create or join another organization to transfer.':
    'אין לכם ארגונים שאליהם ניתן להעביר את הפרויקט הזה. צרו ארגון אחר או הצטרפו אליו כדי להעביר.',
  'an A or AAAA record': 'רשומת A או AAAA',
  'and all its databases, functions, and files? This action cannot be undone.':
    'ואת כל מסדי הנתונים, הפונקציות והקבצים שלו? לא ניתן לבטל פעולה זו.',
  characters: 'תווים',
  error: 'שגיאה',
  errors: 'שגיאות',
  'errors.': 'שגיאות.',
  'events allowed.': 'אירועים מותרים.',
  failed: 'נכשלו',
  global: 'גלובלי',
  'has been disconnected from this project': 'נותק מהפרויקט הזה',
  'has been transferred to': 'הועבר אל',
  'has been verified': 'אומת',
  'have been disabled.': 'הושבתו.',
  'have been enabled.': 'הופעלו.',
  here: 'כאן',
  installations: 'התקנות',
  "instead. If you're using Cloudflare or another CDN, make sure the proxy is disabled (set to DNS only) for this record, since Appwrite serves your domain through its own CDN.": // pragma: allowlist secret
    'במקום זאת. אם אתם משתמשים ב-Cloudflare או ב-CDN אחר, ודאו שהפרוקסי מושבת (מוגדר כ-DNS only) עבור הרשומה הזו, כי Appwrite מגיש את הדומיין שלכם דרך ה-CDN שלו.', // pragma: allowlist secret
  "is an apex domain, CNAME record is only supported by certain providers. If yours doesn't, please verify using":
    'הוא דומיין שורש (apex), ורשומת CNAME נתמכת רק אצל ספקים מסוימים. אם הספק שלכם לא תומך בכך, אמתו באמצעות',
  item: 'פריט',
  'key for the API key.': 'בתור מפתח ה-API.',
  nameservers: 'שרתי שמות',
  'protocol has been disabled': 'הושבת',
  'protocol has been enabled': 'הופעל',
  recipient: 'נמען',
  recipients: 'נמענים',
  'recipients.': 'נמענים.',
  recorded: 'נרשמו',
  'service has been disabled': 'הושבת',
  'service has been enabled': 'הופעל',
  succeeded: 'הצליחו',
  'this webhook': 'ה-webhook הזה',
  'to confirm': 'לאישור',
  variables: 'משתנים',
  warning: 'אזהרה',
  'API keys and provider-specific fields from the console API.':
    'מפתחות API ושדות ייחודיים לספק מתוך ה-API של הקונסולה.',
  'API secret': 'סוד API',
  'Account SID': 'Account SID',
  'Add attachment': 'הוספת קובץ מצורף',
  "Add files from your project's Storage buckets.":
    'הוסיפו קבצים מבאקטי האחסון בפרויקט שלכם.',
  'Add project users to deliver to every matching channel target on their account (email, SMS, or push), alongside any topics you selected.':
    'הוסיפו משתמשי פרויקט כדי לשלוח לכל יעד ערוץ תואם בחשבונם (אימייל, SMS או Push), בנוסף לנושאים שבחרתם.',
  'Add subscriber': 'הוספת מנוי',
  'Add subscriber functionality coming soon': 'אפשרות הוספת מנויים תגיע בקרוב',
  'Add subscribers': 'הוספת מנויים',
  'Add subscribers to this topic': 'הוסיפו מנויים לנושא הזה',
  'Add subscribers to this topic to start sending messages':
    'הוסיפו מנויים לנושא הזה כדי להתחיל לשלוח הודעות', // pragma: allowlist secret
  'Add users': 'הוספת משתמשים',
  'Are you sure you want to cancel the scheduling of':
    'האם אתם בטוחים שברצונכם לבטל את התזמון של',
  'Are you sure you want to delete this message? This action cannot be undone.':
    'האם אתם בטוחים שברצונכם למחוק את ההודעה הזו? פעולה זו אינה ניתנת לביטול.',
  'Are you sure you want to delete this provider? This action cannot be undone.':
    'האם אתם בטוחים שברצונכם למחוק את הספק הזה? פעולה זו אינה ניתנת לביטול.',
  'Are you sure you want to delete this topic? This action cannot be undone.':
    'האם אתם בטוחים שברצונכם למחוק את הנושא הזה? פעולה זו אינה ניתנת לביטול.',
  Attachments: 'קבצים מצורפים',
  'Audit log entries for this message.': 'רשומות יומן ביקורת עבור ההודעה הזו.',
  'Audit log events for subscriber': 'אירועי יומן ביקורת עבור המנוי',
  'Auth Key': 'Auth Key',
  'Auth Key ID': 'Auth Key ID',
  'Auth key': 'Auth Key',
  'Auth key ID': 'Auth Key ID',
  'Auth token': 'Auth Token',
  'BCC targets': 'יעדי BCC',
  'Back to providers': 'חזרה לספקים',
  'Back to topics': 'חזרה לנושאים',
  'Badge (iOS)': 'Badge (iOS)',
  'Build title, body, optional image and custom data. Advanced fields control action, appearance, and iOS-specific options.':
    'הגדירו כותרת, גוף, תמונה אופציונלית ונתונים מותאמים. שדות מתקדמים שולטים בפעולה, במראה ובאפשרויות ייחודיות ל-iOS.',
  'CC targets': 'יעדי CC',
  'Cancel scheduling': 'ביטול תזמון',
  'Channel type': 'סוג ערוץ',
  'Choose email targets for copy. Targets must match the email channel.':
    'בחרו יעדי אימייל לעותק. היעדים חייבים להתאים לערוץ האימייל.',
  'Choose one or more topics to send this message to.':
    'בחרו נושא אחד או יותר לשליחת ההודעה אליו.',
  'Choose user targets for this message. Each user can have multiple targets per channel.':
    'בחרו יעדי משתמשים להודעה זו. לכל משתמש יכולים להיות מספר יעדים בכל ערוץ.',
  'Choose users using Add to target every matching channel target for each user.':
    "בחרו משתמשים באמצעות 'הוספה' כדי לשלוח לכל יעד ערוץ תואם של כל משתמש.",
  'Choose when this message should be delivered. Time uses your local timezone.':
    'בחרו מתי ההודעה תישלח. השעה לפי אזור הזמן המקומי שלכם.',
  Color: 'צבע',
  Compose: 'כתיבה',
  'Confirm sending message': 'אישור שליחת הודעה',
  'Connect any SMTP server.': 'התחברו לכל שרת SMTP.',
  'Connection details for this provider instance.':
    'פרטי חיבור עבור מופע ספק זה.',
  Content: 'תוכן',
  'Content available (iOS)': 'תוכן זמין (iOS)',
  'Could not create message': 'לא ניתן ליצור הודעה',
  'Could not create provider': 'לא ניתן ליצור ספק',
  'Could not create topic': 'לא ניתן ליצור נושא',
  'Create message': 'יצירת הודעה',
  'Create provider': 'יצירת ספק',
  'Create your first message to start sending notifications':
    'צרו את ההודעה הראשונה שלכם כדי להתחיל לשלוח התראות',
  'Create your first provider to send messages':
    'צרו את הספק הראשון שלכם כדי לשלוח הודעות', // pragma: allowlist secret
  'Create your first topic to organize subscribers':
    'צרו את הנושא הראשון שלכם כדי לארגן מנויים',
  'Credentials (JSON)': 'פרטי גישה (JSON)',
  'Credentials and options must be valid JSON':
    'פרטי הגישה והאפשרויות חייבים להיות JSON תקין',
  'Critical (iOS)': 'קריטי (iOS)',
  'Current image:': 'תמונה נוכחית:',
  'Custom Data': 'נתונים מותאמים',
  'Customer ID': 'מזהה לקוח',
  'Delete Provider': 'מחיקת ספק',
  'Delete message': 'מחיקת הודעה',
  'Delete provider': 'מחיקת ספק',
  'Delete topic': 'מחיקת נושא',
  'Deliver in the background when possible.': 'מסירה ברקע כשאפשר.',
  'Delivered at': 'נמסרה בתאריך',
  'Delivery errors': 'שגיאות מסירה',
  Draft: 'טיוטה',
  'Draft message created': 'טיוטת הודעה נוצרה',
  'Draft updated': 'הטיוטה עודכנה',
  'EU region': 'אזור האיחוד האירופי',
  'Email content': 'תוכן האימייל',
  'Email subject': 'נושא האימייל',
  'Enable for development builds, disable for production.':
    'הפעילו עבור גרסאות פיתוח, השביתו עבור סביבת ייצור.',
  'Enable or disable this provider for your project.':
    'הפעילו או השביתו את הספק הזה עבור הפרויקט שלכם.',
  'Enable the HTML mode if your message contains HTML tags.':
    'הפעילו את מצב HTML אם ההודעה שלכם מכילה תגיות HTML.',
  'Enable when your Mailgun account is hosted in the EU.':
    'הפעילו כאשר חשבון ה-Mailgun שלכם מאוחסן באיחוד האירופי.',
  'Enter the SMS body for this message. Delivery uses topics, users, and targets you add on this page.':
    'הזינו את גוף ה-SMS עבור ההודעה הזו. השליחה משתמשת בנושאים, במשתמשים וביעדים שתוסיפו בעמוד זה.',
  'Failed to add subscribers': 'הוספת המנויים נכשלה',
  'Failed to cancel scheduling': 'ביטול התזמון נכשל',
  'Failed to delete items': 'מחיקת הפריטים נכשלה',
  'Failed to delete message': 'מחיקת ההודעה נכשלה',
  'Failed to delete provider': 'מחיקת הספק נכשלה',
  'Failed to delete topic': 'מחיקת הנושא נכשלה',
  'Failed to remove subscriber': 'הסרת המנוי נכשלה',
  'Failed to schedule message': 'תזמון ההודעה נכשל',
  'Failed to send message': 'שליחת ההודעה נכשלה',
  'Failed to update draft': 'עדכון הטיוטה נכשל',
  'Failed to update message': 'עדכון ההודעה נכשל',
  'Failed to update provider name': 'עדכון שם הספק נכשל',
  'Failed to update provider settings': 'עדכון הגדרות הספק נכשל',
  'Failed to update provider status': 'עדכון סטטוס הספק נכשל',
  'Failed to update topic name': 'עדכון שם הנושא נכשל',
  'From Email': 'אימייל שולח',
  'From Name': 'שם שולח',
  'HTML mode': 'מצב HTML',
  High: 'גבוהה',
  Icon: 'אייקון',
  'Include the leading + and country code.':
    'כללו את סימן ה-+ ואת קידומת המדינה.',
  'Invalid attachment': 'קובץ מצורף לא תקין',
  'Keep scheduled': 'השארת התזמון',
  'Link topics so this message reaches their subscribers when you send. Subscriber counts reflect targets registered on each topic.':
    'קשרו נושאים כדי שההודעה תגיע למנויים שלהם בעת השליחה. ספירת המנויים משקפת יעדים רשומים בכל נושא.',
  'Loading logs…': 'טוען לוגים…',
  'Loading provider...': 'טוען ספק...',
  'Loading subscribers...': 'טוען מנויים...',
  'Loading target details…': 'טוען פרטי יעד…',
  'Loading topic...': 'טוען נושא...',
  'Loading topics...': 'טוען נושאים...',
  'Log entries': 'רשומות יומן',
  Marketing: 'שיווק',
  'Media (Optional)': 'מדיה (אופציונלי)',
  'Message ID and delivery timestamps.': 'מזהה ההודעה וחותמות זמן של המסירה.',
  'Message deleted': 'ההודעה נמחקה',
  'Message error': 'שגיאת הודעה',
  'Message logs': 'יומני הודעה',
  'Message not found': 'ההודעה לא נמצאה',
  'Message updated successfully': 'ההודעה עודכנה בהצלחה',
  'No BCC targets': 'אין יעדי BCC',
  'No CC targets': 'אין יעדי CC',
  'No attachments': 'אין קבצים מצורפים',
  'No content': 'אין תוכן',
  'No log entries': 'אין רשומות יומן',
  'No log entries for this subscriber.': 'אין רשומות יומן עבור המנוי הזה.',
  'No log entries returned for this message.':
    'לא הוחזרו רשומות יומן עבור ההודעה הזו.',
  'No log entries.': 'אין רשומות יומן.',
  'No new targets selected (or all are already subscribed)':
    'לא נבחרו יעדים חדשים (או שכולם כבר מנויים)',
  'No providers yet': 'אין ספקים עדיין',
  'No subscribers found': 'לא נמצאו מנויים',
  'No subscribers yet': 'אין מנויים עדיין',
  'No targets': 'אין יעדים',
  'No targets have been selected for this message.':
    'לא נבחרו יעדים עבור ההודעה הזו.',
  'No targets yet': 'אין יעדים עדיין',
  'No topics': 'אין נושאים',
  'No topics yet': 'אין נושאים עדיין',
  'No users': 'אין משתמשים',
  Normal: 'רגילה',
  'Notification body': 'גוף ההתראה',
  'Notification title': 'כותרת ההתראה',
  'Optional provider options object.': 'אובייקט אפשרויות ספק אופציונלי.',
  'Options (JSON)': 'אפשרויות (JSON)',
  'Paste the contents of the FCM service account JSON file from the Firebase console.':
    'הדביקו את תוכן קובץ ה-JSON של חשבון השירות של FCM מקונסולת Firebase.',
  'Permanently delete this message. This action cannot be undone.':
    'מחיקה לצמיתות של ההודעה הזו. פעולה זו אינה ניתנת לביטול.',
  'Permanently delete this topic and all its subscribers. This action cannot be undone.':
    'מחיקה לצמיתות של הנושא הזה וכל המנויים שלו. פעולה זו אינה ניתנת לביטול.',
  'Pick a bucket and file from Storage. It will be referenced as bucketId:fileId on the message.':
    'בחרו באקט וקובץ מאחסון. הקובץ יצוין בהודעה בתור bucketId:fileId.',
  'Pick specific channel targets for this message. Targets must match the message provider (email, SMS, or push).':
    'בחרו יעדי ערוץ ספציפיים להודעה זו. היעדים חייבים להתאים לספק ההודעה (אימייל, SMS או Push).',
  'Please confirm you want to send this message now. It will be delivered to an estimated':
    'אשרו שברצונכם לשלוח את ההודעה כעת. היא תימסר לכ-',
  'Provider ID, channel type, and timestamps.':
    'מזהה הספק, סוג הערוץ וחותמות זמן.',
  'Provider ID:': 'מזהה ספק:',
  'Provider deleted': 'הספק נמחק',
  'Provider deleted successfully': 'הספק נמחק בהצלחה',
  'Provider name': 'שם הספק',
  'Provider name updated successfully': 'שם הספק עודכן בהצלחה',
  'Provider not found': 'הספק לא נמצא',
  'Provider settings updated successfully': 'הגדרות הספק עודכנו בהצלחה',
  'Provider status updated successfully': 'סטטוס הספק עודכן בהצלחה',
  Providers: 'ספקים',
  'Remove subscriber?': 'להסיר את המנוי?',
  'Reply Name': 'שם למענה',
  'Reply To Email': 'אימייל למענה',
  'Reply To Name': 'שם למענה',
  'Requires critical notification entitlement.': 'דורש הרשאת התראות קריטיות.',
  Reschedule: 'תזמון מחדש',
  SMS: 'SMS',
  'SMS content': 'תוכן ה-SMS',
  SSL: 'SSL',
  'Save selection': 'שמירת הבחירה',
  'Schedule a time in the future': 'יש לתזמן מועד עתידי',
  'Schedule message': 'תזמון הודעה',
  Scheduled: 'מתוזמנת',
  'Scheduled at': 'מתוזמנת לתאריך',
  'Scheduled for': 'מתוזמנת לתאריך',
  'Search by name, email, phone or ID...':
    'חיפוש לפי שם, אימייל, טלפון או מזהה…',
  'Search messages...': 'חיפוש הודעות...', // pragma: allowlist secret
  'Search subscribers...': 'חיפוש מנויים...',
  'Search topics...': 'חיפוש נושאים...',
  'Search users by name, email, or ID...':
    'חיפוש משתמשים לפי שם, אימייל או מזהה…',
  'Select BCC targets': 'בחירת יעדי BCC',
  'Select CC targets': 'בחירת יעדי CC',
  'Select bucket for image upload': 'בחרו באקט להעלאת התמונה',
  'Select targets': 'בחירת יעדים',
  'Select targets using Add to deliver this message on the matching channel.':
    "בחרו יעדים באמצעות 'הוספה' כדי לשלוח את ההודעה בערוץ המתאים.",
  'Select topics': 'בחירת נושאים',
  'Select topics using Add to reach their subscribers when you send.':
    "בחרו נושאים באמצעות 'הוספה' כדי להגיע למנויים שלהם בעת השליחה.",
  'Select user targets to subscribe to this topic. Targets already subscribed are skipped.':
    'בחרו יעדי משתמשים לרישום לנושא הזה. יעדים שכבר מנויים ידולגו.',
  'Send SMS through MSG91.': 'שליחת SMS דרך MSG91.',
  'Send SMS through Telesign.': 'שליחת SMS דרך Telesign.',
  'Send SMS through Textmagic.': 'שליחת SMS דרך Textmagic.',
  'Send SMS through Twilio.': 'שליחת SMS דרך Twilio.',
  'Send SMS through Vonage.': 'שליחת SMS דרך Vonage.',
  'Send at': 'מועד שליחה',
  'Send push notifications via APNS (iOS).':
    'שליחת התראות Push דרך APNS (iOS).',
  'Send push notifications via FCM (Android, iOS, web).':
    'שליחת התראות Push דרך FCM (Android, iOS, ווב).',
  'Send transactional email through Mailgun.':
    'שליחת אימייל טרנזקציוני דרך Mailgun.',
  'Send transactional email through Resend.':
    'שליחת אימייל טרנזקציוני דרך Resend.',
  'Send transactional email through SendGrid.':
    'שליחת אימייל טרנזקציוני דרך SendGrid.',
  'Sender ID': 'מזהה שולח',
  'Sender Name': 'שם שולח',
  'Sender phone': 'טלפון שולח',
  Sent: 'נשלחה',
  'Service Account JSON': 'JSON של חשבון שירות',
  'Service account JSON must be valid JSON':
    'ה-JSON של חשבון השירות חייב להיות JSON תקין',
  'Settings tab coming soon': 'לשונית ההגדרות תגיע בקרוב',
  'Settings update functionality coming soon': 'אפשרות עדכון הגדרות תגיע בקרוב',
  Sound: 'צליל',
  'Subscriber ID': 'מזהה מנוי',
  'Subscriber actions': 'פעולות מנוי',
  'Subscriber logs': 'יומני מנוי',
  'Subscriber removed': 'המנוי הוסר',
  TLS: 'TLS',
  Tag: 'תגית',
  'Template ID': 'מזהה תבנית',
  'The draft message has been deleted': 'טיוטת ההודעה נמחקה',
  'The message failed to deliver. See the details below.':
    'מסירת ההודעה נכשלה. ראו פרטים למטה.',
  'The message has already been sent. After deleting it, you will no longer see it here.':
    'ההודעה כבר נשלחה. לאחר מחיקתה, לא תראו אותה כאן יותר.',
  'The message has been deleted': 'ההודעה נמחקה',
  'The message has been scheduled and will be sent to an estimated':
    'ההודעה תוזמנה ותישלח לכ-',
  'The message has been sent to an estimated': 'ההודעה נשלחה לכ-',
  'The message has been sent with errors. After deleting it, you will no longer see it here.':
    'ההודעה נשלחה עם שגיאות. לאחר מחיקתה, לא תראו אותה כאן יותר.',
  'The message returns to draft.': 'ההודעה תחזור למצב טיוטה.',
  "The provider's instance will be permanently deleted. This action is irreversible.":
    'מופע הספק יימחק לצמיתות. פעולה זו אינה הפיכה.',
  'The scheduled message has been deleted, and its delivery was cancelled':
    'ההודעה המתוזמנת נמחקה ומסירתה בוטלה',
  'The scheduling has been cancelled.': 'התזמון בוטל.',
  'This action is irreversible.': 'פעולה זו אינה הפיכה.',
  'This file is already attached': 'הקובץ הזה כבר מצורף',
  'This is a scheduled message. Deleting it will result in the cancellation of its delivery.':
    'זוהי הודעה מתוזמנת. מחיקתה תגרום לביטול מסירתה.',
  'This is a scheduled message. Deleting it will result in the cancellation of its delivery. This action is irreversible.':
    'זוהי הודעה מתוזמנת. מחיקתה תגרום לביטול מסירתה. פעולה זו אינה הפיכה.',
  'This message has no linked topics.': 'להודעה זו אין נושאים מקושרים.',
  'This message has no selected users.': 'להודעה זו אין משתמשים נבחרים.',
  'This message may have been deleted or the link is incorrect.':
    'ייתכן שההודעה נמחקה או שהקישור שגוי.',
  'This subscriber will be removed from the topic. You can add them again later.':
    'המנוי יוסר מהנושא. תוכלו להוסיף אותו שוב מאוחר יותר.',
  Title: 'כותרת',
  'Topic ID': 'מזהה נושא',
  'Topic created': 'הנושא נוצר',
  'Topic deleted': 'הנושא נמחק',
  'Topic deleted successfully': 'הנושא נמחק בהצלחה',
  'Topic name': 'שם הנושא',
  'Topic name updated successfully': 'שם הנושא עודכן בהצלחה',
  'Topic not found': 'הנושא לא נמצא',
  'Topics group subscribers for email, SMS, or push.':
    'נושאים מקבצים מנויים לאימייל, SMS או Push.',
  'Type:': 'סוג:',
  'Update draft': 'עדכון טיוטה',
  "Update your provider's display name. This will be visible to all organization members.":
    'עדכנו את שם התצוגה של הספק. השם יהיה גלוי לכל חברי הארגון.',
  "Update your topic's display name. This will be visible to all organization members.":
    'עדכנו את שם התצוגה של הנושא. השם יהיה גלוי לכל חברי הארגון.',
  'Upload bucket': 'באקט העלאה',
  'Uploading replaces the push image with a Storage file reference (bucket:file).':
    'העלאה מחליפה את תמונת ה-Push בהפניה לקובץ באחסון (באקט:קובץ).',
  'Use sandbox environment': 'שימוש בסביבת sandbox',
  'Users receive this message on every target matching the message channel for their account.':
    'משתמשים יקבלו את ההודעה בכל יעד בחשבונם התואם את ערוץ ההודעה.',
  'When delivery fails, API errors are included below when available.':
    'כאשר המסירה נכשלת, שגיאות API יוצגו למטה כשהן זמינות.',
  'Write the subject and body, enable HTML if your content uses tags, add optional CC and BCC targets, and attach files from Storage.':
    'כתבו את הנושא והגוף, הפעילו HTML אם התוכן משתמש בתגיות, הוסיפו יעדי CC ו-BCC אופציונליים וצרפו קבצים מאחסון.',
  'You do not have permission to create this resource.':
    'אין לכם הרשאה ליצור את המשאב הזה.',
  "You don't have permission to create messages.":
    'אין לכם הרשאה ליצור הודעות.', // pragma: allowlist secret
  "You don't have permission to create providers.":
    'אין לכם הרשאה ליצור ספקים.',
  "You don't have permission to create topics.": 'אין לכם הרשאה ליצור נושאים.',
  "You don't have permission to manage topic subscribers.":
    'אין לכם הרשאה לנהל מנויי נושא.',
  added: 'נוספו',
  'created successfully': 'נוצר בהצלחה',
  entries: 'רשומות',
  from: 'מתוך',
  subscriber: 'מנוי',
  subscribers: 'מנויים',
  targets: 'יעדים',
  'targets.': 'יעדים.',
  'this message': 'ההודעה הזו',
  "Access Appwrite services using this project's API Endpoint and Project ID.":
    'גשו לשירותי Appwrite באמצעות נקודת הקצה של ה-API ומזהה הפרויקט של הפרויקט הזה.', // pragma: allowlist secret
  "You don't have permission to add domains.": 'אין לכם הרשאה להוסיף דומיינים.',
  "You don't have permission to create migrations.":
    'אין לכם הרשאה ליצור מיגרציות.',
  "You don't have permission to create webhooks.":
    'אין לכם הרשאה ליצור webhooks.',
  // Project selector
  Organizations: 'ארגונים',
  'Organization:': 'ארגון:',
  'Loading more...': 'טוען עוד...',
  // Connect to your project (install labels)
  Install: 'התקנה',
  'Import from JSR': 'ייבוא מ-JSR',
  '1. Add to pubspec.yaml': '1. הוסיפו ל-pubspec.yaml',
  '2. Install packages': '2. התקינו את החבילות',
  '1. Add to build.gradle.kts (module)': '1. הוסיפו ל-build.gradle.kts (מודול)',
  '2. Sync project': '2. סנכרנו את הפרויקט',
  'Add to pubspec.yaml': 'הוספה ל-pubspec.yaml',
  'Add to build.gradle.kts': 'הוספה ל-build.gradle.kts',
  'Config (env or xcconfig)': 'הגדרות (env או xcconfig)',
  '.env or launchSettings': '.env או launchSettings',
  '.env or xcconfig': '.env או xcconfig',
  '.env or env vars': '.env או משתני סביבה',
  'Build config / env': 'הגדרות build / סביבה',
  // Usage log retention
  'Usage history limit reached': 'הגעתם למגבלת היסטוריית שימוש',
  'Your plan includes': 'התוכנית שלכם כוללת',
  'days of usage history. Choose a shorter date range or upgrade for longer retention.':
    'ימים של היסטוריית שימוש. בחרו טווח תאריכים קצר יותר או שדרגו לשמירה ארוכה יותר.',
  'days of usage history. Use a shorter range or upgrade for more.':
    'ימים של היסטוריית שימוש. השתמשו בטווח קצר יותר או שדרגו לעוד.',
  'Use shorter range': 'טווח קצר יותר',
  'Date range exceeds log retention': 'טווח התאריכים חורג משמירת הלוגים',
  'Premium Geo DB required': 'נדרש Premium Geo DB',
  'Enable the Premium Geo DB addon for this project to view city and country usage breakdowns.':
    'הפעילו את תוסף Premium Geo DB בפרויקט זה כדי לצפות בפירוטי שימוש לפי עיר ומדינה.',
  'Enable Premium Geo DB': 'הפעלת Premium Geo DB',
  'Upgrade plan': 'שדרוג תוכנית',
  'Premium Geo DB': 'Premium Geo DB',
  'Enrich sessions, activity, and usage with detailed geolocation from every request.':
    'העשירו סשנים, פעילות ושימוש עם נתוני מיקום מפורטים מכל בקשה.',
  'Not enabled': 'לא מופעל',
  Continent: 'יבשת',
  'EU membership': 'חברות באיחוד האירופי',
  Currency: 'מטבע',
  'State / region': 'מחוז / אזור',
  Timezone: 'אזור זמן',
  Coordinates: 'קואורדינטות',
  ISP: 'ISP',
  ASN: 'ASN',
  'Connection type': 'סוג חיבור',
  'Connection usage': 'שימוש בחיבור',
  'Add premium geolocation details to sessions and requests, including city, timezone, postal code, and ISP.':
    'הוסיפו פרטי מיקום מתקדמים לסשנים ולבקשות, כולל עיר, אזור זמן, מיקוד וספק אינטרנט.',
  'Premium Geo DB is not available on your current plan. Upgrade your plan to enable it.':
    'Premium Geo DB אינו זמין בתוכנית הנוכחית שלכם. שדרגו את התוכנית כדי להפעיל אותו.',
  'Premium Geo DB is not available on your current plan.':
    'Premium Geo DB אינו זמין בתוכנית הנוכחית שלכם.',
  'Payment pending': 'תשלום ממתין',
  "A payment is awaiting confirmation. If you've completed authentication, click refresh to check the payment status.":
    'תשלום ממתין לאישור. אם השלמתם את האימות, לחצו על רענון כדי לבדוק את סטטוס התשלום.',
  'Scheduled for removal': 'מתוזמן להסרה',
  'Enabled for this project at {price}/month.': 'מופעל בפרויקט זה ב-{price}/חודש.',
  'Enabled for this project.': 'מופעל בפרויקט זה.',
  '{price}/month': '{price}/חודש',
  'Enrich request and session data with premium geolocation. {price}/month, prorated for the current billing cycle.':
    'העשירו נתוני בקשות וסשן עם מיקום מתקדם. {price}/חודש, בחיוב יחסי למחזור החיוב הנוכחי.',
  'Enrich request and session data with premium geolocation. Billed prorated for the current cycle.':
    'העשירו נתוני בקשות וסשן עם מיקום מתקדם. מחויב באופן יחסי למחזור הנוכחי.',
  'Premium Geo DB will be removed at the end of your current billing cycle.':
    'Premium Geo DB יוסר בסוף מחזור החיוב הנוכחי שלכם.',
  'Keep Premium Geo DB': 'שמירת Premium Geo DB',
  'Disable Premium Geo DB': 'השבתת Premium Geo DB',
  'Premium Geo DB addon has been enabled': 'תוסף Premium Geo DB הופעל',
  'Premium Geo DB addon is already active for this project':
    'תוסף Premium Geo DB כבר פעיל בפרויקט זה',
  'Premium Geo DB addon has been re-enabled': 'תוסף Premium Geo DB הופעל מחדש',
  'Premium Geo DB addon will be removed at the end of your current billing cycle':
    'תוסף Premium Geo DB יוסר בסוף מחזור החיוב הנוכחי',
  'Unable to verify Premium Geo DB addon status. Please retry.':
    'לא ניתן לאמת את סטטוס תוסף Premium Geo DB. נסו שוב.',
  'Payment could not be authorized. Please try enabling the addon again.':
    'לא ניתן היה לאשר את התשלום. נסו להפעיל את התוסף שוב.',
  'Add a payment method to your organization before enabling this addon.':
    'הוסיפו אמצעי תשלום לארגון לפני הפעלת התוסף.',
  'By clicking Enable, the monthly addon amount will be added to your subscription and your payment method will be charged the prorated amount immediately for the remaining days in your billing cycle.':
    'בלחיצה על הפעלה, הסכום החודשי של התוסף יתווסף למנוי שלכם ואמצעי התשלום יחויב מיד בסכום היחסי עבור הימים שנותרו במחזור החיוב.',
  'By clicking Enable, your payment method will be charged for the prorated amount for the remaining days in your billing cycle, and the addon will be added to this project subscription for future cycles.':
    'בלחיצה על הפעלה, אמצעי התשלום שלכם יחויב בסכום היחסי עבור הימים שנותרו במחזור החיוב, והתוסף יתווסף למנוי הפרויקט למחזורים הבאים.',
  'Premium Geo DB enriches session and request data with premium geolocation details including timezone, postal code, ISP, connection type, and organization.':
    'Premium Geo DB מעשיר נתוני סשן ובקשות בפרטי מיקום מתקדמים כולל אזור זמן, מיקוד, ספק אינטרנט, סוג חיבור וארגון.',
  'Due today (prorated)': 'לתשלום היום (יחסי)',
  '* Plus applicable tax and fees': '* בתוספת מסים ועמלות רלוונטיים',
  'Are you sure you want to disable the Premium Geo DB addon? The addon will remain active until the end of your current billing cycle and will not be renewed.':
    'האם אתם בטוחים שברצונכם להשבית את תוסף Premium Geo DB? התוסף יישאר פעיל עד סוף מחזור החיוב הנוכחי ולא יחודש.',
  'Upgrade required': 'נדרש שדרוג',
  'This feature requires an upgrade to access.':
    'נדרש שדרוג כדי לגשת לתכונה זו.',
}
