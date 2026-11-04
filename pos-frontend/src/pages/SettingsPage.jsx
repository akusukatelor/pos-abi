import { useCallback, useEffect, useState } from 'react'
import { api, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import { formatNumber } from '../utils/format.js'

function settingForm(product) {
  const setting = product.restock_setting || {}
  return {
    sales_period_days: setting.sales_period_days ?? 30,
    lead_time_days: setting.lead_time_days ?? '',
    safety_stock: setting.safety_stock ?? '',
    review_interval_days: setting.review_interval_days ?? '',
    manual_minimum_stock: setting.manual_minimum_stock ?? '',
    notes: setting.notes ?? '',
  }
}

export default function SettingsPage({ search = '', onToast }) {
  const [products, setProducts] = useState([])
  const [forms, setForms] = useState({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(null)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/restock-settings')
      const rows = unwrapList(response)
      setProducts(rows)
      setForms(Object.fromEntries(rows.map((product) => [product.id, settingForm(product)])))
    } catch (err) {
      setError(err.message || 'Pengaturan rekomendasi restok tidak dapat dimuat.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const updateField = (productId, field, value) => {
    setForms((current) => ({ ...current, [productId]: { ...current[productId], [field]: value } }))
  }

  const save = async (product) => {
    const form = forms[product.id]
    setSaving(product.id)
    setError('')
    try {
      const toNullableNumber = (value) => value === '' || value === null ? null : Number(value)
      await api.put(`/restock-settings/${product.id}`, {
        sales_period_days: Number(form.sales_period_days),
        lead_time_days: toNullableNumber(form.lead_time_days),
        safety_stock: toNullableNumber(form.safety_stock),
        review_interval_days: toNullableNumber(form.review_interval_days),
        manual_minimum_stock: toNullableNumber(form.manual_minimum_stock),
        notes: form.notes || null,
      })
      onToast({ type: 'success', message: `Parameter restok ${product.name} berhasil disimpan.` })
      await load()
    } catch (err) {
      setError(err.message || 'Pengaturan gagal disimpan.')
    } finally {
      setSaving(null)
    }
  }

  const filtered = products.filter((product) => !search.trim() || `${product.name} ${product.sku_code}`.toLowerCase().includes(search.toLowerCase()))

  return (
    <div className="module-page">
      <div className="page-heading-row"><div><h1>Settings</h1><p>Atur parameter rekomendasi pengadaan ulang stok untuk Owner.</p></div><button type="button" className="outline-button" onClick={load}><Icon name="refresh" size={15} /> Muat ulang</button></div>
      <div className="report-note"><Icon name="info" size={16} /><span>Isi lead time, safety stock, dan interval peninjauan secara lengkap untuk metode berbasis penjualan. Jika histori belum tersedia, Owner dapat memakai ambang minimum stok manual. Rekomendasi hanya berupa saran, bukan pembelian otomatis.</span></div>
      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}</div>}
      <div className="restock-settings-list">
        {loading ? <div className="panel table-message">Memuat pengaturan produk…</div> : filtered.map((product) => {
          const form = forms[product.id] || settingForm(product)
          return <section className="panel restock-setting-card" key={product.id}>
            <div className="restock-product-head"><div className="restock-product-icon">{product.name.charAt(0).toUpperCase()}</div><div><strong>{product.name}</strong><span>{product.sku_code} · Stok saat ini {formatNumber(product.stock)} {product.unit?.short_name || product.unit?.name || ''}</span></div><span className={`status-pill ${product.restock_setting ? 'status-success' : 'status-warning'}`}>{product.restock_setting ? 'Sudah diatur' : 'Belum diatur'}</span></div>
            <div className="settings-form-grid">
              <label>Periode analisis (hari)<input type="number" min="1" max="365" value={form.sales_period_days} onChange={(event) => updateField(product.id, 'sales_period_days', event.target.value)} /></label>
              <label>Lead time (hari)<input type="number" min="0" max="365" value={form.lead_time_days} onChange={(event) => updateField(product.id, 'lead_time_days', event.target.value)} placeholder="Mis. 3" /></label>
              <label>Safety stock (unit)<input type="number" min="0" value={form.safety_stock} onChange={(event) => updateField(product.id, 'safety_stock', event.target.value)} placeholder="Mis. 4" /></label>
              <label>Interval peninjauan (hari)<input type="number" min="1" max="365" value={form.review_interval_days} onChange={(event) => updateField(product.id, 'review_interval_days', event.target.value)} placeholder="Mis. 7" /></label>
              <label>Minimum stok manual (opsional)<input type="number" min="0" value={form.manual_minimum_stock} onChange={(event) => updateField(product.id, 'manual_minimum_stock', event.target.value)} placeholder="Alternatif tanpa histori" /></label>
              <label>Catatan<input value={form.notes} onChange={(event) => updateField(product.id, 'notes', event.target.value)} placeholder="Catatan lead time / supplier" /></label>
            </div>
            <div className="setting-card-footer"><span>Parameter ini hanya digunakan untuk perhitungan rekomendasi.</span><button type="button" className="primary-button button-small" disabled={saving === product.id} onClick={() => save(product)}>{saving === product.id ? 'Menyimpan…' : 'Simpan parameter'}</button></div>
          </section>
        })}
        {!loading && filtered.length === 0 && <div className="panel table-message">Produk tidak ditemukan.</div>}
      </div>
    </div>
  )
}
