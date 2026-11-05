import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, makeIdempotencyKey, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import Modal from '../components/Modal.jsx'
import { formatCurrency, formatDate, formatNumber } from '../utils/format.js'

const colorSet = ['tile-sand', 'tile-peach', 'tile-blue', 'tile-green', 'tile-lilac', 'tile-yellow']

function ProductCard({ product, index, quantityInCart, onAdd }) {
  const stock = Number(product.stock || 0)
  const unit = product.unit?.short_name || product.unit?.name || 'unit'
  const soldOut = stock <= 0
  const maxed = quantityInCart >= stock
  return (
    <article className={`catalog-card ${soldOut ? 'catalog-card-disabled' : ''}`}>
      <div className={`catalog-art ${colorSet[index % colorSet.length]}`}>
        <span>{(product.name || 'P').trim().charAt(0).toUpperCase()}</span>
        <small>{soldOut ? 'Stok habis' : `Stok ${formatNumber(stock)} ${unit}`}</small>
      </div>
      <div className="catalog-card-content">
        <strong title={product.name}>{product.name}</strong>
        <span className="catalog-category">{product.category?.name || 'Barang'}</span>
        <div className="catalog-card-bottom">
          <b>{formatCurrency(product.sell_price)}</b>
          <button type="button" className="add-button" onClick={() => onAdd(product)} disabled={soldOut || maxed} aria-label={`Tambah ${product.name}`}>
            <Icon name="plus" size={17} />
          </button>
        </div>
      </div>
    </article>
  )
}

function ServiceCard({ service, index, onSelect }) {
  return (
    <article className="catalog-card service-card">
      <div className={`catalog-art ${colorSet[(index + 2) % colorSet.length]}`}>
        <span>{(service.name || 'J').trim().charAt(0).toUpperCase()}</span>
        <small>Layanan digital</small>
      </div>
      <div className="catalog-card-content">
        <strong title={service.name}>{service.name}</strong>
        <span className="catalog-category">{service.description || 'Pencatatan jasa berhasil'}</span>
        <div className="catalog-card-bottom">
          <b className="service-price">Sesuai nominal</b>
          <button type="button" className="add-button" onClick={() => onSelect(service)} aria-label={`Catat ${service.name}`}>
            <Icon name="plus" size={17} />
          </button>
        </div>
      </div>
    </article>
  )
}

