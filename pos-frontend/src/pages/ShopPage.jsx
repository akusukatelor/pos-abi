import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { customerApi, getCustomerToken, saveCustomerToken, newCustomerOrderKey } from '../api/customerApi.js'
import './ShopPage.css'

const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(n) || 0)
const statusLabel = { pending: 'Menunggu diproses', completed: 'Selesai / lunas', cancelled: 'Dibatalkan' }

export default function ShopPage() {
  const [products, setProducts] = useState([])
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [catalogLoading, setCatalogLoading] = useState(false)
  const [account, setAccount] = useState(null)
  const [authMode, setAuthMode] = useState('login')
  const [authOpen, setAuthOpen] = useState(false)
  const [authForm, setAuthForm] = useState({ name: '', email: '', phone: '', password: '', password_confirmation: '' })
  const [authLoading, setAuthLoading] = useState(false)
  const [orders, setOrders] = useState([])
  const [loyalty, setLoyalty] = useState(null)
  const [tab, setTab] = useState('catalog')
  const [cart, setCart] = useState([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const orderKey = useRef(null)
  const checkoutLock = useRef(false)

  const loadCatalog = useCallback(async (term, number) => {
    setCatalogLoading(true)
    try {
      const response = await customerApi.catalog(term, number)
      setProducts(response.data || [])
      setLastPage(response.meta?.last_page || 1)
    } catch (err) {
      setError(err.message)
    } finally {
      setCatalogLoading(false)
    }
  }, [])

  const loadPrivate = useCallback(async () => {
    const [orderRes, loyaltyRes] = await Promise.all([customerApi.orders(), customerApi.loyalty()])
    setOrders(orderRes.data?.data || [])
    setLoyalty(loyaltyRes.data)
  }, [])

  useEffect(() => {
    document.title = 'Belanja Online | LARIS - ABICELL'
    if (!getCustomerToken()) return
    customerApi.me().then((response) => {
      setAccount(response.data)
      return loadPrivate()
    }).catch(() => saveCustomerToken(null))
  }, [loadPrivate])

  useEffect(() => {
    const task = window.setTimeout(() => { void loadCatalog(search, page) }, 250)
    return () => window.clearTimeout(task)
  }, [search, page, loadCatalog])

  const add = (product) => {
    if (!product.available) return
    orderKey.current = null
    setCart((current) => {
      const existing = current.find((item) => item.id === product.id)
      if (existing) return current.map((item) => item.id === product.id ? { ...item, quantity: Math.min(20, item.quantity + 1) } : item)
      if (current.length >= 30) return current
      return [...current, { ...product, quantity: 1 }]
    })
    setNotice('Barang ditambahkan ke keranjang.')
  }

  const quantity = (id, delta) => {
    orderKey.current = null
    setCart((current) => current.map((item) => item.id === id ? { ...item, quantity: item.quantity + delta } : item)
      .filter((item) => item.quantity > 0).map((item) => ({ ...item, quantity: Math.min(20, item.quantity) })))
  }

  const total = useMemo(() => cart.reduce((sum, item) => sum + Number(item.price) * item.quantity, 0), [cart])
  const cartCount = cart.reduce((sum, item) => sum + item.quantity, 0)

  const submitAuth = async (event) => {
    event.preventDefault()
    setError('')
    setAuthLoading(true)
    try {
      const payload = authMode === 'register' ? authForm : { email: authForm.email, password: authForm.password }
      const result = authMode === 'register' ? await customerApi.register(payload) : await customerApi.login(payload)
      saveCustomerToken(result.data.access_token)
      setAccount(result.data.account)
      setAuthOpen(false)
      setAuthForm({ name: '', email: '', phone: '', password: '', password_confirmation: '' })
      setNotice('Akun pelanggan berhasil digunakan.')
      await loadPrivate()
    } catch (err) { setError(err.message) } finally { setAuthLoading(false) }
  }

  const logout = async () => {
    try { await customerApi.logout() } catch { /* tetap keluar di browser */ }
    saveCustomerToken(null)
    setAccount(null)
    setOrders([])
    setLoyalty(null)
    setTab('catalog')
  }

  const checkout = async () => {
    if (!account) { setAuthOpen(true); return }
    if (!cart.length || checkoutLock.current) return
    checkoutLock.current = true
    setBusy(true)
    setError('')
    try {
      if (!orderKey.current) orderKey.current = newCustomerOrderKey()
      const result = await customerApi.placeOrder({
        idempotency_key: orderKey.current,
        items: cart.map((item) => ({ product_id: item.id, quantity: item.quantity })),
      })
      setCart([])
      orderKey.current = null
      setNotice(`Pesanan ${result.data.order_number} tercatat. Bayar tunai ketika mengambil barang di toko.`)
      await loadPrivate()
      setTab('orders')
      void loadCatalog(search, page)
    } catch (err) { setError(err.message) } finally { checkoutLock.current = false; setBusy(false) }
  }

  return (
    <div className="shop-root">
      <header className="shop-header"><div className="shop-header-inner">
        <a className="shop-brand" href="/?halaman=toko"><img src="/logo_abicell.png" alt="Logo toko" /><span><strong>LARIS - ABICELL</strong><small>Belanja Online · Ambil di Toko</small></span></a>
        <nav aria-label="Menu toko"><button className={tab === 'catalog' ? 'current' : ''} onClick={() => setTab('catalog')}>Katalog</button><button className={tab === 'orders' ? 'current' : ''} onClick={() => account ? setTab('orders') : setAuthOpen(true)}>Pesanan saya</button><button className={tab === 'loyalty' ? 'current' : ''} onClick={() => account ? setTab('loyalty') : setAuthOpen(true)}>Loyalty</button></nav>
        <div className="shop-account">{account ? <><span>Hai, {account.name}</span><button onClick={() => void logout()}>Keluar</button></> : <button className="shop-solid" onClick={() => { setAuthMode('login'); setAuthOpen(true) }}>Masuk / Daftar</button>}</div>
      </div></header>

      <main className="shop-main">
        <div className="shop-toolbar"><span><a href="/?halaman=bantuan">Bantuan & FAQ</a> · <a href="/">Login Petugas</a></span><span>Harga & stok akhir dikonfirmasi oleh toko</span></div>
        {error && <div className="shop-alert" role="alert">{error}<button onClick={() => setError('')}>Tutup</button></div>}
        {notice && <div className="shop-notice" role="status">{notice}<button onClick={() => setNotice('')}>×</button></div>}

        {tab === 'catalog' && <>
          <section className="shop-hero"><div><p className="shop-kicker">TOKO KELONTONG & JASA DIGITAL</p><h1>Belanja kebutuhan harian,<br /><em>lebih praktis.</em></h1><p>Pilih barang dan buat pesanan dari rumah. Pesanan diproses petugas dan dibayar tunai saat pengambilan di toko.</p></div><div className="shop-hero-mark">LARIS<span>✦</span>ABICELL</div></section>
          <div className="shop-section-head"><div><p className="shop-kicker">PILIH PRODUK</p><h2>Katalog toko</h2></div><input aria-label="Cari produk" placeholder="Cari nama barang..." value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} /></div>
          <div className="shop-layout"><section className="shop-products">
            {catalogLoading && <p>Memuat katalog...</p>}
            {!catalogLoading && products.length === 0 && <p>Belum ada produk yang cocok.</p>}
            <div className="shop-product-grid">{products.map((product, i) => <article className="shop-product" key={product.id}>
              <div className={`shop-product-art shop-art-${i % 4}`}><strong>{product.name?.charAt(0).toUpperCase()}</strong><span>{product.available ? 'TERSEDIA' : 'HABIS'}</span></div>
              <h3>{product.name}</h3><div className="shop-product-bottom"><strong>{rupiah(product.price)}</strong><button disabled={!product.available || cart.find((x) => x.id === product.id)?.quantity >= 20} onClick={() => add(product)}>+ Tambah</button></div>
            </article>)}</div>
            <div className="shop-pages"><button disabled={page <= 1 || catalogLoading} onClick={() => setPage((v) => v - 1)}>Sebelumnya</button><span>Halaman {page} / {lastPage}</span><button disabled={page >= lastPage || catalogLoading} onClick={() => setPage((v) => v + 1)}>Berikutnya</button></div>
          </section><aside className="shop-cart"><h2>Keranjang <small>{cartCount} item</small></h2>
            {cart.length === 0 ? <p>Keranjang masih kosong. Pilih barang untuk memulai.</p> : cart.map((item) => <div className="shop-cart-item" key={item.id}><div><strong>{item.name}</strong><small>{rupiah(item.price)} × {item.quantity}</small></div><div className="shop-quantity"><button aria-label="Kurangi" onClick={() => quantity(item.id, -1)}>−</button><b>{item.quantity}</b><button aria-label="Tambah" onClick={() => quantity(item.id, 1)} disabled={item.quantity >= 20}>+</button></div></div>)}
            <div className="shop-total"><span>Total estimasi</span><strong>{rupiah(total)}</strong></div><p className="shop-cart-hint">Pembayaran: <b>Tunai saat ambil</b>. Tidak ada pembayaran online atau kasbon.</p>
            <button className="shop-checkout" disabled={!cart.length || busy} onClick={() => void checkout()}>{busy ? 'Membuat pesanan...' : account ? 'Buat Pesanan' : 'Masuk untuk Pesan'} →</button>
          </aside></div>
        </>}

        {tab === 'orders' && account && <section className="shop-panel"><p className="shop-kicker">AKUN PELANGGAN</p><h1>Pesanan saya</h1><p>Pesanan pending belum dibayar dan stok belum dipotong sampai pengambilan diproses Owner.</p>
          {orders.length === 0 ? <p>Belum ada pesanan.</p> : orders.map((order) => <article className="shop-order" key={order.id}><div><strong>{order.order_number}</strong><span className={`shop-order-status shop-${order.status}`}>{statusLabel[order.status] || order.status}</span></div><p>{order.items?.map((item) => `${item.product_name} ×${item.quantity}`).join(' · ')}</p><div className="shop-order-footer"><strong>{rupiah(order.total_amount)}</strong><span>{order.payment_status === 'paid' ? 'Sudah dibayar' : 'Bayar di toko'}</span></div></article>)}
          <button className="shop-outline" onClick={() => void loadPrivate()}>Perbarui status</button>
        </section>}

        {tab === 'loyalty' && account && <section className="shop-panel"><p className="shop-kicker">PROGRAM PELANGGAN</p><h1>Loyalty points</h1><div className="shop-points"><small>Saldo poin</small><strong>{loyalty?.points_balance ?? 0}</strong><span>Program poin belum aktif</span></div><p>Aturan perolehan dan penukaran poin masih menunggu kesepakatan klien. Belum ada poin otomatis dari transaksi.</p>
          {loyalty?.entries?.length > 0 && loyalty.entries.map((entry) => <div className="shop-point-entry" key={entry.id}><span>{entry.description}</span><b>{entry.points_delta > 0 ? '+' : ''}{entry.points_delta}</b></div>)}
        </section>}
      </main>

      {authOpen && <div className="shop-modal-backdrop" role="presentation"><section className="shop-modal" role="dialog" aria-modal="true" aria-labelledby="shop-auth-heading"><button className="shop-modal-close" aria-label="Tutup" onClick={() => { setAuthOpen(false); setError('') }}>×</button><p className="shop-kicker">AKUN PELANGGAN</p><h2 id="shop-auth-heading">{authMode === 'register' ? 'Buat akun baru' : 'Selamat datang kembali'}</h2><p>Gunakan akun pelanggan, bukan akun Owner/Kasir.</p>
        <form onSubmit={(e) => void submitAuth(e)}>
          {authMode === 'register' && <><label>Nama lengkap<input minLength={2} maxLength={255} value={authForm.name} onChange={(e) => setAuthForm({ ...authForm, name: e.target.value })} required /></label><label>No. HP (opsional)<input maxLength={20} value={authForm.phone} onChange={(e) => setAuthForm({ ...authForm, phone: e.target.value })} /></label></>}
          <label>Email<input type="email" autoComplete="username" value={authForm.email} onChange={(e) => setAuthForm({ ...authForm, email: e.target.value })} required /></label>
          <label>Password<input type="password" minLength={authMode === 'register' ? 8 : undefined} autoComplete={authMode === 'register' ? 'new-password' : 'current-password'} value={authForm.password} onChange={(e) => setAuthForm({ ...authForm, password: e.target.value })} required /></label>
          {authMode === 'register' && <label>Ulangi password<input type="password" autoComplete="new-password" value={authForm.password_confirmation} onChange={(e) => setAuthForm({ ...authForm, password_confirmation: e.target.value })} required /></label>}
          {error && <p className="shop-auth-error">{error}</p>}
          <button className="shop-checkout" disabled={authLoading}>{authLoading ? 'Memproses...' : authMode === 'register' ? 'Daftar' : 'Masuk'}</button>
        </form><button className="shop-switch-auth" onClick={() => { setAuthMode((m) => m === 'register' ? 'login' : 'register'); setError('') }}>{authMode === 'register' ? 'Sudah punya akun? Masuk' : 'Belum punya akun? Daftar'}</button>
        <p className="shop-auth-note">Akun baru tidak otomatis terhubung ke catatan pelanggan offline atau kasbon tanpa verifikasi petugas.</p>
      </section></div>}

      <footer className="shop-footer">© {new Date().getFullYear()} LARIS - ABICELL · Belanja Online / Ambil di Toko</footer>
    </div>
  )
}
