import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, makeIdempotencyKey, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import Modal from '../components/Modal.jsx'
import { formatCurrency, formatDate, formatNumber, todayInput } from '../utils/format.js'

const tabs = [
  { id: 'stock', label: 'Persediaan', icon: 'inventory' },
  { id: 'receipts', label: 'Penerimaan barang', icon: 'receipt' },
  { id: 'opname', label: 'Stock opname', icon: 'clipboard' },
  { id: 'history', label: 'Riwayat stok', icon: 'history' },
  { id: 'restock', label: 'Rekomendasi restok', icon: 'chart', ownerOnly: true },
]

const localDateTimeInput = () => {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

const newReceiptForm = () => ({
  supplier_id: '',
  received_at: todayInput(),
  supplier_invoice_reference: '',
  notes: '',
  items: [{ product_id: '', quantity: '1', unit_cost: '' }],
})

const newOpnameForm = () => ({
  counted_at: localDateTimeInput(),
  notes: '',
  items: [{ product_id: '', physical_stock: '', notes: '' }],
})

const statusText = (status) => ({
  draft: 'Draft',
  confirmed: 'Dikonfirmasi',
  pending_approval: 'Menunggu Owner',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  needs_restock: 'Perlu restok',
  sufficient: 'Stok cukup',
  insufficient_data: 'Data belum cukup',
}[status] || status || '—')

const statusClass = (status) => ({
  draft: 'status-neutral',
  confirmed: 'status-success',
  pending_approval: 'status-warning',
  approved: 'status-success',
  rejected: 'status-danger',
  needs_restock: 'status-warning',
  sufficient: 'status-success',
  insufficient_data: 'status-neutral',
}[status] || 'status-neutral')

function Field({ label, children, hint, className = '' }) {
  return (
    <label className={`inventory-field ${className}`}>
      <span>{label}</span>
      {children}
      {hint && <small>{hint}</small>}
    </label>
  )
}

export default function InventoryPage({ search = '', user, onToast, onNavigate }) {
  const isOwner = user?.role === 'admin'
  const [activeTab, setActiveTab] = useState('stock')
  const [products, setProducts] = useState([])
  const [productMeta, setProductMeta] = useState(null)
  const [summary, setSummary] = useState(null)
  const [productPage, setProductPage] = useState(1)
  const [suppliers, setSuppliers] = useState([])
  const [receipts, setReceipts] = useState([])
  const [opnames, setOpnames] = useState([])
  const [movements, setMovements] = useState([])
  const [recommendations, setRecommendations] = useState([])
  const [recommendationSummary, setRecommendationSummary] = useState(null)

  const [loadingStock, setLoadingStock] = useState(true)
  const [loadingTab, setLoadingTab] = useState(false)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [categoryFilter, setCategoryFilter] = useState('all')

  const [modal, setModal] = useState('')
  const [receiptForm, setReceiptForm] = useState(newReceiptForm)
  const receiptKeyRef = useRef(null)
  const [selectedReceipt, setSelectedReceipt] = useState(null)
  const [receiptAcknowledged, setReceiptAcknowledged] = useState(false)

  const [opnameForm, setOpnameForm] = useState(newOpnameForm)
  const opnameKeyRef = useRef(null)
  const [selectedOpname, setSelectedOpname] = useState(null)
  const [rejectionReason, setRejectionReason] = useState('')

  const [supplierForm, setSupplierForm] = useState({ name: '', contact_person: '', phone: '', address: '' })
  const [priceForm, setPriceForm] = useState({ product_id: '', product_name: '', current_price: '', buy_price: '', reason: '' })
  const [priceHistory, setPriceHistory] = useState([])
  const [priceHistoryLoading, setPriceHistoryLoading] = useState(false)
  const [saving, setSaving] = useState(false)

  const notify = (type, message) => onToast?.({ type, message })

  const loadStock = useCallback(async (page = productPage) => {
    setLoadingStock(true)
    setError('')
    try {
      const response = await api.get(`/products?page=${page}&for_receiving=1`)
      setProducts(unwrapList(response))
      setProductMeta(response.data && !Array.isArray(response.data) ? response.data : null)

      if (isOwner) {
        const report = await api.get(`/reports/inventory?page=${page}`)
        setSummary(report.summary || null)
      } else {
        setSummary(null)
      }
    } catch (err) {
      setError(err.message || 'Data persediaan tidak dapat dimuat.')
    } finally {
      setLoadingStock(false)
    }
  }, [isOwner, productPage])

  const loadSuppliers = useCallback(async () => {
    const response = await api.get('/suppliers')
    const rows = unwrapList(response)
    setSuppliers(rows)
    return rows
  }, [])

  const loadReceipts = useCallback(async () => {
    setLoadingTab(true)
    setError('')
    try {
      const response = await api.get('/purchase-receipts')
      setReceipts(unwrapList(response))
    } catch (err) {
      setError(err.message || 'Riwayat penerimaan barang tidak dapat dimuat.')
    } finally {
      setLoadingTab(false)
    }
  }, [])

  const loadOpnames = useCallback(async () => {
    setLoadingTab(true)
    setError('')
    try {
      const response = await api.get('/stock-opnames')
      setOpnames(unwrapList(response))
    } catch (err) {
      setError(err.message || 'Data stock opname tidak dapat dimuat.')
    } finally {
      setLoadingTab(false)
    }
  }, [])

  const loadMovements = useCallback(async () => {
    setLoadingTab(true)
    setError('')
    try {
      const response = await api.get('/stock-movements')
      setMovements(unwrapList(response))
    } catch (err) {
      setError(err.message || 'Riwayat pergerakan stok tidak dapat dimuat.')
    } finally {
      setLoadingTab(false)
    }
  }, [])

  const loadRecommendations = useCallback(async () => {
    if (!isOwner) return
    setLoadingTab(true)
    setError('')
    try {
      const response = await api.get('/restock-recommendations')
      setRecommendations(unwrapList(response))
      setRecommendationSummary(response.summary || null)
    } catch (err) {
      setError(err.message || 'Rekomendasi restok tidak dapat dimuat.')
    } finally {
      setLoadingTab(false)
    }
  }, [isOwner])

  useEffect(() => { void Promise.resolve().then(() => loadStock(productPage)) }, [loadStock, productPage])

  useEffect(() => {
    void Promise.resolve().then(() => {
      if (activeTab === 'receipts') return loadReceipts()
      if (activeTab === 'opname') return loadOpnames()
      if (activeTab === 'history') return loadMovements()
      if (activeTab === 'restock' && isOwner) return loadRecommendations()
      return undefined
    })
  }, [activeTab, isOwner, loadReceipts, loadOpnames, loadMovements, loadRecommendations])

  const recommendationByProduct = useMemo(() => new Map(
    recommendations.map((row) => [Number(row.product?.id), row])
  ), [recommendations])

  const filteredProducts = useMemo(() => products.filter((product) => {
    const term = search.trim().toLowerCase()
    const matchesSearch = !term || `${product.name} ${product.sku_code} ${product.category?.name || ''}`.toLowerCase().includes(term)
    const matchesCategory = categoryFilter === 'all' || String(product.category_id) === String(categoryFilter)
    const recommendation = recommendationByProduct.get(Number(product.id))?.recommendation?.status
    const stock = Number(product.stock || 0)
    const matchesStatus = statusFilter === 'all'
      || (statusFilter === 'zero' && stock === 0)
      || (statusFilter === 'available' && stock > 0 && recommendation !== 'needs_restock')
    return matchesSearch && matchesCategory && matchesStatus
  }), [products, search, categoryFilter, statusFilter, recommendationByProduct])

  const categories = useMemo(() => {
    const unique = new Map()
    products.forEach((product) => {
      if (product.category?.id) unique.set(product.category.id, product.category.name)
    })
    return [...unique.entries()].map(([id, name]) => ({ id, name }))
  }, [products])

  const openPriceEditor = async (product) => {
    if (!isOwner) return
    setPriceForm({
      product_id: product.id,
      product_name: product.name,
      current_price: String(product.buy_price ?? 0),
      buy_price: String(product.buy_price ?? 0),
      reason: '',
    })
    setPriceHistory([])
    setFormError('')
    setModal('price-edit')
    setPriceHistoryLoading(true)
    try {
      const response = await api.get(`/products/${product.id}/price-history`)
      setPriceHistory(unwrapList(response))
    } catch (err) {
      setFormError(err.message || 'Riwayat perubahan harga tidak dapat dimuat.')
    } finally {
      setPriceHistoryLoading(false)
    }
  }

  const saveMasterBuyPrice = async (event) => {
    event.preventDefault()
    if (!isOwner) return
    setFormError('')
    const nextPrice = Number(priceForm.buy_price)
    if (!Number.isFinite(nextPrice) || nextPrice < 0) {
      return setFormError('Harga beli master harus berupa angka nol atau lebih.')
    }
    if (Math.round(nextPrice * 100) === Math.round(Number(priceForm.current_price || 0) * 100)) {
      return setFormError('Harga baru sama dengan harga master saat ini. Tidak ada perubahan untuk disimpan.')
    }
    if (!priceForm.reason.trim()) {
      return setFormError('Alasan perubahan harga wajib diisi.')
    }

    setSaving(true)
    try {
      await api.patch(`/products/${priceForm.product_id}`, {
        buy_price: nextPrice,
        buy_price_change_reason: priceForm.reason.trim(),
      })
      setModal('')
      await loadStock(productPage)
      notify('success', `Harga beli master ${priceForm.product_name} berhasil diperbarui dan dicatat.`)
    } catch (err) {
      setFormError(err.message || 'Harga beli master gagal diperbarui.')
    } finally {
      setSaving(false)
    }
  }

  const openReceiptForm = async () => {
    setFormError('')
    setReceiptForm(newReceiptForm())
    receiptKeyRef.current = null
    setModal('receipt-create')
    try {
      await loadSuppliers()
    } catch (err) {
      setFormError(err.message || 'Data supplier tidak dapat dimuat.')
    }
  }

  const updateReceiptField = (field, value) => {
    receiptKeyRef.current = null
    setReceiptForm((current) => ({ ...current, [field]: value }))
  }

  const updateReceiptItem = (index, field, value) => {
    receiptKeyRef.current = null
    setReceiptForm((current) => ({
      ...current,
      items: current.items.map((item, itemIndex) => {
        if (itemIndex !== index) return item
        if (field === 'product_id') {
          const product = products.find((row) => String(row.id) === String(value))
          return { ...item, product_id: value, unit_cost: product?.buy_price ?? '' }
        }
        return { ...item, [field]: value }
      }),
    }))
  }

  const addReceiptItem = () => {
    receiptKeyRef.current = null
    setReceiptForm((current) => ({
    ...current,
    items: [...current.items, { product_id: '', quantity: '1', unit_cost: '' }],
    }))
  }

  const removeReceiptItem = (index) => {
    receiptKeyRef.current = null
    setReceiptForm((current) => ({
      ...current,
      items: current.items.filter((_, itemIndex) => itemIndex !== index),
    }))
  }

  const receiptTotal = receiptForm.items.reduce((sum, item) => (
    sum + Math.max(0, Number(item.quantity || 0)) * Math.max(0, Number(item.unit_cost || 0))
  ), 0)

  const createReceipt = async (event) => {
    event.preventDefault()
    setFormError('')
    const selectedIds = receiptForm.items.map((item) => String(item.product_id)).filter(Boolean)
    if (!receiptForm.supplier_id) return setFormError('Pilih supplier terlebih dahulu.')
    if (!receiptForm.items.length || receiptForm.items.some((item) => !item.product_id || !Number.isInteger(Number(item.quantity)) || Number(item.quantity) < 1 || item.unit_cost === '' || !Number.isFinite(Number(item.unit_cost)) || Number(item.unit_cost) < 0)) {
      return setFormError('Lengkapi produk, kuantitas, dan harga beli setiap item dengan benar.')
    }
    if (new Set(selectedIds).size !== selectedIds.length) return setFormError('Produk yang sama tidak boleh dimasukkan dua kali. Gabungkan kuantitasnya dalam satu baris.')

    setSaving(true)
    try {
      if (!receiptKeyRef.current) receiptKeyRef.current = makeIdempotencyKey()
        const response = await api.post('/purchase-receipts', {
        idempotency_key: receiptKeyRef.current,
        supplier_id: Number(receiptForm.supplier_id),
        received_at: receiptForm.received_at,
        supplier_invoice_reference: receiptForm.supplier_invoice_reference.trim() || null,
        notes: receiptForm.notes.trim() || null,
        items: receiptForm.items.map((item) => ({
          product_id: Number(item.product_id),
          quantity: Number(item.quantity),
          unit_cost: Number(item.unit_cost),
        })),
      })
      const receipt = response.data
      setSelectedReceipt(receipt)
      setReceiptAcknowledged(false)
      setModal('receipt-detail')
      await Promise.all([loadReceipts(), loadStock(productPage)])
      notify('success', `Draft penerimaan ${receipt.receipt_number} berhasil dicatat. Stok belum berubah.`)
    } catch (err) {
      setFormError(err.message || 'Draft penerimaan gagal disimpan.')
    } finally {
      setSaving(false)
    }
  }

  const openReceiptDetail = (receipt) => {
    setSelectedReceipt(receipt)
    setReceiptAcknowledged(false)
    setFormError('')
    setModal('receipt-detail')
  }

  const confirmReceipt = async () => {
    if (!selectedReceipt || !receiptAcknowledged) return
    setSaving(true)
    setFormError('')
    try {
      const response = await api.post(`/purchase-receipts/${selectedReceipt.id}/confirm`, {
        received_and_paid_confirmed: true,
      })
      setSelectedReceipt(response.data)
      await Promise.all([loadReceipts(), loadStock(productPage), loadMovements()])
      notify('success', `Penerimaan ${response.data.receipt_number} dikonfirmasi. Stok bertambah satu kali.`)
    } catch (err) {
      setFormError(err.message || 'Penerimaan tidak dapat dikonfirmasi.')
    } finally {
      setSaving(false)
    }
  }

  const deleteDraftReceipt = async () => {
    if (!selectedReceipt || selectedReceipt.status !== 'draft') return
    if (!window.confirm(`Hapus draft penerimaan ${selectedReceipt.receipt_number}? Stok tidak akan berubah.`)) return
    setSaving(true)
    try {
      await api.delete(`/purchase-receipts/${selectedReceipt.id}`)
      setModal('')
      setSelectedReceipt(null)
      await loadReceipts()
      notify('success', 'Draft penerimaan berhasil dihapus.')
    } catch (err) {
      setFormError(err.message || 'Draft penerimaan tidak dapat dihapus.')
    } finally {
      setSaving(false)
    }
  }

  const openSupplierForm = () => {
    setSupplierForm({ name: '', contact_person: '', phone: '', address: '' })
    setFormError('')
    setModal('supplier-create')
  }

  const createSupplier = async (event) => {
    event.preventDefault()
    setFormError('')
    if (!supplierForm.name.trim()) return setFormError('Nama supplier wajib diisi.')
    setSaving(true)
    try {
      const response = await api.post('/suppliers', {
        name: supplierForm.name.trim(),
        contact_person: supplierForm.contact_person.trim() || null,
        phone: supplierForm.phone.trim() || null,
        address: supplierForm.address.trim() || null,
      })
      setSuppliers((current) => [...current.filter((supplier) => Number(supplier.id) !== Number(response.data.id)), response.data])
      setReceiptForm((current) => ({ ...current, supplier_id: String(response.data.id) }))
      setModal('receipt-create')
      notify('success', 'Supplier berhasil ditambahkan.')
    } catch (err) {
      setFormError(err.message || 'Supplier gagal ditambahkan.')
    } finally {
      setSaving(false)
    }
  }

  const openOpnameForm = () => {
    setOpnameForm(newOpnameForm())
    opnameKeyRef.current = null
    setFormError('')
    setModal('opname-create')
  }

  const updateOpnameField = (field, value) => {
    opnameKeyRef.current = null
    setOpnameForm((current) => ({ ...current, [field]: value }))
  }

  const updateOpnameItem = (index, field, value) => {
    opnameKeyRef.current = null
    setOpnameForm((current) => ({
    ...current,
    items: current.items.map((item, itemIndex) => itemIndex === index ? { ...item, [field]: value } : item),
    }))
  }

  const createOpname = async (event) => {
    event.preventDefault()
    setFormError('')
    const selectedItems = opnameForm.items.filter((item) => item.product_id !== '')
    if (!selectedItems.length) return setFormError('Tambahkan setidaknya satu produk untuk dihitung.')
    if (selectedItems.some((item) => item.physical_stock === '' || !Number.isInteger(Number(item.physical_stock)) || Number(item.physical_stock) < 0)) {
      return setFormError('Isi jumlah fisik dengan bilangan bulat nol atau lebih.')
    }
    const ids = selectedItems.map((item) => String(item.product_id))
    if (new Set(ids).size !== ids.length) return setFormError('Produk yang sama hanya boleh dicatat satu kali dalam satu stock opname.')
    const hasDifferenceWithoutReason = selectedItems.some((item) => {
      const product = products.find((row) => String(row.id) === String(item.product_id))
      return product && Number(item.physical_stock) !== Number(product.stock) && !item.notes.trim() && !opnameForm.notes.trim()
    })
    if (hasDifferenceWithoutReason) return setFormError('Setiap selisih stok wajib memiliki alasan pada item atau catatan umum.')

    setSaving(true)
    try {
      if (!opnameKeyRef.current) opnameKeyRef.current = makeIdempotencyKey()
      const response = await api.post('/stock-opnames', {
        idempotency_key: opnameKeyRef.current,
        counted_at: opnameForm.counted_at,
        notes: opnameForm.notes.trim() || null,
        items: selectedItems.map((item) => ({
          product_id: Number(item.product_id),
          physical_stock: Number(item.physical_stock),
          notes: item.notes.trim() || null,
        })),
      })
      setSelectedOpname(response.data)
      setModal('opname-detail')
      await Promise.all([loadOpnames(), loadStock(productPage)])
      notify('success', 'Hasil stock opname dicatat dan menunggu persetujuan Owner. Stok belum berubah.')
    } catch (err) {
      setFormError(err.message || 'Stock opname gagal dicatat.')
    } finally {
      setSaving(false)
    }
  }

  const openOpnameDetail = async (opname) => {
    setSelectedOpname(opname)
    setFormError('')
    setRejectionReason('')
    setModal('opname-detail')
    try {
      const response = await api.get(`/stock-opnames/${opname.id}`)
      setSelectedOpname(response.data)
    } catch (err) {
      setFormError(err.message || 'Detail stock opname tidak dapat dimuat.')
    }
  }

  const approveOpname = async () => {
    if (!isOwner || !selectedOpname) return
    if (!window.confirm(`Setujui penyesuaian stok untuk ${selectedOpname.opname_number}? Stok produk akan disamakan dengan hasil hitung fisik.`)) return
    setSaving(true)
    setFormError('')
    try {
      const response = await api.post(`/stock-opnames/${selectedOpname.id}/approve`, {})
      setSelectedOpname(response.data)
      await Promise.all([loadOpnames(), loadStock(productPage), loadMovements()])
      notify('success', 'Stock opname disetujui. Penyesuaian stok dan riwayatnya sudah dicatat.')
    } catch (err) {
      setFormError(err.message || 'Stock opname tidak dapat disetujui.')
    } finally {
      setSaving(false)
    }
  }

  const rejectOpname = async () => {
    if (!isOwner || !selectedOpname) return
    if (rejectionReason.trim().length < 3) return setFormError('Alasan penolakan minimal 3 karakter.')
    setSaving(true)
    setFormError('')
    try {
      const response = await api.post(`/stock-opnames/${selectedOpname.id}/reject`, {
        rejection_reason: rejectionReason.trim(),
      })
      setSelectedOpname(response.data)
      await loadOpnames()
      notify('success', 'Stock opname ditolak. Stok tidak berubah.')
    } catch (err) {
      setFormError(err.message || 'Stock opname tidak dapat ditolak.')
    } finally {
      setSaving(false)
    }
  }

  const refreshActiveTab = () => {
    if (activeTab === 'stock') return loadStock(productPage)
    if (activeTab === 'receipts') return loadReceipts()
    if (activeTab === 'opname') return loadOpnames()
    if (activeTab === 'history') return loadMovements()
    if (activeTab === 'restock' && isOwner) return loadRecommendations()
    return undefined
  }

  const stockCount = summary?.active_product_count ?? productMeta?.total ?? products.length
  const zeroStockCount = summary?.zero_stock_product_count ?? products.filter((product) => Number(product.stock) === 0).length
  const negativeStockCount = summary?.negative_stock_product_count ?? products.filter((product) => Number(product.stock) < 0).length

  return (
    <div className="module-page inventory-page">
      <div className="page-heading-row">
        <div>
          <h1>Inventory</h1>
          <p>Kelola persediaan, penerimaan barang, dan pemeriksaan stok Toko Abi.</p>
        </div>
        <div className="heading-actions inventory-heading-actions">
          <button type="button" className="outline-button" onClick={refreshActiveTab} disabled={loadingStock || loadingTab}>
            <Icon name="refresh" size={15} /> Muat ulang
          </button>
          {activeTab === 'stock' && <button type="button" className="primary-button" onClick={openReceiptForm}><Icon name="plus" size={15} /> Catat penerimaan</button>}
          {activeTab === 'receipts' && <button type="button" className="primary-button" onClick={openReceiptForm}><Icon name="plus" size={15} /> Catat penerimaan</button>}
          {activeTab === 'opname' && <button type="button" className="primary-button" onClick={openOpnameForm}><Icon name="plus" size={15} /> Stock opname baru</button>}
        </div>
      </div>

      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}</div>}

      <div className="inventory-tabs" role="tablist" aria-label="Modul inventory">
        {tabs.filter((tab) => !tab.ownerOnly || isOwner).map((tab) => (
          <button key={tab.id} type="button" role="tab" aria-selected={activeTab === tab.id} className={activeTab === tab.id ? 'selected' : ''} onClick={() => setActiveTab(tab.id)}>
            <Icon name={tab.icon} size={15} /> {tab.label}
          </button>
        ))}
      </div>

      {activeTab === 'stock' && (
        <>
          <div className="mini-stat-grid">
            <div className="mini-stat"><span>Produk aktif</span><strong>{formatNumber(stockCount)}</strong></div>
            <div className="mini-stat"><span>{isOwner ? 'Stok kosong' : 'Stok kosong (halaman)'}</span><strong className="text-danger">{formatNumber(zeroStockCount)}</strong></div>
            <div className="mini-stat"><span>{isOwner ? 'Stok negatif' : 'Stok negatif (halaman)'}</span><strong className={negativeStockCount ? 'text-danger' : ''}>{formatNumber(negativeStockCount)}</strong></div>
          </div>
          <section className="panel module-panel">
            <div className="panel-heading inventory-panel-heading">
              <div><h2>Daftar Persediaan</h2><p>Stok hanya berubah melalui penjualan, penerimaan terkonfirmasi, atau penyesuaian yang disetujui.</p></div>
              <span className="count-pill">{filteredProducts.length} produk ditampilkan</span>
            </div>
            <div className="inventory-toolbar">
              <label className="inventory-filter"><Icon name="filter" size={14}/><select value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)}><option value="all">Semua kategori</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
              <label className="inventory-filter"><Icon name="layers" size={14}/><select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}><option value="all">Semua kondisi</option><option value="zero">Stok kosong</option><option value="available">Tersedia</option></select></label>
            </div>
            <div className="table-scroll"><table className="data-table inventory-table">
              <thead><tr><th>Produk</th><th>SKU</th><th>Kategori</th><th>Stok tersedia</th><th>Satuan</th>{isOwner && <th>Harga beli master</th>}<th>Harga jual</th><th>Status</th>{isOwner && <th>Aksi</th>}</tr></thead>
              <tbody>
                {loadingStock ? <tr><td colSpan={isOwner ? 9 : 7} className="table-message">Memuat persediaan…</td></tr> : filteredProducts.length ? filteredProducts.map((product) => {
                  const stock = Number(product.stock || 0)
                  const recommendation = recommendationByProduct.get(Number(product.id))?.recommendation?.status
                  const label = stock <= 0 ? 'Habis' : recommendation === 'needs_restock' ? 'Perlu restok' : 'Tersedia'
                  const className = stock <= 0 ? 'status-danger' : recommendation === 'needs_restock' ? 'status-warning' : 'status-success'
                  return <tr key={product.id}>
                    <td className="strong-cell">{product.name}</td>
                    <td className="mono-cell">{product.sku_code}</td>
                    <td>{product.category?.name || '—'}</td>
                    <td className="money-cell">{formatNumber(stock)}</td>
                    <td>{product.unit?.short_name || product.unit?.name || '—'}</td>
                    {isOwner && <td>{product.buy_price == null ? '—' : formatCurrency(product.buy_price)}</td>}
                    <td>{formatCurrency(product.sell_price)}</td>
                    <td><span className={`status-pill ${className}`}>{label}</span></td>
                    {isOwner && <td><button type="button" className="small-action" onClick={() => openPriceEditor(product)}>Ubah harga</button></td>}
                  </tr>
                }) : <tr><td colSpan={isOwner ? 9 : 7} className="table-message">Produk tidak ditemukan pada halaman ini.</td></tr>}
              </tbody>
            </table></div>
            <div className="inventory-table-footer">
              <span>Menampilkan {products.length} produk pada halaman ini; total database {productMeta?.total ?? products.length} produk.</span>
              <div className="inventory-pagination">
                <button type="button" className="outline-button button-small" disabled={!productMeta || productMeta.current_page <= 1 || loadingStock} onClick={() => setProductPage((page) => Math.max(1, page - 1))}>Sebelumnya</button>
                <span>Halaman {productMeta?.current_page ?? 1} / {productMeta?.last_page ?? 1}</span>
                <button type="button" className="outline-button button-small" disabled={!productMeta || productMeta.current_page >= productMeta.last_page || loadingStock} onClick={() => setProductPage((page) => page + 1)}>Berikutnya</button>
              </div>
            </div>
          </section>
        </>
      )}

      {activeTab === 'receipts' && (
        <section className="panel module-panel">
          <div className="panel-heading inventory-panel-heading"><div><h2>Penerimaan Barang Supplier</h2><p>Stok bertambah setelah barang diterima dan pembayaran supplier dikonfirmasi.</p></div><span className="count-pill">{receipts.length} dokumen terbaru</span></div>
          <div className="table-scroll"><table className="data-table">
            <thead><tr><th>Nomor penerimaan</th><th>Tanggal diterima</th><th>Supplier</th><th>Item</th><th>Total pembelian</th><th>Status</th><th>Dikonfirmasi oleh</th><th>Aksi</th></tr></thead>
            <tbody>
              {loadingTab ? <tr><td colSpan="8" className="table-message">Memuat penerimaan…</td></tr> : receipts.length ? receipts.map((receipt) => <tr key={receipt.id}>
                <td className="mono-cell">{receipt.receipt_number}</td><td>{formatDate(receipt.received_at)}</td><td className="strong-cell">{receipt.supplier?.name || '—'}</td><td>{receipt.items?.length ?? '—'} item</td><td className="money-cell">{formatCurrency(receipt.total_amount)}</td>
                <td><span className={`status-pill ${statusClass(receipt.status)}`}>{statusText(receipt.status)}</span></td><td>{receipt.confirmer?.name || '—'}</td>
                <td><button type="button" className="small-action" onClick={() => openReceiptDetail(receipt)}>Detail</button></td>
              </tr>) : <tr><td colSpan="8" className="table-message">Belum ada penerimaan barang.</td></tr>}
            </tbody>
          </table></div>
          <div className="table-footnote"><Icon name="info" size={13}/>Draft tidak menambah stok. Penerimaan berstatus dikonfirmasi tercatat sebagai pembelian dibayar dan menambah stok satu kali.</div>
        </section>
      )}

      {activeTab === 'opname' && (
        <section className="panel module-panel">
          <div className="panel-heading inventory-panel-heading"><div><h2>Dokumen Stock Opname</h2><p>Kasir dan Owner dapat mencatat hitung fisik; koreksi stok hanya dapat disetujui Owner.</p></div><span className="count-pill">{opnames.length} dokumen terbaru</span></div>
          <div className="table-scroll"><table className="data-table">
            <thead><tr><th>Nomor opname</th><th>Waktu hitung</th><th>Jumlah item</th><th>Dibuat oleh</th><th>Status</th><th>Disetujui oleh</th><th>Aksi</th></tr></thead>
            <tbody>
              {loadingTab ? <tr><td colSpan="7" className="table-message">Memuat stock opname…</td></tr> : opnames.length ? opnames.map((opname) => <tr key={opname.id}>
                <td className="mono-cell">{opname.opname_number}</td><td>{formatDate(opname.counted_at, true)}</td><td>{opname.items_count ?? '—'} item</td><td>{opname.creator?.name || '—'}</td>
                <td><span className={`status-pill ${statusClass(opname.status)}`}>{statusText(opname.status)}</span></td><td>{opname.approver?.name || '—'}</td>
                <td><button type="button" className="small-action" onClick={() => openOpnameDetail(opname)}>Detail</button></td>
              </tr>) : <tr><td colSpan="7" className="table-message">Belum ada dokumen stock opname.</td></tr>}
            </tbody>
          </table></div>
          <div className="table-footnote"><Icon name="info" size={13}/>Stok tidak berubah saat hasil hitung disimpan. Penyesuaian baru dilakukan setelah Owner menyetujui hasil yang masih valid.</div>
        </section>
      )}

      {activeTab === 'history' && (
        <section className="panel module-panel">
          <div className="panel-heading inventory-panel-heading"><div><h2>Riwayat Pergerakan Stok</h2><p>Jejak perubahan persediaan dari penjualan, penerimaan, dan penyesuaian.</p></div><span className="count-pill">{movements.length} pergerakan terbaru</span></div>
          <div className="table-scroll"><table className="data-table">
            <thead><tr><th>Waktu</th><th>Produk</th><th>Jenis</th><th>Jumlah</th><th>Supplier</th><th>Petugas</th><th>Keterangan</th></tr></thead>
            <tbody>
              {loadingTab ? <tr><td colSpan="7" className="table-message">Memuat riwayat stok…</td></tr> : movements.filter((movement) => !search.trim() || `${movement.product?.name || ''} ${movement.product?.sku_code || ''} ${movement.description || ''} ${movement.user?.name || ''}`.toLowerCase().includes(search.toLowerCase())).length ? movements.filter((movement) => !search.trim() || `${movement.product?.name || ''} ${movement.product?.sku_code || ''} ${movement.description || ''} ${movement.user?.name || ''}`.toLowerCase().includes(search.toLowerCase())).map((movement) => <tr key={movement.id}>
                <td>{formatDate(movement.created_at, true)}</td><td className="strong-cell">{movement.product?.name || 'Produk dihapus'}</td><td><span className={`status-pill ${movement.type === 'in' ? 'status-success' : 'status-danger'}`}>{movement.type === 'in' ? 'Masuk' : 'Keluar'}</span></td><td className="money-cell">{movement.type === 'in' ? '+' : '−'}{formatNumber(movement.quantity)}</td><td>{movement.supplier?.name || '—'}</td><td>{movement.user?.name || '—'}</td><td>{movement.description || '—'}</td>
              </tr>) : <tr><td colSpan="7" className="table-message">Belum ada pergerakan stok yang sesuai.</td></tr>}
            </tbody>
          </table></div>
          <div className="table-footnote"><Icon name="info" size={13}/>Riwayat ditampilkan dari endpoint stock movements. Data terbaru tersedia sesuai batas halaman API.</div>
        </section>
      )}

      {activeTab === 'restock' && isOwner && (
        <>
          <div className="report-note"><Icon name="info" size={16}/><span>Rekomendasi ini hanya alat bantu keputusan Owner. Sistem tidak membuat pesanan supplier, tidak mengubah stok, dan hanya menggunakan transaksi penjualan berstatus paid.</span><button type="button" className="text-button" onClick={() => onNavigate?.('settings')}>Atur parameter di Settings →</button></div>
          <div className="mini-stat-grid">
            <div className="mini-stat"><span>Produk aktif dianalisis</span><strong>{formatNumber(recommendationSummary?.active_product_count ?? 0)}</strong></div>
            <div className="mini-stat"><span>Perlu restok</span><strong className="text-danger">{formatNumber(recommendationSummary?.needs_restock_count ?? 0)}</strong></div>
            <div className="mini-stat"><span>Data belum cukup</span><strong>{formatNumber(recommendationSummary?.insufficient_data_count ?? 0)}</strong></div>
          </div>
          <section className="panel module-panel">
            <div className="panel-heading inventory-panel-heading"><div><h2>Rekomendasi Pengadaan Ulang</h2><p>Dasar perhitungan dan jumlah usulan ditampilkan per produk.</p></div><span className="count-pill">{recommendations.length} produk</span></div>
            <div className="table-scroll"><table className="data-table inventory-restock-table">
              <thead><tr><th>Produk</th><th>Stok saat ini</th><th>Terjual</th><th>Metode</th><th>Reorder point</th><th>Usulan jumlah</th><th>Status / alasan</th></tr></thead>
              <tbody>
                {loadingTab ? <tr><td colSpan="7" className="table-message">Menghitung rekomendasi…</td></tr> : recommendations.length ? recommendations.map((row) => {
                  const rec = row.recommendation || {}
                  const unit = row.unit?.short_name || row.unit?.name || ''
                  return <tr key={row.product.id}>
                    <td><strong className="strong-cell">{row.product.name}</strong><small className="inventory-subtext">{row.product.sku_code}</small></td>
                    <td>{formatNumber(row.current_stock)} {unit}</td>
                    <td>{formatNumber(row.sales_basis?.units_sold || 0)} <small className="inventory-subtext">/{row.sales_basis?.period_days || '—'} hari</small></td>
                    <td>{rec.method === 'sales_velocity' ? 'Kecepatan penjualan' : rec.method === 'manual_minimum_stock' ? 'Minimum manual' : '—'}</td>
                    <td>{rec.reorder_point == null ? '—' : `${formatNumber(rec.reorder_point)} ${unit}`}</td>
                    <td className="money-cell">{rec.suggested_order_quantity == null ? '—' : `${formatNumber(rec.suggested_order_quantity)} ${unit}`}</td>
                    <td><span className={`status-pill ${statusClass(rec.status)}`}>{statusText(rec.status)}</span><small className="inventory-subtext inventory-reason">{rec.reason || '—'}</small></td>
                  </tr>
                }) : <tr><td colSpan="7" className="table-message">Belum ada data rekomendasi.</td></tr>}
              </tbody>
            </table></div>
            <div className="table-footnote"><Icon name="info" size={13}/>Jika parameter belum lengkap atau histori belum memadai, sistem sengaja tidak menampilkan angka usulan yang seolah-olah pasti.</div>
          </section>
        </>
      )}

      {modal === 'price-edit' && isOwner && (
        <Modal title="Ubah Harga Beli Master" subtitle={priceForm.product_name} onClose={() => { if (!saving) setModal('') }}>
          <form className="modal-form" onSubmit={saveMasterBuyPrice}>
            <div className="service-confirm-note"><Icon name="info" size={15}/><span>Harga master menjadi harga acuan saat produk dipilih pada penerimaan berikutnya. Harga aktual pada penerimaan yang sudah tersimpan tidak ikut berubah.</span></div>
            <Field label="Harga beli master saat ini"><input value={formatCurrency(priceForm.current_price)} disabled readOnly/></Field>
            <Field label="Harga beli master baru *"><input type="number" min="0" step="0.01" required value={priceForm.buy_price} onChange={(event) => setPriceForm((current) => ({ ...current, buy_price: event.target.value }))} placeholder="Masukkan harga baru"/></Field>
            <Field label="Alasan perubahan *"><textarea rows="2" maxLength="255" required value={priceForm.reason} onChange={(event) => setPriceForm((current) => ({ ...current, reason: event.target.value }))} placeholder="Contoh: supplier menaikkan harga pada nota terbaru"/></Field>
            <div className="inventory-line-section">
              <div className="inventory-line-heading"><strong>Riwayat perubahan harga</strong><span className="count-pill">{priceHistory.length} catatan terbaru</span></div>
              {priceHistoryLoading ? <div className="table-message">Memuat riwayat harga…</div> : priceHistory.length ? (
                <div className="table-scroll"><table className="data-table"><thead><tr><th>Waktu</th><th>Harga lama</th><th>Harga baru</th><th>Diubah oleh</th><th>Alasan</th></tr></thead><tbody>
                  {priceHistory.map((history) => <tr key={history.id}><td>{formatDate(history.created_at, true)}</td><td>{formatCurrency(history.old_price)}</td><td className="money-cell">{formatCurrency(history.new_price)}</td><td>{history.changer?.name || 'Akun dihapus'}</td><td>{history.reason}</td></tr>)}
                </tbody></table></div>
              ) : <div className="table-message">Belum ada perubahan harga master yang tercatat.</div>}
            </div>
            {formError && <div className="form-error"><Icon name="warning" size={14}/>{formError}</div>}
            <div className="modal-actions"><button type="button" className="outline-button" disabled={saving} onClick={() => setModal('')}>Batal</button><button type="submit" className="primary-button" disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan harga master'}</button></div>
          </form>
        </Modal>
      )}

      {modal === 'receipt-create' && (
        <Modal title="Catat Penerimaan Barang" subtitle="Masukkan jumlah fisik yang benar-benar diterima dan harga pembelian." wide onClose={() => { if (!saving) setModal('') }}>
          <form className="modal-form inventory-modal-form" onSubmit={createReceipt}>
            <div className="inventory-form-grid">
              <Field label="Supplier *"><select required value={receiptForm.supplier_id} onChange={(event) => updateReceiptField('supplier_id', event.target.value)}><option value="">Pilih supplier</option>{suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></Field>
              <Field label="Tanggal diterima *"><input type="date" required value={receiptForm.received_at} onChange={(event) => updateReceiptField('received_at', event.target.value)}/></Field>
              <Field label="Nomor nota supplier"><input maxLength="100" value={receiptForm.supplier_invoice_reference} onChange={(event) => updateReceiptField('supplier_invoice_reference', event.target.value)} placeholder="Opsional"/></Field>
            </div>
            <div className="service-confirm-note"><Icon name="info" size={15}/><span>{suppliers.length ? 'Owner dan Kasir mengisi harga aktual sesuai nota. Harga ini tidak mengubah harga beli master produk.' : (isOwner ? 'Belum ada data supplier. Tambahkan supplier terlebih dahulu.' : 'Belum ada data supplier. Minta Owner menambahkan data supplier terlebih dahulu.')}</span>{isOwner && <button type="button" className="small-action" onClick={openSupplierForm}>Tambah supplier</button>}</div>
            <div className="service-confirm-note"><Icon name="info" size={15}/><span>Harga master otomatis menjadi nilai awal untuk Owner dan Kasir. Sesuaikan dengan nota supplier; nilai akhir hanya tersimpan pada dokumen penerimaan.</span></div>
            <div className="inventory-line-section">
              <div className="inventory-line-heading"><strong>Barang yang diterima</strong><button type="button" className="small-action" onClick={addReceiptItem}><Icon name="plus" size={12}/> Tambah item</button></div>
              {receiptForm.items.map((item, index) => <div className="inventory-line-row" key={index}>
                <Field label="Produk"><select required value={item.product_id} onChange={(event) => updateReceiptItem(index, 'product_id', event.target.value)}><option value="">Pilih produk</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name} · {product.sku_code}</option>)}</select></Field>
                <Field label="Qty diterima"><input type="number" min="1" step="1" required value={item.quantity} onChange={(event) => updateReceiptItem(index, 'quantity', event.target.value)}/></Field>
                <Field label="Harga beli aktual / unit" hint={item.product_id ? `Harga master saat ini: ${formatCurrency(products.find((product) => String(product.id) === String(item.product_id))?.buy_price ?? 0)}. Harga nota disimpan di riwayat penerimaan.` : 'Isi sesuai harga pada nota supplier.'}><input type="number" min="0" step="0.01" required value={item.unit_cost} onChange={(event) => updateReceiptItem(index, 'unit_cost', event.target.value)} placeholder="Rp"/></Field>
                <button type="button" className="icon-button inventory-remove-line" disabled={receiptForm.items.length === 1} onClick={() => removeReceiptItem(index)} aria-label="Hapus item"><Icon name="close" size={15}/></button>
              </div>)}
            </div>
            <Field label="Catatan"><textarea rows="2" maxLength="1000" value={receiptForm.notes} onChange={(event) => updateReceiptField('notes', event.target.value)} placeholder="Catatan kondisi barang atau pembayaran (opsional)"/></Field>
            <div className="inventory-total-row"><span>Perkiraan total pembelian</span><strong>{formatCurrency(receiptTotal)}</strong></div>
            <div className="service-confirm-note"><Icon name="info" size={15}/><span>Draft ini belum menambah stok. Setelah barang benar-benar diterima dan supplier sudah dibayar, lakukan konfirmasi pada detail penerimaan.</span></div>
            {formError && <div className="form-error"><Icon name="warning" size={14}/>{formError}</div>}
            <div className="modal-actions"><button type="button" className="outline-button" disabled={saving} onClick={() => setModal('')}>Batal</button><button type="submit" className="primary-button" disabled={saving || !suppliers.length}>{saving ? 'Menyimpan…' : 'Simpan draft penerimaan'}</button></div>
          </form>
        </Modal>
      )}

      {modal === 'supplier-create' && isOwner && (
        <Modal title="Tambah Supplier" subtitle="Data supplier baru untuk pencatatan penerimaan barang." onClose={() => { if (!saving) setModal('receipt-create') }}>
          <form className="modal-form" onSubmit={createSupplier}>
            <Field label="Nama supplier *"><input required maxLength="255" value={supplierForm.name} onChange={(event) => setSupplierForm((current) => ({ ...current, name: event.target.value }))} placeholder="Nama toko / pemasok"/></Field>
            <Field label="Contact person"><input maxLength="255" value={supplierForm.contact_person} onChange={(event) => setSupplierForm((current) => ({ ...current, contact_person: event.target.value }))} placeholder="Nama kontak"/></Field>
            <Field label="Nomor telepon"><input maxLength="20" value={supplierForm.phone} onChange={(event) => setSupplierForm((current) => ({ ...current, phone: event.target.value }))} placeholder="08xx…"/></Field>
            <Field label="Alamat"><textarea rows="2" value={supplierForm.address} onChange={(event) => setSupplierForm((current) => ({ ...current, address: event.target.value }))} placeholder="Alamat supplier"/></Field>
            {formError && <div className="form-error"><Icon name="warning" size={14}/>{formError}</div>}
            <div className="modal-actions"><button type="button" className="outline-button" disabled={saving} onClick={() => setModal('receipt-create')}>Kembali</button><button type="submit" className="primary-button" disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan supplier'}</button></div>
          </form>
        </Modal>
      )}

      {modal === 'receipt-detail' && selectedReceipt && (
        <Modal title="Detail Penerimaan Barang" subtitle={selectedReceipt.receipt_number} wide onClose={() => { if (!saving) setModal('') }}>
          <div className="inventory-detail-body">
            <div className="inventory-detail-grid">
              <div><span>Supplier</span><strong>{selectedReceipt.supplier?.name || '—'}</strong></div>
              <div><span>Tanggal diterima</span><strong>{formatDate(selectedReceipt.received_at)}</strong></div>
              <div><span>Referensi nota</span><strong>{selectedReceipt.supplier_invoice_reference || '—'}</strong></div>
              <div><span>Status</span><strong><span className={`status-pill ${statusClass(selectedReceipt.status)}`}>{statusText(selectedReceipt.status)}</span></strong></div>
              <div><span>Dibuat oleh</span><strong>{selectedReceipt.creator?.name || '—'}</strong></div>
              <div><span>Dikonfirmasi oleh</span><strong>{selectedReceipt.confirmer?.name || '—'}</strong></div>
            </div>
            <div className="table-scroll"><table className="data-table"><thead><tr><th>Produk</th><th>Qty diterima</th><th>Harga beli</th><th>Subtotal</th></tr></thead><tbody>
              {(selectedReceipt.items || []).map((item) => <tr key={item.id}><td className="strong-cell">{item.product?.name || 'Produk'}</td><td>{formatNumber(item.quantity)}</td><td>{formatCurrency(item.unit_cost)}</td><td className="money-cell">{formatCurrency(item.line_total)}</td></tr>)}
            </tbody></table></div>
            <div className="inventory-total-row"><span>Total pembelian</span><strong>{formatCurrency(selectedReceipt.total_amount)}</strong></div>
            {selectedReceipt.notes && <div className="inventory-detail-note">Catatan: {selectedReceipt.notes}</div>}
            {selectedReceipt.status === 'draft' && <div className="inventory-confirm-box">
              <label className="inventory-acknowledge"><input type="checkbox" checked={receiptAcknowledged} onChange={(event) => setReceiptAcknowledged(event.target.checked)}/><span>Saya memastikan seluruh barang pada dokumen ini benar-benar telah diterima dan pembayaran kepada supplier telah dilakukan.</span></label>
              <p>Konfirmasi akan menambah stok sesuai kuantitas yang diterima dan mencatat pembelian sebagai lunas. Proses tidak dapat dibatalkan.</p>
            </div>}
            {selectedReceipt.status === 'confirmed' && <div className="service-confirm-note"><Icon name="check" size={15}/><span>Dikonfirmasi {formatDate(selectedReceipt.confirmed_at, true)} oleh {selectedReceipt.confirmer?.name || 'petugas'}. Stok sudah ditambahkan satu kali.</span></div>}
            {formError && <div className="form-error"><Icon name="warning" size={14}/>{formError}</div>}
            <div className="modal-actions inventory-detail-actions">
              {selectedReceipt.status === 'draft' && (isOwner || Number(selectedReceipt.created_by) === Number(user?.id)) && <button type="button" className="text-button text-danger" disabled={saving} onClick={deleteDraftReceipt}>Hapus draft</button>}
              <button type="button" className="outline-button" disabled={saving} onClick={() => setModal('')}>Tutup</button>
              {selectedReceipt.status === 'draft' && <button type="button" className="primary-button" disabled={saving || !receiptAcknowledged} onClick={confirmReceipt}>{saving ? 'Memproses…' : 'Konfirmasi & tambah stok'}</button>}
            </div>
          </div>
        </Modal>
      )}

      {modal === 'opname-create' && (
        <Modal title="Catat Stock Opname" subtitle="Masukkan hasil penghitungan fisik. Stok sistem belum berubah sampai disetujui Owner." wide onClose={() => { if (!saving) setModal('') }}>
          <form className="modal-form inventory-modal-form" onSubmit={createOpname}>
            <div className="inventory-form-grid inventory-form-grid-two">
              <Field label="Waktu penghitungan *"><input type="datetime-local" required value={opnameForm.counted_at} onChange={(event) => updateOpnameField('counted_at', event.target.value)}/></Field>
              <Field label="Catatan umum"><input maxLength="1000" value={opnameForm.notes} onChange={(event) => updateOpnameField('notes', event.target.value)} placeholder="Alasan umum / lokasi penghitungan"/></Field>
            </div>
            <div className="inventory-line-section">
              <div className="inventory-line-heading"><strong>Hasil hitung per produk</strong><button type="button" className="small-action" onClick={() => { opnameKeyRef.current = null; setOpnameForm((current) => ({ ...current, items: [...current.items, { product_id: '', physical_stock: '', notes: '' }] })) }}><Icon name="plus" size={12}/> Tambah produk</button></div>
              {opnameForm.items.map((item, index) => {
                const product = products.find((row) => String(row.id) === String(item.product_id))
                const difference = product && item.physical_stock !== '' ? Number(item.physical_stock) - Number(product.stock) : null
                return <div className="inventory-opname-row" key={index}>
                  <Field label="Produk"><select value={item.product_id} onChange={(event) => updateOpnameItem(index, 'product_id', event.target.value)}><option value="">Pilih produk</option>{products.map((row) => <option key={row.id} value={row.id}>{row.name} · stok sistem {formatNumber(row.stock)}</option>)}</select></Field>
                  <div className="inventory-opname-count"><Field label="Stok fisik"><input type="number" min="0" step="1" value={item.physical_stock} onChange={(event) => updateOpnameItem(index, 'physical_stock', event.target.value)} placeholder="Jumlah hasil hitung"/></Field>{product && <small>Stok sistem: {formatNumber(product.stock)} {product.unit?.short_name || product.unit?.name || ''}<br/>Selisih: <strong className={difference === null || difference === 0 ? '' : difference > 0 ? 'text-success' : 'text-danger'}>{difference === null ? '—' : `${difference > 0 ? '+' : ''}${formatNumber(difference)}`}</strong></small>}</div>
                  <Field label="Alasan selisih (opsional)"><input maxLength="255" value={item.notes} onChange={(event) => updateOpnameItem(index, 'notes', event.target.value)} placeholder="Wajib jika ada selisih tanpa catatan umum"/></Field>
                  <button type="button" className="icon-button inventory-remove-line" disabled={opnameForm.items.length === 1} onClick={() => { opnameKeyRef.current = null; setOpnameForm((current) => ({ ...current, items: current.items.filter((_, itemIndex) => itemIndex !== index) })) }} aria-label="Hapus produk"><Icon name="close" size={15}/></button>
                </div>
              })}
            </div>
            <div className="service-confirm-note"><Icon name="info" size={15}/><span>Kasir boleh mencatat hasil hitung, tetapi tidak dapat menyetujui penyesuaian. Jika stok berubah setelah penghitungan, backend akan menolak persetujuan agar koreksi tidak memakai data kedaluwarsa.</span></div>
            {formError && <div className="form-error"><Icon name="warning" size={14}/>{formError}</div>}
            <div className="modal-actions"><button type="button" className="outline-button" disabled={saving} onClick={() => setModal('')}>Batal</button><button type="submit" className="primary-button" disabled={saving}>{saving ? 'Menyimpan…' : 'Simpan hasil hitung'}</button></div>
          </form>
        </Modal>
      )}

      {modal === 'opname-detail' && selectedOpname && (
        <Modal title="Detail Stock Opname" subtitle={selectedOpname.opname_number} wide onClose={() => { if (!saving) setModal('') }}>
          <div className="inventory-detail-body">
            <div className="inventory-detail-grid">
              <div><span>Waktu penghitungan</span><strong>{formatDate(selectedOpname.counted_at, true)}</strong></div>
              <div><span>Status</span><strong><span className={`status-pill ${statusClass(selectedOpname.status)}`}>{statusText(selectedOpname.status)}</span></strong></div>
              <div><span>Dibuat oleh</span><strong>{selectedOpname.creator?.name || '—'}</strong></div>
              <div><span>Disetujui oleh</span><strong>{selectedOpname.approver?.name || '—'}</strong></div>
            </div>
            <div className="table-scroll"><table className="data-table"><thead><tr><th>Produk</th><th>Stok sistem</th><th>Stok fisik</th><th>Selisih</th><th>Catatan</th></tr></thead><tbody>
              {(selectedOpname.items || []).map((item) => <tr key={item.id}><td className="strong-cell">{item.product?.name || 'Produk'}</td><td>{formatNumber(item.system_stock)}</td><td>{formatNumber(item.physical_stock)}</td><td className={item.difference > 0 ? 'text-success' : item.difference < 0 ? 'text-danger' : ''}>{item.difference > 0 ? '+' : ''}{formatNumber(item.difference)}</td><td>{item.notes || selectedOpname.notes || '—'}</td></tr>)}
            </tbody></table></div>
            {selectedOpname.notes && <div className="inventory-detail-note">Catatan umum: {selectedOpname.notes}</div>}
            {selectedOpname.status === 'rejected' && <div className="form-error">Alasan penolakan: {selectedOpname.rejection_reason || '—'}</div>}
            {selectedOpname.status === 'pending_approval' && isOwner && <div className="inventory-confirm-box">
              <p>Periksa kembali selisih fisik. Persetujuan akan menyamakan stok sistem dengan jumlah fisik dan mencatat stock movement untuk setiap selisih.</p>
              <Field label="Alasan penolakan (isi hanya jika akan menolak)"><textarea rows="2" maxLength="1000" value={rejectionReason} onChange={(event) => setRejectionReason(event.target.value)} placeholder="Jelaskan alasan penolakan…"/></Field>
            </div>}
            {formError && <div className="form-error"><Icon name="warning" size={14}/>{formError}</div>}
            <div className="modal-actions inventory-detail-actions">
              <button type="button" className="outline-button" disabled={saving} onClick={() => setModal('')}>Tutup</button>
              {selectedOpname.status === 'pending_approval' && isOwner && <>
                <button type="button" className="outline-button inventory-reject-button" disabled={saving} onClick={rejectOpname}>{saving ? 'Memproses…' : 'Tolak'}</button>
                <button type="button" className="primary-button" disabled={saving} onClick={approveOpname}>{saving ? 'Memproses…' : 'Setujui penyesuaian'}</button>
              </>}
            </div>
          </div>
        </Modal>
      )}
    </div>
  )
}
