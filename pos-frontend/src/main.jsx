import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'
import PublicHelpPage from './pages/PublicHelpPage.jsx'
import ShopPage from './pages/ShopPage.jsx'
import './index.css'

const shopPath = window.location.pathname.replace(/\/+$/, '') === '/toko'
const shopQuery = new URLSearchParams(window.location.search).get('halaman') === 'toko'

const publicChatPath = window.location.pathname.replace(/\/+$/, '') === '/bantuan'
const publicChatQuery = new URLSearchParams(window.location.search).get('halaman') === 'bantuan'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    {shopPath || shopQuery ? <ShopPage /> : publicChatPath || publicChatQuery ? <PublicHelpPage /> : <App />}
  </StrictMode>,
)
