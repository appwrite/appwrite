/**
 * Hebrew translations for the Sites console area.
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heSitesDictionary: Record<string, string> = {
  // Placeholder pages
  'Add Domain': 'הוספת דומיין',
  'Add a custom domain to your site': 'הוסיפו דומיין מותאם אישית לאתר שלכם',
  'Sites Usage': 'שימוש באתרים',
  'View aggregated usage statistics across all sites':
    'צפייה בנתוני שימוש מצטברים בכל האתרים',
  Usage: 'שימוש',
  'View usage statistics for this site': 'צפייה בנתוני השימוש של האתר הזה',
  'Verify Domain': 'אימות דומיין',
  'Configure DNS settings to verify your domain':
    'הגדירו את רשומות ה-DNS כדי לאמת את הדומיין שלכם',

  // Sites list (View.tsx)
  Sites: 'אתרים',
  'Search sites...': 'חיפוש אתרים...',
  'Create site': 'יצירת אתר',
  "You don't have permission to create sites.": 'אין לכם הרשאה ליצור אתרים.',
  'Loading sites...': 'טוען אתרים...',
  Preview: 'תצוגה מקדימה',
  Site: 'אתר',
  Status: 'סטטוס',
  'Last deployed': 'פריסה אחרונה',
  Created: 'נוצר',
  Updated: 'עודכן',
  'Unnamed Site': 'אתר ללא שם',
  Never: 'אף פעם',
  'No sites yet': 'אין אתרים עדיין',
  'Create your first site to get started': 'צרו את האתר הראשון שלכם כדי להתחיל',
  Deployed: 'נפרס',
  preview: 'תצוגה מקדימה',
  'Preview not available': 'תצוגה מקדימה אינה זמינה',
  sites: 'אתרים',
  site: 'אתר',
  selected: 'נבחרו',
  Cancel: 'ביטול',
  Delete: 'מחיקה',
  'Delete Sites': 'מחיקת אתרים',
  'Are you sure you want to delete': 'האם אתם בטוחים שברצונכם למחוק',
  'This action cannot be undone.': 'פעולה זו אינה ניתנת לביטול.',
  'Site deleted successfully': 'האתר נמחק בהצלחה',
  'Successfully deleted': 'נמחקו בהצלחה',
  'Failed to delete sites': 'מחיקת האתרים נכשלה',

  // Deployments
  'Loading deployments...': 'טוען פריסות...',
  'Download started': 'ההורדה החלה',
  'Failed to download source code': 'הורדת קוד המקור נכשלה',
  'Failed to download build output': 'הורדת פלט הבנייה נכשלה',
  'Deployment rebuild started': 'בנייה מחדש של הפריסה החלה',
  'Failed to redeploy': 'הפריסה מחדש נכשלה',
  'Deployment activated successfully': 'הפריסה הופעלה בהצלחה',
  'Failed to activate deployment': 'הפעלת הפריסה נכשלה',
  'Build cancelled': 'הבנייה בוטלה',
  'Failed to cancel build': 'ביטול הבנייה נכשל',
  'Cannot delete the active deployment. Please activate another deployment first.':
    'לא ניתן למחוק את הפריסה הפעילה. הפעילו קודם פריסה אחרת.',
  'Deployment deleted successfully': 'הפריסה נמחקה בהצלחה',
  'Failed to delete deployment': 'מחיקת הפריסה נכשלה',
  'Failed to delete deployments': 'מחיקת הפריסות נכשלה',
  'Active deployment': 'פריסה פעילה',
  Building: 'בבנייה',
  'Cancel build': 'ביטול בנייה',
  'Deployment screenshot': 'צילום מסך של הפריסה',
  'Light screenshot': 'צילום מסך בהיר',
  'Dark screenshot': 'צילום מסך כהה',
  'Build duration': 'משך הבנייה',
  'Total size': 'גודל כולל',
  Source: 'מקור',
  'Global CDN': 'CDN גלובלי',
  'Content Delivery Network': 'רשת אספקת תוכן (CDN)',
  // pragma: allowlist secret
  "Appwrite's CDN provides global coverage with 120+ points of presence worldwide, reducing latency through edge caching and content optimization. All content is delivered over TLS for secure, encrypted connections.":
    'ה-CDN של Appwrite מספק כיסוי גלובלי עם יותר מ-120 נקודות נוכחות ברחבי העולם, ומפחית זמני תגובה באמצעות מטמון קצה ואופטימיזציית תוכן. כל התוכן מועבר באמצעות TLS לחיבורים מאובטחים ומוצפנים.', // pragma: allowlist secret
  'Learn more →': 'למדו עוד ←',
  Connected: 'מחובר',
  'DDoS protection': 'הגנת DDoS',
  'DDoS Mitigation': 'מיגון DDoS',
  // pragma: allowlist secret
  "Appwrite's network includes built-in DDoS mitigation to protect against distributed denial-of-service attacks, ensuring uninterrupted access to your sites and maintaining high availability even during high traffic loads.":
    'הרשת של Appwrite כוללת מיגון DDoS מובנה להגנה מפני מתקפות מניעת שירות מבוזרות, המבטיח גישה רציפה לאתרים שלכם וזמינות גבוהה גם בעומסי תנועה גבוהים.', // pragma: allowlist secret
  Active: 'פעיל',
  Domains: 'דומיינים',
  more: 'נוספים',
  'View all domains': 'צפייה בכל הדומיינים',
  'Add domain': 'הוספת דומיין',
  'View all': 'צפייה בכל',
  domains: 'דומיינים',
  Download: 'הורדה',
  'Source code': 'קוד מקור',
  'Build output': 'פלט בנייה',
  'Build output is available after the deployment has completed.':
    'פלט הבנייה זמין לאחר שהפריסה הושלמה.',
  Redeploy: 'פריסה מחדש',
  'Settings changes are not live yet': 'שינויי ההגדרות עדיין לא פעילים',
  "You've updated site settings, but they won't take effect until you redeploy. The current deployment is still running with the previous settings.":
    'עדכנתם את הגדרות האתר, אך הן לא ייכנסו לתוקף עד שתפרסו מחדש. הפריסה הנוכחית עדיין רצה עם ההגדרות הקודמות.',
  'Build logs': 'לוגי בנייה',
  Visit: 'מעבר לאתר',
  'There is no active deployment': 'אין פריסה פעילה',
  'Create your first deployment to activate this site.':
    'צרו את הפריסה הראשונה שלכם כדי להפעיל את האתר הזה.',
  'Deployment ID': 'מזהה פריסה',
  Type: 'סוג',
  'Total Size': 'גודל כולל',
  Duration: 'משך',
  Manual: 'ידני',
  by: 'מאת',
  'Build must be ready before activating':
    'הבנייה חייבת להיות מוכנה לפני ההפעלה',
  Activate: 'הפעלה',
  'The active deployment cannot be deleted from the list':
    'לא ניתן למחוק את הפריסה הפעילה מהרשימה',
  'Wait for the build to finish or cancel it first':
    'המתינו לסיום הבנייה או בטלו אותה קודם',
  deployments: 'פריסות',
  deployment: 'פריסה',
  'No deployments yet': 'אין פריסות עדיין',
  'Create your first deployment to get started':
    'צרו את הפריסה הראשונה שלכם כדי להתחיל',
  'Delete Deployments': 'מחיקת פריסות',
  'Delete deployment': 'מחיקת פריסה',
  'Are you sure you want to delete this deployment? This action cannot be undone.':
    'האם אתם בטוחים שברצונכם למחוק את הפריסה הזו? פעולה זו אינה ניתנת לביטול.',
  'Stop the current deployment? You can deploy again later.':
    'לעצור את הפריסה הנוכחית? תוכלו לפרוס שוב מאוחר יותר.',
  'Keep building': 'המשך בנייה',
  'Redeploy deployment': 'פריסה מחדש',
  "This will create a new build for this deployment using the current site configuration. The original deployment's code will be preserved and used for the new build.":
    'פעולה זו תיצור בנייה חדשה עבור הפריסה הזו לפי תצורת האתר הנוכחית. הקוד של הפריסה המקורית יישמר וישמש לבנייה החדשה.',
  'Activate deployment': 'הפעלת פריסה',
  'This will switch the active deployment to this one. All traffic will be routed to this deployment once activated.':
    'פעולה זו תחליף את הפריסה הפעילה בפריסה הזו. כל התנועה תנותב לפריסה זו לאחר ההפעלה.',
  // Deployment status labels (from deployment-status helper)
  Ready: 'מוכן',
  Processing: 'בעיבוד',
  Waiting: 'ממתין',
  Failed: 'נכשל',
  Canceled: 'בוטל',
  Timeout: 'Timeout',

  // Logs pages
  'No logs yet': 'אין לוגים עדיין',
  'Logs will appear here when your site runs.':
    'לוגים יופיעו כאן כאשר האתר שלכם ירוץ.',
  logs: 'לוגים',
  'No executions yet': 'אין הרצות עדיין',
  'Executions will appear here when your site runs.':
    'הרצות יופיעו כאן כאשר האתר שלכם ירוץ.',

  // Domains pages
  'No domains yet': 'אין דומיינים עדיין',
  'Connect a custom domain to your site for a branded experience':
    'חברו דומיין מותאם אישית לאתר שלכם לחוויה ממותגת',
  Domain: 'דומיין',
  'Redirect to': 'הפניה אל',
  'Deployed from': 'נפרס מ-',
  'SSL certificate is being issued. This usually takes a couple of minutes.':
    'תעודת ה-SSL בתהליך הנפקה. זה נמשך בדרך כלל כמה דקות.',
  'View logs': 'צפו בלוגים',
  Retry: 'ניסיון חוזר',
  Logs: 'לוגים',
  Records: 'רשומות',
  'Domain has been deleted': 'הדומיין נמחק',
  'Loading domains...': 'טוען דומיינים...',
  Verified: 'מאומת',
  'Generating certificate': 'מנפיק תעודה',
  'Verification failed': 'האימות נכשל',
  'Certificate generation failed': 'הנפקת התעודה נכשלה',

  // Site layout and settings shell
  Deployments: 'פריסות',
  Variables: 'משתנים',
  Settings: 'הגדרות',
  'Back to sites': 'חזרה לאתרים',
  'Your site is currently being deployed.': 'האתר שלכם נמצא כעת בתהליך פריסה.',
  'Filters coming soon': 'מסננים יגיעו בקרוב',
  'Loading settings...': 'טוען הגדרות...',

  // Settings: name card
  Name: 'שם',
  'Site name used for identification': 'שם האתר המשמש לזיהוי',
  'Enter site name': 'הזינו שם אתר',
  Update: 'עדכון',
  'Site name updated successfully': 'שם האתר עודכן בהצלחה',
  'Failed to update site name': 'עדכון שם האתר נכשל',
  'Site name is required': 'נדרש שם אתר',

  // Settings: status card
  'Enable or disable this site without deleting it.':
    'הפעילו או השביתו את האתר הזה מבלי למחוק אותו.',
  'Site has been enabled': 'האתר הופעל',
  'Site has been disabled': 'האתר הושבת',
  'Site is disabled': 'האתר מושבת',
  'This site is disabled and not accessible to visitors. Console actions remain available.':
    'האתר הזה מושבת ואינו נגיש למבקרים. פעולות מהקונסולה עדיין זמינות.',
  'Enable this site in the Settings tab': 'הפעילו את האתר בלשונית ההגדרות',
  'to make it available to visitors.': 'כדי להפוך אותו לזמין למבקרים.',

  // Settings: danger zone
  'Delete site': 'מחיקת אתר',
  'Permanently delete this site and all its data. This action cannot be undone.':
    'מחיקה לצמיתות של האתר הזה וכל הנתונים שלו. פעולה זו אינה ניתנת לביטול.',
  'this site': 'האתר הזה',
  'and all its data? This action cannot be undone.':
    'ואת כל הנתונים שלו? פעולה זו אינה ניתנת לביטול.',
  'Failed to delete site': 'מחיקת האתר נכשלה',

  // Settings: repository card
  Repository: 'Repo',
  'Connect your site to a Git repository for automatic deployments':
    'חברו את האתר שלכם ל-Git repo לפריסות אוטומטיות',
  'No repository connected': 'לא מחובר repo',
  'Connect a repository to enable automatic deployments':
    'חברו repo כדי לאפשר פריסות אוטומטיות',
  'Connect repository': 'חיבור repo',
  'Select a Git installation and repository to connect to this site. You can connect an existing repository or create a new site from a template.':
    'בחרו התקנת Git ו-repo לחיבור לאתר הזה. אפשר לחבר repo קיים או ליצור אתר חדש מתבנית.',
  Connect: 'חיבור',
  'Last updated': 'עודכן לאחרונה',
  'Open repository in new tab': 'פתיחת ה-repo בכרטיסייה חדשה',
  Disconnect: 'ניתוק',
  'Disconnect repository': 'ניתוק repo',
  'Are you sure you want to disconnect': 'האם אתם בטוחים שברצונכם לנתק את',
  "from this site? This will remove the Git integration but won't affect your deployments.":
    'מהאתר הזה? פעולה זו תסיר את האינטגרציה עם Git אך לא תשפיע על הפריסות שלכם.',
  'Branch Settings': 'הגדרות Branch',
  'Production branch': 'Branch פרודקשן',
  'Root directory': 'תיקיית שורש',
  'Choose the directory containing your site code':
    'בחרו את התיקייה המכילה את קוד האתר שלכם',
  'Repository settings updated successfully': 'הגדרות ה-repo עודכנו בהצלחה',
  'Failed to update repository settings': 'עדכון הגדרות ה-repo נכשל',
  'Repository connected successfully': 'ה-repo חובר בהצלחה',
  'Failed to connect repository': 'חיבור ה-repo נכשל',
  'Repository disconnected successfully': 'ה-repo נותק בהצלחה',
  'Failed to disconnect repository': 'ניתוק ה-repo נכשל',
  'No changes to save': 'אין שינויים לשמירה',
  'Please select an installation and repository': 'בחרו התקנה ו-repo',

  // Settings: framework card
  'Learn more': 'למדו עוד',
  'Server side rendering': 'רינדור בצד השרת',
  'Static site': 'אתר סטטי',
  Adapter: 'Adapter',
  'Choose how your site is rendered at runtime.':
    'בחרו כיצד האתר שלכם מרונדר בזמן ריצה.',
  Framework: 'Framework',
  'Choose your stack, adapter mode, and where build output is written.':
    'בחרו את הסטאק, מצב ה-Adapter והמיקום שאליו נכתב פלט הבנייה.',
  'Select framework': 'בחירת framework',
  'Output directory': 'תיקיית פלט',
  'Enter output directory': 'הזינו תיקיית פלט',
  'Fallback file': 'קובץ ברירת מחדל',
  'Select a framework to configure the fallback file':
    'בחרו framework כדי להגדיר את קובץ ברירת המחדל',
  "File to serve for routes that don't match any static files":
    'קובץ שיוגש עבור נתיבים שאינם תואמים לקבצים סטטיים',
  'Framework settings updated successfully': 'הגדרות ה-framework עודכנו בהצלחה',
  'Failed to update framework settings': 'עדכון הגדרות ה-framework נכשל',
  'Framework is required': 'נדרש framework',

  // Settings: commands card
  Commands: 'פקודות',
  'Shell commands run on the build worker (defaults follow your framework).':
    'פקודות מעטפת שרצות על שרת הבנייה (ברירות המחדל נקבעות לפי ה-framework שלכם).',
  'Install command': 'פקודת התקנה',
  'Enter install command': 'הזינו פקודת התקנה',
  'Build command': 'פקודת בנייה',
  'Enter build command': 'הזינו פקודת בנייה',
  'Build commands updated successfully': 'פקודות הבנייה עודכנו בהצלחה',
  'Failed to update build commands': 'עדכון פקודות הבנייה נכשל',

  // Settings: specification cards
  Specification: 'מפרט',
  'CPU and memory allocated on the build worker for dependency install and compile steps.':
    'מעבד וזיכרון המוקצים לשרת הבנייה עבור שלבי התקנת התלויות והקומפילציה.',
  'CPU and memory allocated when your site handles requests, including server-side rendering (SSR).':
    'מעבד וזיכרון המוקצים כאשר האתר שלכם מטפל בבקשות, כולל רינדור בצד השרת (SSR).',
  'Specification updated successfully': 'המפרט עודכן בהצלחה',
  'Failed to update specification': 'עדכון המפרט נכשל',

  // Settings: runtime image card
  Image: 'Image',
  'Base image used when your site runs in production (SSR, API routes, and dynamic handlers). Pick an image that matches your stack. Changes take effect after the next successful deploy.':
    'ה-Image הבסיסי שמשמש כאשר האתר שלכם רץ בפרודקשן (SSR, נתיבי API ומטפלים דינמיים). בחרו Image שמתאים לסטאק שלכם. השינויים ייכנסו לתוקף לאחר הפריסה המוצלחת הבאה.',
  'Select an image': 'בחרו Image',
  'No images are available for this framework yet.':
    'עדיין אין Images זמינים עבור ה-framework הזה.',
  'Choose a framework in build settings to see compatible images.':
    'בחרו framework בהגדרות הבנייה כדי לראות Images תואמים.',
  'Runtime settings updated successfully': 'הגדרות זמן הריצה עודכנו בהצלחה',
  'Failed to update runtime settings': 'עדכון הגדרות זמן הריצה נכשל',

  // Settings: start command card
  'Start command': 'פקודת הפעלה',
  'Command used to start your SSR server after a successful deploy. Leave it empty to use the framework default.':
    'הפקודה המשמשת להפעלת שרת ה-SSR שלכם לאחר פריסה מוצלחת. השאירו ריק כדי להשתמש בברירת המחדל של ה-framework.',
  'Enter start command': 'הזינו פקודת הפעלה',
  'Start command updated successfully': 'פקודת ההפעלה עודכנה בהצלחה',
  'Failed to update start command': 'עדכון פקודת ההפעלה נכשל',

  // Settings: timeout card
  'Upper bound on how long a single request may run before the platform stops it. Use a higher value for slow SSR or data-heavy pages; use a lower value to fail fast when something hangs. Allowed range is 1–30 seconds.':
    'הגבול העליון למשך הריצה של בקשה בודדת לפני שהפלטפורמה עוצרת אותה. השתמשו בערך גבוה יותר עבור SSR איטי או דפים עתירי נתונים; השתמשו בערך נמוך יותר כדי להיכשל מהר כשמשהו נתקע. הטווח המותר הוא 1 עד 30 שניות.',
  'Seconds per request': 'שניות לבקשה',
  'Request timeout updated successfully': 'הזמן הקצוב לבקשה עודכן בהצלחה',
  'Failed to update request timeout': 'עדכון הזמן הקצוב לבקשה נכשל',
  'Timeout must be between 1 and 30 seconds':
    'הזמן הקצוב חייב להיות בין 1 ל-30 שניות',

  // Settings: logging card
  Logging: 'רישום לוגים',
  'Controls how much detail is captured for each request. Full logging helps you debug production issues with stdout, stderr, and stack traces in the console. Turning logging off reduces overhead and can slightly improve response time when you do not need that detail.':
    'קובע כמה פירוט נשמר עבור כל בקשה. רישום מלא עוזר לנפות באגים בפרודקשן עם stdout, stderr ו-stack traces בקונסולה. כיבוי הרישום מפחית תקורה ויכול לשפר מעט את זמן התגובה כשאינכם זקוקים לפירוט הזה.',
  'Full request logging': 'רישום מלא של בקשות',
  'Enabled - logs and errors from your site are recorded.':
    'מופעל - לוגים ושגיאות מהאתר שלכם נשמרים.',
  'Disabled - lighter request records; responses may be slightly faster.':
    'כבוי - רשומות בקשה קלות יותר; ייתכן שהתגובות יהיו מעט מהירות יותר.',
  'Logging updated successfully': 'רישום הלוגים עודכן בהצלחה',
  'Failed to update logging': 'עדכון רישום הלוגים נכשל',

  // Settings: details card
  Details: 'פרטים',
  'Identifiers and timestamps for this site.': 'מזהים וחותמות זמן של האתר הזה.',
  'Site ID:': 'מזהה אתר:',
  'Created:': 'נוצר:',
  'Last updated:': 'עודכן לאחרונה:',

  // Settings: retention and triggers
  'Retention has been updated': 'הגדרות השמירה עודכנו',
  'Failed to update retention': 'עדכון הגדרות השמירה נכשל',
  'Triggers updated.': 'הטריגרים עודכנו.',
  'Failed to update triggers': 'עדכון הטריגרים נכשל',

  // Settings: silent mode card
  'Silent mode': 'מצב שקט',
  // pragma: allowlist secret
  'Control whether Appwrite posts automated comments on commits in your connected GitHub repository (for example deployment notes on pull requests). Deployments, checks, and builds are unchanged-only optional commit comments are skipped when silent mode is on.':
    'קובע אם Appwrite מפרסם תגובות אוטומטיות על קומיטים ב-repo ה-GitHub המחובר שלכם (למשל הערות פריסה על pull requests). פריסות, בדיקות ובניות אינן משתנות; רק תגובות אופציונליות על קומיטים מדולגות כשמצב שקט פעיל.', // pragma: allowlist secret
  'Disable automated commit comments': 'השבתת תגובות אוטומטיות על קומיטים',

  // Settings: variables card
  'Environment variables': 'משתני סביבה',
  'Configure environment variables for your site deployments. Site-specific variables override global project variables. Set the environment variables or secret keys that will be passed to this site during deployment.':
    'הגדירו משתני סביבה לפריסות האתר שלכם. משתנים ייעודיים לאתר גוברים על משתני פרויקט גלובליים. הגדירו את משתני הסביבה או מפתחות הסוד שיועברו לאתר הזה במהלך הפריסה.',
  Global: 'גלובלי',

  // Overview extras
  'Loading site...': 'טוען אתר...',
  'Recent deployments': 'פריסות אחרונות',
  'Deployments will appear here when available':
    'פריסות יופיעו כאן כשיהיו זמינות',

  // Start command label
  '(optional)': '(אופציונלי)',
  'About start command (optional)': 'אודות פקודת ההפעלה (אופציונלי)',
  'Shell command that starts your SSR app after deploy (for example, npm run start). If left empty, your framework default is used. This field is optional.':
    'פקודת מעטפת שמפעילה את אפליקציית ה-SSR שלכם לאחר הפריסה (לדוגמה, npm run start). אם תשאירו ריק, תשמש ברירת המחדל של ה-framework שלכם. שדה זה אופציונלי.',

  // Site context menu
  Copy: 'העתקה',
  'Copy ID': 'העתקת מזהה',
  'Copy name': 'העתקת שם',
  'Copy link': 'העתקת קישור',
  'Copy as JSON': 'העתקה כ-JSON',
  'Open in new tab': 'פתיחה בכרטיסייה חדשה',
  'Open in new window': 'פתיחה בחלון חדש',
  'Site deleted': 'האתר נמחק',
  'Are you sure you want to delete this site? This action cannot be undone.':
    'האם אתם בטוחים שברצונכם למחוק את האתר הזה? פעולה זו אינה ניתנת לביטול.',

  // Verify domain dialog and add domain wizard
  Verify: 'אימות',
  Change: 'שינוי',
  Add: 'הוספה',
  'Verify domain': 'אימות דומיין',
  'Domain name': 'שם דומיין',
  'Domain verified': 'הדומיין אומת',
  'Verifying...': 'מאמת...',
  'Verification in progress': 'האימות בתהליך',
  'Verification failed. Check DNS and retry.':
    'האימות נכשל. בדקו את רשומות ה-DNS ונסו שוב.',
  'Failed to verify domain': 'אימות הדומיין נכשל',
  'Failed to remove domain': 'הסרת הדומיין נכשלה',
  'Failed to add domain': 'הוספת הדומיין נכשלה',
  'Failed to register domain': 'רישום הדומיין נכשל',
  Required: 'שדה חובה',
  Invalid: 'לא תקין',
  'Invalid URL': 'כתובת URL לא תקינה',
  'Enter URL': 'הזינו כתובת URL',
  'Select branch': 'בחירת Branch',
  'Connect repository first': 'חברו repo קודם',

  // Template gallery
  'Search templates...': 'חיפוש תבניות...',
  'No templates found': 'לא נמצאו תבניות',
  'No templates available': 'אין תבניות זמינות',
  'Search use cases...': 'חיפוש תרחישי שימוש...',
  'No use case found.': 'לא נמצא תרחיש שימוש.',
  'Search frameworks...': 'חיפוש frameworks...',
  'No framework found.': 'לא נמצא framework.',
  'All frameworks': 'כל ה-frameworks',
  'All use cases': 'כל תרחישי השימוש',
  Starter: 'ערכת התחלה',
  AI: 'AI',
  Databases: 'מסדי נתונים',
  Messaging: 'הודעות',
  'Dev tools': 'כלי פיתוח',
  Utilities: 'כלי עזר',

  // Create wizard: entry view
  'Import repository': 'ייבוא repo',
  'Connect Git provider': 'חיבור ספק Git',
  'Import repositories for automatic deployments':
    'ייבאו repos לפריסות אוטומטיות',
  'Connect GitHub': 'חיבור GitHub',
  'Connect GitLab': 'חיבור GitLab',
  'Select organization': 'בחירת ארגון',
  'Add account': 'הוספת חשבון',
  'Search...': 'חיפוש...',
  'No repositories found': 'לא נמצאו repos',
  'No repositories available': 'אין repos זמינים',
  "Can't find a repository?": 'לא מוצאים repo?',
  'If you selected specific repositories during setup, you may need to update your permissions to include additional ones.':
    'אם בחרתם repos ספציפיים במהלך ההגדרה, ייתכן שתצטרכו לעדכן את ההרשאות שלכם כדי לכלול repos נוספים.',
  'Update GitHub permissions': 'עדכון הרשאות GitHub',
  'Update GitLab permissions': 'עדכון הרשאות GitLab',
  'Clone template': 'שכפול תבנית',
  'Want to deploy without connecting a repository or using a template?':
    'רוצים לפרוס בלי לחבר repo או להשתמש בתבנית?',
  'Upload your website manually': 'העלו את האתר שלכם ידנית',
  Deploy: 'פריסה',
  Back: 'חזרה',

  // Create wizard: manual upload
  'Only .tar.gz files are supported': 'נתמכים רק קובצי .tar.gz',
  'File size must be less than 100MB': 'גודל הקובץ חייב להיות קטן מ-100MB',
  'Please fill in all required fields and upload a file':
    'מלאו את כל שדות החובה והעלו קובץ',
  'Please enter a valid domain': 'הזינו דומיין תקין',
  'Failed to create site': 'יצירת האתר נכשלה',
  'Other options': 'אפשרויות נוספות',
  'Import from Git': 'ייבוא מ-Git',
  'Browse templates': 'עיון בתבניות',
  'Upload file': 'העלאת קובץ',
  'Upload a .tar.gz file containing your site source code':
    'העלו קובץ .tar.gz המכיל את קוד המקור של האתר שלכם',
  'Drop your file here or click to browse': 'גררו את הקובץ לכאן או לחצו לעיון',
  'Only .tar.gz files up to 100MB': 'רק קובצי .tar.gz עד 100MB',
  'Site name': 'שם האתר',
  'Site ID': 'מזהה אתר',
  'My awesome site': 'האתר המדהים שלי',
  'Auto-generated': 'נוצר אוטומטית',
  'Your site will be accessible at this URL': 'האתר שלכם יהיה נגיש בכתובת הזו',
  // pragma: allowlist secret
  'Want to use your own domain? After deployment, you can connect a custom domain via CNAME record or let Appwrite manage your DNS.':
    'רוצים להשתמש בדומיין משלכם? לאחר הפריסה תוכלו לחבר דומיין מותאם אישית באמצעות רשומת CNAME או לתת ל-Appwrite לנהל את ה-DNS שלכם.', // pragma: allowlist secret

  // Create wizard: quick deploy and repository config
  'Please fill in all required fields': 'מלאו את כל שדות החובה',
  'Could not detect framework. Select one manually.':
    'לא ניתן לזהות את ה-framework. בחרו אחד ידנית.',
  'Repository not connected. Go back and select a repository.':
    'ה-repo אינו מחובר. חזרו אחורה ובחרו repo.',
  'Repository information is missing from the URL.':
    'פרטי ה-repo חסרים בכתובת ה-URL.',
  'GitHub Repository': 'GitHub repo',
  'GitLab Repository': 'GitLab repo',
  'View on GitHub': 'צפו ב-GitHub',
  'View repository': 'צפייה ב-repo',
  'Detecting framework...': 'מזהה framework...',
  Detect: 'זיהוי',
  'Git configuration': 'תצורת Git',
  Branch: 'Branch',
  'Production branch for the repo linked to the site. Successful deployments from this branch get activated automatically.':
    'ה-Branch של הפרודקשן בה-repo המקושר לאתר. פריסות מוצלחות מה-Branch הזה מופעלות אוטומטית.',
  'Path to site code in the linked repo. Use the repository root (./) or a subdirectory that contains your app (e.g. ./apps/web).':
    'הנתיב לקוד האתר בה-repo המקושר. השתמשו בשורש ה-repo (./) או בתת-תיקייה שמכילה את האפליקציה שלכם (למשל ./apps/web).',
  'Disable automated comments on repository commits':
    'השבתת תגובות אוטומטיות על קומיטים ב-repo',
  configured: 'הוגדרו',
  'Ready to deploy': 'מוכן לפריסה',

  // Create wizard: build settings
  Build: 'בנייה',
  Reset: 'איפוס',

  // Create wizard: template config
  'Template not found': 'התבנית לא נמצאה',
  'Please select a repository': 'בחרו repo',
  'Template is missing repository information': 'לתבנית חסרים פרטי repo',
  Version: 'גרסה',
  required: 'חובה',
  'View source': 'צפייה בקוד המקור',
  'Live demo': 'הדגמה חיה',
  'Connect your repository': 'חיבור ה-repo שלכם',
  'Clone this template into a new Git repository or link it to an existing one.':
    'שכפלו את התבנית הזו ל-Git repo חדש או קשרו אותה ל-repo קיים.',
  'Connect later': 'חיבור מאוחר יותר',
  'Deploy now and connect your version control later via CLI or Git integration in your settings.':
    'פרסו עכשיו וחברו את ניהול הגרסאות שלכם מאוחר יותר דרך ה-CLI או אינטגרציית Git בהגדרות.',
  'Connect Git repository': 'חיבור Git repo',
  'Create and deploy a Site with a connected git repository.':
    'צרו ופרסו אתר עם Git repo מחובר.',
  'Template variables': 'משתני תבנית',
  'Configure the environment variables for this template':
    'הגדירו את משתני הסביבה עבור התבנית הזו',
  'Optional variables': 'משתנים אופציונליים',
  Secret: 'סודי',

  // Create wizard: deploying and finish
  'Deployment cancelled': 'הפריסה בוטלה',
  'Failed to cancel deployment': 'ביטול הפריסה נכשל',
  'Cancel deployment': 'ביטול פריסה',
  'Go to dashboard': 'מעבר ללוח הבקרה',
  Size: 'גודל',
  'Loading preview…': 'טוען תצוגה מקדימה…',
  'Generating preview…': 'יוצר תצוגה מקדימה…',
  'Screenshot may take a few moments after build completes':
    'צילום המסך עשוי להיות מוכן כמה רגעים לאחר סיום הבנייה',
  'Visit site': 'מעבר לאתר',
  'Next steps': 'הצעדים הבאים',
  'Configure your site or share it with others':
    'הגדירו את האתר שלכם או שתפו אותו עם אחרים',
  'Add repository': 'הוספת repo',
  'Connect Git for automatic deployments': 'חברו Git לפריסות אוטומטיות',
  'Connect a Git repository for automatic deployments':
    'חברו Git repo לפריסות אוטומטיות',
  'Add custom domain': 'הוספת דומיין מותאם אישית',
  'Use your own domain name': 'השתמשו בשם הדומיין שלכם',
  'Configure your own domain name': 'הגדירו שם דומיין משלכם',
  'Copy site URL': 'העתקת כתובת האתר',
  'Copy URL to clipboard': 'העתקת הכתובת ללוח',
  'Copy the site URL to clipboard': 'העתקת כתובת האתר ללוח',
  'URL copied to clipboard': 'הכתובת הועתקה ללוח',
  'Open on mobile': 'פתיחה בנייד',
  'Scan QR code': 'סרקו קוד QR',
  'Scan QR code to view on your phone': 'סרקו קוד QR לצפייה בטלפון שלכם',
  'View on mobile': 'צפייה בנייד',
  'Scan this QR code to open your site on a mobile device':
    'סרקו את קוד ה-QR הזה כדי לפתוח את האתר שלכם במכשיר נייד',
  'QR code to open site on mobile': 'קוד QR לפתיחת האתר בנייד',
  'QR code could not be generated. Try again in a moment.':
    'לא ניתן היה ליצור את קוד ה-QR. נסו שוב בעוד רגע.',
  Close: 'סגירה',
  'Site URL is not available yet': 'כתובת האתר עדיין אינה זמינה',
  'Deployment successful!': 'הפריסה הצליחה!',
  'Your site is now live': 'האתר שלכם באוויר',
}
