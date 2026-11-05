import { useCallback, useEffect, useRef, useState } from 'react'
import { api, getToken, saveToken } from './api/client.js'
import AppShell from './components/AppShell.jsx'
import Toast from './components/Toast.jsx'
import LoginPage from './pages/LoginPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import PosPage from './pages/PosPage.jsx'
import InventoryPage from './pages/InventoryPage.jsx'
import SuppliersPage from './pages/SuppliersPage.jsx'
import DigitalServicesPage from './pages/DigitalServicesPage.jsx'
import ReportsPage from './pages/ReportsPage.jsx'
import SettingsPage from './pages/SettingsPage.jsx'
import StoreManagementPage from './pages/StoreManagementPage.jsx'
import './App.css'

export default function App() {
  const [token, setToken] = useState(getToken())
  const [user, setUser] = useState(null)
  const [authLoading, setAuthLoading] = useState(Boolean(getToken()))
  const [page, setPage] = useState('dashboard')
  const [globalSearch, setGlobalSearch] = useState('')
  const [toast, setToast] = useState(null)
  const toastTimer = useRef(null)

  const showToast = useCallback((nextToast) => {
    setToast(nextToast)
    window.clearTimeout(toastTimer.current)
    toastTimer.current = window.setTimeout(() => setToast(null), 4200)
  }, [])

  useEffect(() => () => window.clearTimeout(toastTimer.current), [])

  useEffect(() => {
    if (!token) return undefined
    let active = true
    api.get('/me')
      .then((response) => {
        if (!active) return
        const currentUser = response.data
        setUser(currentUser)
        setPage(currentUser?.role === 'admin' ? 'dashboard' : 'pos')
      })
      .catch(() => {
        if (!active) return
        saveToken(null)
        setToken(null)
        setUser(null)
      })
      .finally(() => {
        if (active) setAuthLoading(false)
      })
    return () => { active = false }
  }, [token])

  useEffect(() => {
    const handleUnauthorized = () => {
      setToken(null)
      setUser(null)
      setPage('pos')
    }
    window.addEventListener('pos:unauthorized', handleUnauthorized)
    return () => window.removeEventListener('pos:unauthorized', handleUnauthorized)
  }, [])

  const login = async (email, password) => {
    const response = await api.post('/login', { email, password })
    const accessToken = response.data?.access_token
    if (!accessToken) throw new Error('Token akses tidak ditemukan pada respons server.')
    saveToken(accessToken)
    setToken(accessToken)
    setUser(response.data.user)
    setPage(response.data.user?.role === 'admin' ? 'dashboard' : 'pos')
  }

  const logout = async () => {
    try {
      await api.post('/logout', {})
    } catch {
      // Token tetap dihapus di sisi browser meskipun server tidak dapat dijangkau.
    }
    saveToken(null)
    setToken(null)
    setUser(null)
    setPage('pos')
    setGlobalSearch('')
  }

  const navigate = (nextPage) => {
    if (nextPage === 'dashboard' && user?.role !== 'admin') {
      setPage('pos')
      return
    }
    if (['reports', 'management', 'settings'].includes(nextPage) && user?.role !== 'admin') return
    setGlobalSearch('')
    setPage(nextPage)
  }

  if (authLoading) {
    return <div className="app-loading"><div className="loading-brand brand-logo"><img src="/logo_abicell.png" alt="Logo AbiCell" /></div><span className="button-spinner" /><p>Menyiapkan aplikasi…</p></div>
  }

  if (!token || !user) return <LoginPage onLogin={login} />

  let content
  switch (page) {
    case 'dashboard':
      content = <DashboardPage search={globalSearch} onNavigate={navigate} />
      break
    case 'pos':
      content = <PosPage user={user} globalSearch={globalSearch} onToast={showToast} />
      break
    case 'inventory':
      content = <InventoryPage search={globalSearch} user={user} onToast={showToast} onNavigate={navigate} />
      break
    case 'suppliers':
      content = <SuppliersPage search={globalSearch} />
      break
    case 'services':
      content = <DigitalServicesPage user={user} search={globalSearch} onToast={showToast} />
      break
    case 'reports':
      content = <ReportsPage />
      break
    case 'management':
      content = <StoreManagementPage user={user} search={globalSearch} onToast={showToast} />
      break
    case 'settings':
      content = <SettingsPage search={globalSearch} onToast={showToast} />
      break
    default:
      content = <PosPage globalSearch={globalSearch} onToast={showToast} />
  }

  return (
    <AppShell
      user={user}
      page={page}
      onNavigate={navigate}
      onLogout={logout}
      search={globalSearch}
      onSearch={setGlobalSearch}
    >
      {content}
      <Toast toast={toast} onClose={() => setToast(null)} />
    </AppShell>
  )
}