function ServiceRecordModal({ service, onClose, onSaved }) {
  const [reference, setReference] = useState('')
  const [nominal, setNominal] = useState('')
  const [margin, setMargin] = useState('')
  const [notes, setNotes] = useState('')
  const idempotencyKeyRef = useRef(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')
    try {
      if (!idempotencyKeyRef.current) idempotencyKeyRef.current = makeIdempotencyKey()
      const response = await api.post('/service-transactions', {
        idempotency_key: idempotencyKeyRef.current,
        service_type_id: service.id,
        reference_number: reference.trim() || null,
        service_at: new Date().toISOString(),
        nominal_amount: nominal === '' ? null : Number(nominal),
        margin_amount: margin === '' ? null : Number(margin),
        notes: notes.trim() || null,
      })
      idempotencyKeyRef.current = null
      onSaved(response.data)
    } catch (err) {
      setError(err.message || 'Layanan tidak berhasil dicatat.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal title={`Catat ${service.name}`} subtitle="Catat setelah transaksi berhasil diproses pada aplikasi penyedia layanan." onClose={onClose}>
      <form className="modal-form" onSubmit={submit}>
        <div className="service-confirm-note"><Icon name="info" size={17} /> Pencatatan ini tidak memotong stok dan nominal pokok bukan omzet toko.</div>
        <label>Nomor referensi <span className="optional-label">Opsional</span><input value={reference} onChange={(event) => setReference(event.target.value)} maxLength="100" placeholder="Nomor referensi dari penyedia" /></label>
        <label>Nominal pokok layanan (Rp) <span className="optional-label">Opsional</span><input type="number" min="0" step="1" value={nominal} onChange={(event) => setNominal(event.target.value)} placeholder="Contoh: 100000" /></label>
        <label>Margin / biaya jasa (Rp) <span className="optional-label">Boleh dikosongkan</span><input type="number" min="0" step="1" value={margin} onChange={(event) => setMargin(event.target.value)} placeholder="Isi jika sudah diketahui" /></label>
        <label>Catatan <span className="optional-label">Opsional</span><textarea rows="2" value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Catatan tambahan..." /></label>
        {error && <div className="form-error"><Icon name="warning" size={16} />{error}</div>}
        <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button type="submit" className="primary-button" disabled={loading}>{loading ? 'Menyimpan…' : 'Simpan transaksi jasa'}</button></div>
      </form>
    </Modal>
  )
}

function ReceiptModal({ transaction, onClose }) {
  if (!transaction) return null
  const details = transaction.details || []
  return (
    <Modal title="Transaksi berhasil" subtitle="Pembayaran telah dicatat dan stok telah diperbarui." onClose={onClose} className="receipt-modal">
      <div className="receipt-paper" id="printable-receipt">
        <div className="receipt-brand"><div className="brand-mark brand-logo"><img src="/logo_abicell.png" alt="Logo AbiCell" /></div><strong>LARIS - ABICELL</strong><span>Toko Kelontong &amp; Jasa Digital</span></div>
        <div className="receipt-meta"><span>No. {transaction.invoice_number}</span><span>{formatDate(transaction.created_at, true)}</span></div>
        <div className="receipt-divider" />
        {details.map((detail) => <div className="receipt-line" key={detail.id}><span>{detail.product?.name || `Produk #${detail.product_id}`}<small>{detail.quantity} × {formatCurrency(detail.price)}</small></span><b>{formatCurrency(detail.subtotal)}</b></div>)}
        <div className="receipt-divider" />
        <div className="receipt-total"><span>Total</span><strong>{formatCurrency(transaction.total_price)}</strong></div>
        <div className="receipt-line"><span>Bayar ({transaction.payment_method === 'cash' ? 'Tunai' : 'QRIS GoPay'})</span><b>{formatCurrency(transaction.pay_amount)}</b></div>
        {transaction.payment_method === 'cash' && <div className="receipt-line"><span>Kembalian</span><b>{formatCurrency(transaction.change_amount)}</b></div>}
        {transaction.payment_method === 'qris_gopay' && transaction.payment_reference && <div className="receipt-line"><span>Referensi QRIS</span><b>{transaction.payment_reference}</b></div>}
        <p className="receipt-thanks">Terima kasih telah berbelanja.</p>
      </div>
      <div className="modal-actions receipt-actions"><button type="button" className="secondary-button" onClick={onClose}>Tutup</button><button type="button" className="primary-button" onClick={() => window.print()}><Icon name="printer" size={16} /> Cetak struk</button></div>
    </Modal>
  )
}

export default function PosPage({ user, globalSearch = '', onToast }) {
  const isOwner = user?.role === 'admin'
  const [products, setProducts] = useState([])
  const [categories, setCategories] = useState([])
  const [customers, setCustomers] = useState([])
  const [selectedCustomerId, setSelectedCustomerId] = useState('')
  const [services, setServices] = useState([])
  const [cart, setCart] = useState([])
  const [tab, setTab] = useState('all')
  const [category, setCategory] = useState('all')
  const [search, setSearch] = useState(globalSearch)
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [payAmount, setPayAmount] = useState('')
  const [qrisConfirmed, setQrisConfirmed] = useState(false)
  const [qrisPreviewOpen, setQrisPreviewOpen] = useState(false)
  const [paymentReference, setPaymentReference] = useState('')
  const checkoutKeyRef = useRef(null)
  const [loading, setLoading] = useState(true)
  const [checkoutLoading, setCheckoutLoading] = useState(false)
  const [error, setError] = useState('')
  const [serviceToRecord, setServiceToRecord] = useState(null)
  const [receipt, setReceipt] = useState(null)

  useEffect(() => { void Promise.resolve().then(() => setSearch(globalSearch)) }, [globalSearch])

  useEffect(() => {
    if (!qrisPreviewOpen) return undefined

    const closeOnEscape = (event) => {
      if (event.key === 'Escape') setQrisPreviewOpen(false)
    }

    window.addEventListener('keydown', closeOnEscape)
    return () => window.removeEventListener('keydown', closeOnEscape)
  }, [qrisPreviewOpen])

  const loadCatalog = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [productResponse, categoryResponse, serviceResponse, customerResponse] = await Promise.all([
        api.get('/products'),
        api.get('/categories'),
        api.get('/service-types'),
        isOwner ? api.get('/customers') : Promise.resolve({ data: [] }),
      ])
      setProducts(unwrapList(productResponse))
      setCategories(unwrapList(categoryResponse))
      setServices(unwrapList(serviceResponse).filter((service) => service.is_active))
      setCustomers(unwrapList(customerResponse))
    } catch (err) {
      setError(err.message || 'Katalog tidak dapat dimuat.')
    } finally {
      setLoading(false)
    }
  }, [isOwner])

  useEffect(() => { void Promise.resolve().then(loadCatalog) }, [loadCatalog])

  const addProduct = (product) => {
    checkoutKeyRef.current = null
    setCart((current) => {
      const existing = current.find((item) => item.id === product.id)
      if (existing) {
        if (existing.quantity >= Number(product.stock || 0)) {
          onToast({ type: 'error', message: `Stok ${product.name} tidak mencukupi.` })
          return current
        }
        return current.map((item) => item.id === product.id ? { ...item, quantity: item.quantity + 1 } : item)
      }
      if (Number(product.stock || 0) <= 0) return current
      return [...current, { ...product, quantity: 1 }]
    })
  }

  const updateQuantity = (productId, nextQuantity) => {
    checkoutKeyRef.current = null
    const product = products.find((item) => item.id === productId)
    if (!product) return
    if (nextQuantity <= 0) {
      setCart((current) => current.filter((item) => item.id !== productId))
      return
    }
    if (nextQuantity > Number(product.stock || 0)) {
      onToast({ type: 'error', message: `Stok tersedia hanya ${product.stock}.` })
      return
    }
    setCart((current) => current.map((item) => item.id === productId ? { ...item, quantity: nextQuantity } : item))
  }

  const filteredProducts = useMemo(() => products.filter((product) => {
    const term = search.trim().toLowerCase()
    const matchesSearch = !term || [product.name, product.sku_code, product.category?.name].join(' ').toLowerCase().includes(term)
    const matchesCategory = category === 'all' || String(product.category_id) === String(category)
    return matchesSearch && matchesCategory
  }), [products, search, category])

  const filteredServices = useMemo(() => services.filter((service) => {
    const term = search.trim().toLowerCase()
    return !term || [service.name, service.description].join(' ').toLowerCase().includes(term)
  }), [services, search])

  const total = cart.reduce((sum, item) => sum + Number(item.sell_price || 0) * item.quantity, 0)
  const cartCount = cart.reduce((sum, item) => sum + item.quantity, 0)
  const numericPayAmount = paymentMethod === 'qris_gopay' ? total : Number(payAmount || total)
  const change = Math.max(0, numericPayAmount - total)
  const canCheckout = cart.length > 0 && !checkoutLoading && (
    paymentMethod === 'cash' ? numericPayAmount >= total : qrisConfirmed
  )

  const checkout = async () => {
    if (!canCheckout) return
    setCheckoutLoading(true)
    setError('')
    try {
      if (!checkoutKeyRef.current) checkoutKeyRef.current = makeIdempotencyKey()
      const payload = {
        idempotency_key: checkoutKeyRef.current,
        payment_method: paymentMethod,
        pay_amount: paymentMethod === 'qris_gopay' ? total : numericPayAmount,
        items: cart.map((item) => ({ product_id: item.id, quantity: item.quantity })),
      }
      if (selectedCustomerId) payload.customer_id = Number(selectedCustomerId)
      if (paymentMethod === 'qris_gopay') {
        payload.manual_verification_confirmed = true
        payload.payment_reference = paymentReference.trim() || null
      }
      const response = await api.post('/transactions', payload)
      setReceipt(response.data)
      checkoutKeyRef.current = null
      setCart([])
      setSelectedCustomerId('')
      setPayAmount('')
      setPaymentReference('')
      setQrisConfirmed(false)
      setPaymentMethod('cash')
      await loadCatalog()
    } catch (err) {
      setError(err.message || 'Checkout gagal diproses.')
    } finally {
      setCheckoutLoading(false)
    }
  }

  const recordServiceSaved = (serviceTransaction) => {
    setServiceToRecord(null)
    onToast({ type: 'success', message: `Transaksi ${serviceTransaction.service_type?.name || 'jasa'} berhasil dicatat.` })
  }

  return (
    <div className="pos-page">
      <div className="pos-heading">
        <div><h1>Kasir / Point of Sale</h1><p>Pilih barang atau layanan, lalu proses transaksi pelanggan.</p></div>
        <button type="button" className="outline-button" onClick={loadCatalog}><Icon name="refresh" size={15} /> Perbarui katalog</button>
      </div>

      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}<button type="button" onClick={() => setError('')}>Tutup</button></div>}

      <div className="pos-layout">
        <section className="catalog-section">
          <div className="catalog-toolbar">
            <div className="catalog-tabs" role="tablist" aria-label="Jenis katalog">
              {[['all', 'Semua'], ['products', 'Barang'], ['services', 'Layanan']].map(([id, label]) => <button key={id} type="button" role="tab" aria-selected={tab === id} className={tab === id ? 'selected' : ''} onClick={() => setTab(id)}>{label}</button>)}
            </div>
            <label className="catalog-search"><Icon name="search" size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari nama barang, kode produk, atau layanan..." /></label>
            <label className="category-select"><span>Kategori:</span><select value={category} onChange={(event) => setCategory(event.target.value)}><option value="all">Semua barang</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><Icon name="chevron" size={14} /></label>
          </div>

          <div className="catalog-results">
            {loading ? <div className="catalog-loading"><span className="button-spinner" /> Memuat katalog...</div> : (
              <>
                {(tab === 'all' || tab === 'products') && filteredProducts.map((product, index) => <ProductCard key={product.id} product={product} index={index} quantityInCart={cart.find((item) => item.id === product.id)?.quantity || 0} onAdd={addProduct} />)}
                {(tab === 'all' || tab === 'services') && filteredServices.map((service, index) => <ServiceCard key={`service-${service.id}`} service={service} index={index} onSelect={setServiceToRecord} />)}
                {((tab === 'services' && filteredServices.length === 0) || (tab === 'products' && filteredProducts.length === 0) || (tab === 'all' && filteredProducts.length + filteredServices.length === 0)) && <div className="empty-state"><Icon name="box" size={28} /><strong>Tidak ada item ditemukan</strong><span>Coba ubah kata kunci pencarian atau filter kategori.</span></div>}
              </>
            )}
          </div>

          <div className="catalog-count">Menampilkan {tab === 'services' ? filteredServices.length : tab === 'products' ? filteredProducts.length : filteredProducts.length + filteredServices.length} {tab === 'services' ? 'layanan' : tab === 'products' ? 'barang' : 'barang dan layanan'}</div>
          <div className="transaction-guide">
            <strong><Icon name="info" size={16} /> Alur transaksi</strong>
            <ol><li>Pilih barang dari katalog dan tambahkan ke keranjang.</li><li>Atur jumlah barang sesuai kebutuhan pelanggan.</li><li>Pilih metode pembayaran, lalu proses transaksi.</li></ol>
          </div>
        </section>

        <aside className="checkout-column">
          <section className="panel cart-panel">
            <div className="cart-heading"><div><h2>Keranjang Transaksi</h2><p>{cartCount} item</p></div><button type="button" className="text-button" onClick={() => { checkoutKeyRef.current = null; setCart([]) }} disabled={!cart.length}>Kosongkan</button></div>
            <div className="cart-items">
              {cart.length ? cart.map((item, index) => (
                <div className="cart-item" key={item.id}>
                  <div className={`cart-item-art ${colorSet[index % colorSet.length]}`}>{item.name.charAt(0).toUpperCase()}</div>
                  <div className="cart-item-info"><strong>{item.name}</strong><span>{formatCurrency(item.sell_price)} / {item.unit?.short_name || item.unit?.name || 'unit'}</span><div className="quantity-control"><button type="button" onClick={() => updateQuantity(item.id, item.quantity - 1)} aria-label="Kurangi"><Icon name="minus" size={13} /></button><b>{item.quantity}</b><button type="button" onClick={() => updateQuantity(item.id, item.quantity + 1)} aria-label="Tambah"><Icon name="plus" size={13} /></button></div></div>
                  <div className="cart-item-total"><strong>{formatCurrency(Number(item.sell_price) * item.quantity)}</strong><button type="button" onClick={() => updateQuantity(item.id, 0)} aria-label={`Hapus ${item.name}`}><Icon name="close" size={14} /></button></div>
                </div>
              )) : <div className="cart-empty"><div className="cart-empty-icon"><Icon name="receipt" size={24} /></div><strong>Keranjang masih kosong</strong><span>Pilih barang dari katalog untuk memulai transaksi.</span></div>}
            </div>
            <div className="cart-totals">
              <div><span>Subtotal</span><strong>{formatCurrency(total)}</strong></div>
              <div><span>Diskon</span><strong>{formatCurrency(0)}</strong></div>
              <div className="cart-grand-total"><span>Total pembayaran</span><strong>{formatCurrency(total)}</strong></div>
            </div>
          </section>

          <section className="panel payment-panel">
            <div className="payment-title"><h2>Ringkasan Pembayaran</h2><span>Total pembayaran</span><strong className="payment-grand-total">{formatCurrency(total)}</strong></div>
            {isOwner && (
              <label className="customer-select">
                Pelanggan
                <select value={selectedCustomerId} onChange={(event) => setSelectedCustomerId(event.target.value)}>
                  <option value="">Pelanggan umum</option>
                  {customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}{customer.phone ? ` · ${customer.phone}` : ''}</option>)}
                </select>
              </label>
            )}
            <div className="payment-method-label">Metode pembayaran</div>
            <div className="payment-methods">
              <button type="button" className={paymentMethod === 'cash' ? 'payment-option active' : 'payment-option'} onClick={() => { checkoutKeyRef.current = null; setPaymentMethod('cash'); setQrisConfirmed(false) }}>Tunai</button>
              <button type="button" className={paymentMethod === 'qris_gopay' ? 'payment-option active' : 'payment-option'} onClick={() => { checkoutKeyRef.current = null; setPaymentMethod('qris_gopay'); setPayAmount(String(total)); setQrisConfirmed(false) }}>QRIS GoPay</button>
            </div>
            {paymentMethod === 'cash' ? (
              <div className="cash-input-row"><label htmlFor="cash-received">Uang diterima</label><div className="currency-input"><span>Rp</span><input id="cash-received" type="number" min={total} step="1" value={payAmount} onChange={(event) => { checkoutKeyRef.current = null; setPayAmount(event.target.value) }} placeholder={String(total)} /></div><div className="change-row"><span>Kembalian</span><strong>{formatCurrency(change)}</strong></div></div>
            ) : (
              <div className="qris-confirm-box">
                <button type="button" className="qris-preview-button" onClick={() => setQrisPreviewOpen(true)} aria-label="Perbesar kode QRIS">
                  <img className="qris-image" src="/qris.jpeg" alt="Kode pembayaran QRIS Toko Abi 6839" />
                  <span>Klik untuk perbesar</span>
                </button>
                <label className="qris-reference">Nomor referensi <span>(opsional)</span><input value={paymentReference} onChange={(event) => { checkoutKeyRef.current = null; setPaymentReference(event.target.value) }} placeholder="Referensi GoPay Merchant" /></label>
                <label className="qris-check"><input type="checkbox" checked={qrisConfirmed} onChange={(event) => { checkoutKeyRef.current = null; setQrisConfirmed(event.target.checked) }} /><span>Saya sudah memeriksa GoPay Merchant dan memastikan pembayaran diterima sesuai nominal.</span></label>
              </div>
            )}
            <button type="button" className="checkout-button" onClick={checkout} disabled={!canCheckout}>
              {checkoutLoading ? <span className="button-spinner" /> : null}
              {checkoutLoading ? 'Memproses...' : paymentMethod === 'qris_gopay' ? 'Konfirmasi QRIS & Selesaikan' : 'Proses Pembayaran'}
              {!checkoutLoading && <span>→</span>}
            </button>
            {paymentMethod === 'qris_gopay' && <p className="payment-hint">QRIS dicatat lunas setelah verifikasi manual oleh Kasir.</p>}
          </section>
        </aside>
      </div>

      {serviceToRecord && <ServiceRecordModal service={serviceToRecord} onClose={() => setServiceToRecord(null)} onSaved={recordServiceSaved} />}
      {receipt && <ReceiptModal transaction={receipt} onClose={() => setReceipt(null)} />}
      {qrisPreviewOpen && (
        <Modal title="Kode pembayaran QRIS" subtitle="Scan kode ini menggunakan aplikasi pembayaran." onClose={() => setQrisPreviewOpen(false)} className="qris-preview">
          <div className="qris-preview-content">
            <img src="/qris.jpeg" alt="Kode pembayaran QRIS Toko Abi 6839" />
          </div>
        </Modal>
      )}
    </div>
  )
}
