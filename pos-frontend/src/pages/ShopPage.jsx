import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { customerApi, getCustomerToken, saveCustomerToken, newCustomerOrderKey } from '../api/customerApi.js'
import './ShopPage.css'

const rupiah = (n) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(n) || 0)
const statusLabel = {
  pending: 'Menunggu diproses',
  processing: 'Sedang disiapkan toko',
  ready: 'Siap diambil di toko',
  completed: 'Selesai / lunas',
  cancelled: 'Dibatalkan',
}

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
  const [paymentMethod, setPaymentMethod] = useState('cash_on_pickup')
  const [paymentReference, setPaymentReference] = useState('')
  const [qrisConfirmed, setQrisConfirmed] = useState(false)
  const [qrisPreviewOpen, setQrisPreviewOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [cancellingId, setCancellingId] = useState(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const orderKey = useRef(null)
  const checkoutLock = useRef(false)
  const [refreshingOrders, setRefreshingOrders] = useState(false)

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

  const loadPrivate = useCallback(async (showFeedback = false) => {
    setRefreshingOrders(true)
    setError('')
    try {
      const [orderRes, loyaltyRes] = await Promise.all([
        customerApi.orders(),
        customerApi.loyalty(),
      ])
      setOrders(orderRes.data?.data || [])
      setLoyalty(loyaltyRes.data)
      if (showFeedback) {
        setNotice('Status pesanan terbaru berhasil dimuat.')
      }
    } catch (err) {
      setError(err.message || 'Gagal memuat status pesanan.')
    } finally {
      setRefreshingOrders(false)
    }
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

  useEffect(() => {
    if (!qrisPreviewOpen) return undefined
    const closeOnEscape = (event) => {
      if (event.key === 'Escape') setQrisPreviewOpen(false)
    }
    window.addEventListener('keydown', closeOnEscape)
    return () => window.removeEventListener('keydown', closeOnEscape)
  }, [qrisPreviewOpen])

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

  const canCheckout = cart.length > 0 && !busy && (paymentMethod === 'cash_on_pickup' || qrisConfirmed)

  const checkout = async () => {
    if (!account) { setAuthOpen(true); return }
    if (!canCheckout || checkoutLock.current) return
    checkoutLock.current = true
    setBusy(true)
    setError('')
    try {
      if (!orderKey.current) orderKey.current = newCustomerOrderKey()
      const payload = {
        idempotency_key: orderKey.current,
        payment_method: paymentMethod,
        items: cart.map((item) => ({ product_id: item.id, quantity: item.quantity })),
      }
      if (paymentMethod === 'qris_gopay') {
        payload.qris_confirmed = true
        payload.payment_reference = paymentReference.trim() || null
      }
      const result = await customerApi.placeOrder(payload)
      setCart([])
      setPaymentMethod('cash_on_pickup')
      setPaymentReference('')
      setQrisConfirmed(false)
      orderKey.current = null
      setNotice(
        result.message ||
        (paymentMethod === 'qris_gopay'
          ? `Pesanan ${result.data.order_number} tercatat. Pembayaran QRIS akan diverifikasi petugas saat pesanan diproses.`
          : `Pesanan ${result.data.order_number} tercatat. Bayar tunai ketika mengambil barang di toko.`)
      )
      await loadPrivate()
      setTab('orders')
      void loadCatalog(search, page)
    } catch (err) { setError(err.message) } finally { checkoutLock.current = false; setBusy(false) }
  }

  const cancelOrder = async (order) => {
    const reason = window.prompt(
      `Yakin ingin membatalkan pesanan ${order.order_number}?\nTulis alasan pembatalan (opsional):`,
      ''
    )
    if (reason === null) return

    setCancellingId(order.id)
    setError('')
    setNotice('')
    try {
      await customerApi.cancelOrder(order.id, {
        reason: reason.trim() || null,
      })
      setNotice(`Pesanan ${order.order_number} berhasil dibatalkan.`)
      await loadPrivate()
    } catch (err) {
      setError(err.message || 'Pesanan tidak dapat dibatalkan.')
    } finally {
      setCancellingId(null)
    }
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
          <section className="shop-hero"><div><p className="shop-kicker">TOKO KELONTONG & JASA DIGITAL</p><h1>Belanja kebutuhan harian,<br /><em>lebih praktis.</em></h1><p>Pilih barang dan buat pesanan dari rumah. Bisa bayar tunai saat ambil di toko atau transfer langsung via QRIS.</p></div><div className="shop-hero-mark">LARIS<span>✦</span>ABICELL</div></section>
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
            <div className="shop-total"><span>Total estimasi</span><strong>{rupiah(total)}</strong></div>

            {cart.length > 0 && (
              <div style={{ margin: '12px 0' }}>
                <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Metode pembayaran</div>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                  <button
                    type="button"
                    className={paymentMethod === 'cash_on_pickup' ? 'shop-solid' : 'shop-outline'}
                    style={{ padding: '8px 10px', fontSize: 13 }}
                    onClick={() => {
                      orderKey.current = null
                      setPaymentMethod('cash_on_pickup')
                      setQrisConfirmed(false)
                    }}
                  >
                    Tunai di Toko
                  </button>
                  <button
                    type="button"
                    className={paymentMethod === 'qris_gopay' ? 'shop-solid' : 'shop-outline'}
                    style={{ padding: '8px 10px', fontSize: 13 }}
                    onClick={() => {
                      orderKey.current = null
                      setPaymentMethod('qris_gopay')
                      setQrisConfirmed(false)
                    }}
                  >
                    QRIS GoPay
                  </button>
                </div>

                {paymentMethod === 'qris_gopay' && (
                  <div style={{ marginTop: 12, padding: 12, borderRadius: 10, border: '1px solid #e2e8f0', background: '#f8fafc', textAlign: 'center' }}>
                    <button
                      type="button"
                      onClick={() => setQrisPreviewOpen(true)}
                      style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', display: 'inline-flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}
                      aria-label="Perbesar kode QRIS"
                    >
                      <img src="/qris.jpeg" alt="Kode pembayaran QRIS Toko Abi 6839" style={{ width: 170, maxWidth: '100%', borderRadius: 8, border: '1px solid #cbd5e1' }} />
                      <small style={{ color: '#475569', fontSize: 11 }}>Klik gambar untuk memperbesar QRIS</small>
                    </button>

                    <p style={{ fontSize: 12, color: '#334155', margin: '8px 0' }}>
                      Scan QRIS di atas dan bayar tepat sebesar <b>{rupiah(total)}</b>.
                    </p>

                    <label style={{ display: 'block', textAlign: 'left', fontSize: 12, marginBottom: 8 }}>
                      <span>Nomor referensi / nama pengirim <small style={{ color: '#64748b' }}>(opsional)</small></span>
                      <input
                        type="text"
                        maxLength={100}
                        value={paymentReference}
                        onChange={(event) => {
                          orderKey.current = null
                          setPaymentReference(event.target.value)
                        }}
                        placeholder="Contoh: Ref GoPay / Nama akun"
                        style={{ width: '100%', marginTop: 4, padding: '6px 8px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: 12 }}
                      />
                    </label>

                    <label style={{ display: 'flex', alignItems: 'flex-start', gap: 8, textAlign: 'left', fontSize: 12, cursor: 'pointer', color: '#1e293b' }}>
                      <input
                        type="checkbox"
                        checked={qrisConfirmed}
                        onChange={(event) => {
                          orderKey.current = null
                          setQrisConfirmed(event.target.checked)
                        }}
                        style={{ marginTop: 2 }}
                      />
                      <span>Saya sudah melakukan pembayaran QRIS sebesar <b>{rupiah(total)}</b>.</span>
                    </label>
                  </div>
                )}
              </div>
            )}

            <p className="shop-cart-hint">
              {paymentMethod === 'qris_gopay'
                ? <>Pembayaran: <b>QRIS Statis</b>. Diverifikasi petugas saat pesanan disiapkan.</>
                : <>Pembayaran: <b>Tunai saat ambil</b> di toko. Tidak melayani kasbon online.</>}
            </p>
            <button
              className="shop-checkout"
              disabled={account ? !canCheckout : (!cart.length || busy)}
              onClick={() => void checkout()}
            >
              {busy ? 'Membuat pesanan...' : account ? 'Buat Pesanan' : 'Masuk untuk Pesan'} →
            </button>
          </aside></div>
        </>}

        {tab === 'orders' && account && (
          <section className="shop-panel">
            <p className="shop-kicker">AKUN PELANGGAN</p>
            <h1>Pesanan saya</h1>
            <p>
              Pesanan diproses oleh petugas toko. Untuk pembayaran QRIS, petugas akan memverifikasi mutasi masuk sebelum menyelesaikan pesanan.
            </p>

            {orders.length === 0 ? (
              <p>Belum ada pesanan.</p>
            ) : (
              orders.map((order) => {
                const isQrisOrder = order.payment_method === 'qris_gopay'
                return (
                  <article className="shop-order" key={order.id}>
                    <div>
                      <strong>{order.order_number}</strong>
                      <span className={`shop-order-status shop-${order.status}`}>
                        {statusLabel[order.status] || order.status}
                      </span>
                    </div>

                    <p>
                      {order.items?.map((item) => `${item.product_name} ×${item.quantity}`).join(' · ')}
                    </p>

                    <p style={{ fontSize: 12, color: '#475569', margin: '4px 0' }}>
                      Metode: <b>{isQrisOrder ? 'QRIS GoPay' : 'Tunai saat ambil di toko'}</b>
                      {order.payment_reference ? ` · Ref: ${order.payment_reference}` : ''}
                    </p>

                    {order.status === 'cancelled' && order.cancellation_reason && (
                      <p style={{ fontSize: 13, color: '#64748b', margin: '4px 0 8px' }}>
                        Alasan: {order.cancellation_reason}
                      </p>
                    )}

                    <div className="shop-order-footer">
                      <strong>{rupiah(order.total_amount)}</strong>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <span>
                          {order.payment_status === 'paid'
                            ? 'Sudah dibayar'
                            : order.status === 'cancelled'
                            ? 'Dibatalkan'
                            : isQrisOrder
                            ? 'Menunggu verifikasi QRIS'
                            : 'Bayar di toko'}
                        </span>

                        {order.status === 'pending' && (
                          <button
                            type="button"
                            className="shop-outline"
                            style={{ color: '#dc2626', borderColor: '#fecaca' }}
                            disabled={cancellingId === order.id}
                            onClick={() => void cancelOrder(order)}
                          >
                            {cancellingId === order.id ? 'Membatalkan...' : 'Batalkan Pesanan'}
                          </button>
                        )}
                      </div>
                    </div>
                  </article>
                )
              })
            )}

            <button
              type="button"
              className="shop-outline"
              disabled={refreshingOrders}
              onClick={() => void loadPrivate(true)}
            >
              {refreshingOrders ? 'Memperbarui...' : 'Perbarui status'}
            </button>
          </section>
        )}

       {tab === 'loyalty' && account && (
          <section className="shop-panel">
            <p className="shop-kicker">PROGRAM PELANGGAN SETIA</p>
            <h1>Loyalty Points</h1>

            <div className="shop-points">
              <small>Saldo poin aktif</small>
              <strong>{loyalty?.points_balance ?? 0} Poin</strong>
              <span>
                Setara {rupiah((loyalty?.points_balance ?? 0) * (loyalty?.redeem_value_per_point || 1000))} nilai voucher/hadiah
              </span>
            </div>

            <p style={{ marginTop: 12 }}>
              {loyalty?.message || 'Setiap belanja lunas kelipatan Rp 10.000 mendapatkan 1 poin. Tunjukkan akun Anda kepada Owner di toko untuk menukarkan poin.'}
            </p>

            {loyalty?.rewards?.length > 0 && (
              <div style={{ marginTop: 20 }}>
                <h2 style={{ fontSize: 16, marginBottom: 10 }}>Katalog Penukaran Poin di Toko</h2>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: 10 }}>
                  {loyalty.rewards.map((reward) => {
                    const currentPoints = Number(loyalty?.points_balance ?? 0)
                    const eligible = currentPoints >= reward.points
                    return (
                      <div
                        key={reward.points}
                        style={{
                          padding: 12,
                          borderRadius: 10,
                          border: `1px solid ${eligible ? '#86efac' : '#e2e8f0'}`,
                          background: eligible ? '#f0fdf4' : '#f8fafc',
                        }}
                      >
                        <div style={{ fontWeight: 700, fontSize: 15, color: eligible ? '#15803d' : '#334155' }}>
                          {reward.points} Poin
                        </div>
                        <div style={{ fontSize: 13, fontWeight: 600, margin: '4px 0' }}>{reward.title}</div>
                        <small style={{ color: eligible ? '#166534' : '#64748b' }}>
                          {eligible
                            ? 'Poin mencukupi — tukarkan di kasir toko'
                            : `Kurang ${reward.points - currentPoints} poin lagi`}
                        </small>
                      </div>
                    )
                  })}
                </div>
              </div>
            )}

            <div style={{ marginTop: 22 }}>
              <h2 style={{ fontSize: 16, marginBottom: 10 }}>Riwayat Perolehan &amp; Penukaran Poin</h2>
              {loyalty?.entries?.length > 0 ? (
                loyalty.entries.map((entry) => (
                  <div className="shop-point-entry" key={entry.id}>
                    <div>
                      <div>{entry.description}</div>
                      {entry.created_at && (
                        <small style={{ color: '#64748b' }}>
                          {new Date(entry.created_at).toLocaleString('id-ID')}
                        </small>
                      )}
                    </div>
                    <b style={{ color: entry.points_delta > 0 ? '#16a34a' : '#dc2626' }}>
                      {entry.points_delta > 0 ? `+${entry.points_delta}` : entry.points_delta} poin
                    </b>
                  </div>
                ))
              ) : (
                <p style={{ color: '#64748b', fontSize: 14 }}>
                  Belum ada riwayat poin. Selesaikan transaksi belanja untuk mulai mengumpulkan poin.
                </p>
              )}
            </div>
          </section>
        )}
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

      {qrisPreviewOpen && (
        <div className="shop-modal-backdrop" role="presentation" onClick={() => setQrisPreviewOpen(false)}>
          <section className="shop-modal" role="dialog" aria-modal="true" aria-label="Kode pembayaran QRIS" onClick={(e) => e.stopPropagation()} style={{ textAlign: 'center' }}>
            <button className="shop-modal-close" aria-label="Tutup" onClick={() => setQrisPreviewOpen(false)}>×</button>
            <p className="shop-kicker">PEMBAYARAN QRIS</p>
            <h2>Scan QRIS Toko Abi 6839</h2>
            <p>Total tagihan: <b>{rupiah(total)}</b></p>
            <img src="/qris.jpeg" alt="Kode pembayaran QRIS Toko Abi 6839" style={{ width: '100%', maxWidth: 320, borderRadius: 12, margin: '8px auto', border: '1px solid #cbd5e1' }} />
          </section>
        </div>
      )}

      <footer className="shop-footer">© {new Date().getFullYear()} LARIS - ABICELL · Belanja Online / Ambil di Toko</footer>
    </div>
  )
}