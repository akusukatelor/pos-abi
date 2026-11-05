import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import { formatCurrency, formatDate, formatNumber } from '../utils/format.js'

const modules = {
  products: {
    label: 'Produk',
    endpoint: '/products?include_inactive=1',
    fields: [
      { key: 'category_id', label: 'Kategori', type: 'category', required: true },
      { key: 'unit_id', label: 'Satuan', type: 'unit' },
      { key: 'sku_code', label: 'Kode SKU', required: true },
      { key: 'name', label: 'Nama produk', required: true },
      { key: 'buy_price', label: 'Harga beli', type: 'number', required: true },
      { key: 'sell_price', label: 'Harga jual', type: 'number', required: true },
      { key: 'stock', label: 'Stok awal', type: 'number', createOnly: true },
      { key: 'minimum_stock', label: 'Minimum stok', type: 'number' },
      { key: 'buy_price_change_reason', label: 'Alasan perubahan harga beli', editOnly: true },
    ],
  },
  categories: {
    label: 'Kategori',
    endpoint: '/categories',
    fields: [{ key: 'name', label: 'Nama kategori', required: true }],
  },
  units: {
    label: 'Satuan',
    endpoint: '/units',
    fields: [
      { key: 'name', label: 'Nama satuan', required: true },
      { key: 'short_name', label: 'Singkatan' },
    ],
  },
  customers: {
    label: 'Pelanggan',
    endpoint: '/customers',
    fields: [
      { key: 'name', label: 'Nama pelanggan', required: true },
      { key: 'phone', label: 'Telepon' },
      { key: 'address', label: 'Alamat', type: 'textarea' },
    ],
  },
  discounts: {
    label: 'Diskon',
    endpoint: '/discounts',
    fields: [
      { key: 'name', label: 'Nama promo', required: true },
      { key: 'type', label: 'Jenis', type: 'discountType', required: true },
      { key: 'value', label: 'Nilai', type: 'number', required: true },
      { key: 'start_date', label: 'Mulai', type: 'date', required: true },
      { key: 'end_date', label: 'Berakhir', type: 'date', required: true },
      { key: 'is_active', label: 'Status aktif', type: 'boolean' },
    ],
  },
  expenses: {
    label: 'Pengeluaran',
    endpoint: '/expenses',
    fields: [
      { key: 'amount', label: 'Jumlah (Rp)', type: 'number', required: true },
      { key: 'category', label: 'Kategori', required: true },
      { key: 'description', label: 'Keterangan', type: 'textarea' },
      { key: 'expense_date', label: 'Tanggal', type: 'date', required: true },
    ],
  },
}

const tabs = [
  ...Object.entries(modules).map(([id, module]) => ({ id, label: module.label })),
  { id: 'shifts', label: 'Shift' },
]

function emptyForm(moduleId) {
  const form = Object.fromEntries((modules[moduleId]?.fields || []).map(({ key }) => [key, '']))

  if (moduleId === 'products') {
    form.stock = '0'
    form.minimum_stock = '5'
  }
  if (moduleId === 'discounts') form.is_active = true
  if (moduleId === 'expenses') form.expense_date = new Date().toISOString().slice(0, 10)

  return form
}

function valueForEdit(field, record) {
  if (field.key === 'category_id' || field.key === 'unit_id') return record[field.key] ?? ''
  if (field.key === 'buy_price_change_reason') return ''
  const value = record[field.key]
  if (field.type === 'date' && value) return String(value).slice(0, 10)
  return value ?? ''
}

