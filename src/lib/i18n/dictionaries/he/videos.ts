/**
 * Hebrew translations for the Videos product (list, profiles, detail tabs,
 * stream player, and debug panel).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heVideosDictionary: Record<string, string> = {
  // Navigation and titles
  Videos: 'סרטונים',
  Video: 'סרטון',
  Profiles: 'פרופילים',
  Renditions: 'גרסאות קידוד',
  Rendition: 'גרסת קידוד',
  Subtitles: 'כתוביות',
  'Back to videos': 'חזרה לסרטונים',
  'Untitled video': 'סרטון ללא שם',
  'Video not found': 'הסרטון לא נמצא',
  "The video you're looking for doesn't exist or you don't have access to it.":
    'הסרטון שחיפשתם לא קיים או שאין לכם גישה אליו.',
  'Loading video...': 'טוען סרטון...',
  'Loading videos...': 'טוען סרטונים...',
  'Loading profiles...': 'טוען פרופילים...',
  'Search videos...': 'חיפוש סרטונים...',
  'Search profiles...': 'חיפוש פרופילים...',
  video: 'סרטון',
  videos: 'סרטונים',
  'this video': 'הסרטון הזה',
  optional: 'אופציונלי',

  // List page
  'Create video': 'יצירת סרטון',
  'No videos yet': 'אין עדיין סרטונים',
  'Create a video from a Storage file to encode adaptive HLS, DASH, and CMAF streams.':
    'צרו סרטון מקובץ באחסון כדי לקודד סטרימים אדפטיביים ב-HLS, DASH ו-CMAF.',
  'Failed to load videos. Please try again.': 'טעינת הסרטונים נכשלה. נסו שוב.',
  "You don't have permission to create videos.": 'אין לכם הרשאה ליצור סרטונים.',
  "You don't have permission to manage videos.": 'אין לכם הרשאה לנהל סרטונים.',
  'Delete videos': 'מחיקת סרטונים',
  'Delete video': 'מחיקת סרטון',
  'Video deleted': 'הסרטון נמחק',
  'Failed to delete videos': 'מחיקת הסרטונים נכשלה',
  'Failed to delete video': 'מחיקת הסרטון נכשלה',
  'Are you sure you want to delete this video? Its renditions, subtitles, and previews are removed. The source file in Storage is kept.':
    'למחוק את הסרטון? גרסאות הקידוד, הכתוביות והתמונות המקדימות שלו יימחקו. קובץ המקור באחסון יישמר.',

  // Create video
  'Choose a video file from Storage. Appwrite downloads a working copy so you can encode renditions and stream it.':
    'בחרו קובץ וידאו מהאחסון. Appwrite מוריד עותק עבודה כדי שתוכלו לקודד גרסאות ולהזרים אותו.',
  'Source file': 'קובץ מקור',
  'Defaults to the file name': 'ברירת המחדל היא שם הקובץ',
  'This file does not look like a video. The server may reject it.':
    'הקובץ הזה לא נראה כמו סרטון. ייתכן שהשרת ידחה אותו.',
  'Select source video': 'בחירת סרטון מקור',
  'Choose a bucket, then pick or upload a video file.':
    'בחרו באקט, ואז בחרו או העלו קובץ וידאו.',
  'Video created. Downloading source...': 'הסרטון נוצר. מוריד את המקור...',
  'Failed to create video': 'יצירת הסרטון נכשלה',

  // Statuses
  Downloading: 'בהורדה',
  Aborted: 'בוטל',
  Encoding: 'בקידוד',
  Encoded: 'קודד',
  Uploading: 'בהעלאה',

  // Profiles
  'Create profile': 'יצירת פרופיל',
  'Update profile': 'עדכון פרופיל',
  'Delete profile': 'מחיקת פרופיל',
  'Profile created': 'הפרופיל נוצר',
  'Profile updated': 'הפרופיל עודכן',
  'Profile deleted': 'הפרופיל נמחק',
  'Failed to save profile': 'שמירת הפרופיל נכשלה',
  'Failed to delete profile': 'מחיקת הפרופיל נכשלה',
  'Failed to load profiles': 'טעינת הפרופילים נכשלה',
  'No profiles': 'אין פרופילים',
  'Create a profile to choose the resolution and bitrate of your renditions.':
    'צרו פרופיל כדי לבחור את הרזולוציה וה-Bitrate של גרסאות הקידוד.',
  'Profiles define the resolution and bitrate of each rendition. Use them when encoding a video into adaptive streams.':
    'פרופילים מגדירים את הרזולוציה וה-Bitrate של כל גרסת קידוד. השתמשו בהם כשמקודדים סרטון לסטרימים אדפטיביים.',
  "You don't have permission to manage video profiles.":
    'אין לכם הרשאה לנהל פרופילי וידאו.',
  'Renditions already encoded with this profile stay playable. This action cannot be undone.':
    'גרסאות קידוד שכבר קודדו עם הפרופיל הזה ימשיכו להתנגן. לא ניתן לבטל פעולה זו.',
  Width: 'רוחב',
  Height: 'גובה',
  Resolution: 'רזולוציה',
  'Video bitrate': 'Bitrate וידאו',
  'Audio bitrate': 'Bitrate אודיו',
  Bitrate: 'Bitrate',

  // Filters
  'Video codec': 'Codec וידאו',
  'Audio codec': 'Codec אודיו',
  'Duration (ms)': 'משך (ms)',
  'Size (bytes)': 'גודל (בתים)',

  // Detail layout
  'Working copy released': 'עותק העבודה שוחרר',
  'Appwrite releases the working copy after encoding. Existing renditions keep streaming. Download it again from Storage to create more renditions.':
    'Appwrite משחרר את עותק העבודה בסיום הקידוד. גרסאות הקידוד הקיימות ממשיכות לשדר. הורידו אותו שוב מהאחסון כדי ליצור גרסאות קידוד נוספות.',
  'Source download failed': 'הורדת המקור נכשלה',
  'New renditions need a working copy of the source file. Download it again from Storage to continue encoding.':
    'גרסאות קידוד חדשות דורשות עותק עבודה של קובץ המקור. הורידו אותו שוב מהאחסון כדי להמשיך לקודד.',
  'Download again': 'הורדה מחדש',
  'Source download started': 'הורדת המקור התחילה',
  'Failed to start source download': 'הפעלת הורדת המקור נכשלה',
  'Wait until the source download finishes.':
    'המתינו עד שסיום הורדת המקור.',
  'Download the source again before creating more renditions.':
    'הורידו את המקור שוב לפני יצירת גרסאות קידוד נוספות.',
  'Renditions can be created once the source download is ready.':
    'אפשר ליצור גרסאות קידוד לאחר שהורדת המקור מסתיימת.',

  // Overview
  'Source is ready to encode': 'המקור מוכן לקידוד',
  'Create renditions to stream this video with adaptive bitrate. Until then, the player uses the original file.':
    'צרו גרסאות קידוד כדי להזרים את הסרטון ב-Bitrate אדפטיבי. עד אז, הנגן משתמש בקובץ המקורי.',

  // Renditions
  'Create renditions': 'יצירת גרסאות קידוד',
  'No renditions': 'אין גרסאות קידוד',
  'Encode the source into HLS, DASH, or CMAF renditions to stream it with adaptive bitrate.':
    'קודדו את המקור לגרסאות HLS, DASH או CMAF כדי להזרים אותו ב-Bitrate אדפטיבי.',
  'Encode the source into one rendition per profile. Progress updates live while the worker encodes.':
    'קודדו את המקור לגרסת קידוד אחת לכל פרופיל. ההתקדמות מתעדכנת בזמן אמת במהלך הקידוד.',
  Output: 'פלט',
  'Manage profiles': 'ניהול פרופילים',
  'No profiles. Create a profile first.': 'אין פרופילים. צרו פרופיל קודם.',
  Exists: 'קיים',
  Upscale: 'הגדלה',
  'MPEG-TS segments. Plays natively on Safari and iOS.':
    'סגמנטים של MPEG-TS. מתנגן באופן מובנה ב-Safari וב-iOS.',
  'MPEG-DASH with fragmented MP4 segments.':
    'MPEG-DASH עם סגמנטים של fragmented MP4.',
  'Fragmented MP4 served as both HLS and DASH.':
    'fragmented MP4 שמוגש גם כ-HLS וגם כ-DASH.',
  'Rendition queued': 'גרסת הקידוד נכנסה לתור',
  'renditions queued': 'גרסאות קידוד נכנסו לתור',
  'Failed to create renditions': 'יצירת גרסאות הקידוד נכשלה',
  'Encoding time': 'זמן קידוד',
  'Delete rendition': 'מחיקת גרסת קידוד',
  'Rendition deleted': 'גרסת הקידוד נמחקה',
  'Failed to delete rendition': 'מחיקת גרסת הקידוד נכשלה',
  'Failed to retry rendition': 'ניסיון חוזר לגרסת הקידוד נכשל',
  'Its segments are removed and players stop receiving this quality. This action cannot be undone.':
    'הסגמנטים שלה יימחקו והנגנים יפסיקו לקבל את האיכות הזו. לא ניתן לבטל פעולה זו.',

  // Subtitles
  'Create subtitle': 'יצירת כתובית',
  'Update subtitle': 'עדכון כתובית',
  'Delete subtitle': 'מחיקת כתובית',
  'Subtitle added': 'הכתובית נוספה',
  'Subtitle updated': 'הכתובית עודכנה',
  'Subtitle deleted': 'הכתובית נמחקה',
  'Default subtitle updated': 'כתובית ברירת המחדל עודכנה',
  'Failed to save subtitle': 'שמירת הכתובית נכשלה',
  'Failed to delete subtitle': 'מחיקת הכתובית נכשלה',
  'No subtitles': 'אין כתוביות',
  'No subtitles added yet.': 'עדיין לא נוספו כתוביות.',
  'Add WebVTT or SRT files from Storage. Every HLS, DASH, and CMAF manifest lists them as text tracks.':
    'הוסיפו קובצי WebVTT או SRT מהאחסון. כל מניפסט HLS, DASH ו-CMAF מציג אותם כרצועות טקסט.',
  'Attach a WebVTT or SRT file from Storage. Subtitles are segmented to match the stream and listed in every manifest.':
    'צרפו קובץ WebVTT או SRT מהאחסון. הכתוביות מחולקות לסגמנטים בהתאם לסטרים ומופיעות בכל מניפסט.',
  'Subtitle file': 'קובץ כתוביות',
  'Subtitle files must be WebVTT (text/vtt), SRT (application/x-subrip), or plain text.':
    'קובצי כתוביות חייבים להיות WebVTT (text/vtt), SRT (application/x-subrip) או טקסט פשוט.',
  'Select subtitle file': 'בחירת קובץ כתוביות',
  'Choose a bucket, then pick or upload a .vtt or .srt file.':
    'בחרו באקט, ואז בחרו או העלו קובץ .vtt או .srt.',
  Language: 'שפה',
  'Select language': 'בחירת שפה',
  'Search languages...': 'חיפוש שפות...',
  'No languages found': 'לא נמצאו שפות',
  "Shown in the player's subtitle menu. Letters, numbers, spaces, and - . , ( ) _ ' only.":
    "מוצג בתפריט הכתוביות של הנגן. אותיות לטיניות, ספרות, רווחים ו- . , ( ) _ ' בלבד.",
  'Default subtitle': 'כתובית ברירת מחדל',
  'Players turn this subtitle on automatically.':
    'נגנים מפעילים את הכתובית הזו אוטומטית.',
  'Make default': 'הגדרה כברירת מחדל',
  Embedded: 'מוטמע',
  'Storage file': 'קובץ באחסון',
  'The Storage file is kept. This action cannot be undone.':
    'הקובץ באחסון יישמר. לא ניתן לבטל פעולה זו.',

  // Settings
  'Shown in the Console and returned by the API.':
    'מוצג ב-Console ומוחזר על ידי ה-API.',
  'Video name updated': 'שם הסרטון עודכן',
  'Failed to update video': 'עדכון הסרטון נכשל',
  'The Storage file this video was created from. Deleting the video keeps the file.':
    'הקובץ באחסון שממנו נוצר הסרטון. מחיקת הסרטון לא מוחקת את הקובץ.',
  'Permanently delete this video with its renditions, subtitles, and previews. The source file in Storage is kept.':
    'מחיקה לצמיתות של הסרטון יחד עם גרסאות הקידוד, הכתוביות והתמונות המקדימות שלו. קובץ המקור באחסון יישמר.',
  'Enter video name': 'הזינו את שם הסרטון',

  // Player
  'Original file': 'קובץ מקורי',
  'No ready HLS renditions': 'אין גרסאות HLS מוכנות',
  'No ready DASH renditions': 'אין גרסאות DASH מוכנות',
  'No ready CMAF renditions': 'אין גרסאות CMAF מוכנות',
  'No ready renditions': 'אין גרסאות קידוד מוכנות',
  'Downloading source': 'מוריד את המקור',
  'Processing renditions': 'מעבד גרסאות קידוד',
  'Some renditions failed': 'חלק מגרסאות הקידוד נכשלו',
  'The worker downloads the Storage file into a working copy before encoding.':
    'ה-worker מוריד את הקובץ מהאחסון לעותק עבודה לפני הקידוד.',
  'Auto quality': 'איכות אוטומטית',
  'Subtitles off': 'ללא כתוביות',
  'New renditions are ready.': 'גרסאות קידוד חדשות מוכנות.',
  'Reload stream': 'טעינה מחדש של הסטרים',
  'Playing the original Storage file. Switch to HLS, DASH, or CMAF to test adaptive streaming.':
    'מתנגן קובץ המקור מהאחסון. עברו ל-HLS, DASH או CMAF כדי לבדוק הזרמה אדפטיבית.',
  'Playing the original Storage file. Create HLS, DASH, or CMAF renditions to test adaptive streaming.':
    'מתנגן קובץ המקור מהאחסון. צרו גרסאות HLS, DASH או CMAF כדי לבדוק הזרמה אדפטיבית.',
  'Generate timeline': 'יצירת ציר זמן',
  'Timeline generation started': 'יצירת ציר הזמן התחילה',
  'Failed to generate timeline': 'יצירת ציר הזמן נכשלה',

  // Debug panel
  Playback: 'ניגון',
  Player: 'נגן',
  'Native video element': 'רכיב וידאו מובנה',
  Position: 'מיקום',
  'Ready state': 'מצב מוכנות',
  'Network state': 'מצב רשת',
  Buffer: 'באפר',
  'Buffered ahead': 'נטען מראש',
  'Buffered ranges': 'טווחים טעונים',
  'Buffer visualizer': 'הצגת באפר',
  'Fully buffered': 'נטען במלואו',
  'Buffer ahead, last 60s': 'באפר קדימה, 60 שניות אחרונות',
  Audio: 'אודיו',
  Healthy: 'תקין',
  'Low buffer': 'באפר נמוך',
  'Buffer critical': 'באפר קריטי',
  Played: 'נוגן',
  Buffered: 'נטען',
  'Adaptive bitrate': 'Bitrate אדפטיבי',
  'Current level': 'רמת איכות נוכחית',
  'Loading level': 'רמת איכות בטעינה',
  'Next level': 'רמת האיכות הבאה',
  'Level selection': 'בחירת רמת איכות',
  Automatic: 'אוטומטית',
  'Bandwidth estimate': 'הערכת רוחב פס',
  Latency: 'השהיה',
  'Dropped frames': 'פריימים שנפלו',
  'Decoded resolution': 'רזולוציה מפוענחת',
  'Start playback to collect stream statistics.':
    'הפעילו ניגון כדי לאסוף נתוני סטרים.',
  Levels: 'רמות איכות',
  Level: 'רמת איכות',
  Playing: 'מתנגן',
  Lock: 'נעילה',
  'No levels parsed yet.': 'עדיין לא נותחו רמות איכות.',
  'Quality levels are only available for adaptive streams.':
    'רמות איכות זמינות רק בהזרמה אדפטיבית.',
  Segments: 'סגמנטים',
  'No segments loaded yet.': 'עדיין לא נטענו סגמנטים.',
  'Load time': 'זמן טעינה',
  Throughput: 'תפוקה',
  'Event log': 'יומן אירועים',
  'Player lifecycle events, level switches, and errors (newest first).':
    'אירועי מחזור החיים של הנגן, החלפות רמת איכות ושגיאות (החדשים ביותר קודם).',
  'No events recorded yet.': 'עדיין לא נרשמו אירועים.',
  Manifests: 'מניפסטים',
  'Master manifests': 'מניפסטים ראשיים',
  'Media playlists': 'פלייליסטים של מדיה',
  'Public playback URLs. Clients need read access to the source file; the console adds admin mode.':
    'כתובות ניגון ציבוריות. לקוחות צריכים הרשאת קריאה לקובץ המקור; ה-Console מוסיף מצב אדמין.',
  Reload: 'טעינה מחדש',
  Container: 'קונטיינר',
  'Source bucket': 'באקט מקור',
  'Preview ID': 'מזהה תמונה מקדימה',
  'Aspect ratio': 'יחס גובה-רוחב',
  'Video stream': 'סטרים וידאו',
  'Audio stream': 'סטרים אודיו',
  Codec: 'Codec',
  Codecs: 'Codecs',
  'Format profile': 'פרופיל פורמט',
  'Frame rate': 'קצב פריימים',
  'Sample rate': 'קצב דגימה',
  Chunks: 'חלקים',
  'Target duration': 'משך יעד',
  Elapsed: 'זמן שחלף',
  'No renditions requested yet.': 'עדיין לא התבקשו גרסאות קידוד.',
  'No timeline yet. Generate sprite thumbnails for scrubbing previews and the video poster.':
    'אין עדיין ציר זמן. צרו תמונות ממוזערות לתצוגה מקדימה בגלילה ולתמונת השער של הסרטון.',
  'Generating sprite timeline. This view refreshes automatically.':
    'יוצר ציר זמן של תמונות ממוזערות. התצוגה תתרענן אוטומטית.',
  'Select a thumbnail to seek the player.':
    'בחרו תמונה ממוזערת כדי לדלג למיקום בנגן.',
  thumbnails: 'תמונות ממוזערות',
  'Time to manifest': 'זמן עד למניפסט',
  'Time to first frame': 'זמן עד לפריים הראשון',
  'Data source': 'מקור נתונים',
  'Inspect live playback health: buffer, timing, dropped frames, and which variant Shaka is using.':
    'בדקו את בריאות הניגון בזמן אמת: באפר, תזמון, פריימים שנפלו ואיזה variant Shaka משתמשת.',
  'Lists every adaptive variant from the manifest. Lock a row to disable ABR and pin playback to that variant.':
    'מציג את כל ה-variants האדפטיביים מהמניפסט. נעלו שורה כדי לכבות ABR ולקבע ניגון ל-variant הזה.',
  'Shows the most recent media segments fetched for the current stream, with size and load timing.':
    'מציג את סגמנטי המדיה האחרונים שנמשכו עבור הסטרים הנוכחי, עם גודל וזמני טעינה.',
  'Chronological log of player lifecycle, adaptation, and errors (newest first).':
    'יומן כרונולוגי של מחזור החיים של הנגן, adaptation ושגיאות (החדשים ביותר קודם).',
  'Public manifest URLs for HLS and DASH outputs, plus raw playlist or MPD text for debugging clients.':
    'כתובות מניפסט ציבוריות לפלטי HLS ו-DASH, ובנוסף טקסט גולמי של playlist או MPD לדיבוג לקוחות.',
  'Container and stream metadata from the Videos probe when the source file was ingested.':
    'מטא-דאטה של קונטיינר וסטרים מ-probe של Videos בעת ingest של קובץ המקור.',
  'Worker state for the source download and every rendition and subtitle encoding job.':
    'מצב ה-worker להורדת המקור ולכל job של קידוד rendition או כתוביות.',
  'Sprite thumbnail cues from the timeline WebVTT. Select a thumbnail to seek the player.':
    'רמזי תמונות ממוזערות מ-WebVTT של ציר הזמן. בחרו תמונה ממוזערת כדי לדלג בנגן.',
}
