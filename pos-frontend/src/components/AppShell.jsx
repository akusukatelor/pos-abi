import { useState } from 'react'
import Icon from './Icon.jsx'
import { initials } from '../utils/format.js'

const navigation = [
  { id: 'dashboard', label: 'Dashboard', icon: 'dashboard', ownerOnly: true },
  { id: 'pos', label: 'Point of Sale (POS)', icon: 'pos' },
  { id: 'inventory', label: 'Inventory', icon: 'inventory' },
  { id: 'suppliers', label: 'Supplier', icon: 'supplier' },
  { id: 'services', label: 'Digital Services', icon: 'services' },
  { id: 'reports', label: 'Reports', icon: 'reports', ownerOnly: true },
  { id: 'settings', label: 'Settings', icon: 'settings', ownerOnly: true },
]

const titles = {
  dashboard: 'Dashboard - LARIS ABICELL',
  pos: 'Point of Sale (POS)',
  inventory: 'Inventory',
  suppliers: 'Supplier',
  services: 'Digital Services',
  reports: 'Reports',
  settings: 'Settings',
}

export default function AppShell({ user, page, onNavigate, onLogout, children, search, onSearch }) {
  const [mobileOpen, setMobileOpen] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)
  const [notificationOpen, setNotificationOpen] = useState(false)
  const isOwner = user?.role === 'admin'

  const go = (id) => {
    onNavigate(id)
    setMobileOpen(false)
  }

  return (
    <div className="app-shell">
      {mobileOpen && <button className="mobile-scrim" aria-label="Tutup menu" onClick={() => setMobileOpen(false)} />}
      <aside className={`sidebar ${mobileOpen ? 'sidebar-open' : ''}`}>
        <div className="brand">
          <div className="brand-mark">SR</div>
          <div className="brand-copy">
            <span>Toko Kelontong &amp; Jasa Digital</span>
            <strong>LARIS - ABICELL</strong>
          </div>
        </div>

        <nav className="side-nav" aria-label="Navigasi utama">
          {navigation.filter((item) => !item.ownerOnly || isOwner).map((item) => (
            <button
              key={item.id}
              type="button"
              className={`nav-item ${page === item.id ? 'active' : ''}`}
              onClick={() => go(item.id)}
            >
              <Icon name={item.icon} size={17} />
              <span>{item.label}</span>
            </button>
          ))}
        </nav>

        <div className="sidebar-bottom">
          <div className="sidebar-status"><span className="online-dot" /> Sistem operasional</div>
          <div className="sidebar-user">
            <div className="avatar avatar-sidebar">{initials(user?.name)}</div>
            <div className="sidebar-user-copy">
              <strong>{user?.name || 'Pengguna'}</strong>
              <span>{isOwner ? 'Admin / Owner' : 'Kasir'}</span>
            </div>
            <button type="button" className="icon-button sidebar-logout" onClick={onLogout} title="Keluar">
              <Icon name="logout" size={16} />
            </button>
          </div>
        </div>
      </aside>

      <div className="main-column">
        <header className="topbar">
          <button type="button" className="mobile-menu-button" onClick={() => setMobileOpen(true)} aria-label="Buka menu">
            <span /><span /><span />
          </button>
          <div className="topbar-title">{titles[page] || 'POS Toko'}</div>
          <label className="global-search">
            <Icon name="search" size={16} />
            <input
              value={search}
              onChange={(event) => onSearch(event.target.value)}
              placeholder="Search"
              aria-label="Pencarian"
            />
            {search && <button type="button" onClick={() => onSearch('')} aria-label="Hapus pencarian">×</button>}
          </label>
          <div className="topbar-actions">
            <div className="notification-wrap">
              <button type="button" className="icon-button notification-button" onClick={() => setNotificationOpen((value) => !value)} aria-label="Notifikasi" title="Notifikasi">
                <Icon name="bell" size={17} />
              </button>
              {notificationOpen && <div className="notification-menu"><strong>Notifikasi</strong><span>Notifikasi otomatis belum diaktifkan pada versi ini.</span></div>}
            </div>
            <div className="topbar-user-label">{isOwner ? 'Admin / Owner' : 'Kasir'}</div>
            <div className="profile-wrap">
              <button type="button" className="avatar avatar-top" onClick={() => setProfileOpen((value) => !value)} aria-label="Profil pengguna">
                {initials(user?.name)}
                <span className="avatar-online" />
              </button>
              {profileOpen && (
                <div className="profile-menu">
                  <strong>{user?.name}</strong>
                  <span>{user?.email}</span>
                  <span className="role-pill">{isOwner ? 'Owner' : 'Kasir'}</span>
                  <button type="button" onClick={onLogout}><Icon name="logout" size={15} /> Keluar</button>
                </div>
              )}
            </div>
          </div>
        </header>
        <main className="page-content">
          <div className="page-context">{page === 'dashboard' ? 'Dashboard' : (titles[page] || 'POS Toko')} <span>•</span> Sumber Rezeki</div>
          {children}
        </main>
      </div>
    </div>
  )
}
