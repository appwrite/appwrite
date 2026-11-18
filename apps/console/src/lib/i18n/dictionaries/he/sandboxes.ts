/**
 * Hebrew translations for the Sandboxes product (live workspaces with a
 * terminal and a file browser: sandboxes list, create dialog and detail).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heSandboxesDictionary: Record<string, string> = {
  // Navigation and titles
  Sandboxes: 'Sandboxes',
  sandboxes: 'sandboxes',
  'Back to sandboxes': 'חזרה ל-Sandboxes',
  'Sandbox ID': 'מזהה Sandbox',
  'Sandbox not found': 'ה-Sandbox לא נמצא',
  'This sandbox may have been deleted, or it was idle long enough to be deleted.':
    'ייתכן שה-Sandbox נמחק, או שלא היה בשימוש מספיק זמן כדי להימחק.',

  // List and empty state
  'Run code in a sandbox': 'הרצת קוד ב-Sandbox',
  'A sandbox is a live workspace with a terminal and a file system. Create one to run commands, start processes and manage files, from the Console or the API.':
    'Sandbox הוא סביבת עבודה חיה עם טרמינל ומערכת קבצים. צרו אחד כדי להריץ פקודות, להפעיל תהליכים ולנהל קבצים, מה-Console או מה-API.',
  Creating: 'בתהליך יצירה',
  "You don't have permission to create sandboxes.":
    'אין לכם הרשאה ליצור Sandboxes.',

  // Create dialog
  'Create sandbox': 'יצירת Sandbox',
  'A live workspace you can open a terminal in, run commands in and manage files in. It is deleted after it has been idle for 15 minutes.':
    'סביבת עבודה חיה שבה אפשר לפתוח טרמינל, להריץ פקודות ולנהל קבצים. היא נמחקת אחרי 15 דקות ללא שימוש.',
  'One NAME=value per line. Every command, process and terminal in the sandbox inherits them. They are never stored or shown again.':
    'NAME=value אחד בכל שורה. כל פקודה, תהליך וטרמינל ב-Sandbox יורשים אותם. הם אף פעם לא נשמרים ולא מוצגים שוב.',
  'Use NAME=value. Names use letters, digits and underscores, cannot start with a digit or SANDBOX_.':
    'השתמשו ב-NAME=value. שמות מכילים אותיות, ספרות וקווים תחתונים, ואינם יכולים להתחיל בספרה או ב-SANDBOX_.',
  'A sandbox takes at most 100 variables.': 'Sandbox מקבל עד 100 משתנים.',
  'Sandbox created': 'ה-Sandbox נוצר',

  // Delete
  'Delete sandbox': 'מחיקת Sandbox',
  'The sandbox, its files and its processes are deleted. This cannot be undone.':
    'ה-Sandbox, הקבצים שלו והתהליכים שלו יימחקו. לא ניתן לבטל פעולה זו.',
  'Sandbox deleted': 'ה-Sandbox נמחק',

  // Detail
  'Starting sandbox...': 'ה-Sandbox מופעל...',
  'Deleting sandbox...': 'ה-Sandbox נמחק...',
  'Sandbox failed to start': 'הפעלת ה-Sandbox נכשלה',
  'Delete it and create a new one.': 'מחקו אותו וצרו חדש.',
  "You don't have permission to run commands in sandboxes.":
    'אין לכם הרשאה להריץ פקודות ב-Sandboxes.',

  // Terminal
  'Starting terminal...': 'הטרמינל מופעל...',
  'Restart terminal': 'הפעלה מחדש של הטרמינל',
  'Process exited with code': 'התהליך הסתיים עם קוד',

  // Files
  Workspace: 'סביבת עבודה',
  'This folder is empty.': 'התיקייה ריקה.',
  'Files can be at most 5 MB.': 'גודל קובץ מוגבל ל-5 MB.',
  'Delete folder': 'מחיקת תיקייה',
  'Folder deleted': 'התיקייה נמחקה',
  'The folder and everything in it are deleted.':
    'התיקייה וכל מה שבתוכה יימחקו.',
  'The file is deleted from the sandbox.': 'הקובץ יימחק מה-Sandbox.',
}
