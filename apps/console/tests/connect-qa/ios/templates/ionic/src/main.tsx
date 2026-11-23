import React from 'react'
import ReactDOM from 'react-dom/client'
import { setupIonicReact } from '@ionic/react'
import App from './App'

import '@ionic/react/css/core.css'

setupIonicReact()

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
)
