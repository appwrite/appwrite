
from gtts import gTTS
  

import os
  

mytext = 'My Pull request at app write'
  

language = 'en'
  

myobj = gTTS(text=mytext, lang=language, slow=False)
  

myobj.save("welcome.mp3")

os.system("mpg321 welcome.mp3")
