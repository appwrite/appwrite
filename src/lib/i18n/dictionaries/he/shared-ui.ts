/**
 * Hebrew translations for global shared UI components.
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heSharedUiDictionary: Record<string, string> = {
  // Error formatting (toast titles and static messages) // pragma: allowlist secret
  Error: 'שגיאה',
  'Not Found': 'לא נמצא',
  'Access Denied': 'הגישה נדחתה',
  Forbidden: 'אין הרשאה',
  'Invalid Request': 'בקשה לא תקינה',
  'Server Error': 'שגיאת שרת',
  'Connection Error': 'שגיאת חיבור',
  'Request Timeout': 'חריגת זמן בבקשה',
  'Database timed out. Try adjusting your queries or adding an index.':
    'מסד הנתונים חרג מזמן ההמתנה. נסו להתאים את השאילתות או להוסיף אינדקס.',
  'Something went wrong. Please try again.': 'משהו השתבש. נסו שוב.',
  'The requested resource could not be found. It may have been deleted or you may not have permission to access it.':
    'המשאב המבוקש לא נמצא. ייתכן שהוא נמחק או שאין לכם הרשאה לגשת אליו.',
  'You do not have permission to perform this action. Please contact your administrator if you believe this is an error.':
    'אין לכם הרשאה לבצע פעולה זו. פנו למנהל המערכת אם לדעתכם מדובר בטעות.',
  'You do not have permission to access this resource.':
    'אין לכם הרשאה לגשת למשאב זה.',
  'The request is invalid. Please check your input and try again.':
    'הבקשה אינה תקינה. בדקו את הקלט ונסו שוב.',
  'An error occurred on the server. Please try again in a few moments. If the problem persists, contact support.':
    'אירעה שגיאה בשרת. נסו שוב בעוד מספר רגעים. אם הבעיה נמשכת, פנו לתמיכה.',
  'Unable to connect to the server. Please check your internet connection and try again.':
    'לא ניתן להתחבר לשרת. בדקו את חיבור האינטרנט ונסו שוב.',
  'The request took too long to complete. Please try again.':
    'הבקשה ארכה זמן רב מדי. נסו שוב.',

  // Punctuated fragments and short segments
  '(Paused)': '(מושהה)',
  '(Shift for range, ⌘/Ctrl to toggle)':
    '(Shift לבחירת טווח, ⌘/Ctrl להחלפת בחירה)',
  '(empty)': '(ריק)',
  '. Operator': '. המפעיל:',
  copied: 'הועתק',
  'drag to reorder': 'גררו לשינוי הסדר',
  'e.g. email, score, tags': 'לדוגמה: email, score, tags',
  for: 'עבור',
  navigate: 'ניווט',
  of: 'מתוך',
  or: 'או',
  'per page': 'בעמוד',
  'scopes.': 'הרשאות.',
  select: 'בחירה',
  back: 'חזרה',
  close: 'סגירה',
  then: 'ואז',
  'already exists': 'כבר קיים',
  'is longer than 8192 allowed characters': 'ארוך מ-8192 התווים המותרים',
  'contact sales': 'צרו קשר עם מכירות',
  'to include more repos.': 'כדי לכלול repos נוספים.',
  'to unlock additional specifications.': 'כדי לפתוח מפרטים נוספים.',

  // Common actions and labels
  'Accept all': 'אישור הכול',
  Actions: 'פעולות',
  Activate: 'הפעלה',
  'Activate deployment': 'הפעלת פריסה',
  'Activate deployment functionality coming soon':
    'הפעלת פריסה תהיה זמינה בקרוב',
  'Activate is not available for this deployment type':
    'הפעלה אינה זמינה לסוג פריסה זה',
  Active: 'פעיל',
  'Active session:': 'סשן פעיל:',
  'Add account': 'הוספת חשבון',
  'Add filter': 'הוספת סינון',
  'Add installation': 'הוספת התקנה',
  'Add one or more environment variables. You can add multiple variables at once.':
    'הוסיפו משתנה סביבה אחד או יותר. אפשר להוסיף כמה משתנים בבת אחת.',
  'Add variable': 'הוספת משתנה',
  All: 'הכול',
  'All shortcuts': 'כל הקיצורים',
  'All variable keys are required': 'כל מפתחות המשתנים נדרשים',
  'Always active': 'פעילות תמיד',
  Amount: 'כמות',
  Analytics: 'אנליטיקה',
  Apply: 'החלה',
  'Are you sure you want to delete this deployment? This action cannot be undone.':
    'האם למחוק את הפריסה הזו? פעולה זו אינה ניתנת לביטול.',
  'Are you sure you want to delete this variable? This action cannot be undone.':
    'האם למחוק את המשתנה הזה? פעולה זו אינה ניתנת לביטול.',
  'Article toolbar': 'סרגל הכלים של המאמר',
  Asc: 'עולה',
  Ascending: 'סדר עולה',
  'Attribute name': 'שם מאפיין',
  Auto: 'אוטומטי',
  Back: 'חזרה',
  Boolean: 'בוליאני',
  Branch: 'Branch',
  'Branch copied': 'שם הענף הועתק',
  'Branch:': 'ענף:',
  Bucket: 'באקט',
  Buckets: 'באקטים',
  'Build cancelled': 'הבנייה בוטלה',
  'Build must be ready before activating.':
    'הבנייה חייבת להיות מוכנה לפני ההפעלה.',
  'Build output': 'פלט בנייה',
  'Build output is available after the deployment has completed.':
    'פלט הבנייה זמין לאחר שהפריסה הושלמה.',
  // pragma: allowlist secret
  'CLI deployments are created using the Appwrite command line tool, useful for developer workflows and scripted automation.':
    'פריסות CLI נוצרות באמצעות כלי שורת הפקודה של Appwrite, שימושי לתהליכי עבודה של מפתחים ולאוטומציה בסקריפטים.', // pragma: allowlist secret
  "Can't find a repository?": 'לא מוצאים repo?',
  Cancel: 'ביטול',
  'Cancel build': 'ביטול בנייה',
  'Cancel build is not available': 'ביטול הבנייה אינו זמין',
  'Cancel upload': 'ביטול העלאה',
  'Cannot delete the active deployment. Activate another deployment first.':
    'לא ניתן למחוק את הפריסה הפעילה. יש להפעיל קודם פריסה אחרת.',
  'Cannot delete the active deployment. Please activate another deployment first.':
    'לא ניתן למחוק את הפריסה הפעילה. יש להפעיל קודם פריסה אחרת.',
  'Cell value': 'ערך תא',
  'Choose which optional cookies you allow. Read our':
    'בחרו אילו עוגיות אופציונליות לאשר. קראו את',
  Clear: 'ניקוי',
  'Clear all': 'ניקוי הכול',
  'Clear search': 'ניקוי חיפוש',
  'Clear shown': 'ניקוי המוצגים',
  Close: 'סגירה',
  'Close wizard': 'סגירת האשף',
  'Code language': 'שפת קוד',
  Column: 'עמודה',
  'Command center': 'Command Center',
  Commit: 'קומיט',
  'Commit copied': 'הקומיט הועתק',
  'Commit:': 'קומיט:',
  Committer: 'מבצע הקומיט',
  'Committer:': 'מבצע הקומיט:',
  'Community and enterprise resources': 'משאבי קהילה וארגונים',
  'Community and enterprise resources for self-hosting':
    'משאבי קהילה וארגונים לאירוח עצמי',
  Connect: 'חיבור',
  'Connect existing repository': 'חיבור repo קיים',
  'Connect to GitHub': 'התחברות ל-GitHub',
  'Connect to GitLab': 'התחברות ל-GitLab',
  'Console user': 'משתמש קונסולה',
  'Contact Support': 'פנייה לתמיכה',
  'Contact sales': 'פנייה למכירות',
  Content: 'תוכן',
  'Cookie preferences': 'העדפות עוגיות',
  'Cookies Policy': 'מדיניות העוגיות',
  'Copied line': 'השורה הועתקה',
  'Copied to clipboard': 'הועתק ללוח',
  Copy: 'העתקה',
  'Copy ID': 'העתקת מזהה',
  'Copy as JSON': 'העתקה כ-JSON',
  'Copy branch name': 'העתקת שם הענף',
  'Copy commit hash': 'העתקת ה-hash של הקומיט',
  'Copy key': 'העתקת מפתח',
  'Copy link': 'העתקת קישור',
  'Copy logs': 'העתקת לוגים',
  'Copy prompt': 'העתקת פרומפט',
  'Copy value': 'העתקת ערך',
  'Could not start impersonation.': 'לא ניתן היה להתחיל התחזות.',
  Create: 'יצירה',
  'Create a new Git repository and clone the template into it.':
    'יצירת Git repo חדש ושכפול התבנית לתוכו.',
  'Create new repository': 'יצירת repo חדש',
  'Create variable': 'יצירת משתנה',
  Created: 'נוצר',
  Customize: 'התאמה אישית',
  'Dark screenshot': 'צילום מסך כהה',
  'Date / time': 'תאריך / שעה',
  Decimal: 'עשרוני',
  Delete: 'מחיקה',
  'Delete deployment': 'מחיקת פריסה',
  'Delete saved filter': 'מחיקת סינון שמור',
  'Delete saved preset': 'מחיקת פריסט שמור',
  'Delete variable': 'מחיקת משתנה',
  Deploy: 'פריסה',
  Deployed: 'נפרס',
  'Deployed:': 'נפרס:',
  Deployment: 'פריסה',
  'Deployment ID': 'מזהה פריסה',
  'Deployment actions': 'פעולות פריסה',
  'Deployment activated successfully': 'הפריסה הופעלה בהצלחה',
  'Deployment deleted successfully': 'הפריסה נמחקה בהצלחה',
  'Deployment not found': 'הפריסה לא נמצאה',
  'Deployment rebuild started': 'הבנייה מחדש של הפריסה החלה',
  'Deployment screenshot': 'צילום מסך של הפריסה',
  Deprecated: 'הוצא משימוש',
  Desc: 'יורד',
  Descending: 'סדר יורד',
  'Deselect all': 'ביטול בחירת הכול',
  Details: 'פרטים',
  Disabled: 'מושבת',
  'Discord Community': 'קהילת Discord',
  Dismiss: 'סגירה',
  Domain: 'דומיין',
  'Domain is available': 'הדומיין זמין',
  Done: 'סיום',
  Download: 'הורידו',
  'Download build output': 'הורדת פלט הבנייה',
  'Download logs': 'הורדת לוגים',
  'Download source code': 'הורדת קוד המקור',
  'Download started': 'ההורדה החלה',
  'Download, redeploy, activate, cancel or delete this deployment.':
    'הורדה, פריסה מחדש, הפעלה, ביטול או מחיקה של הפריסה הזו.',
  Drawer: 'חלונית צד',
  Duration: 'משך',
  'Duration:': 'משך:',
  'Edit all variables at once. Secret variables are not shown and will not be affected.':
    'עריכת כל המשתנים בבת אחת. משתנים סודיים אינם מוצגים ולא יושפעו.',
  'Edit manually': 'עריכה ידנית',
  Editor: 'עורך',
  Enabled: 'מופעל',
  End: 'סוף',
  'Enter text that matches the start of a name, email, phone, or user ID.':
    'הזינו טקסט התואם את תחילת השם, האימייל, הטלפון או מזהה המשתמש.',
  'Enter value': 'הזינו ערך',
  'Show value': 'הצגת ערך',
  'Hide value': 'הסתרת ערך',
  'Enterprise & 24/7 support': 'תמיכת אנטרפרייז מסביב לשעון',
  Essential: 'חיוניות',
  'Execute function': 'הרצת פונקציה',
  'Execution ID': 'מזהה הרצה',
  Exit: 'יציאה',
  'Exit impersonation': 'יציאה מהתחזות',
  'Expanded image': 'תמונה מוגדלת',

  // Errors and failures
  'Failed to activate deployment': 'הפעלת הפריסה נכשלה',
  'Failed to cancel build': 'ביטול הבנייה נכשל',
  'Failed to copy': 'ההעתקה נכשלה',
  'Failed to copy logs': 'העתקת הלוגים נכשלה',
  'Failed to copy prompt': 'העתקת הפרומפט נכשלה',
  'Failed to copy to clipboard': 'ההעתקה ללוח נכשלה',
  'Failed to create repository': 'יצירת ה-repo נכשלה',
  'Failed to create variable': 'יצירת המשתנה נכשלה',
  'Failed to delete deployment': 'מחיקת הפריסה נכשלה',
  'Failed to delete variable': 'מחיקת המשתנה נכשלה',
  'Failed to download build output': 'הורדת פלט הבנייה נכשלה',
  'Failed to download source code': 'הורדת קוד המקור נכשלה',
  'Failed to import variables': 'ייבוא המשתנים נכשל',
  'Failed to load scopes': 'טעינת ההרשאות נכשלה',
  'Failed to mark variable as secret': 'סימון המשתנה כסודי נכשל',
  'Failed to redeploy': 'הפריסה מחדש נכשלה',
  'Failed to save variables': 'שמירת המשתנים נכשלה',
  'Failed to submit feedback': 'שליחת המשוב נכשלה',
  'Failed to update variable': 'עדכון המשתנה נכשל',

  False: 'False',
  Feedback: 'משוב',
  'Feedback is not configured. Set VITE_GROWTH_ENDPOINT in .env to enable submission.':
    'משוב אינו מוגדר. הגדירו VITE_GROWTH_ENDPOINT בקובץ ‎.env כדי לאפשר שליחה.',
  File: 'קובץ',
  'File uploaded': 'הקובץ הועלה',
  'Filter name': 'שם הסינון',
  Filters: 'סינונים',
  'Find a branch...': 'חיפוש ענף...',
  'Fix payment': 'הסדרת התשלום',
  'Fix with an Agent': 'תיקון עם סוכן',
  'For me': 'עבורי',
  'For team': 'עבור הצוות',
  Function: 'פונקציה',
  Functions: 'פונקציות',
  'Get help from our support team': 'קבלו עזרה מצוות התמיכה שלנו',
  'Get started by creating your first item.':
    'התחילו ביצירת הפריט הראשון שלכם.',
  'Organization': 'ארגון',
  'Git repository': 'Git repo',
  'GitHub Issues': 'GitHub Issues',
  'GitHub repository': 'GitHub repo',
  'GitLab repository': 'GitLab repo',
  'Go to first page': 'לעמוד הראשון',
  'Go to last page': 'לעמוד האחרון',
  'Go to next page': 'לעמוד הבא',
  'Go to previous page': 'לעמוד הקודם',
  'Go to query tab': 'מעבר ללשונית שאילתה',
  'Help us improve your experience': 'עזרו לנו לשפר את החוויה שלכם',
  "If selected, you and your team won't be able to read the values after creation.":
    'אם תסומן אפשרות זו, אתם והצוות שלכם לא תוכלו לקרוא את הערכים לאחר היצירה.',
  Impersonate: 'התחזות',
  'Impersonate user': 'התחזות למשתמש',
  Impersonating: 'במצב התחזות',
  'Impersonation active. Operating as': 'התחזות פעילה. פועלים בתור',
  'Impersonation active. Operating as another console user. Exit to return to your operator session.':
    'התחזות פעילה. פועלים בתור משתמש קונסולה אחר. צאו כדי לחזור לסשן המפעיל שלכם.',
  Import: 'ייבוא',
  'Import .env': 'ייבוא ‎.env',
  'Import .env file': 'ייבוא קובץ ‎.env',
  Integer: 'מספר שלם',
  'Invalid JSON format': 'פורמט JSON לא תקין',
  'Invalid format': 'פורמט לא תקין',
  'Join 24k+ developers': 'הצטרפו ליותר מ-24 אלף מפתחים',
  'Skip for now': 'דילוג לעכשיו',
  'Help other Appwriters on Discord and grow with the community.':
    'עזרו ל-Appwriters אחרים ב-Discord וצמחו יחד עם הקהילה.',
  'Star us on GitHub': 'תנו לנו כוכב ב-GitHub',
  'A star helps more developers discover Appwrite.':
    'כוכב עוזר ליותר מפתחים לגלות את Appwrite.',
  'Spread the word on X': 'שתפו ב-X',
  'Tell others what you are building with Appwrite.':
    'ספרו לאחרים מה אתם בונים עם Appwrite.',
  'Write content': 'כתיבת תוכן',
  'Publish blogs, videos, or tutorials that help developers discover Appwrite.':
    'פרסמו בלוגים, סרטונים או מדריכים שעוזרים למפתחים לגלות את Appwrite.',
  'Join the Affiliates program': 'הצטרפות לתוכנית האפיליאייטס',
  'Share invite links and earn credits when developers upgrade to Pro.':
    'שתפו קישורי הזמנה והרוויחו קרדיטים כשמפתחים משדרגים ל-Pro.',
  'Build integrations': 'בניית אינטגרציות',
  'Connect Appwrite to the tools your stack already uses.':
    'חברו את Appwrite לכלים שכבר נמצאים בסטאק שלכם.',
  'A note from the team': 'הערה מהצוות',
  'Hey,': 'היי,',
  'Sorry to interrupt. We know you came here to build, not to read a message from us.':
    'סליחה על ההפרעה. אנחנו יודעים שבאתם לכאן כדי לבנות, לא כדי לקרוא הודעה מאיתנו.',
  'We are a product-obsessed team. Our job is to make Appwrite something you love building on. The part we cannot do alone is spreading the word and welcoming the next wave of developers.':
    'אנחנו צוות אובססיבי למוצר. התפקיד שלנו הוא להפוך את Appwrite למשהו שתאהבו לבנות עליו. את מה שאי אפשר לעשות לבד, הפצה וקבלת המפתחים הבאים, אנחנו צריכים אתכם.',
  'If you have a minute, here is how you can help. If not, skip and get back to work.':
    'אם יש לכם דקה, כך אפשר לעזור. אם לא, דלגו וחזרו לעבודה.',
  'Write something true to your experience, or start from one of these examples.':
    'כתבו משהו אמיתי מהחוויה שלכם, או התחילו מאחת הדוגמאות האלה.',
  'Try another example': 'דוגמה אחרת',
  'Share message': 'הודעה לשיתוף',
  'Spread the word': 'הפיצו את הבשורה',
  'Thank you for building with us.': 'תודה שאתם בונים איתנו.',
  'Just now': 'הרגע',
  'Ago relative time': 'לפני',
  'In relative time': 'בעוד',
  minute: 'דקה',
  minutes: 'דקות',
  hour: 'שעה',
  hours: 'שעות',
  day: 'יום',
  days: 'ימים',
  week: 'שבוע',
  weeks: 'שבועות',
  month: 'חודש',
  months: 'חודשים',
  year: 'שנה',
  years: 'שנים',
  'Keep building': 'המשך בנייה',
  'Keep repository private': 'השארת ה-repo פרטי',
  Key: 'מפתח',
  'Keyboard shortcuts': 'קיצורי מקלדת',
  'Learn more': 'למדו עוד',
  'Light screenshot': 'צילום מסך בהיר',
  Line: 'שורה',
  'Link this deployment to an existing repository.':
    'קישור הפריסה הזו ל-repo קיים.',
  'List order': 'סדר הרשימה',
  'Live traffic uses the active deployment until you activate this one.':
    'תעבורה חיה משתמשת בפריסה הפעילה עד שתפעילו את הפריסה הזו.',
  'Loading branches...': 'טוען ענפים...',
  'Loading deployment...': 'טוען פריסה...',
  'Loading files…': 'טוען קבצים…',
  'Loading logs': 'טוען לוגים',
  'Loading scopes…': 'טוען הרשאות…',
  'Loading users': 'טוען משתמשים',
  'Loading...': 'טוען...',
  'Loading…': 'טוען…',
  Local: 'מקומי',
  'Logs copied to clipboard': 'הלוגים הועתקו ללוח',
  'Logs downloaded': 'הלוגים הורדו',
  Manual: 'ידני',
  'Manual deployments are created by uploading code through the Console or API, or by redeploying an existing deployment. Useful for quick testing and re-running builds.':
    'פריסות ידניות נוצרות על ידי העלאת קוד דרך הקונסולה או ה-API, או על ידי פריסה מחדש של פריסה קיימת. שימושי לבדיקות מהירות ולהרצה חוזרת של בניות.',
  'Mark all as secret': 'סימון הכול כסודי',
  'Mark as secret': 'סימון כסודי',
  "Matches the start of name, email, phone, or user ID. The Console runs with the selected account's access until you end impersonation.":
    'מתאים לתחילת שם, אימייל, טלפון או מזהה משתמש. הקונסולה פועלת עם הרשאות החשבון הנבחר עד לסיום ההתחזות.',
  Max: 'מקסימום',
  Method: 'מתודה',
  Min: 'מינימום',
  'Min. 3 characters': 'לפחות 3 תווים',
  'Mon–Fri': 'שני עד שישי',
  'More info': 'מידע נוסף',
  'More options': 'אפשרויות נוספות',
  'My filters': 'הסינונים שלי',
  'Name, email, phone, or user ID…': 'שם, אימייל, טלפון או מזהה משתמש…',
  'Need more resources?': 'צריכים משאבים נוספים?',
  'Next image': 'התמונה הבאה',

  // Empty states
  No: 'אין',
  'No API key scopes are available from the server.':
    'אין הרשאות מפתח API זמינות מהשרת.',
  'No branches available': 'אין ענפים זמינים',
  'No branches found': 'לא נמצאו ענפים',
  'No buckets': 'אין באקטים',
  'No build logs available.': 'אין לוגים של בנייה זמינים.',
  'No columns': 'אין עמודות',
  'No columns found': 'לא נמצאו עמודות',
  'No file selected': 'לא נבחר קובץ',
  'No files in this bucket': 'אין קבצים בבאקט הזה',
  'No files match your search': 'אין קבצים התואמים את החיפוש',
  'No functions found': 'לא נמצאו פונקציות',
  'No functions found.': 'לא נמצאו פונקציות.',
  'No sites found': 'לא נמצאו אתרים',
  'No items found': 'לא נמצאו פריטים',
  'No items match your criteria.': 'אין פריטים התואמים את הקריטריונים.',
  'No items yet': 'אין פריטים עדיין',
  'No logs to copy': 'אין לוגים להעתקה',
  'No logs to download': 'אין לוגים להורדה',
  'No matching users': 'אין משתמשים תואמים',
  'No operators found': 'לא נמצאו אופרטורים',
  'No preview': 'אין תצוגה מקדימה',
  'No projects found': 'לא נמצאו פרויקטים',
  'No proxy rules reference this deployment.':
    'אין כללי פרוקסי המפנים לפריסה זו.',
  'No query tabs found': 'לא נמצאו לשוניות שאילתה',
  'No query tabs found.': 'לא נמצאו לשוניות שאילתה.',
  'No repositories available for this installation':
    'אין repos זמינים להתקנה זו',
  'No repositories found': 'לא נמצאו repos',
  'No results': 'אין תוצאות',
  'No results found': 'לא נמצאו תוצאות',
  'No results found.': 'לא נמצאו תוצאות.',
  'No saved filters yet. Add filters in the Filters tab and save them here for quick access.':
    'אין עדיין סינונים שמורים. הוסיפו סינונים בלשונית הסינונים ושמרו אותם כאן לגישה מהירה.',
  'No scopes match your search.': 'אין הרשאות התואמות את החיפוש.',
  'No types': 'אין סוגים',
  'No units found': 'לא נמצאו יחידות',
  'No users to show': 'אין משתמשים להצגה',
  'No values found': 'לא נמצאו ערכים',
  'No variables found': 'לא נמצאו משתנים',
  'Not ready': 'לא מוכן',
  Number: 'מספר',
  Off: 'כבוי',
  Offline: 'לא מקוון',
  "Once marked as secret, you and your team won't be able to read this variable's value. This action cannot be undone.":
    'לאחר סימון כסודי, אתם והצוות שלכם לא תוכלו לקרוא את ערך המשתנה הזה. פעולה זו אינה ניתנת לביטול.',
  Online: 'מקוון',
  'Only owners and developers can save team-level filters.':
    'רק בעלים ומפתחים יכולים לשמור סינונים ברמת הצוות.',
  'Open in new tab': 'פתיחה בכרטיסייה חדשה',
  'Open in new window': 'פתיחה בחלון חדש',
  Operator: 'אופרטור',
  'Operator context was lost. Stop impersonating, then start again.':
    'פרטי המפעיל אבדו. עצרו את ההתחזות והתחילו שוב.',
  'Order from the Appwrite Store': 'הזמנה מה-Appwrite Store', // pragma: allowlist secret
  Overview: 'סקירה כללית',
  Page: 'עמוד',
  Path: 'נתיב',
  'Payment failed - act now. Unresolved billing may interrupt your projects and services.':
    'התשלום נכשל - פעלו עכשיו. בעיית חיוב שלא נפתרה עלולה לשבש את הפרויקטים והשירותים שלכם.',
  'Payment failed - your organization has restricted access due to an unresolved billing issue. Changes to projects and services are restricted until payment succeeds. Update billing to restore full access.':
    'התשלום נכשל - לארגון שלכם יש גישה מוגבלת עקב בעיית חיוב שלא נפתרה. שינויים בפרויקטים ובשירותים מוגבלים עד להשלמת התשלום. עדכנו את פרטי החיוב כדי לשחזר גישה מלאה.',
  Preview: 'תצוגה מקדימה',
  Bold: 'מודגש',
  Italic: 'נטוי',
  Link: 'קישור',
  'Bullet list': 'רשימת תבליטים',
  'Numbered list': 'רשימה ממוספרת',
  'bold text': 'טקסט מודגש',
  'italic text': 'טקסט נטוי',
  code: 'קוד',
  'link text': 'טקסט קישור',
  'list item': 'פריט ברשימה',
  'Nothing to preview': 'אין מה להציג בתצוגה מקדימה',
  'Previous image': 'התמונה הקודמת',
  // pragma: allowlist secret
  'Privacy-friendly usage analytics and error reporting to help us improve Appwrite.':
    'אנליטיקת שימוש ודיווח שגיאות ששומרים על פרטיות ועוזרים לנו לשפר את Appwrite.', // pragma: allowlist secret
  Prompt: 'פרומפט',
  'Prompt copied to clipboard': 'הפרומפט הועתק ללוח',
  'Query tab': 'לשונית שאילתה',
  'Query tabs': 'לשוניות שאילתה',
  Recent: 'אחרונים',
  Redeploy: 'פריסה מחדש',
  'Redeploy deployment': 'פריסה מחדש',
  'Redeploy is not available for this deployment type':
    'פריסה מחדש אינה זמינה לסוג פריסה זה',
  Refresh: 'רענון',
  'Refresh repositories': 'רענון repos',
  'Reject non-essential': 'דחיית עוגיות לא חיוניות',
  'Remove filter': 'הסרת סינון',
  'Remove scope': 'הסרת הרשאה',
  Rename: 'שינוי שם',
  'Rename saved filter': 'שינוי שם סינון שמור',
  'Rename saved preset': 'שינוי שם פריסט שמור',
  'Report bugs or request features': "דיווח על באגים או בקשת פיצ'רים",
  Repository: 'Repo',
  'Repository name': 'שם ה-repo',
  'Required for sign-in, site access, security, and remembering your preferences.':
    'נדרשות להתחברות, לגישה לאתר, לאבטחה ולשמירת ההעדפות שלכם.',
  Reset: 'איפוס',
  Row: 'שורה',
  'Rows per page': 'שורות בעמוד',
  Runtime: 'סביבת ריצה',
  'Same as saved filter': 'זהה לסינון השמור',
  'Same name as a project variable': 'שם זהה למשתנה פרויקט',
  'Save current filters with a name:': 'שמירת הסינונים הנוכחיים עם שם:',
  'Save filter': 'שמירת סינון',
  'Save for later': 'שמירה לאחר כך',
  'Save preferences': 'שמירת העדפות',
  Saved: 'שמורים',
  'Saved filter name': 'שם הסינון השמור',
  'Saved preset name': 'שם הפריסט השמור',
  Scope: 'הרשאת גישה',
  Scopes: 'הרשאות גישה',
  'Scroll to bottom': 'גלילה לתחתית',
  'Scroll to top': 'גלילה לראש',

  // Search
  'Search account, sessions, security...': 'חיפוש חשבון, סשנים, אבטחה…',
  'Search anything - pages, tabs, settings, resources...':
    'חיפוש בכל מקום - עמודים, לשוניות, הגדרות, משאבים…',
  'Search columns...': 'חיפוש עמודות…',
  'Search columns by key or ID...': 'חיפוש עמודות לפי מפתח או מזהה…',
  'Search commands and documentation pages...':
    'חיפוש פקודות ועמודי דוקומנטציה…',
  'Search databases...': 'חיפוש מסדי נתונים…',
  'Search databases by name or ID...': 'חיפוש מסדי נתונים לפי שם או מזהה…',
  'Search documentation...': 'חיפוש בדוקומנטציה...',
  'Search domains...': 'חיפוש דומיינים…',
  'Search domains by name or ID...': 'חיפוש דומיינים לפי שם או מזהה…',
  'Search files...': 'חיפוש קבצים...',
  'Search files by name or ID...': 'חיפוש קבצים לפי שם או מזהה…',
  'Search functions...': 'חיפוש פונקציות…',
  'Search functions by name or ID...': 'חיפוש פונקציות לפי שם או מזהה…',
  'Search indexes by key or ID...': 'חיפוש אינדקסים לפי מפתח או מזהה…',
  'Search logs...': 'חיפוש בלוגים...',
  'Search operators...': 'חיפוש אופרטורים…',
  'Search projects, settings, members...': 'חיפוש פרויקטים, הגדרות, חברי צוות…',
  'Search projects...': 'חיפוש פרויקטים…',
  'Search providers...': 'חיפוש ספקים…',
  'Search providers by name or ID...': 'חיפוש ספקים לפי שם או מזהה…',
  'Search rows by ID...': 'חיפוש שורות לפי מזהה…',
  'Search sites...': 'חיפוש אתרים…',
  'Search sites by name or ID...': 'חיפוש אתרים לפי שם או מזהה…',
  'Search tables by name or ID...': 'חיפוש טבלאות לפי שם או מזהה…',
  'Search teams...': 'חיפוש צוותים…',
  'Search teams by name or ID...': 'חיפוש צוותים לפי שם או מזהה…',
  'Search topics...': 'חיפוש נושאים…',
  'Search topics by name or ID...': 'חיפוש נושאים לפי שם או מזהה…',
  'Search users...': 'חיפוש משתמשים…',
  'Search buckets by name or ID...': 'חיפוש באקטים לפי שם או מזהה…',
  'Search query tabs...': 'חיפוש לשוניות שאילתה…',
  'Search repositories...': 'חיפוש repos...',
  'Search scopes...': 'חיפוש הרשאות…',
  'Search templates...': 'חיפוש תבניות...',
  'Search units...': 'חיפוש יחידות…',
  'Search values...': 'חיפוש ערכים…',
  'Searching resources…': 'מחפש משאבים…',
  'Search...': 'חיפוש…',
  'Select…': 'בחירה…',
  Select: 'בחירה',
  Selection: 'בחירה',
  Enter: 'הזינו',
  Optional: 'אופציונלי',
  Searching: 'מחפש',
  'Select database': 'בחירת מסד נתונים',

  Secret: 'סודי',
  'Select a bucket to browse files': 'בחרו באקט כדי לעיין בקבצים',
  'Select a service to build': 'בחרו שירות לבנייה',
  'Select all': 'בחירת הכול',
  'Select function to execute...': 'בחרו פונקציה להרצה...',
  'Select line': 'בחירת שורה',
  'Select organization': 'בחירת ארגון',
  'Select root directory': 'בחירת ספריית שורש',
  'Select shown': 'בחירת המוצגים',
  Selected: 'נבחר',
  'Send feedback': 'שליחת משוב',
  'Share your feedback...': 'שתפו את המשוב שלכם...',
  Show: 'הצגה',
  Showing: 'מציג',
  Size: 'גודל',
  'Sort field': 'שדה מיון',
  'Source code': 'קוד מקור',
  Start: 'התחלה',
  Status: 'סטטוס',
  'Status Code': 'קוד סטטוס',
  'Stop the current deployment? You can deploy again later.':
    'לעצור את הפריסה הנוכחית? אפשר לפרוס שוב מאוחר יותר.',
  Submit: 'שליחה',
  Support: 'תמיכה',
  'Support hours': 'שעות תמיכה',
  'Supports optional impersonation.': 'תומך בהתחזות אופציונלית.',
  'System status': 'סטטוס מערכת',
  Tab: 'לשונית',
  'Team filters': 'סינוני צוות',
  Text: 'טקסט',
  'Thank you!': 'תודה!',
  'That user is already the active Console session.':
    'המשתמש הזה הוא כבר הסשן הפעיל בקונסולה.',
  'The Appwriter mechanical keyboard': 'המקלדת המכנית The Appwriter', // pragma: allowlist secret
  'The active deployment cannot be deleted from the list':
    'לא ניתן למחוק את הפריסה הפעילה מהרשימה',
  // pragma: allowlist secret
  "The console needs an internet connection to reach Appwrite's data centers. We'll restore the page automatically when you are back online.":
    'הקונסולה זקוקה לחיבור אינטרנט כדי להגיע למרכזי הנתונים של Appwrite. נשחזר את העמוד אוטומטית כשתחזרו להיות מקוונים.', // pragma: allowlist secret
  'The deployment type indicates how this deployment was created.':
    'סוג הפריסה מציין כיצד הפריסה הזו נוצרה.',
  'This deployment is already active.': 'הפריסה הזו כבר פעילה.',
  'This feature is coming soon': "הפיצ'ר הזה יגיע בקרוב",
  'This organization has reached its budget limit and is now blocked. To continue using Appwrite services, update the budget limit.':
    'הארגון הגיע למגבלת התקציב והוא חסום כעת. כדי להמשיך להשתמש בשירותי Appwrite, עדכנו את מגבלת התקציב.',
  'This organization has reached its budget limit and is now blocked. Increase the budget cap below to restore access to billable services.':
    'הארגון הגיע למגבלת התקציב והוא חסום כעת. העלו את תקרת התקציב למטה כדי לשחזר גישה לשירותים החייבים בחיוב.',
  'This organization has reached its plan limit for':
    'הארגון הגיע למגבלת התוכנית עבור',
  '. Upgrade your plan or wait until the end of the billing cycle to restore access.':
    '. שדרגו את התוכנית או המתינו עד סוף מחזור החיוב כדי לשחזר גישה.',
  'This organization has reached its plan usage limit and is now blocked. Upgrade your plan or wait until the end of the billing cycle to restore access.':
    'הארגון הגיע למגבלת השימוש של התוכנית והוא חסום כעת. שדרגו את התוכנית או המתינו עד סוף מחזור החיוב כדי לשחזר גישה.',
  'Plan limit reached': 'מגבלת התוכנית הושגה',
  'Phone auth': 'אימות טלפון',
  'View current cycle usage': 'צפייה בשימוש במחזור הנוכחי',
  'Organization usage': 'שימוש בארגון',
  Screenshots: 'צילומי מסך',
  'Realtime connections': 'חיבורי Realtime',
  'Realtime messages': 'הודעות Realtime',
  'This section is under construction': 'החלק הזה נמצא בבנייה',
  'This user has no valid ID; pick another user.':
    'למשתמש הזה אין מזהה תקין; בחרו משתמש אחר.',
  "This will create a new build for this deployment using the current function configuration. The original deployment's code will be preserved and used for the new build.":
    'פעולה זו תיצור בנייה חדשה לפריסה זו עם תצורת הפונקציה הנוכחית. קוד הפריסה המקורי יישמר וישמש לבנייה החדשה.',
  "This will create a new build for this deployment using the current site configuration. The original deployment's code will be preserved and used for the new build.":
    'פעולה זו תיצור בנייה חדשה לפריסה זו עם תצורת האתר הנוכחית. קוד הפריסה המקורי יישמר וישמש לבנייה החדשה.',
  'This will switch the active deployment to this one. All traffic will be routed to this deployment once activated.':
    'פעולה זו תחליף את הפריסה הפעילה לפריסה זו. כל התעבורה תנותב לפריסה זו לאחר ההפעלה.',
  Time: 'שעה',
  Trigger: 'טריגר',
  True: 'True',
  'Try a different prefix for name, email, phone, or user ID.':
    'נסו קידומת אחרת של שם, אימייל, טלפון או מזהה משתמש.',
  'Try a different search or upload a file.': 'נסו חיפוש אחר או העלו קובץ.',
  'Try a different search term or installation':
    'נסו מונח חיפוש אחר או התקנה אחרת',
  'Try adjusting your search or filters to see more results.':
    'נסו לשנות את החיפוש או הסינונים כדי לראות תוצאות נוספות.',
  Type: 'סוג',
  'Type to search all branches': 'הקלידו כדי לחפש בכל הענפים',
  'URL copied': 'הכתובת הועתקה',
  URLs: 'כתובות URL',
  Unit: 'יחידה',
  Unknown: 'לא ידוע',
  'Unmark secret': 'ביטול סימון כסודי',
  Update: 'עדכון',
  'Update GitHub permissions': 'עדכון הרשאות GitHub',
  'Update GitLab permissions': 'עדכון הרשאות GitLab',
  'Update filter': 'עדכון סינון',
  'Update limit': 'עדכון המגבלה',
  'Update the value of this variable. The key cannot be changed.':
    'עדכון ערך המשתנה הזה. לא ניתן לשנות את המפתח.',
  'Update variable': 'עדכון משתנה',
  Upgrade: 'שדרוג',
  'Upgrade your plan to get email support.':
    'שדרגו את התוכנית כדי לקבל תמיכה באימייל.',
  Upload: 'העלאה',
  'Upload a .env file to import variables. Existing variables with the same key will be updated.':
    'העלו קובץ ‎.env כדי לייבא משתנים. משתנים קיימים עם אותו מפתח יעודכנו.',
  'Upload a file to this bucket or choose another bucket.':
    'העלו קובץ לבאקט הזה או בחרו באקט אחר.',
  'Use preset columns for $id and other system fields. Custom names must not start with $.':
    'השתמשו בעמודות מוגדרות מראש עבור ‎$id ושדות מערכת אחרים. שמות מותאמים אישית אסור שיתחילו ב-$.',
  User: 'משתמש',
  'VCS (Version Control System) deployments are triggered from a connected Git repository and enable automatic deployments on code pushes.':
    'פריסות VCS (מערכת ניהול גרסאות) מופעלות מ-Git repo מחובר ומאפשרות פריסות אוטומטיות בעת דחיפת קוד.',
  Value: 'ערך',
  'Value type': 'סוג ערך',
  Variable: 'משתנה',
  'Variable editor': 'עורך משתנים',
  'Variable value is longer than 8192 allowed characters':
    'ערך המשתנה ארוך מ-8192 התווים המותרים',
  'Variables have been updated.': 'המשתנים עודכנו.',
  'Variables have been uploaded.': 'המשתנים הועלו.',
  'View file': 'צפייה בקובץ',
  'View full value': 'צפייה בערך המלא',
  'Wait for the build to finish or cancel it first':
    'המתינו לסיום הבנייה או בטלו אותה קודם',
  'Waiting for build logs...': 'ממתין ללוגים של הבנייה...',
  // pragma: allowlist secret
  'We use essential cookies to keep you signed in, manage site access, and remember your preferences. With your permission, we also use analytics to understand how Appwrite is used and improve it. Read our': // pragma: allowlist secret
    'אנחנו משתמשים בעוגיות חיוניות כדי לשמור אתכם מחוברים, לנהל גישה לאתר ולזכור את ההעדפות שלכם. באישורכם, אנחנו משתמשים גם באנליטיקה כדי להבין איך Appwrite בשימוש ולשפר אותו. קראו את', // pragma: allowlist secret
  'We value your privacy': 'הפרטיות שלכם חשובה לנו',
  'You cannot impersonate your own operator account.':
    'לא ניתן להתחזות לחשבון המפעיל של עצמכם.',
  "You're offline": 'אין חיבור לאינטרנט',
  'Your feedback helps us improve.': 'המשוב שלכם עוזר לנו להשתפר.',
  'another console user': 'משתמש קונסולה אחר',
  '75% hot-swap mechanical keyboard with Gateron G Pro Yellow switches, tri-mode USB-C/2.4GHz/BT, and 84 dye-sublimated keycaps optimized for Console shortcuts.':
    'מקלדת מכנית 75% עם מקשים ניתנים להחלפה, מתגי Gateron G Pro Yellow, חיבור משולש USB-C/2.4GHz/BT ו-84 מקשי dye-sublimation מותאמים לקיצורי הקונסולה.',

  // Dynamic pass-through values: pagination item labels
  items: 'פריטים',
  executions: 'הרצות',
  rows: 'שורות',
  files: 'קבצים',
  logs: 'לוגים',
  users: 'משתמשים',

  // Dynamic pass-through values: execution statuses and triggers
  Completed: 'הושלם',
  Processing: 'בעיבוד',
  Failed: 'נכשל',
  Waiting: 'ממתין',
  Scheduled: 'מתוזמן',
  Schedule: 'תזמון',
  Event: 'אירוע',

  // Dynamic pass-through values: defaults used by shared components
  'No executions yet': 'אין הרצות עדיין',
  'Executions will appear here when your function runs.':
    'הרצות יופיעו כאן כשהפונקציה שלכם תרוץ.',
  'This scope is on the API key but was not returned in the server scope list.':
    'הרשאה זו נמצאת על מפתח ה-API אך לא הוחזרה ברשימת ההרשאות מהשרת.',
  'Loading rows…': 'טוען שורות…',
  'No rows to display.': 'אין שורות להצגה.',
  'No matching settings': 'אין הגדרות תואמות',
  'Select project': 'בחירת פרויקט',
  'Select date & time': 'בחירת תאריך ושעה',
  'Select branch': 'בחירת Branch',
  'Clone template': 'שכפול תבנית',
  'Upgrade your plan': 'שדרגו את התוכנית',
  Screenshot: 'צילום מסך',
  'Payment failed - update billing to avoid interrupting your projects and services.':
    'התשלום נכשל - עדכנו את פרטי החיוב כדי למנוע שיבוש בפרויקטים ובשירותים שלכם.',
  'Payment failed - this organization has restricted access until the outstanding invoice is paid. Project and service changes are limited; open Billing to update payment and restore access.':
    'התשלום נכשל - לארגון יש גישה מוגבלת עד לתשלום החשבונית הפתוחה. שינויים בפרויקטים ובשירותים מוגבלים; פתחו את החיוב כדי לעדכן תשלום ולשחזר גישה.',

  // Dynamic pass-through values: system status panel
  Operational: 'תקין',
  Maintenance: 'תחזוקה',
  Unavailable: 'לא זמין',
  Degraded: 'ביצועים ירודים',
  'All services are available.': 'כל השירותים זמינים.',
  'Maintenance is in progress.': 'תחזוקה מתבצעת כעת.',
  'Some services may be unavailable right now.':
    'ייתכן שחלק מהשירותים אינם זמינים כרגע.',
  'Some services are experiencing issues.': 'חלק מהשירותים חווים תקלות.',
  'Several services may be affected while we restore them.':
    'ייתכן שכמה שירותים מושפעים בזמן שאנחנו משחזרים אותם.',
  'Planned maintenance window in progress.': 'חלון תחזוקה מתוכנן מתבצע כעת.',
  'A subset of services is degraded.': 'חלק מהשירותים פועלים באופן חלקי.',

  // Dynamic pass-through values: filter operator labels
  equal: 'שווה ל-',
  'not equal': 'שונה מ-',
  'starts with': 'מתחיל ב-',
  'not starts with': 'לא מתחיל ב-',
  'ends with': 'מסתיים ב-',
  'not ends with': 'לא מסתיים ב-',
  contains: 'מכיל',
  'not contains': 'לא מכיל',
  search: 'תואם חיפוש',
  'does not match search': 'לא תואם חיפוש',
  'matches regex': 'תואם ביטוי רגולרי',
  'greater than': 'גדול מ-',
  'greater than or equal': 'גדול או שווה ל-',
  'less than': 'קטן מ-',
  'less than or equal': 'קטן או שווה ל-',
  between: 'בין',
  'not between': 'לא בין',
  'is null': 'ריק',
  'is not null': 'אינו ריק',
  exists: 'קיים',
  'does not exist': 'לא קיים',
  Bytes: 'בייטים',

  // Scoped variable toast templates (known scope labels)
  'Function variable has been created.': 'משתנה הפונקציה נוצר.',
  'Function variable has been updated.': 'משתנה הפונקציה עודכן.',
  'Function variable has been deleted.': 'משתנה הפונקציה נמחק.',
  'Function variable has been marked as secret.': 'משתנה הפונקציה סומן כסודי.',
  'Site variable has been created.': 'משתנה האתר נוצר.',
  'Site variable has been updated.': 'משתנה האתר עודכן.',
  'Site variable has been deleted.': 'משתנה האתר נמחק.',
  'Site variable has been marked as secret.': 'משתנה האתר סומן כסודי.',
  'Project variable has been created.': 'משתנה הפרויקט נוצר.',
  'Project variable has been updated.': 'משתנה הפרויקט עודכן.',
  'Project variable has been deleted.': 'משתנה הפרויקט נמחק.',
  'Project variable has been marked as secret.': 'משתנה הפרויקט סומן כסודי.',
  ' variable has been created.': 'המשתנה נוצר.',
  ' variable has been updated.': 'המשתנה עודכן.',
  ' variable has been deleted.': 'המשתנה נמחק.',
  ' variable has been marked as secret.': 'המשתנה סומן כסודי.',

  // Organization selector
  'Search organizations...': 'חיפוש ארגונים…',
  'No organizations found': 'לא נמצאו ארגונים',

  // Theme toggle
  Theme: 'ערכת נושא',
  'Light theme': 'ערכת נושא בהירה',
  'Dark theme': 'ערכת נושא כהה',
  'System theme': 'ערכת נושא לפי המערכת',

  // Refresh toasts (default label)
  'data refreshed successfully': 'הנתונים רועננו בהצלחה',
  'Failed to refresh data': 'רענון הנתונים נכשל',

  // Keyboard shortcuts reference: group titles
  Navigation: 'ניווט',
  Help: 'עזרה',
  Tabs: 'לשוניות',
  'Settings & cards': 'הגדרות וכרטיסים',

  // Keyboard shortcuts reference: global shortcut descriptions
  'Open command center': 'פתיחת ה-Command Center',
  'Show keyboard shortcuts': 'הצגת קיצורי המקלדת',
  'Close / go back': 'סגירה / חזרה',
  'Focus search': 'מעבר לשדה החיפוש',
  'Set theme to light': 'החלפה לערכת נושא בהירה',
  'Set theme to dark': 'החלפה לערכת נושא כהה',
  'Set theme to system': 'החלפה לערכת נושא לפי המערכת',

  // Keyboard shortcuts reference: terminal shortcut descriptions
  'Toggle terminal': 'פתיחה או סגירה של הטרמינל',
  'Enter full screen': 'מעבר למסך מלא',
  'Move to start of line': 'מעבר לתחילת השורה',
  'Move to end of line': 'מעבר לסוף השורה',
  'Move to previous word': 'מעבר למילה הקודמת',
  'Move to next word': 'מעבר למילה הבאה',
  'Previous command': 'הפקודה הקודמת',
  'Next command': 'הפקודה הבאה',
  'Delete word before cursor': 'מחיקת המילה שלפני הסמן',
  'Clear from cursor to start of line': 'ניקוי מהסמן עד תחילת השורה',
  'Clear from cursor to end of line': 'ניקוי מהסמן עד סוף השורה',
  'Clear input line or cancel command': 'ניקוי שורת הקלט או ביטול הפקודה',
  'Delete character at cursor': 'מחיקת התו במיקום הסמן',
  'Tab completion': 'השלמה עם Tab',

  // Keyboard shortcuts reference: SQL editor shortcut descriptions
  Run: 'הרצה',
  Explain: 'הסבר (EXPLAIN)',
  'Format SQL': 'עיצוב SQL',
  'Next query tab': 'לשונית השאילתה הבאה',
  'Previous query tab': 'לשונית השאילתה הקודמת',
  'Close query tab': 'סגירת לשונית השאילתה',
  'Jump to tab 1': 'מעבר ללשונית 1',
  'Jump to tab 2': 'מעבר ללשונית 2',
  'Jump to tab 3': 'מעבר ללשונית 3',
  'Jump to tab 4': 'מעבר ללשונית 4',
  'Jump to tab 5': 'מעבר ללשונית 5',
  'Jump to tab 6': 'מעבר ללשונית 6',
  'Jump to tab 7': 'מעבר ללשונית 7',
  'Jump to tab 8': 'מעבר ללשונית 8',
  'Jump to last tab': 'מעבר ללשונית האחרונה',

  // Table filters: column titles from filter configs
  'Resource type': 'סוג משאב',
  'Event path': 'נתיב אירוע',
  Signature: 'חתימה',
  'Deployment status': 'סטטוס פריסה',
  'Custom attribute': 'מאפיין מותאם אישית',
  'Last activity': 'פעילות אחרונה',

  // Table filters: enum value labels from filter configs
  'User / key': 'משתמש / מפתח',
  'User (client API)': 'משתמש (Client API)',
  Verifying: 'באימות',
  Relationship: 'קשר',
  Point: 'נקודה',
  Polygon: 'מצולע',
  'String (deprecated)': 'מחרוזת (הוצא משימוש)',
  Fulltext: 'טקסט מלא',
  Spatial: 'מרחבי',

  // Cloud status banner and fullscreen loader
  // pragma: allowlist secret
  'Some Appwrite Cloud services are temporarily unavailable.':
    'חלק משירותי Appwrite Cloud אינם זמינים באופן זמני.', // pragma: allowlist secret
  'Scheduled maintenance is in progress.': 'תחזוקה מתוכננת מתבצעת כעת.',
  'We’re experiencing issues with some services.':
    'אנחנו חווים תקלות בחלק מהשירותים.',
  'You may have trouble accessing some services. We’re working to restore full access.':
    'ייתכן שתיתקלו בקשיים בגישה לחלק מהשירותים. אנחנו פועלים לשחזור גישה מלאה.',
  // pragma: allowlist secret
  'A subset of Appwrite Cloud services is degraded.':
    'חלק משירותי Appwrite Cloud פועלים באופן חלקי.', // pragma: allowlist secret
  'All regions are affected.': 'כל האזורים מושפעים.',
  'View Status': 'צפייה בסטטוס',

  // Deployment retention period options
  '1 Week': 'שבוע',
  '1 Month': 'חודש',
  '3 Months': '3 חודשים',
  '6 Months': '6 חודשים',
  '1 Year': 'שנה',
  '2 Years': 'שנתיים',
  '5 Years': '5 שנים',
  '10 Years': '10 שנים',

  // Command center: account entries
  'Account · General': 'חשבון · כללי',
  'Profile, name, email and account ID': 'פרופיל, שם, אימייל ומזהה חשבון',
  'Account · Security': 'חשבון · אבטחה',
  'Password, identities and MFA': 'סיסמה, זהויות ו-MFA',
  'Account · Sessions': 'חשבון · סשנים',
  'Active sessions and devices': 'סשנים פעילים ומכשירים',
  'Account · Payment methods': 'חשבון · אמצעי תשלום',
  'Saved cards and payment methods': 'כרטיסים שמורים ואמצעי תשלום',
  'Account · Billing addresses': 'חשבון · כתובות חיוב',
  'Billing addresses on your account': 'כתובות החיוב בחשבון שלכם',
  'Security · Change password': 'אבטחה · שינוי סיסמה',
  'Update your account password': 'עדכון סיסמת החשבון שלכם',
  'Security · Multi-factor authentication': 'אבטחה · אימות רב-שלבי',
  'Enable MFA with TOTP, email or SMS': 'הפעלת MFA עם TOTP, אימייל או SMS',

  // Command center: theme and help entries
  'Switch the console to light mode': 'החלפת הקונסולה למצב בהיר',
  'Switch the console to dark mode': 'החלפת הקונסולה למצב כהה',
  'Match your operating system appearance': 'התאמה למראה מערכת ההפעלה שלכם',
  'Share feedback to help us improve the console':
    'שתפו משוב כדי לעזור לנו לשפר את הקונסולה',
  'Contact support, Discord, GitHub, and system status':
    'פנייה לתמיכה, Discord, GitHub וסטטוס מערכת',

  // Command center: docs entries
  'Find guides, API references, and tutorials':
    'חיפוש מדריכים, דוקומנטציית API והדרכות',
  'Docs home': 'עמוד הבית של הדוקומנטציה',
  'Appwrite documentation home': 'עמוד הבית של דוקומנטציית Appwrite', // pragma: allowlist secret
  'Quick starts': 'התחלה מהירה',
  'Get started with Appwrite in minutes': 'התחילו עם Appwrite תוך דקות', // pragma: allowlist secret
  'API references': 'דוקומנטציית API',
  'Browse API references documentation': 'עיון בדוקומנטציית API',
  SDKs: 'ערכות SDK',
  'Client and server SDK documentation': 'דוקומנטציית SDK ללקוח ולשרת',

  // Command center: project navigation entries
  // pragma: allowlist secret
  'Toggle the built-in Appwrite CLI terminal':
    'פתיחה או סגירה של טרמינל ה-CLI המובנה של Appwrite', // pragma: allowlist secret
  'All projects in this organization': 'כל הפרויקטים בארגון הזה',
  'Project dashboard and key metrics': 'לוח הבקרה של הפרויקט ומדדים מרכזיים',
  'Connect platforms (web, iOS, Android, Flutter, server)':
    'חיבור פלטפורמות (ווב, iOS, Android, Flutter, שרת)',
  'Server API keys and tokens': 'מפתחות API וטוקנים לשרת',
  // pragma: allowlist secret
  'Browse and test Appwrite REST API endpoints':
    'עיון ובדיקה של נקודות הקצה של Appwrite REST API', // pragma: allowlist secret
  'Users, teams, sessions and authentication providers':
    'משתמשים, צוותים, סשנים וספקי אימות',
  'Manage databases, tables and collections':
    'ניהול מסדי נתונים, טבלאות ואוספים',
  'Buckets and files': 'באקטים וקבצים',
  'Serverless functions and executions': 'פונקציות serverless והרצות',
  'Push notifications, email and SMS messages':
    'התראות פוש, הודעות אימייל ו-SMS', // pragma: allowlist secret
  'Deployed websites and hosting': 'אתרים פרוסים ואירוח אתרים',
  'Audit log of project events': 'יומן ביקורת של אירועי הפרויקט',
  'Realtime channels and live messages': 'ערוצי זמן אמת והודעות חיות', // pragma: allowlist secret
  'Usage statistics and quotas': 'נתוני שימוש ומכסות',
  'Website analytics and traffic insights': 'אנליטיקת אתרים ותובנות תעבורה',
  'Project settings, custom domains, variables, webhooks':
    'הגדרות פרויקט, דומיינים מותאמים אישית, משתנים ו-webhooks',
  'Project settings (comma shortcut)': 'הגדרות פרויקט (קיצור: פסיק)',
  'Create user': 'יצירת משתמש',

  // Command center: project tab entries (Auth, Messaging, Settings, Security)
  'Auth · Users': 'אימות · משתמשים',
  'Browse and manage your project users': 'עיון וניהול של משתמשי הפרויקט שלכם',
  'Auth · Teams': 'אימות · צוותים',
  'Group users into teams with roles': 'קיבוץ משתמשים לצוותים עם תפקידים',
  'Auth · Policies': 'אימות · מדיניות',
  'User, session, email, membership, and password policies':
    'מדיניות משתמשים, סשנים, אימיילים, חברויות וסיסמאות',
  'Auth · Policies · Sessions': 'אימות · מדיניות · סשנים',
  'Session length, limits, alerts, and invalidation':
    'משך סשן, מגבלות, התראות וביטול תוקף',
  'Auth · Policies · Users': 'אימות · מדיניות · משתמשים',
  'Maximum number of users allowed in the project':
    'מספר המשתמשים המרבי המותר בפרויקט',
  'Auth · Policies · Emails': 'אימות · מדיניות · אימיילים',
  'Block free, aliased, disposable, and corporate emails at signup':
    'חסימת אימיילים חינמיים, עם כינוי, חד-פעמיים וארגוניים בהרשמה',
  'Auth · Policies · Memberships': 'אימות · מדיניות · חברויות',
  'Hide member name, email, or MFA status from other team members':
    'הסתרת שם, אימייל או סטטוס MFA של חבר צוות משאר חברי הצוות',
  'Auth · Policies · Passwords': 'אימות · מדיניות · סיסמאות',
  'Password history, dictionary, and personal data checks':
    'בדיקות היסטוריית סיסמאות, מילון ונתונים אישיים',
  'Auth · Social providers': 'אימות · ספקי OAuth',
  'Configure OAuth2 providers for social login': 'הגדרת ספקי OAuth2',
  'Auth · OAuth2 server · Server': 'אימות · שרת OAuth2 · שרת',
  'Configure OAuth2 authorization server for third-party apps':
    'הגדרת שרת הרשאות OAuth2 לאפליקציות צד שלישי',
  'Auth · OAuth2 server · Apps': 'אימות · שרת OAuth2 · אפליקציות',
  'Manage OAuth2 client apps for this project':
    'ניהול אפליקציות לקוח OAuth2 לפרויקט הזה',
  'Auth · Templates': 'אימות · תבניות',
  'Customize verification, recovery and magic URL emails':
    'התאמה אישית של אימיילים לאימות, לשחזור ול-Magic URL',
  'Auth · Settings': 'אימות · הגדרות',
  'Configure auth methods and mock phone numbers':
    'הגדרת שיטות אימות ומספרי טלפון מדומים',
  'Messaging · Messages': 'הודעות · הודעות', // pragma: allowlist secret
  'Sent and scheduled messages': 'הודעות שנשלחו ושמתוזמנות', // pragma: allowlist secret
  'Messaging · Topics': 'הודעות · נושאים',
  'Subscriber topics for fan-out messaging':
    'נושאי מנויים לשליחת הודעות בתפוצה רחבה',
  'Messaging · Providers': 'הודעות · ספקים',
  'Email, SMS and push providers': 'ספקי אימייל, SMS ופוש',
  'Settings · Overview': 'הגדרות · סקירה',
  'Project ID, name, region, API endpoint':
    'מזהה פרויקט, שם, אזור ונקודת קצה של ה-API',
  'Settings · Custom domains': 'הגדרות · דומיינים מותאמים אישית',
  'Custom domains for your project endpoint':
    'דומיינים מותאמים אישית לנקודת הקצה של הפרויקט',
  'Settings · Variables': 'הגדרות · משתנים',
  'Project-level environment variables': 'משתני סביבה ברמת הפרויקט',
  'Settings · Webhooks': 'הגדרות · Webhooks',
  'HTTP callbacks for project events': 'קריאות HTTP חוזרות לאירועי הפרויקט',
  'Settings · Migrations': 'הגדרות · מיגרציות',
  'Import data from other backends': 'ייבוא נתונים משירותי backend אחרים',
  'Settings · SMTP': 'הגדרות · SMTP',
  'Custom SMTP server for outgoing emails':
    'שרת SMTP מותאם אישית לאימיילים יוצאים',
  Advisor: 'יועץ',
  Firewall: 'חומת אש',
  'Security · Firewall rules': 'אבטחה · כללי חומת אש',
  'IP allow/block lists and request rules':
    'רשימות היתר/חסימה של IP וכללי בקשות',

  // Command center: project settings card entries
  'Settings · Project name': 'הגדרות · שם הפרויקט',
  'Rename your project': 'שינוי שם הפרויקט שלכם',
  'Settings · API credentials': 'הגדרות · פרטי גישה ל-API',
  'Project ID and API endpoint for SDKs':
    'מזהה פרויקט ונקודת קצה של ה-API עבור ערכות SDK',
  'Settings · Services': 'הגדרות · שירותים',
  // pragma: allowlist secret
  'Enable or disable Appwrite services for this project':
    'הפעלה או השבתה של שירותי Appwrite לפרויקט הזה', // pragma: allowlist secret
  'Settings · Transfer project': 'הגדרות · העברת פרויקט',
  'Move this project to a different organization':
    'העברת הפרויקט הזה לארגון אחר',
  'Settings · Delete project': 'הגדרות · מחיקת פרויקט',
  'Permanently delete this project and all its data':
    'מחיקה לצמיתות של הפרויקט הזה וכל הנתונים שלו',

  // Command center: organization entries
  // pragma: allowlist secret
  'Browse and publish Appwrite marketplace apps':
    'עיון ופרסום של אפליקציות במרקטפלייס של Appwrite', // pragma: allowlist secret
  'Manage organization-level custom domains':
    'ניהול דומיינים מותאמים אישית ברמת הארגון',
  'Organization settings (members, billing, compliance)':
    'הגדרות ארגון (חברי צוות, חיוב, ציות)',
  'Organization members and roles': 'חברי הארגון ותפקידיהם',
  'Plan, payment methods and invoices': 'תוכנית, אמצעי תשלום וחשבוניות',
  'Settings · OAuth apps': 'הגדרות · אפליקציות OAuth',
  'Third-party OAuth apps with access to this organization':
    'אפליקציות OAuth של צד שלישי עם גישה לארגון הזה',
  'Settings · API keys': 'הגדרות · מפתחות API',
  'Org-level API keys for automation': 'מפתחות API ברמת הארגון לאוטומציה',
  'Settings · Delete organization': 'הגדרות · מחיקת ארגון',
  'Permanently delete this organization': 'מחיקה לצמיתות של הארגון הזה',
  'Spin up a new project in this organization': 'הקמת פרויקט חדש בארגון הזה',
  'Create a new organization': 'יצירת ארגון חדש',
  'Invite a new member to this organization': 'הזמנת חבר צוות חדש לארגון הזה',

  // Command center: create command descriptions
  'Create a new database': 'יצירת מסד נתונים חדש',
  'Create a new bucket': 'יצירת באקט חדש',
  'Create a new function': 'יצירת פונקציה חדשה',
  'Create a new site': 'יצירת אתר חדש',
  'Create a new team': 'יצירת צוות חדש',
  'Create a new user': 'יצירת משתמש חדש',

  // Command center: organization navigation
  'DPA, BAA, SOC 2, HIPAA, GDPR': 'DPA, BAA, SOC 2, HIPAA, GDPR',

  // Command center: resource search commands
  'Search databases': 'חיפוש מסדי נתונים',
  'Search users': 'חיפוש משתמשים',
  'Search teams': 'חיפוש צוותים',
  'Search buckets': 'חיפוש באקטים',
  'Search buckets...': 'חיפוש באקטים...',
  'Search functions': 'חיפוש פונקציות',
  'Search sites': 'חיפוש אתרים',
  'Search messages': 'חיפוש הודעות',
  'Search topics': 'חיפוש נושאים',
  'Search providers': 'חיפוש ספקים',
  'Search projects': 'חיפוש פרויקטים',
  'Find a database by name or ID': 'חיפוש מסד נתונים לפי שם או מזהה',
  'Find a user by name, email or ID': 'חיפוש משתמש לפי שם, אימייל או מזהה',
  'Find a team by name or ID': 'חיפוש צוות לפי שם או מזהה',
  'Find a storage bucket by name or ID': 'חיפוש באקט אחסון לפי שם או מזהה',
  'Find a function by name or ID': 'חיפוש פונקציה לפי שם או מזהה',
  'Find a site by name or ID': 'חיפוש אתר לפי שם או מזהה',
  'Find a message by content or ID': 'חיפוש הודעה לפי תוכן או מזהה',
  'Find a topic by name or ID': 'חיפוש נושא לפי שם או מזהה',
  'Find a provider by name or ID': 'חיפוש ספק לפי שם או מזהה',
  'Find a project in this organization': 'חיפוש פרויקט בארגון הזה',

  // Command center: local actions and groups
  'See all keyboard shortcuts': 'הצגת כל קיצורי המקלדת',
  'Pick a function to execute': 'בחירת פונקציה להרצה',
  'Open project activity log': 'פתיחת יומן הפעילות של הפרויקט',
  'View activity log': 'צפייה ביומן הפעילות',
  Results: 'תוצאות',

  // Database create wizard: compute tiers, replicas, and pricing
  Micro: 'מיקרו',
  Small: 'קטן',
  Medium: 'בינוני',
  Large: 'גדול',
  Replicas: 'רפליקות',
  off: 'כבוי',
  '1 replica': 'רפליקה אחת',
  '2 replicas': '2 רפליקות',
  '3 replicas': '3 רפליקות',
  '4 replicas': '4 רפליקות',
  '5 replicas': '5 רפליקות',
  'Primary instance only. Suitable for development and workloads that can tolerate brief downtime.':
    'מופע ראשי בלבד. מתאים לפיתוח ולעומסי עבודה שיכולים לספוג השבתה קצרה.',
  'One read replica to offload queries and reduce recovery time if the primary fails.':
    'רפליקת קריאה אחת להפחתת עומס שאילתות ולקיצור זמן ההתאוששות אם המופע הראשי נכשל.',
  'Two read replicas for higher throughput and smoother operation during maintenance.':
    'שתי רפליקות קריאה לתפוקה גבוהה יותר ולפעולה חלקה יותר בזמן תחזוקה.',
  'Three read replicas for production workloads with sustained read demand.':
    'שלוש רפליקות קריאה לעומסי עבודה בסביבת ייצור עם ביקוש קריאה מתמשך.',
  'Four read replicas for large-scale read traffic and increased failover capacity.':
    'ארבע רפליקות קריאה לתעבורת קריאה בקנה מידה גדול וליכולת failover מוגברת.',
  'Maximum self-serve replica count for high-traffic production environments.':
    'מספר הרפליקות המרבי בשירות עצמי לסביבות ייצור עתירות תעבורה.',
  '$10 of compute credits for database usage included every month.':
    'כל חודש כלולים $10 של קרדיט מחשוב לשימוש במסד הנתונים.',

  // Command center: SQL editor action entries
  'Execute the current SQL in the editor': 'הרצת ה-SQL הנוכחי בעורך',
  'Show the query execution plan': 'הצגת תוכנית ביצוע השאילתה',
  'Save the current SQL as a personal or team query':
    'שמירת ה-SQL הנוכחי כשאילתה אישית או צוותית',
  'Format the current SQL in the editor': 'עיצוב ה-SQL הנוכחי בעורך',
  'Undo the last SQL editor change': 'ביטול השינוי האחרון בעורך ה-SQL',
  'Redo the last undone SQL editor change':
    'שחזור השינוי האחרון שבוטל בעורך ה-SQL',
  'Switch to the next SQL editor tab': 'מעבר ללשונית הבאה בעורך ה-SQL',
  'Switch to the previous SQL editor tab': 'מעבר ללשונית הקודמת בעורך ה-SQL',
  'Open a new SQL editor tab': 'פתיחת לשונית חדשה בעורך ה-SQL',
  'Close the current SQL editor tab': 'סגירת הלשונית הנוכחית בעורך ה-SQL',
  'Pick a SQL editor tab to open': 'בחירת לשונית לפתיחה בעורך ה-SQL',

  // Event editor
  'All databases': 'כל מסדי הנתונים',
  'All buckets': 'כל הבאקטים',
  'All functions': 'כל הפונקציות',
  'All teams': 'כל הצוותים',
  'All topics': 'כל הנושאים',
  'All tables': 'כל הטבלאות',
  'All files': 'כל הקבצים',
  'All rows': 'כל השורות',
  'All columns': 'כל העמודות',
  'All indexes': 'כל האינדקסים',
  'Edit channel': 'עריכת ערוץ',
  'Create channel': 'יצירת ערוץ',
  'Edit event': 'עריכת אירוע',
  'Create event': 'יצירת אירוע',
  'Build a Realtime channel to subscribe to. Use wildcards (*) to match multiple resources.':
    'בנו ערוץ Realtime להרשמה. השתמשו בתווים כלליים (*) כדי להתאים למספר משאבים.',
  'Select events that will trigger your function or webhook.':
    'בחרו אירועים שיפעילו את הפונקציה או ה-webhook שלכם.',
  'Select file': 'בחירת קובץ',
  'Select tool': 'בחירת כלי',
  Service: 'שירות',
  'Bucket (optional)': 'באקט (אופציונלי)',
  'Function (optional)': 'פונקציה (אופציונלי)',
  'Team (optional)': 'צוות (אופציונלי)',
  'User (optional)': 'משתמש (אופציונלי)',
  'Topic (optional)': 'נושא (אופציונלי)',
  'Provider (optional)': 'ספק (אופציונלי)',
  'Resource (optional)': 'משאב (אופציונלי)',
  'Table (optional)': 'טבלה (אופציונלי)',
  'File (optional)': 'קובץ (אופציונלי)',
  'Row (optional)': 'שורה (אופציונלי)',
  'Column (optional)': 'עמודה (אופציונלי)',
  'Index (optional)': 'אינדקס (אופציונלי)',
  'Action (optional)': 'פעולה (אופציונלי)',
  'Attribute (optional)': 'מאפיין (אופציונלי)',
  'e.g. account or databases.*.tables.*.rows.*':
    'לדוגמה: account או databases.*.tables.*.rows.*',
  'e.g. databases.*.tables.*.rows.*.create':
    'לדוגמה: databases.*.tables.*.rows.*.create',

  // Deployment detail, keyboard visualizer, code block
  'Deployment details': 'פרטי פריסה',
  'Keyboard layout': 'פריסת מקלדת',
  'Press keys in order': 'לחצו על המקשים לפי הסדר',
  example: 'דוגמה',

  // Error pages
  'Project Not Found': 'הפרויקט לא נמצא',
  'This project could not be found or you do not have access to view it.':
    'הפרויקט לא נמצא או שאין לכם גישה לצפות בו.',
  'You do not have permission to access this project. Please contact your administrator if you believe this is an error.':
    'אין לכם הרשאה לגשת לפרויקט הזה. פנו למנהל המערכת אם לדעתכם מדובר בטעות.',
  // pragma: allowlist secret
  'This page needs a connection to Appwrite. Reconnect to the internet, then try again - we can reload automatically when you are back online.':
    'הדף הזה זקוק לחיבור ל-Appwrite. התחברו מחדש לאינטרנט ונסו שוב, נטען מחדש אוטומטית כשתחזרו להיות מקוונים.', // pragma: allowlist secret
  'Update available': 'עדכון זמין',
  'A newer version of the console was deployed while you had this tab open. Reload the page to continue.':
    'גרסה חדשה יותר של הקונסולה נפרסה בזמן שהלשונית הזו הייתה פתוחה. טענו מחדש את הדף כדי להמשיך.',
  'An unexpected error occurred.': 'אירעה שגיאה בלתי צפויה.',
  'Error details copied to clipboard': 'פרטי השגיאה הועתקו ללוח',
  'If your connection looks fine, check our':
    'אם החיבור שלכם נראה תקין, בדקו את',
  'status page': 'דף הסטטוס שלנו',
  'for service updates.': 'לעדכוני שירות.',
  'No error details available': 'אין פרטי שגיאה זמינים',
  'Copy error details': 'העתקת פרטי השגיאה',
  'We’ve already logged it to our error system and will probably spin up a super agent any minute to hunt this bug down. If you think this might be more than a client-side hiccup, check our':
    'כבר תיעדנו את זה במערכת השגיאות שלנו, וכנראה שבכל רגע יופעל סוכן-על שיצא לצוד את הבאג הזה. אם לדעתכם מדובר ביותר מתקלה רגעית בצד הלקוח, בדקו את',
  'Until then - try again or head home. You’ve got this.':
    'עד אז, נסו שוב או חזרו לדף הבית. אתם על זה.',
  'Reload page': 'טעינת הדף מחדש',
  'Try again': 'נסו שוב',
  'Go back': 'חזרה',
  'Go home': 'חזרה לדף הבית',
  'Page not found': 'הדף לא נמצא',
  'The page you requested does not exist, may have been moved, or is temporarily unavailable.':
    'הדף שביקשתם אינו קיים, ייתכן שהועבר או שאינו זמין באופן זמני.',
  'Looking for product docs?': 'מחפשים את דוקומנטציית המוצר?',
  'Browse documentation': 'עיון בדוקומנטציה',

  // Screenshot frames (cover generator, perspective cards)
  'Screenshot preview': 'תצוגה מקדימה של צילום מסך',
}