function displayValue(moduleId, record) {
  switch (moduleId) {
    case 'products':
      return [
        record.sku_code,
        record.name,
        record.category?.name || '—',
        formatNumber(record.stock),
        formatCurrency(record.sell_price),
        record.is_active ? 'Aktif' : 'Nonaktif',
      ]
    case 'categories':
      return [record.name]
    case 'units':
      return [record.name, record.short_name || '—']
    case 'customers':
      return [record.name, record.phone || '—', record.address || '—', formatCurrency(record.total_debt)]
    case 'discounts':
      return [
        record.name,
        record.type === 'percentage' ? 'Persentase' : 'Nominal tetap',
        record.type === 'percentage' ? `${formatNumber(record.value)}%` : formatCurrency(record.value),
        `${formatDate(record.start_date)} – ${formatDate(record.end_date)}`,
        record.is_active ? 'Aktif' : 'Nonaktif',
      ]
    case 'expenses':
      return [
        formatDate(record.expense_date),
        record.category,
        record.description || '—',
        formatCurrency(record.amount),
        record.user?.name || '—',
      ]
    default:
      return []
  }
}

export default function StoreManagementPage({ user, search = '', onToast }) {
  const [activeTab, setActiveTab] = useState('products')
  const [rows, setRows] = useState([])
  const [categories, setCategories] = useState([])
  const [units, setUnits] = useState([])
  const [form, setForm] = useState(() => emptyForm('products'))
  const [editing, setEditing] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      if (activeTab === 'shifts') {
        setRows(unwrapList(await api.get('/shifts')))
        return
      }

      const response = await api.get(modules[activeTab].endpoint)
      setRows(unwrapList(response))

      if (activeTab === 'products') {
        const [categoryResponse, unitResponse] = await Promise.all([
          api.get('/categories'),
          api.get('/units'),
        ])
        setCategories(unwrapList(categoryResponse))
        setUnits(unwrapList(unitResponse))
      }
    } catch (err) {
      setError(err.message || 'Data administrasi tidak dapat dimuat.')
    } finally {
      setLoading(false)
    }
  }, [activeTab])

  useEffect(() => { void Promise.resolve().then(load) }, [load])

  const filteredRows = useMemo(() => rows.filter((row) => {
    const text = JSON.stringify(row).toLowerCase()
    return !search.trim() || text.includes(search.trim().toLowerCase())
  }), [rows, search])

  const resetForm = () => {
    setForm(emptyForm(activeTab))
    setEditing(null)
  }

  const beginEdit = (record) => {
    const nextForm = Object.fromEntries(
      modules[activeTab].fields
        .filter((field) => !field.createOnly)
        .map((field) => [field.key, valueForEdit(field, record)]),
    )
    setForm({ ...emptyForm(activeTab), ...nextForm })
    setEditing(record)
  }

  const updateForm = (key, value) => {
    setForm((current) => ({ ...current, [key]: value }))
  }

  const save = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      const fields = modules[activeTab].fields.filter((field) => (
        !field.editOnly || editing
      )).filter((field) => !field.createOnly || !editing)
      const payload = Object.fromEntries(fields.map(({ key, type }) => {
        let value = form[key]
        if (type === 'number') value = value === '' ? null : Number(value)
        if (type === 'boolean') value = Boolean(value)
        if ((type === 'category' || type === 'unit') && value === '') value = null
        return [key, value]
      }))
      if (activeTab === 'expenses' && !editing) payload.user_id = user.id

      if (editing) {
        await api.patch(`/${activeTab}/${editing.id}`, payload)
        onToast({ type: 'success', message: `${modules[activeTab].label} berhasil diperbarui.` })
      } else {
        await api.post(`/${activeTab}`, payload)
        onToast({ type: 'success', message: `${modules[activeTab].label} berhasil ditambahkan.` })
      }

      resetForm()
      await load()
    } catch (err) {
      setError(err.message || 'Data gagal disimpan.')
    } finally {
      setSaving(false)
    }
  }

  const remove = async (record) => {
    if (!window.confirm(`Hapus ${modules[activeTab].label.toLowerCase()} "${record.name || record.sku_code}"?`)) return
    setError('')
    try {
      await api.delete(`/${activeTab}/${record.id}`)
      onToast({ type: 'success', message: `${modules[activeTab].label} berhasil dihapus.` })
      await load()
    } catch (err) {
      setError(err.message || 'Data tidak dapat dihapus.')
    }
  }

  const createShift = async (event) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.post('/shifts', {
        user_id: user.id,
        opening_balance: Number(form.opening_balance),
      })
      setForm({ opening_balance: '' })
      onToast({ type: 'success', message: 'Shift berhasil dibuka.' })
      await load()
    } catch (err) {
      setError(err.message || 'Shift tidak dapat dibuka.')
    } finally {
      setSaving(false)
    }
  }

  const closeShift = async (shift) => {
    const balance = window.prompt('Jumlah uang fisik saat tutup shift:', '0')
    if (balance === null) return
    if (!Number.isFinite(Number(balance)) || Number(balance) < 0) {
      setError('Saldo penutupan harus berupa angka nol atau lebih.')
      return
    }
    setError('')
    try {
      await api.patch(`/shifts/${shift.id}`, { closing_balance_actual: Number(balance) })
      onToast({ type: 'success', message: 'Shift berhasil ditutup.' })
      await load()
    } catch (err) {
      setError(err.message || 'Shift tidak dapat ditutup.')
    }
  }

  const module = modules[activeTab]
  const fields = module?.fields.filter((field) => (
    (!field.editOnly || editing) && (!field.createOnly || !editing)
  ))
  const columns = {
    products: ['SKU', 'Produk', 'Kategori', 'Stok', 'Harga jual', 'Status'],
    categories: ['Kategori'],
    units: ['Nama satuan', 'Singkatan'],
    customers: ['Nama', 'Telepon', 'Alamat', 'Piutang'],
    discounts: ['Promo', 'Jenis', 'Nilai', 'Periode', 'Status'],
    expenses: ['Tanggal', 'Kategori', 'Keterangan', 'Jumlah', 'Dicatat oleh'],
  }

  return (
    <div className="module-page">
      <div className="page-heading-row">
        <div><h1>Data Toko</h1><p>Kelola data master dan operasional yang digunakan oleh POS.</p></div>
        <button type="button" className="outline-button" onClick={load}><Icon name="refresh" size={15} /> Muat ulang</button>
      </div>

      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}</div>}

      <div className="management-tabs" role="tablist" aria-label="Modul data toko">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={activeTab === tab.id}
            className={activeTab === tab.id ? 'management-tab active' : 'management-tab'}
            onClick={() => {
              setActiveTab(tab.id)
              setForm(emptyForm(tab.id))
              setEditing(null)
              setError('')
            }}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {activeTab === 'shifts' ? (
        <>
          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>Buka shift</h2><p>Shift akan dicatat atas nama akun yang sedang masuk.</p></div></div>
            <form className="management-form" onSubmit={createShift}>
              <label>Saldo awal (Rp)<input type="number" min="0" step="1" value={form.opening_balance || ''} onChange={(event) => setForm({ opening_balance: event.target.value })} required /></label>
              <button type="submit" className="primary-button" disabled={saving}>{saving ? 'Menyimpan…' : 'Buka shift'}</button>
            </form>
          </section>
          <section className="panel module-panel">
            <div className="panel-heading"><div><h2>Riwayat shift</h2><p>Saldo sistem dihitung dari saldo awal dan transaksi kasir.</p></div><span className="count-pill">{filteredRows.length} shift</span></div>
            <div className="table-scroll"><table className="data-table">
              <thead><tr><th>Kasir</th><th>Dibuka</th><th>Status</th><th>Saldo awal</th><th>Saldo fisik akhir</th><th>Aksi</th></tr></thead>
              <tbody>
                {loading ? <tr><td colSpan="6" className="table-message">Memuat shift…</td></tr> : filteredRows.length ? filteredRows.map((shift) => (
                  <tr key={shift.id}>
                    <td>{shift.user?.name || '—'}</td><td>{formatDate(shift.opened_at, true)}</td><td>{shift.status}</td>
                    <td>{formatCurrency(shift.opening_balance)}</td><td>{shift.closing_balance_actual == null ? '—' : formatCurrency(shift.closing_balance_actual)}</td>
                    <td>{shift.status === 'open' && <button type="button" className="small-action" onClick={() => closeShift(shift)}>Tutup shift</button>}</td>
                  </tr>
                )) : <tr><td colSpan="6" className="table-message">Belum ada data shift.</td></tr>}
              </tbody>
            </table></div>
          </section>
        </>
      ) : (
        <>
          <section className="panel management-panel">
            <div className="panel-heading"><div><h2>{editing ? `Ubah ${module.label.toLowerCase()}` : `Tambah ${module.label.toLowerCase()}`}</h2><p>{activeTab === 'products' ? 'Penyesuaian stok selanjutnya dicatat melalui penerimaan atau stock opname.' : 'Perubahan disimpan ke data toko.'}</p></div></div>
            <form className="management-form-grid" onSubmit={save}>
              {fields.map((field) => (
                <label key={field.key}>
                  {field.label}
                  {field.type === 'textarea' ? (
                    <textarea value={form[field.key] ?? ''} onChange={(event) => updateForm(field.key, event.target.value)} required={field.required} />
                  ) : field.type === 'category' || field.type === 'unit' || field.type === 'discountType' || field.type === 'boolean' ? (
                    <select value={form[field.key] ?? ''} onChange={(event) => updateForm(field.key, field.type === 'boolean' ? event.target.value === 'true' : event.target.value)} required={field.required}>
                      <option value="">{field.required ? 'Pilih…' : '—'}</option>
                      {field.type === 'category' && categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                      {field.type === 'unit' && units.map((item) => <option key={item.id} value={item.id}>{item.name} {item.short_name ? `(${item.short_name})` : ''}</option>)}
                      {field.type === 'discountType' && <><option value="percentage">Persentase</option><option value="fixed_amount">Nominal tetap</option></>}
                      {field.type === 'boolean' && <><option value="true">Aktif</option><option value="false">Nonaktif</option></>}
                    </select>
                  ) : (
                    <input
                      type={field.type || 'text'}
                      value={form[field.key] ?? ''}
                      onChange={(event) => updateForm(field.key, event.target.value)}
                      required={field.required}
                      min={field.type === 'number' ? '0' : undefined}
                      step={field.type === 'number' ? (field.key === 'stock' || field.key === 'minimum_stock' ? '1' : '0.01') : undefined}
                    />
                  )}
                </label>
              ))}
              <div className="management-form-actions">
                {editing && <button type="button" className="secondary-button" onClick={resetForm}>Batal</button>}
                <button type="submit" className="primary-button" disabled={saving}>{saving ? 'Menyimpan…' : editing ? 'Simpan perubahan' : `Tambah ${module.label.toLowerCase()}`}</button>
              </div>
            </form>
          </section>

          <section className="panel module-panel">
            <div className="panel-heading"><div><h2>Daftar {module.label}</h2><p>Data ini tersedia untuk proses operasional yang sesuai.</p></div><span className="count-pill">{filteredRows.length} data</span></div>
            <div className="table-scroll"><table className="data-table">
              <thead><tr>{columns[activeTab].map((column) => <th key={column}>{column}</th>)}<th>Aksi</th></tr></thead>
              <tbody>
                {loading ? <tr><td colSpan={columns[activeTab].length + 1} className="table-message">Memuat data…</td></tr> : filteredRows.length ? filteredRows.map((record) => (
                  <tr key={record.id}>
                    {displayValue(activeTab, record).map((value, index) => <td key={index}>{value}</td>)}
                    <td className="management-actions">
                      <button type="button" className="small-action" onClick={() => beginEdit(record)}>Ubah</button>
                      <button type="button" className="small-action text-danger" onClick={() => remove(record)}>Hapus</button>
                    </td>
                  </tr>
                )) : <tr><td colSpan={columns[activeTab].length + 1} className="table-message">Belum ada data.</td></tr>}
              </tbody>
            </table></div>
          </section>
        </>
      )}
    </div>
  )
}
