/**
 * Hebrew translations for the Videos product (list, profiles, detail tabs,
 * stream player, and debug panel).
 * Keys are the exact English source strings (English is the source of truth).
 */
export const heVideosDictionary: Record<string, string> = {
  // Navigation and titles
  Videos: 'סרטונים',
  'On demand': 'לפי דרישה',
  'On demand and live': 'לפי דרישה ושידור חי',
  Video: 'סרטון',
  Profiles: 'פרופילים',
  Renditions: 'גרסאות קידוד',
  Rendition: 'גרסת קידוד',
  Subtitles: 'כתוביות',
  Captions: 'כתוביות',
  'Back to videos': 'חזרה לסרטונים',
  'Switch video': 'החלפת סרטון',
  'No videos found': 'לא נמצאו סרטונים',
  'Untitled video': 'סרטון ללא שם',
  'Video not found': 'הסרטון לא נמצא',
  "The video you're looking for doesn't exist or you don't have access to it.":
    'הסרטון שחיפשתם לא קיים או שאין לכם גישה אליו.',
  'Loading video...': 'טוען סרטון...',
  'Loading videos...': 'טוען סרטונים...',
  'Loading profiles...': 'טוען פרופילים...',
  'Search videos...': 'חיפוש סרטונים...',
  'Search profiles...': 'חיפוש פרופילים...',
  'No profiles match your search': 'אין פרופילים התואמים לחיפוש',
  'No profiles match your filters': 'אין פרופילים התואמים למסננים',
  'Try a different search term or clear the search.':
    'נסו מונח חיפוש אחר או נקו את החיפוש.',
  profiles: 'פרופילים',
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
  'Source file': 'קובץ מקור',
  'Defaults to the file name': 'ברירת המחדל היא שם הקובץ',
  'This file does not look like a video. The server may reject it.':
    'הקובץ הזה לא נראה כמו סרטון. ייתכן שהשרת ידחה אותו.',
  'Select source video': 'בחירת סרטון מקור',
  'Choose a bucket, then pick or upload a video file.':
    'בחרו באקט, ואז בחרו או העלו קובץ וידאו.',
  'Failed to create video': 'יצירת הסרטון נכשלה',

  // Statuses
  Downloading: 'בהורדה',
  Aborted: 'בוטל',
  Encoding: 'בקידוד',
  'Not encoded': 'לא קודד',
  Encoded: 'קודד',
  Uploading: 'בהעלאה',

  // Profiles
  'Create profile': 'יצירת פרופיל',
  Presets: 'תבניות מוכנות',
  'Custom profile': 'פרופיל מותאם אישית',
  'Create custom profile': 'יצירת פרופיל מותאם אישית',
  'Choose a preset to add a rendition quality. Video codec is chosen by the server when encoding.':
    'בחרו תבנית מוכנה כדי להוסיף איכות לגרסת הקידוד. קודק הווידאו נבחר על ידי השרת בזמן הקידוד.',
  'Set the resolution and bitrate of the rendition. Video codec is chosen by the server when encoding.':
    'הגדירו את הרזולוציה וקצב הסיביות של גרסת הקידוד. קודק הווידאו נבחר על ידי השרת בזמן הקידוד.',
  'Update profile': 'עדכון פרופיל',
  'Delete profile': 'מחיקת פרופיל',
  'Profile created': 'הפרופיל נוצר',
  'Profile updated': 'הפרופיל עודכן',
  'Profile deleted': 'הפרופיל נמחק',
  'Failed to save profile': 'שמירת הפרופיל נכשלה',
  'Failed to delete profile': 'מחיקת הפרופיל נכשלה',
  'Failed to load profiles': 'טעינת הפרופילים נכשלה',
  'The console calls GET /project/profiles on your project API. A server error here usually means the Videos service failed to load or seed profiles for this project, not a problem with your browser.':
    'ה-Console קורא ל-GET /project/profiles ב-API של הפרויקט. שגיאת שרת כאן בדרך כלל מצביעה על כשל בטעינה או ב-seed של פרופילים בשירות Videos בפרויקט, לא על בעיה בדפדפן.',
  'No profiles': 'אין פרופילים',
  'Create a profile to choose the resolution and bitrate of your renditions.':
    'צרו פרופיל כדי לבחור את הרזולוציה וה-Bitrate של גרסאות הקידוד.',
  'Profiles define the resolution and bitrate of each rendition. Use them when encoding a video into adaptive streams.':
    'פרופילים מגדירים את הרזולוציה וה-Bitrate של כל גרסת קידוד. השתמשו בהם כשמקודדים סרטון לסטרימים אדפטיביים.',
  'Profiles define the resolution and bitrate of each rendition. Video codec is chosen by the server when encoding. Use profiles when creating adaptive streams.':
    'פרופילים מגדירים את הרזולוציה וה-Bitrate של כל גרסת קידוד. קודק הווידאו נבחר בשרת בעת הקידוד. השתמשו בפרופילים בעת יצירת סטרימים אדפטיביים.',
  'This video has no detected audio track after prepare. Adaptive streams and the original file will play without sound. Re-upload a file with an audio track or check the source in storage.':
    'לא זוהה מסלול אודיו בסרטון לאחר ההכנה. סטרימים אדפטיביים והקובץ המקורי יתנגנו ללא שמע. העלו מחדש קובץ עם אודיו או בדקו את המקור באחסון.',
  'No audio track was found when the file was probed. Encoding profiles cannot add audio. Replace the storage file with one that includes audio and create a new video, or confirm the original plays with sound on your device.':
    'לא נמצא מסלול אודיו בעת בדיקת הקובץ. פרופילי קידוד לא יוסיפו אודיו. החליפו את קובץ האחסון בקובץ עם אודיו וצרו סרטון חדש, או ודאו שהמקור מתנגן עם שמע במכשיר שלכם.',
  "You don't have permission to manage video profiles.":
    'אין לכם הרשאה לנהל פרופילי וידאו.',
  'Renditions already encoded with this profile stay playable. This action cannot be undone.':
    'גרסאות קידוד שכבר קודדו עם הפרופיל הזה ימשיכו להתנגן. לא ניתן לבטל פעולה זו.',
  'Renditions already encoded with these profiles stay playable. This action cannot be undone.':
    'גרסאות קידוד שכבר קודדו עם הפרופילים האלה ימשיכו להתנגן. לא ניתן לבטל פעולה זו.',
  'Delete profiles': 'מחיקת פרופילים',
  'Failed to delete profiles': 'מחיקת הפרופילים נכשלה',
  'Select all profiles': 'בחירת כל הפרופילים',
  'Select profile': 'בחירת פרופיל',
  '1 profile selected': 'פרופיל אחד נבחר',
  'profiles selected': 'פרופילים נבחרו',
  profile: 'פרופיל',
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

  // Overview
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
  '1 selected profile is larger than the source':
    'פרופיל נבחר אחד גדול מהמקור',
  'selected profiles are larger than the source': 'פרופילים נבחרים גדולים מהמקור',
  'Upscaling adds size without adding detail.':
    'הגדלה מוסיפה נפח בלי להוסיף פרטים.',
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
  'Their segments are removed and players stop receiving these qualities. This action cannot be undone.':
    'הסגמנטים שלהן יימחקו והנגנים יפסיקו לקבל את האיכויות האלה. לא ניתן לבטל פעולה זו.',
  'Delete renditions': 'מחיקת גרסאות קידוד',
  'Failed to delete renditions': 'מחיקת גרסאות הקידוד נכשלה',
  'Failed to retry renditions': 'ניסיון חוזר לגרסאות הקידוד נכשל',
  'Select all renditions': 'בחירת כל גרסאות הקידוד',
  'Select rendition': 'בחירת גרסת קידוד',
  '1 rendition selected': 'גרסת קידוד אחת נבחרה',
  'renditions selected': 'גרסאות קידוד נבחרו',
  rendition: 'גרסת קידוד',
  'Successfully deleted': 'נמחקו בהצלחה',

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
  'Available once a rendition is ready.': 'זמין לאחר שגרסת קידוד מוכנה.',
  Source: 'מקור',
  'Stream format': 'פורמט סטרים',
  'Original file': 'קובץ מקורי',
  'No ready HLS renditions': 'אין גרסאות HLS מוכנות',
  'No ready DASH renditions': 'אין גרסאות DASH מוכנות',
  'No ready CMAF renditions': 'אין גרסאות CMAF מוכנות',
  'No ready renditions': 'אין גרסאות קידוד מוכנות',
  'Processing renditions': 'מעבד גרסאות קידוד',
  'View renditions': 'צפייה בגרסאות קידוד',
  '{encoding} encoding · {ready} of {total} ready':
    '{encoding} בקידוד · {ready} מתוך {total} מוכנות',
  '{failed} failed · {ready} of {total} ready':
    '{failed} נכשלו · {ready} מתוך {total} מוכנות',
  'Some renditions failed': 'חלק מגרסאות הקידוד נכשלו',
  'Renditions are still encoding. Playback starts when one is ready.':
    'גרסאות הקידוד עדיין בעיבוד. הניגון יתחיל כשאחת מהן תהיה מוכנה.',
  'No renditions are ready to play.': 'אין גרסאות קידוד מוכנות לניגון.',
  'This video has no source to play.': 'לסרטון הזה אין מקור לניגון.',
  'Auto quality': 'איכות אוטומטית',
  'Subtitles off': 'ללא כתוביות',
  'New renditions are ready.': 'גרסאות קידוד חדשות מוכנות.',
  'Reload stream': 'טעינה מחדש של הסטרים',
  'Go to start': 'חזרה להתחלה',
  'Go to end': 'מעבר לסוף',
  Mute: 'השתקה',
  Unmute: 'ביטול השתקה',
  Volume: 'עוצמת קול',
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
  Stats: 'סטטיסטיקות',
  Level: 'רמה',
  Lock: 'נעילה',
  Reload: 'טעינה מחדש',
  Throughput: 'קצב העברה',
  'Waiting for the player': 'ממתין לנגן',
  "The inspector follows the video playing on a video's Overview page. Open one to continue inspecting.":
    'הבודק עוקב אחרי הווידאו שמתנגן בדף הסקירה של הווידאו. פתחו אותו כדי להמשיך לבדוק.',
  'Go to overview': 'מעבר לסקירה',
  Inspector: 'בודק',
  'Close inspector': 'סגירת הבודק',
  'Show window': 'הצגת החלון',
  'The inspector is open in a separate window.': 'הבודק פתוח בחלון נפרד.',
  'Your browser blocked the window. Allow pop-ups for this site to open the inspector.':
    'הדפדפן חסם את החלון. אפשרו חלונות קופצים לאתר כדי לפתוח את הבודק.',
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
  'Worker state for rendition and subtitle encoding jobs.':
    'מצב ה-worker לקידוד renditions וכתוביות.',
  'Create your first renditions': 'צרו את גרסאות הקידוד הראשונות שלכם',
  'Encode this video into HLS, DASH, or CMAF for adaptive streaming. Until then, the player uses the original Storage file.':
    'קודדו את הסרטון ל-HLS, DASH או CMAF לסטרימינג אדפטיבי. עד אז, הנגן משתמש בקובץ המקורי ב-Storage.',
  'Encode this video into HLS, DASH, or CMAF renditions to stream it with adaptive bitrate.':
    'קודדו את הסרטון ל-renditions של HLS, DASH או CMAF כדי להזרים אותו עם bitrate אדפטיבי.',
  'Select codec': 'בחרו codec',
  'Video created': 'הסרטון נוצר',
  'Choose a video file from Storage. Appwrite probes the file and lets you encode renditions for adaptive streaming.':
    'בחרו קובץ וידאו מ-Storage. Appwrite סורקת את הקובץ ומאפשרת לקודד renditions לסטרימינג אדפטיבי.',
  'Sprite thumbnail cues from the timeline WebVTT. Select a thumbnail to seek the player.':
    'רמזי תמונות ממוזערות מ-WebVTT של ציר הזמן. בחרו תמונה ממוזערת כדי לדלג בנגן.',

  // Workspace: sidebar, submenu, and welcome state
  'All videos': 'כל הסרטונים',
  Breadcrumb: 'נתיב ניווט',
  'Video sections': 'אזורי הסרטון',
  Media: 'מדיה',
  Streaming: 'סטרימינג',
  Debugger: 'דיבאגר',
  'Encoding profiles': 'פרופילי קידוד',
  'Select a video': 'בחרו סרטון',
  'Stream video with Appwrite': 'סטרימינג של וידאו עם Appwrite',
  'Pick a video from the list to play it, encode renditions, add subtitles, and get streaming URLs.':
    'בחרו סרטון מהרשימה כדי לנגן אותו, לקודד גרסאות קידוד, להוסיף כתוביות ולקבל כתובות לסטרימינג.',
  'Turn files in Storage into adaptive streams that play smoothly on any device and connection.':
    'הפכו קבצים באחסון לסטרימים אדפטיביים שמתנגנים בצורה חלקה בכל מכשיר ובכל חיבור.',
  'Create a video': 'יצירת סרטון',
  'Pick a video or audio file you uploaded to Storage. The file stays in its bucket.':
    'בחרו קובץ וידאו או אודיו שהעליתם לאחסון. הקובץ נשאר בבאקט שלו.',
  'Give the manifest URL to any HLS or DASH player. Quality adapts to each viewer.':
    'העבירו את כתובת המניפסט לכל נגן HLS או DASH. האיכות מתאימה את עצמה לכל צופה.',
  'Stream it': 'הזרמה',
  'Sort videos': 'מיון סרטונים',
  'No videos match your search.': 'אין סרטונים שתואמים לחיפוש.',
  'No videos match your filters.': 'אין סרטונים שתואמים למסננים.',
  'No videos yet. Create one from a Storage file.':
    'אין עדיין סרטונים. צרו סרטון מקובץ באחסון.',
  'Live streaming is coming soon. Ingest a live feed and deliver it with the same HLS and DASH outputs.':
    'שידור חי יגיע בקרוב. קליטת שידור חי והפצה שלו באותם פלטי HLS ו-DASH.',
  'Duration (longest first)': 'משך (הארוך ביותר קודם)',
  'Duration (shortest first)': 'משך (הקצר ביותר קודם)',
  'Resolution (highest first)': 'רזולוציה (הגבוהה ביותר קודם)',
  'Resolution (lowest first)': 'רזולוציה (הנמוכה ביותר קודם)',
  'Size (largest first)': 'גודל (הגדול ביותר קודם)',
  'Size (smallest first)': 'גודל (הקטן ביותר קודם)',
  'Res.': 'רזולוציה',
  Preparing: 'בהכנה',
  'Source failed': 'הכנת המקור נכשלה',
  'Timeline generated': 'ציר הזמן נוצר',
  'Time range': 'טווח זמן',
  'Sprite region': 'אזור ב-Sprite sheet',
  'Scroll through the thumbnails and hover one to see its time range and sprite region. Select one to use it as the preview image.':
    'גללו בין התמונות הממוזערות ורחפו מעל אחת כדי לראות את טווח הזמן ואזור הספרייט שלה. בחרו אחת כדי להשתמש בה כתמונת התצוגה המקדימה.',
  'The frame shown before playback starts. Select a thumbnail above or scrub through the video to choose it.':
    'הפריים שמוצג לפני תחילת הניגון. בחרו תמונה ממוזערת למעלה או גללו לאורך הווידאו כדי לבחור אותו.',
  'Generate a timeline to choose a preview image.':
    'צרו ציר זמן כדי לבחור תמונת תצוגה מקדימה.',
  'Selected frame': 'הפריים שנבחר',
  'Previous frame': 'הפריים הקודם',
  'Next frame': 'הפריים הבא',
  'Preview frame': 'פריים לתצוגה מקדימה',
  'Set as preview': 'הגדרה כתצוגה מקדימה',
  "Saving a preview frame isn't available yet. The Videos API can't set a video's poster frame.":
    'שמירת פריים לתצוגה מקדימה עדיין לא זמינה. ה-API של הווידאו עדיין לא תומך בהגדרת פריים פוסטר.',
  'Sprite sheet': 'Sprite sheet',
  'Ready to stream': 'מוכן לסטרימינג',
  'HLS manifest URL': 'כתובת מניפסט HLS',
  'Copy HLS manifest URL': 'העתקת כתובת מניפסט HLS',

  // Source preparation
  chunks: 'חלקים',
  'Choose a video or audio file from Storage. The file stays in its bucket, and you can encode renditions for adaptive streaming.':
    'בחרו קובץ וידאו או אודיו מהאחסון. הקובץ נשאר בבאקט שלו, ותוכלו לקודד גרסאות קידוד לסטרימינג אדפטיבי.',
  'This file does not look like video or audio. The server may reject it.':
    'הקובץ לא נראה כמו וידאו או אודיו. השרת עשוי לדחות אותו.',

  // Overview
  'Play the video, follow processing, and see what the source file contains.':
    'נגנו את הסרטון, עקבו אחרי העיבוד וראו מה קובץ המקור מכיל.',
  'Debug playback': 'דיבוג ניגון',
  'Renditions ready': 'גרסאות קידוד מוכנות',
  'Source size': 'גודל המקור',
  'Source details': 'פרטי המקור',
  'Encode renditions': 'קידוד גרסאות',
  'Pick profiles and an output format such as HLS.':
    'בחרו פרופילים ופורמט פלט כמו HLS.',
  'Add subtitles': 'הוספת כתוביות',
  'Upload WebVTT or SRT files per language, or write them in the editor.':
    'העלו קובצי WebVTT או SRT לכל שפה, או כתבו אותם בעורך.',
  'Upload subtitle': 'העלאת כתובית',
  'Generate a timeline': 'יצירת ציר זמן',
  'Thumbnails for scrubbing and poster images.':
    'תמונות ממוזערות לגלילה בציר הזמן ולתמונות פוסטר.',
  'Open timeline': 'פתיחת ציר הזמן',
  'Copy a manifest URL into your player.': 'העתיקו כתובת מניפסט לנגן שלכם.',
  'View streaming URLs': 'הצגת כתובות לסטרימינג',
  'Get streaming': 'מתחילים לשדר',
  '{done} of {total} required steps done':
    '{done} מתוך {total} שלבי חובה הושלמו',

  // Renditions
  'Each rendition is one quality level of this video in one output format. Players switch between them as bandwidth changes.':
    'כל גרסת קידוד היא רמת איכות אחת של הסרטון בפורמט פלט אחד. נגנים עוברים ביניהן לפי רוחב הפס.',
  'All outputs': 'כל הפלטים',
  'All statuses': 'כל הסטטוסים',
  'No renditions match your filters': 'אין גרסאות קידוד שתואמות למסננים',
  'Try a different output or status.': 'נסו פלט או סטטוס אחר.',
  Profile: 'פרופיל',
  'Segment length': 'אורך סגמנט',
  'Media playlist URL': 'כתובת Media playlist',
  'Copy media playlist URL': 'העתקת כתובת Media playlist',
  'Open media playlist': 'פתיחת Media playlist',
  renditions: 'גרסאות קידוד',
  'Rendition ID': 'מזהה גרסת קידוד',
  'Profile name': 'שם פרופיל',
  'Video bitrate (kbps)': 'Bitrate וידאו (kbps)',
  'Audio bitrate (kbps)': 'Bitrate אודיו (kbps)',
  Progress: 'התקדמות',
  'Started at': 'התחיל ב-',
  'Ended at': 'הסתיים ב-',
  Started: 'התחיל',
  Ended: 'הסתיים',
  Uploading: 'מעלה',
  Aborted: 'בוטל',

  // Subtitles
  'Text tracks in any language. Every HLS, DASH, and CMAF manifest lists them, so viewers can pick one in the player.':
    'רצועות טקסט בכל שפה. כל מניפסט HLS, DASH ו-CMAF כולל אותן, כך שצופים יכולים לבחור רצועה בנגן.',
  'Embedded in source': 'מוטמע במקור',
  'Remove default': 'הסרת ברירת המחדל',
  'Subtitle URL': 'כתובת הכתובית',
  'Copy HLS subtitle URL': 'העתקת כתובת כתובית HLS',
  'Copy DASH subtitle URL': 'העתקת כתובת כתובית DASH',
  'Copy CMAF subtitle URL': 'העתקת כתובת כתובית CMAF',
  'Open subtitle file': 'פתיחת קובץ הכתובית',
  'Embedded tracks are extracted only once, so this track will not come back. This action cannot be undone.':
    'רצועות מוטמעות מחולצות פעם אחת בלבד, ולכן הרצועה הזו לא תחזור. לא ניתן לבטל את הפעולה.',

  // Timeline
  'Thumbnails sampled across the video. Players show them while scrubbing, and any frame works as a poster image.':
    'תמונות ממוזערות שנדגמו לאורך הסרטון. נגנים מציגים אותן בזמן גלילה בציר הזמן, וכל פריים יכול לשמש כתמונת פוסטר.',
  'Regenerate timeline': 'יצירה מחדש של ציר הזמן',
  'Loading timeline...': 'טוען את ציר הזמן...',
  'Generating timeline': 'יוצר ציר זמן',
  'Sampling frames into sprite sheets. Thumbnails appear here when ready.':
    'דוגם פריימים ל-sprite sheets. התמונות הממוזערות יופיעו כאן כשיהיו מוכנות.',
  'No timeline yet': 'אין עדיין ציר זמן',
  'Generate a timeline to get scrubbing thumbnails and a preview image for this video.':
    'צרו ציר זמן כדי לקבל תמונות ממוזערות לגלילה ותמונת תצוגה מקדימה לסרטון.',
  Thumbnails: 'תמונות ממוזערות',
  'Sprite sheets': 'Sprite sheets',
  'Thumbnail size': 'גודל תמונה ממוזערת',
  'Timeline file': 'קובץ ציר הזמן',
  'A WebVTT file that maps time ranges to sprite sheet regions. Point your player thumbnail option at this URL.':
    'קובץ WebVTT שממפה טווחי זמן לאזורים ב-sprite sheet. הגדירו את כתובת התמונות הממוזערות בנגן לכתובת הזו.',
  'Timeline URL': 'כתובת ציר הזמן',
  'First lines': 'שורות ראשונות',
  'Preview image': 'תמונת תצוגה מקדימה',

  // Streaming
  'Give a master manifest URL to any HLS or DASH player. It lists every ready rendition and subtitle, and the player picks the best quality for each viewer.':
    'העבירו כתובת מניפסט ראשי לכל נגן HLS או DASH. הוא כולל כל גרסת קידוד וכתובית מוכנות, והנגן בוחר את האיכות הטובה ביותר לכל צופה.',
  'Nothing to stream yet': 'אין עדיין מה לשדר',
  'Manifests become available as soon as the first rendition for that output is ready.':
    'המניפסטים זמינים ברגע שגרסת הקידוד הראשונה של אותו פלט מוכנה.',
  'Use HLS for Apple devices and most web players, DASH for Android and Shaka Player. CMAF serves both from one set of files.':
    'השתמשו ב-HLS למכשירי Apple ולרוב נגני הווב, וב-DASH ל-Android ול-Shaka Player. CMAF מגיש את שניהם מאותה קבוצת קבצים.',
  'No ready renditions for this output yet':
    'אין עדיין גרסאות קידוד מוכנות לפלט הזה',
  'Manifest, segment, and subtitle requests use the read permissions of the source file in Storage. Grant read access to Any for public playback, or to signed-in users for private videos.':
    'בקשות למניפסטים, לסגמנטים ולכתוביות משתמשות בהרשאות הקריאה של קובץ המקור באחסון. תנו הרשאת קריאה ל-Any לניגון ציבורי, או למשתמשים מחוברים לסרטונים פרטיים.',
  'Open bucket': 'פתיחת הבאקט',
  'Native HTML': 'HTML מובנה',
  'Install a player': 'התקנת נגן',
  '{index} of {total}': '{index} מתוך {total}',
  'Install {player}': 'התקנת {player}',
  '{player} documentation': 'הדוקומנטציה של {player}',
  'All streaming URLs': 'כל כתובות הסטרימינג',
  'Add to build.gradle.kts (module)': 'הוספה ל-build.gradle.kts (מודול)',
  'Allow network access in AndroidManifest.xml':
    'מתן גישה לרשת ב-AndroidManifest.xml',
  'Install the iOS pods': 'התקנת ה-pods של iOS',
  'Nothing to install. Safari and iOS play HLS natively. Other browsers need a library such as hls.js.':
    'אין צורך בהתקנה. Safari ו-iOS מנגנים HLS באופן מובנה. דפדפנים אחרים צריכים ספרייה כמו hls.js.',
  'Nothing to install. AVPlayer is part of AVKit on iOS, iPadOS, tvOS, visionOS, and macOS.':
    'אין צורך בהתקנה. AVPlayer הוא חלק מ-AVKit ב-iOS, iPadOS, tvOS, visionOS ו-macOS.',
  'Subtitle tracks': 'רצועות כתוביות',
  'Master manifests already include these. Use the direct URLs to load a track yourself. For DASH the URL returns the WebVTT file.':
    'המניפסטים הראשיים כבר כוללים אותן. השתמשו בכתובות הישירות כדי לטעון רצועה בעצמכם. ב-DASH הכתובת מחזירה את קובץ ה-WebVTT.',
  'No subtitle tracks. Add them from the Subtitles page.':
    'אין רצועות כתוביות. הוסיפו אותן מדף הכתוביות.',
  'Track is still being packaged': 'הרצועה עדיין באריזה',
  'Individual streams and segments': 'סטרימים וסגמנטים בודדים',
  'Advanced. Players reach these from the master manifest. Use them to debug one quality level or fetch a single segment.':
    'מתקדם. נגנים מגיעים אליהם דרך המניפסט הראשי. השתמשו בהם כדי לדבג רמת איכות אחת או למשוך סגמנט בודד.',
  'Available once a rendition is ready.': 'זמין אחרי שגרסת קידוד מוכנה.',
  'Stream index': 'אינדקס סטרים',
  'Segment ID': 'מזהה סגמנט',
  'From the media playlist': 'מתוך ה-Media playlist',
  'Media playlist': 'Media playlist',
  'DASH has no per-stream playlist. Segments are listed in the MPD.':
    'ב-DASH אין playlist לכל סטרים. הסגמנטים מופיעים ב-MPD.',
  Segment: 'סגמנט',
  'Enter a segment ID': 'הזינו מזהה סגמנט',
  'Subtitle track': 'רצועת כתוביות',
  'From the subtitle playlist': 'מתוך ה-playlist של הכתוביות',
  'Subtitle segment': 'סגמנט כתוביות',

  // Debugger
  'Play each output the way a client would and inspect manifests, quality switches, buffer health, and player events.':
    'נגנו כל פלט כמו שלקוח היה מנגן אותו, ובדקו מניפסטים, מעברי איכות, מצב הבאפר ואירועי נגן.',

  // Settings and profiles
  'Reusable presets for resolution and bitrate, shared by every video in this project. Pick one or more when you create renditions.':
    'הגדרות קבועות לשימוש חוזר של רזולוציה ו-bitrate, משותפות לכל הסרטונים בפרויקט. בחרו אחת או יותר כשאתם יוצרים גרסאות קידוד.',
  'Video bitrate (bps)': 'Bitrate וידאו (bps)',
  'Sort profiles': 'מיון פרופילים',
  'Video bitrate (highest first)': 'Bitrate וידאו (הגבוה ביותר קודם)',
  'Video bitrate (lowest first)': 'Bitrate וידאו (הנמוך ביותר קודם)',
  'Audio bitrate (bps)': 'Bitrate אודיו (bps)',

  // Glossary tooltips
  'One encoded copy of the video at a set resolution and bitrate. Players switch between renditions as bandwidth changes.':
    'עותק מקודד אחד של הסרטון ברזולוציה וב-bitrate קבועים. נגנים עוברים בין גרסאות הקידוד לפי רוחב הפס.',
  'A reusable encoding preset: resolution plus video and audio bitrate. Each rendition is encoded from one profile.':
    'הגדרת קידוד לשימוש חוזר: רזולוציה ו-bitrate של וידאו ואודיו. כל גרסת קידוד מקודדת מפרופיל אחד.',
  'The streaming format a rendition is packaged in: HLS, DASH, or CMAF.':
    'פורמט הסטרימינג שבו גרסת הקידוד נארזת: HLS, DASH או CMAF.',
  "HTTP Live Streaming. Apple's format: native on Safari and iOS, and works elsewhere with hls.js.":
    'HTTP Live Streaming. הפורמט של Apple: נתמך באופן מובנה ב-Safari וב-iOS, ובשאר המקומות עובד עם hls.js.',
  'MPEG-DASH. An open standard supported by most web and TV players, but not native Safari.':
    'MPEG-DASH. תקן פתוח שנתמך ברוב נגני הווב והטלוויזיה, אבל לא באופן מובנה ב-Safari.',
  'Encodes once and serves the same segments as both HLS and DASH. Saves storage and encoding time.':
    'מקודד פעם אחת ומגיש את אותם סגמנטים גם כ-HLS וגם כ-DASH. חוסך אחסון וזמן קידוד.',
  'The playlist a player loads first. It lists every rendition and subtitle so the player can pick the best one.':
    'ה-playlist שהנגן טוען ראשון. הוא כולל כל גרסת קידוד וכתובית, כדי שהנגן יוכל לבחור את המתאימה ביותר.',
  'A playlist for a single rendition stream. Players reach it from the master manifest.':
    'Playlist לסטרים בודד של גרסת קידוד. נגנים מגיעים אליו דרך המניפסט הראשי.',
  'A short chunk of video, a few seconds long. Players download segments one after another.':
    'קטע וידאו קצר, באורך של כמה שניות. נגנים מורידים סגמנטים אחד אחרי השני.',
  'Adaptive bitrate: the player picks a quality automatically based on network speed.':
    'Bitrate אדפטיבי: הנגן בוחר איכות אוטומטית לפי מהירות הרשת.',
  'Data per second of video. Higher bitrate means better quality and bigger files.':
    'כמות נתונים לכל שנייה של וידאו. Bitrate גבוה יותר פירושו איכות טובה יותר וקבצים גדולים יותר.',
  'Thumbnail sprite sheets plus a WebVTT index. Players use it to show previews while scrubbing.':
    'Sprite sheets של תמונות ממוזערות ואינדקס WebVTT. נגנים משתמשים בהם כדי להציג תצוגה מקדימה בזמן גלילה.',
  'A single image that packs many thumbnails into a grid.':
    'תמונה אחת שמרכזת תמונות ממוזערות רבות ברשת.',
  'An image cut from the timeline. The first one is used as the video poster.':
    'תמונה שנחתכה מציר הזמן. הראשונה משמשת כפוסטר של הסרטון.',
  'Extracted automatically from the source file. Retag its language if it shows as und.':
    'חולצה אוטומטית מקובץ המקור. עדכנו את השפה אם היא מופיעה כ-und.',
  'Players turn on the default subtitle automatically.':
    'נגנים מפעילים את כתובית ברירת המחדל אוטומטית.',
  'ISO 639-2 three-letter code, like eng or heb. und means undetermined.':
    'קוד ISO 639-2 בן שלוש אותיות, כמו eng או heb. und פירושו לא מוגדר.',
  'The longest segment in this rendition, in seconds.':
    'הסגמנט הארוך ביותר בגרסת הקידוד, בשניות.',
  'The profile is larger than the source. Upscaling adds size without adding detail.':
    'הפרופיל גדול מהמקור. הגדלה מוסיפה נפח בלי להוסיף פרטים.',
  'Position of a stream (track) inside the rendition, starting at 0.':
    'מיקום הסטרים (הרצועה) בתוך גרסת הקידוד, החל מ-0.',
  'The compression format of a stream, like H.264 for video or AAC for audio.':
    'פורמט הדחיסה של סטרים, כמו H.264 לווידאו או AAC לאודיו.',
  'The file format that wraps the audio and video streams, like MP4.':
    'פורמט הקובץ שעוטף את סטרימי האודיו והווידאו, כמו MP4.',
  'Rename the video, see its source file, or delete it.':
    'שנו את שם הווידאו, צפו בקובץ המקור שלו או מחקו אותו.',
  'Read from the file during the first encoding job.':
    'נקרא מהקובץ במהלך משימת הקידוד הראשונה.',
  'Metadata appears once the first rendition or timeline job reads the file.':
    'המטא-דאטה תופיע אחרי שמשימת ה-rendition או ציר הזמן הראשונה תקרא את הקובץ.',
  'Write captions in the editor, or import WebVTT or SRT files from Storage. Tracks embedded in the source file are extracted automatically by the first rendition or timeline job.':
    'כתבו כתוביות בעורך, או ייבאו קובצי WebVTT או SRT מהאחסון. רצועות שמוטמעות בקובץ המקור מחולצות אוטומטית במשימת ה-rendition או ציר הזמן הראשונה.',
  'Audio-only videos have no frames for a timeline.':
    'בווידאו של אודיו בלבד אין פריימים לציר זמן.',
  'Choose encoding profiles and an output format. Each profile becomes one quality level, and Appwrite reads the file metadata on the first job.':
    'בחרו פרופילי קידוד ופורמט פלט. כל פרופיל הופך לרמת איכות אחת, ו-Appwrite קורא את המטא-דאטה של הקובץ במשימה הראשונה.',
  'The Storage file this video was created from. Each rendition or timeline job downloads its own copy and deletes it when done.':
    'קובץ האחסון שממנו נוצר הווידאו. כל משימת rendition או ציר זמן מורידה עותק משלה ומוחקת אותו בסיום.',
  'Bitrate ladder': 'סולם קצבי סיביות',
  'Every rendition in the manifest. The marker shows the current bandwidth estimate: renditions to its left fit the connection.':
    'כל גרסאות הקידוד במניפסט. הסמן מציג את הערכת רוחב הפס הנוכחית: גרסאות משמאלו מתאימות לחיבור.',
  Average: 'ממוצע',
  'Watch time': 'זמן צפייה',
  'Time per rendition': 'זמן לכל גרסת קידוד',
  'Share of watch time spent on each rendition.':
    'החלק מזמן הצפייה שעבר על כל גרסת קידוד.',
  'Play the video to measure time per rendition.':
    'הפעילו את הווידאו כדי למדוד זמן לכל גרסת קידוד.',
  'Switch history': 'היסטוריית מעברים',
  'Every rendition change, newest first.':
    'כל מעבר בין גרסאות קידוד, מהחדש לישן.',
  'No rendition switches yet.': 'עדיין אין מעברים בין גרסאות קידוד.',
  Playlists: 'פלייליסטים',
  'Experience score': 'ציון חוויה',
  'How this session feels to a viewer, from 0 to 100.':
    'איך הסשן הזה מרגיש לצופה, בסולם של 0 עד 100.',
  Diagnostics: 'אבחון',
  'Issues detected in this session and how to fix them.':
    'בעיות שזוהו בסשן הזה ואיך לתקן אותן.',
  Manifest: 'מניפסט',
  Rebuffering: 'שיעור עצירות',
  Stalls: 'עצירות',
  'Average bitrate': 'קצב סיביות ממוצע',
  'Share of top rendition': 'יחס לגרסה העליונה',
  'Quality switches': 'מעברי איכות',
  'Locked by you': 'ננעל על ידיכם',
  Upscaling: 'הגדלת תמונה',
  Seeks: 'דילוגים',
  'Media timeline': 'ציר זמן מדיה',
  'How much video is downloaded ahead of the playhead, and the quality of every downloaded segment. Select a point on the buffer bar to seek.':
    'כמה וידאו כבר הורד לפני נקודת הניגון, ובאיזו איכות הורד כל סגמנט. בחרו נקודה בפס הבאפר כדי לדלג אליה.',
  'The rest of the video is downloaded, so playback cannot stall.':
    'שאר הווידאו כבר הורד, כך שהניגון לא ייעצר.',
  '{seconds} s is downloaded ahead of the playhead, enough to ride out network hiccups.':
    '{seconds} שניות הורדו מראש, מספיק כדי לעבור הפרעות קצרות ברשת.',
  'Only {seconds} s is downloaded ahead. Playback may stall if the network slows down.':
    'רק {seconds} שניות הורדו מראש. הניגון עלול להיעצר אם הרשת תאט.',
  'Almost nothing is downloaded ahead of the playhead. Playback is likely to stall.':
    'כמעט שום דבר לא הורד מראש. סביר שהניגון ייעצר.',
  'Ready to play': 'מוכן לניגון',
  'Not downloaded': 'לא הורד',
  'Segment quality': 'איכות הסגמנטים',
  'Brighter means higher quality.': 'בהיר יותר פירושו איכות גבוהה יותר.',
  'Waiting for playback': 'ממתין להפעלה',
  'Playback failed': 'ההפעלה נכשלה',
  Excellent: 'מצוין',
  Good: 'טוב',
  Fair: 'סביר',
  Poor: 'חלש',
  Startup: 'זמן פתיחה',
  'Time until the first frame': 'הזמן עד לפריים הראשון',
  Smoothness: 'רציפות',
  'Stalls and time spent rebuffering': 'עצירות וזמן המתנה לטעינה',
  'Picture quality': 'איכות תמונה',
  'Bitrate played and upscaling': 'קצב הסיביות שהתנגן והגדלת תמונה',
  Stability: 'יציבות',
  'Frames dropped while decoding': 'פריימים שנפלו בזמן פענוח',
  'Slow startup': 'פתיחה איטית',
  'Viewers wait too long for the first frame. Shorter segments or a lower starting rendition help.':
    'הצופים מחכים יותר מדי לפריים הראשון. סגמנטים קצרים יותר או גרסת פתיחה נמוכה יותר יעזרו.',
  'Playback stalled': 'ההפעלה נעצרה',
  'The buffer ran empty. Compare bandwidth with the bitrate below to see if the ladder needs a lower rung.':
    'הבאפר התרוקן. השוו את רוחב הפס לקצב הסיביות למטה כדי לבדוק אם הסולם צריך שלב נמוך יותר.',
  'Bandwidth below the lowest rendition': 'רוחב הפס נמוך מהגרסה הנמוכה ביותר',
  'Even the smallest rendition barely fits this connection. Add a lower bitrate rendition for slow networks.':
    'גם גרסת הקידוד הקטנה ביותר בקושי מתאימה לחיבור הזה. הוסיפו גרסה בקצב סיביות נמוך יותר לרשתות איטיות.',
  'Video is upscaled': 'הווידאו מוגדל',
  'The player is larger than the rendition it receives, so the picture looks soft. Add a higher resolution rendition.':
    'הנגן גדול מגרסת הקידוד שהוא מקבל, ולכן התמונה נראית רכה מדי. הוסיפו גרסה ברזולוציה גבוהה יותר.',
  'Frames are being dropped': 'פריימים נופלים',
  'This device struggles to decode the stream. A lower frame rate or a lighter codec helps.':
    'המכשיר הזה מתקשה לפענח את הסטרים. קצב פריימים נמוך יותר או codec קל יותר יעזרו.',
  'Quality keeps dropping': 'האיכות יורדת שוב ושוב',
  'The player switched down several times. Closer bitrate steps between renditions make switches less visible.':
    'הנגן ירד באיכות כמה פעמים. מרווחים קטנים יותר בין קצבי הסיביות של הגרסאות הופכים את המעברים לפחות מורגשים.',
  'Single rendition': 'גרסת קידוד יחידה',
  'With one rendition the player cannot adapt to the network. Add more renditions for adaptive streaming.':
    'עם גרסת קידוד אחת הנגן לא יכול להסתגל לרשת. הוסיפו גרסאות נוספות לסטרימינג אדפטיבי.',
  'Progressive playback': 'הפעלה פרוגרסיבית',
  'The original file plays as a single download, without adaptive bitrate.':
    'הקובץ המקורי מתנגן כהורדה אחת, בלי קצב סיביות אדפטיבי.',
  'No issues detected': 'לא זוהו בעיות',
  'Startup, smoothness, and picture quality all look healthy.':
    'זמן הפתיחה, הרציפות ואיכות התמונה נראים תקינים.',
  'Playing bitrate': 'קצב סיביות מתנגן',
  'Bandwidth and bitrate': 'רוחב פס וקצב סיביות',
  'Network throughput against the rendition being played. Dashed lines are your renditions; red marks stalls.':
    'תפוקת הרשת מול גרסת הקידוד שמתנגנת. הקווים המקווקווים הם גרסאות הקידוד שלכם, והאדום מסמן עצירות.',
  'Collecting samples...': 'אוסף דגימות...',
  'Buffer health': 'מצב הבאפר',
  'Seconds of video ready ahead of the playhead. Below the target line, stalls become likely.':
    'שניות של וידאו מוכנות לפני ראש ההפעלה. מתחת לקו היעד, עצירות הופכות לסבירות.',
  'Rendered height': 'גובה מוצג',
  'Playback rate': 'מהירות הפעלה',
  'Live latency': 'השהיית שידור חי',
  'Audio tracks': 'רצועות אודיו',
  'Session details': 'פרטי הסשן',
  Transferred: 'הועבר',
  'Average throughput': 'תפוקה ממוצעת',
  'Median time to first byte': 'חציון זמן עד לבייט הראשון',
  'Slowest 5% load time': 'זמן טעינה של 5% האיטיים',
  'Waiting for first byte': 'המתנה לבייט הראשון',
  'Media time': 'זמן מדיה',
  'Time to first byte': 'זמן עד לבייט הראשון',
  Waterfall: 'מפל בקשות',
  'Request URL': 'כתובת הבקשה',
  Playlist: 'פלייליסט',
  'Select a manifest to view it.': 'בחרו מניפסט כדי לצפות בו.',
  Lines: 'שורות',
  'Copy contents': 'העתקת התוכן',
  'Filter events...': 'סינון אירועים...',
  Info: 'מידע',
  Warnings: 'אזהרות',
  'Copy log': 'העתקת הלוג',
  'No events match your filters.': 'אין אירועים שתואמים לסינון.',
  'This stream on this device': 'הסטרים הזה במכשיר הזה',
  'The browser decoder checks each rendition: whether it can play it, play it without dropping frames, and play it with hardware acceleration.':
    'מפענח הדפדפן בודק כל גרסת קידוד: אם הוא יכול לנגן אותה, לנגן אותה בלי לאבד פריימים ולנגן אותה עם האצת חומרה.',
  'Rendition checks are only available for adaptive streams.':
    'בדיקות גרסאות קידוד זמינות רק לסטרימים אדפטיביים.',
  'Some renditions cannot be decoded here. The player skips them, so viewers on this device never get that quality.':
    'חלק מגרסאות הקידוד לא ניתנות לפענוח כאן. הנגן מדלג עליהן, כך שצופים במכשיר הזה לא יקבלו את האיכות הזו.',
  'Some renditions may drop frames on this device. Consider a lighter codec profile or a lower frame rate.':
    'חלק מגרסאות הקידוד עלולות לאבד פריימים במכשיר הזה. שקלו פרופיל codec קל יותר או קצב פריימים נמוך יותר.',
  Supported: 'נתמך',
  Smooth: 'חלק',
  'Hardware decoding': 'פענוח בחומרה',
  'Fits player': 'מתאים לנגן',
  'Larger than the player': 'גדול מהנגן',
  'Codec support': 'תמיכה ב-Codecs',
  'Playback capabilities': 'יכולות הפעלה',
  'Media Source Extensions': 'Media Source Extensions',
  'Native HLS': 'HLS מובנה',
  'Picture in picture': 'תמונה בתוך תמונה',
  'Display and network': 'תצוגה ורשת',
  Screen: 'מסך',
  'HDR display': 'תצוגת HDR',
  'Wide color (P3)': 'צבע רחב (P3)',
  'Downlink estimate': 'הערכת מהירות הורדה',
  'Round trip time': 'זמן הלוך ושוב',
  'Data saver': 'חיסכון בנתונים',
  Browser: 'דפדפן',
  Buffering: 'ממלא באפר',
  'Experience score, diagnostics, and live charts for this playback session.':
    'ציון חוויה, אבחון וגרפים חיים לסשן ההפעלה הזה.',
  'The bitrate ladder, which rendition is playing, and every adaptive switch.':
    'סולם קצבי הסיביות, איזו גרסת קידוד מתנגנת וכל מעבר אדפטיבי.',
  'Every segment request with timing, throughput, and a waterfall.':
    'כל בקשת סגמנט עם תזמון, תפוקה ומפל בקשות.',
  'The player event log, newest first.': 'לוג האירועים של הנגן, מהחדש לישן.',
  'Master and media playlists as the player fetched them, with syntax highlighting.':
    'הפלייליסט הראשי ופלייליסטי המדיה כפי שהנגן הוריד אותם, עם הדגשת תחביר.',
  'What this browser and screen can decode and display, checked against this stream.':
    'מה הדפדפן והמסך האלה יכולים לפענח ולהציג, בבדיקה מול הסטרים הזה.',
  'Copy summary': 'העתקת סיכום',
  'Export session': 'ייצוא הסשן',
  Native: 'מובנה',
  'Press 1 to 6 to switch sections': 'הקישו 1 עד 6 כדי לעבור בין חלקים',
  Snapshot: 'תמונת מצב',
  Seek: 'דילוג',
  Play: 'הפעלה',
  'Reset session data': 'איפוס נתוני הסשן',
  'Clear collected data': 'ניקוי הנתונים שנאספו',
  'Empties charts, segments, and events. Playback continues.':
    'מרוקן את התרשימים, הסגמנטים והאירועים. הניגון ממשיך.',
  'Restart session': 'הפעלה מחדש של הסשן',
  'Reloads the stream and measures everything from startup.':
    'טוען מחדש את הסטרים ומודד הכול מרגע ההפעלה.',
  'Bandwidth is far above your highest rendition, so it runs along the top of the chart. Hover to see exact values.':
    'רוחב הפס גבוה בהרבה מגרסת הקידוד הגבוהה ביותר שלכם, ולכן הוא מוצג לאורך החלק העליון של התרשים. העבירו את העכבר כדי לראות ערכים מדויקים.',
  'Start from a preset': 'התחלה מתבנית מוכנה',
  'Pick a ready-made rung like 1080p or 720p, or set the width, height, and bitrates yourself.':
    'בחרו דרגה מוכנה כמו 1080p או 720p, או הגדירו בעצמכם רוחב, גובה ו-Bitrate.',
  'Each profile produces one rendition of a video. Combine several to build a quality ladder.':
    'כל פרופיל מפיק גרסת קידוד אחת של הסרטון. שלבו כמה פרופילים כדי לבנות סולם איכויות.',
  'Stream adaptively': 'הזרמה אדפטיבית',
  'Players switch between renditions as bandwidth changes, so playback stays smooth on any network.':
    'הנגנים עוברים בין גרסאות הקידוד לפי רוחב הפס, כך שהניגון נשאר חלק בכל רשת.',
  'Define your quality ladder': 'הגדרת סולם האיכויות',
  'Encoding profiles set the resolution and bitrate of every rendition, so each viewer gets the best quality their connection can handle.':
    'פרופילי קידוד קובעים את הרזולוציה וה-Bitrate של כל גרסת קידוד, כך שכל צופה מקבל את האיכות הטובה ביותר שהחיבור שלו מאפשר.',
  'Video player': 'נגן וידאו',
  'Loading video': 'טוען סרטון',
  'This video does not exist or was removed. Check the link and try again.':
    'הסרטון הזה לא קיים או שהוסר. בדקו את הקישור ונסו שוב.',
  'This video is private': 'הסרטון הזה פרטי',
  'Sign in to watch it, or ask the owner to make it public.':
    'התחברו כדי לצפות בו, או בקשו מהבעלים להפוך אותו לציבורי.',
  'Access denied': 'הגישה נדחתה',
  'Your account cannot play this video. The owner may need to update bucket or video permissions.':
    'החשבון שלכם לא יכול לנגן את הסרטון. ייתכן שהבעלים צריך לעדכן הרשאות באקט או בסרטון.',
  'Unable to play': 'לא ניתן לנגן',
  'Something went wrong while loading this video. Try again later.':
    'משהו השתבש בטעינת הסרטון. נסו שוב מאוחר יותר.',
  'Missing project': 'חסר פרויקט',
  'Add a project query parameter to this URL, for example ?project=yourProjectId.':
    'הוסיפו פרמטר project לכתובת, למשל ?project=yourProjectId.',
  'Not ready to play': 'עדיין לא מוכן לניגון',
  'This video is still being prepared. Try again in a few minutes.':
    'הסרטון עדיין בהכנה. נסו שוב בעוד כמה דקות.',
  Player: 'נגן',
  Embed: 'הטמעה',
  'Embed code': 'קוד הטמעה',
  'Embed code copied': 'קוד ההטמעה הועתק',
  'Embed video': 'הטמעת סרטון',
  'Copy the iframe snippet below. Optionally set a start time for playback.':
    'העתיקו את קוד ה-iframe למטה. אפשר להגדיר זמן התחלה לניגון.',
  'Start time': 'זמן התחלה',
  'Custom start time': 'זמן התחלה מותאם',
  'Embed plays from the beginning.': 'ההטמעה מתחילה מההתחלה.',
  'Playback begins at this time when the embed loads.':
    'הניגון מתחיל בזמן זה כשההטמעה נטענת.',
  'Copy embed code': 'העתקת קוד הטמעה',
  'Subtitle size': 'גודל כתוביות',
  'Compact subtitles': 'כתוביות קומפקטיות',
  'Standard subtitles': 'כתוביות רגילות',
  'Large subtitles': 'כתוביות גדולות',
  'The source file is still being probed. Try again when encoding starts or finishes.':
    'קובץ המקור עדיין נבדק. נסו שוב כשהקידוד מתחיל או מסתיים.',

  // Create video wizard
  'Encoding settings': 'הגדרות קידוד',
  Upload: 'העלאה',
  Demo: 'הדגמה',
  'Advanced settings': 'הגדרות מתקדמות',
  'Serves both HLS and DASH.': 'זמין גם כ-HLS וגם כ-DASH.',
  'Plays natively on Safari and iOS.': 'מתנגן באופן מובנה ב-Safari וב-iOS.',
  'Not supported natively on Safari.': 'לא נתמך באופן מובנה ב-Safari.',
  'Review': 'סקירה',
  'Uploaded from your computer': 'הועלה מהמחשב שלכם',
  'File in Storage': 'קובץ באחסון',
  'Demo video by Blender Studio': 'סרטון הדגמה של Blender Studio',
  'Timeline previews': 'תצוגות מקדימות בציר הזמן',
  'Bucket created': 'הבאקט נוצר',
  'Video created with issues': 'הסרטון נוצר עם בעיות',
  'Open the video to retry the steps that failed.':
    'פתחו את הסרטון כדי לנסות שוב את השלבים שנכשלו.',
  'Video creation continues in the background.': 'יצירת הסרטון ממשיכה ברקע.',
  'Open video': 'פתיחת הסרטון',
  'Choose a source': 'בחירת מקור',
  'Every video starts from a file in Storage. Upload one, pick a file you already have, or start with a demo.':
    'כל סרטון מתחיל מקובץ באחסון. העלו קובץ, בחרו קובץ קיים או התחילו עם סרטון הדגמה.',
  'Configure encoding': 'הגדרת קידוד',
  'Choose how the video is packaged for adaptive streaming. Encoding starts as soon as the source is ready.':
    'בחרו איך הסרטון ייארז לסטרימינג אדפטיבי. הקידוד מתחיל ברגע שהמקור מוכן.',
  'Audio-only sources have no frames to preview.':
    'למקורות שמע בלבד אין פריימים לתצוגה מקדימה.',
  'Sprite sheet thumbnails and a WebVTT track for previews while scrubbing. Also sets the video poster.':
    'תמונות ממוזערות בגיליון ספרייט ורצועת WebVTT לתצוגה מקדימה בזמן גלילה בסרטון. משמש גם כתמונת השער של הסרטון.',
  'Clear every profile to skip encoding. You can create renditions later from the video page.':
    'בטלו את הבחירה בכל הפרופילים כדי לדלג על הקידוד. אפשר ליצור גרסאות קידוד מאוחר יותר מדף הסרטון.',
  'Review and create': 'סקירה ויצירה',
  'Name your video and confirm the details. You can rename it later.':
    'תנו שם לסרטון ואשרו את הפרטים. אפשר לשנות את השם מאוחר יותר.',
  'Destination bucket': 'באקט יעד',
  'No renditions. You can encode later.':
    'ללא גרסאות קידוד. אפשר לקודד מאוחר יותר.',
  'Too large for this bucket.': 'הקובץ גדול מדי עבור הבאקט הזה.',
  'Maximum file size:': 'גודל קובץ מרבי:',
  'This bucket does not accept this file type.':
    'הבאקט הזה לא מקבל את סוג הקובץ הזה.',
  'Drop a file here or click to browse': 'גררו קובץ לכאן או לחצו כדי לבחור',
  'MP4, MOV, WebM, MKV, and audio files': 'קובצי MP4, MOV, WebM, MKV ושמע',
  'Up to': 'עד',
  'Choose a video or audio file.': 'בחרו קובץ וידאו או שמע.',
  'The file stays in its bucket. Nothing is copied.':
    'הקובץ נשאר בבאקט שלו ולא מועתק.',
  'Loading file...': 'טוען קובץ...',
  'Too large for the selected bucket.': 'גדול מדי עבור הבאקט שנבחר.',
  'Open movies by Blender Studio, served from Wikimedia Commons. The file is downloaded in your browser and uploaded to the selected bucket.':
    'סרטים פתוחים של Blender Studio שמוגשים מ-Wikimedia Commons. הקובץ יורד בדפדפן שלכם ומועלה לבאקט שנבחר.',
  'Create Videos bucket': 'יצירת באקט Videos',
  'The source file is stored in a Storage bucket. Create one to continue.':
    'קובץ המקור נשמר בבאקט באחסון. צרו באקט כדי להמשיך.',
  'The uploaded file is stored here and stays in Storage.':
    'הקובץ שמועלה נשמר כאן ונשאר באחסון.',
  'Drop a video or audio file from your computer.':
    'גררו קובץ וידאו או שמע מהמחשב שלכם.',
  'Choose from Storage': 'בחירה מהאחסון',
  'Use a file that is already in a bucket.': 'שימוש בקובץ שכבר נמצא בבאקט.',
  'Try a demo video': 'ניסיון עם סרטון הדגמה',
  'Import an open movie to explore Videos.':
    'ייבאו סרט פתוח כדי להכיר את שירות הסרטונים.',
  'No source selected': 'לא נבחר מקור',
  'Preview unavailable': 'תצוגה מקדימה לא זמינה',
  'Audio only': 'שמע בלבד',
  'New video': 'סרטון חדש',
  'Choose a source to see a preview.': 'בחרו מקור כדי לראות תצוגה מקדימה.',
  'Creating your video': 'יוצרים את הסרטון שלכם',
  'Encoding renditions': 'קידוד גרסאות',
  'You can leave this screen. Encoding continues on the video.':
    'צאו מהמסך הזה. הקידוד ממשיך על הסרטון.',
  'The source is saved first. Renditions start after that.':
    'קודם נשמר המקור. גרסאות הקידוד מתחילות אחר כך.',
  'The upload ended before the whole file was stored.':
    'ההעלאה נעצרה לפני שכל הקובץ נשמר.',
  'The demo download ended before the whole file was received.':
    'הורדת ההדגמה נעצרה לפני שכל הקובץ התקבל.',
  'Saving the source and registering the video.':
    'שומרים את המקור ורושמים את הסרטון.',
  'Open the video when you are ready.': 'פתחו את הסרטון כשתהיו מוכנים.',
  '{ready} of {total} ready': '{ready} מתוך {total} מוכנות',
  'Video could not be created': 'לא ניתן ליצור את הסרטון',
  'Keep this window open while the file uploads. Once the video exists, you can open it and the remaining steps continue in the background.':
    'השאירו את החלון פתוח בזמן שהקובץ עולה. אחרי שהסרטון נוצר אפשר לפתוח אותו, והשלבים הנותרים ימשיכו ברקע.',
  'The video was not created. Go back to adjust the source or try again.':
    'הסרטון לא נוצר. חזרו כדי לשנות את המקור או נסו שוב.',
  'Download demo video': 'הורדת סרטון ההדגמה',
  'Fetch the open movie in your browser.': 'הסרט הפתוח יורד בדפדפן שלכם.',
  'Upload to Storage': 'העלאה לאחסון',
  'Store the source file in your bucket.': 'קובץ המקור נשמר בבאקט שלכם.',
  'Register video': 'רישום הסרטון',
  'Create the video from the source file.': 'הסרטון נוצר מקובץ המקור.',
  'Prepare source': 'הכנת המקור',
  'Download, probe, and extract embedded subtitles.':
    'הורדה, ניתוח וחילוץ של כתוביות מוטמעות.',
  'Queue sprite sheet thumbnails for scrubbing previews.':
    'הוספת תמונות ממוזערות לתור לטובת תצוגה מקדימה בגלילה.',
  'Queue renditions': 'הוספת גרסאות קידוד לתור',
  'Start encoding one rendition per selected profile.':
    'התחלת קידוד של גרסה אחת לכל פרופיל שנבחר.',
  'Short and light. The fastest way to try encoding.':
    'קצר וקל. הדרך המהירה ביותר לנסות קידוד.',
  'Bright 16:9 cartoon with fast camera moves.':
    'סרט מצויר צבעוני ביחס 16:9 עם תנועות מצלמה מהירות.',
  'Longer 16:9 short with detailed outdoor scenes.':
    'סרט קצר וארוך יותר ביחס 16:9 עם סצנות חוץ מפורטות.',
  'Ultrawide frame. Shows letterboxed renditions.':
    'פריים רחב במיוחד. מדגים גרסאות קידוד עם פסים שחורים.',
  'Fast action and rich color for bitrate tests.':
    'אקשן מהיר וצבעים עשירים לבדיקות Bitrate.',
  'Ultrawide hybrid of 2D and 3D animation.':
    'שילוב רחב מסך של אנימציית 2D ו-3D.',

  // Subtitle editor
  'Subtitle editor': 'עורך כתוביות',
  'New subtitle': 'כתובית חדשה',
  'Open in editor': 'פתיחה בעורך',
  'Back to subtitles': 'חזרה לכתוביות',
  'Subtitle created': 'הכתובית נוצרה',
  'Subtitle saved. The track is re-packaged in the background.':
    'הכתובית נשמרה. הרצועה נארזת מחדש ברקע.',
  'Subtitle not found': 'הכתובית לא נמצאה',
  'This subtitle may have been deleted. Go back to the list to pick another track.':
    'ייתכן שהכתובית נמחקה. חזרו לרשימה כדי לבחור רצועה אחרת.',
  'Loading cues...': 'טוען קטעים...',
  "Couldn't load this track": 'לא ניתן לטעון את הרצועה',
  'Embedded tracks can be read once they are packaged.':
    'אפשר לקרוא רצועות מוטמעות רק אחרי שהן נארזות.',
  'The subtitle file could not be read.': 'לא ניתן לקרוא את קובץ הכתוביות.',
  'Start from an empty track': 'התחלה מרצועה ריקה',
  Cues: 'קטעים',
  'Failed to download subtitles': 'הורדת הכתוביות נכשלה',
  'No cues': 'אין קטעים',
  'No cues match your search': 'אין קטעים התואמים לחיפוש',
  'Search cues...': 'חיפוש קטעים...',
  'Only cues with issues': 'רק קטעים עם בעיות',
  'No cues yet. Play the video and press N to add a cue at the playhead, or import a WebVTT or SRT file.':
    'עדיין אין קטעים. הפעילו את הסרטון והקישו N כדי להוסיף קטע במיקום הנוכחי, או ייבאו קובץ WebVTT או SRT.',
  'Select a cue in the list or on the timeline to update it.':
    'בחרו קטע ברשימה או בציר הזמן כדי לעדכן אותו.',
  '{count} cues selected': '{count} קטעים נבחרו',
  'Cue {index} of {total}': 'קטע {index} מתוך {total}',
  'Merge with next cue': 'מיזוג עם הקטע הבא',
  'Delete cue': 'מחיקת קטע',
  'Set start to playhead': 'הגדרת ההתחלה למיקום הנוכחי',
  'Set end to playhead': 'הגדרת הסיום למיקום הנוכחי',
  'Use playhead': 'שימוש במיקום הנוכחי',
  'Cue text': 'טקסט הקטע',
  '{value} chars/sec': '{value} תווים לשנייה',
  'Longest line: {value}': 'השורה הארוכה ביותר: {value}',
  'Lines: {value}': 'שורות: {value}',
  'Empty cue': 'קטע ריק',
  'Has errors': 'יש שגיאות',
  'Has warnings': 'יש אזהרות',
  'Showing now': 'מוצג כעת',
  'End time must be after the start time.': 'זמן הסיום חייב להיות אחרי זמן ההתחלה.',
  'Cue has no text.': 'אין טקסט בקטע.',
  'Overlaps the previous cue.': 'חופף לקטע הקודם.',
  'Ends after the video does.': 'מסתיים אחרי סוף הסרטון.',
  'Reading speed is above 20 characters per second.':
    'מהירות הקריאה גבוהה מ-20 תווים לשנייה.',
  'A line is longer than 42 characters.': 'יש שורה ארוכה מ-42 תווים.',
  'More than 2 lines.': 'יותר משתי שורות.',
  'Add cue at playhead': 'הוספת קטע במיקום הנוכחי',
  'Split cue at playhead': 'פיצול הקטע במיקום הנוכחי',
  'Move the playhead inside a cue to split it.':
    'העבירו את סמן הניגון לתוך קטע כדי לפצל אותו.',
  'Back 1 second': 'שנייה אחורה',
  'Forward 1 second': 'שנייה קדימה',
  'Playback speed': 'מהירות ניגון',
  'Drag cues to move them, drag their edges to retime. Hold Alt to turn off snapping.':
    'גררו קטעים כדי להזיז אותם, וגררו את הקצוות כדי לשנות את התזמון. החזיקו Alt כדי לבטל הצמדה.',
  Zoom: 'זום',
  'Shift timings': 'הזזת תזמונים',
  'Move cues earlier or later to fix a track that is out of sync. Cues never move before 0:00.':
    'הקדימו או אחרו קטעים כדי לתקן רצועה שאינה מסונכרנת. קטעים לא יוזזו לפני 0:00.',
  Offset: 'היסט',
  'Seconds or a timestamp. Use a negative value to show cues earlier, e.g. -1.5 or -0:02.000.':
    'שניות או חותמת זמן. השתמשו בערך שלילי כדי להקדים קטעים, למשל -1.5 או -0:02.000.',
  'All cues': 'כל הקטעים',
  'Selected cues': 'הקטעים שנבחרו',
  Shift: 'הזזה',
  'Cue timings shifted': 'התזמונים הוזזו',
  'Fix overlaps': 'תיקון חפיפות',
  'No overlapping cues': 'אין קטעים חופפים',
  'Trimmed {count} overlapping cues': '{count} קטעים חופפים קוצרו',
  'Remove empty cues': 'הסרת קטעים ריקים',
  'No empty cues': 'אין קטעים ריקים',
  'Removed {count} empty cues': '{count} קטעים ריקים הוסרו',
  'From your computer': 'מהמחשב',
  'From Storage': 'מהאחסון',
  'Import subtitle file': 'ייבוא קובץ כתוביות',
  'Choose a .vtt or .srt file. Its cues replace the cues in the editor.':
    'בחרו קובץ .vtt או .srt. הקטעים שבו יחליפו את הקטעים בעורך.',
  'No cues found in this file': 'לא נמצאו קטעים בקובץ',
  'Imported {count} cues. Undo to restore the previous cues.':
    '{count} קטעים יובאו. בטלו את הפעולה כדי לשחזר את הקטעים הקודמים.',
  'Failed to import subtitles': 'ייבוא הכתוביות נכשל',
  'Download WebVTT': 'הורדת WebVTT',
  'Download SRT': 'הורדת SRT',
  'Add at least one cue.': 'הוסיפו לפחות קטע אחד.',
  'Fix {count} cues with errors before saving.':
    'תקנו {count} קטעים עם שגיאות לפני השמירה.',
  'Choose a language in Settings.': 'בחרו שפה בהגדרות.',
  'Enter a valid name in Settings.': 'הזינו שם תקין בהגדרות.',
  'Choose a Storage bucket in Settings.': 'בחרו באקט אחסון בהגדרות.',
  'The selected bucket does not accept .vtt files.':
    'הבאקט שנבחר לא מקבל קובצי .vtt.',
  'Storage bucket': 'באקט אחסון',
  'This bucket only allows specific file extensions and .vtt is not one of them. Choose another bucket or allow .vtt in the bucket settings.':
    'הבאקט הזה מתיר רק סיומות קבצים מסוימות, ו-.vtt אינה ביניהן. בחרו באקט אחר או התירו .vtt בהגדרות הבאקט.',
  'Embedded tracks have no file in Storage. Saving uploads the cues as a new WebVTT file to this bucket and links the track to it.':
    'לרצועות מוטמעות אין קובץ באחסון. השמירה מעלה את הקטעים כקובץ WebVTT חדש לבאקט הזה ומקשרת אליו את הרצועה.',
  'Saving uploads a new WebVTT file to this bucket and re-packages the track. The previous file is kept.':
    'השמירה מעלה קובץ WebVTT חדש לבאקט הזה ואורזת מחדש את הרצועה. הקובץ הקודם נשמר.',
  'Discard unsaved changes?': 'לבטל את השינויים שלא נשמרו?',
  'Your subtitle changes have not been saved. Leaving this page discards them.':
    'השינויים בכתוביות לא נשמרו. יציאה מהדף תבטל אותם.',
  'Play or pause': 'ניגון או השהיה',
  'Seek 1 second (Shift: 5 seconds)': 'דילוג של שנייה (Shift: חמש שניות)',
  'Step one frame': 'מעבר פריים אחד',
  'Previous or next cue': 'הקטע הקודם או הבא',
  'Update cue text': 'עדכון טקסט הקטע',
  'Delete selected cues': 'מחיקת הקטעים שנבחרו',
  'Read-only': 'לקריאה בלבד',
  'Empty cues are left out of the file.': 'קטעים ריקים לא נכללים בקובץ.',
  'Previous cue': 'הקטע הקודם',
  'Next cue': 'הקטע הבא',
  'Add cue': 'הוספת קטע',
  '+{count} more': 'ועוד {count}',
  'Split cue': 'פיצול קטע',
  'More actions': 'פעולות נוספות',
  'Mute or unmute': 'השתקה או ביטול השתקה',
  Milliseconds: 'אלפיות שנייה',
  'Drag to adjust. Hold Shift for bigger steps or Alt for smaller ones.':
    'גררו כדי לכוונן. החזיקו Shift לצעדים גדולים יותר או Alt לצעדים קטנים יותר.',
  'Click a cue to split it at the pointer. Hold Shift to keep splitting, press Esc to stop.':
    'לחצו על קטע כדי לפצל אותו במיקום הסמן. החזיקו Shift כדי להמשיך לפצל, הקישו Esc לסיום.',

  // Renditions empty state
  'Encode this video for streaming': 'קידוד הסרטון להזרמה',
  'Renditions are the quality levels a player switches between. Encode a few to stream this video smoothly on any device and connection.':
    'גרסאות קידוד הן רמות האיכות שהנגן עובר ביניהן. קודדו כמה גרסאות כדי להזרים את הסרטון בצורה חלקה בכל מכשיר ובכל חיבור.',
  'Pick encoding profiles': 'בחירת פרופילי קידוד',
  'Each profile you select becomes one rendition at its own resolution and bitrate.':
    'כל פרופיל שתבחרו הופך לגרסת קידוד אחת ברזולוציה ובקצב סיביות משלה.',
  'Choose an output format': 'בחירת פורמט פלט',
  'Package the renditions as HLS, DASH, or CMAF. Appwrite encodes them in the background.':
    'ארזו את גרסאות הקידוד כ-HLS, DASH או CMAF. Appwrite מקודד אותן ברקע.',
  'Stream the manifest': 'הזרמת המניפסט',
  'Point any player at the manifest URL. It switches renditions as bandwidth changes.':
    'הפנו כל נגן לכתובת המניפסט. הנגן עובר בין גרסאות הקידוד לפי רוחב הפס.',

  // Subtitles empty state
  'Add subtitles to this video': 'הוספת כתוביות לסרטון',
  'Make this video accessible in every language your viewers speak. Players show the tracks you add here alongside the stream.':
    'הנגישו את הסרטון בכל שפה שהצופים שלכם מדברים. הנגנים מציגים את הרצועות שתוסיפו כאן לצד ההזרמה.',
  'Write in the editor': 'כתיבה בעורך',
  'Type captions while the video plays, then drag cues on the timeline to fine-tune their timing.':
    'הקלידו כתוביות בזמן שהסרטון מתנגן, ואז גררו קטעים על ציר הזמן כדי לכוונן את התזמון שלהם.',
  'Or import a file': 'או ייבוא קובץ',
  'Bring WebVTT or SRT files from Storage. Tracks embedded in the source are extracted automatically.':
    'ייבאו קובצי WebVTT או SRT מהאחסון. רצועות שמוטמעות בקובץ המקור מחולצות אוטומטית.',
  'Ship with every stream': 'זמינות בכל הזרמה',
  'Subtitles are listed in the manifest, so viewers can pick a language in any player.':
    'הכתוביות מופיעות במניפסט, כך שהצופים יכולים לבחור שפה בכל נגן.',

  // Captions (Stream v4 UI)
  'Create caption': 'יצירת כתובית',
  'Update caption': 'עדכון כתובית',
  'Delete caption': 'מחיקת כתובית',
  'Caption added': 'הכתובית נוספה',
  'Caption updated': 'הכתובית עודכנה',
  'Caption deleted': 'הכתובית נמחקה',
  'Default caption updated': 'כתובית ברירת המחדל עודכנה',
  'Failed to save caption': 'שמירת הכתובית נכשלה',
  'Failed to delete caption': 'מחיקת הכתובית נכשלה',
  'Failed to download captions': 'הורדת הכתוביות נכשלה',
  'Upload caption': 'העלאת כתובית',
  'Add captions': 'הוספת כתוביות',
  'Add captions to this video': 'הוספת כתוביות לסרטון',
  'Back to captions': 'חזרה לכתוביות',
  'Caption editor': 'עורך כתוביות',
  'New caption': 'כתובית חדשה',
  'Caption created': 'הכתובית נוצרה',
  'Caption saved. The track is re-packaged in the background.':
    'הכתובית נשמרה. הרצועה נארזת מחדש ברקע.',
  'Caption not found': 'הכתובית לא נמצאה',
  'This caption may have been deleted. Go back to the list to pick another track.':
    'ייתכן שהכתובית נמחקה. חזרו לרשימה כדי לבחור רצועה אחרת.',
  'The caption file could not be read.': 'לא ניתן לקרוא את קובץ הכתוביות.',
  'Failed to import captions': 'ייבוא הכתוביות נכשל',
  'Import caption file': 'ייבוא קובץ כתוביות',
  'Your caption changes have not been saved. Leaving this page discards them.':
    'שינויי הכתוביות לא נשמרו. יציאה מהדף תמחק אותם.',
  'Default caption': 'כתובית ברירת מחדל',
  'Players turn this caption on automatically.':
    'נגנים מפעילים את הכתובית הזו אוטומטית.',
  'Select caption file': 'בחירת קובץ כתוביות',
  'Attach a WebVTT or SRT file from Storage. Captions are segmented to match the stream and listed in every manifest.':
    'צרפו קובץ WebVTT או SRT מהאחסון. הכתוביות מחולקות לסגמנטים בהתאם לסטרים ומופיעות בכל מניפסט.',
  'Caption URL': 'כתובת הכתובית',
  'Copy HLS caption URL': 'העתקת כתובת כתובית HLS',
  'Copy DASH caption URL': 'העתקת כתובת כתובית DASH',
  'Copy CMAF caption URL': 'העתקת כתובת כתובית CMAF',
  'Open caption file': 'פתיחת קובץ הכתובית',
  'No caption tracks. Add them from the Captions page.':
    'אין רצועות כתוביות. הוסיפו אותן מדף הכתוביות.',
  'Captions are listed in the manifest, so viewers can pick a language in any player.':
    'הכתוביות מופיעות במניפסט, כך שהצופים יכולים לבחור שפה בכל נגן.',
}
