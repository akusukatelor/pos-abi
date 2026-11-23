import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'
import PublicHelpPage from './pages/PublicHelpPage.jsx'
import './index.css'

const publicChatPath = window.location.pathname.replace(/\/+$/, '') === '/bantuan'
const publicChatQuery = new URLSearchParams(window.location.search).get('halaman') === 'bantuan'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    {publicChatPath || publicChatQuery ? <PublicHelpPage /> : <App />}
  </StrictMode>,
)
