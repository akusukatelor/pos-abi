import { useCallback, useEffect, useState } from 'react'
import { api, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'

export default function SuppliersPage({ search = '' }) {
  const [suppliers, setSuppliers] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/suppliers')
      setSuppliers(unwrapList(response))
    } catch (err) {
      setError(err.message || 'Data supplier tidak dapat dimuat.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const filtered = suppliers.filter((supplier) => !search.trim() || `${supplier.name} ${supplier.phone || ''} ${supplier.contact_person || ''}`.toLowerCase().includes(search.toLowerCase()))

  return (
    <div className="module-page">
      <div className="page-heading-row"><div><h1>Supplier</h1><p>Daftar pemasok barang untuk operasional toko.</p></div><button type="button" className="outline-button" onClick={load}><Icon name="refresh" size={15} /> Muat ulang</button></div>
      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}</div>}
      <section className="panel module-panel">
        <div className="panel-heading"><div><h2>Data Supplier</h2><p>Informasi pemasok yang tersimpan di sistem.</p></div><span className="count-pill">{filtered.length} supplier</span></div>
        <div className="table-scroll"><table className="data-table">
          <thead><tr><th>Nama supplier</th><th>Contact person</th><th>Telepon</th><th>Alamat</th></tr></thead>
          <tbody>{loading ? <tr><td colSpan="4" className="table-message">Memuat supplier…</td></tr> : filtered.length ? filtered.map((supplier) => <tr key={supplier.id}><td className="strong-cell">{supplier.name}</td><td>{supplier.contact_person || '—'}</td><td>{supplier.phone || '—'}</td><td>{supplier.address || '—'}</td></tr>) : <tr><td colSpan="4" className="table-message">Belum ada data supplier.</td></tr>}</tbody>
        </table></div>
      </section>
    </div>
  )
}
