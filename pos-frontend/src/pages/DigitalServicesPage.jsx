import { useCallback, useEffect, useState } from 'react'
import { api, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import Modal from '../components/Modal.jsx'
import { formatCurrency, formatDate } from '../utils/format.js'

function VerifyMarginModal({ transaction, onClose, onSaved }) {
  const [margin, setMargin] = useState(transaction.margin_amount ?? '')
  const [note, setNote] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')
    try {
      await api.post(`/service-transactions/${transaction.id}/verify-margin`, {
        margin_amount: Number(margin),
        note: note.trim() || null,
      })
      onSaved()
    } catch (err) {
      setError(err.message || 'Verifikasi margin gagal.')
    } finally {
      setLoading(false)
    }
  }

  return <Modal title="Verifikasi margin layanan" subtitle={transaction.service_type?.name || 'Transaksi layanan'} onClose={onClose}>
    <form className="modal-form" onSubmit={submit}>
      <label>Margin sebenarnya (Rp)<input type="number" min="0" step="1" required value={margin} onChange={(event) => setMargin(event.target.value)} /></label>
      <label>Catatan perubahan <span className="optional-label">Wajib jika mengubah margin yang sudah diverifikasi</span><textarea rows="2" value={note} onChange={(event) => setNote(event.target.value)} placeholder="Alasan atau sumber verifikasi..." /></label>
      {error && <div className="form-error"><Icon name="warning" size={16} />{error}</div>}
      <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Batal</button><button className="primary-button" disabled={loading}>{loading ? 'Menyimpan…' : 'Verifikasi margin'}</button></div>
    </form>
  </Modal>
}

export default function DigitalServicesPage({ user, search = '', onToast }) {
  const [types, setTypes] = useState([])
  const [transactions, setTransactions] = useState([])
  const [selected, setSelected] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const isOwner = user?.role === 'admin'

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [typeResponse, transactionResponse] = await Promise.all([
        api.get('/service-types'),
        api.get('/service-transactions'),
      ])
      setTypes(unwrapList(typeResponse))
      setTransactions(unwrapList(transactionResponse))
    } catch (err) {
      setError(err.message || 'Data layanan digital tidak dapat dimuat.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void Promise.resolve().then(load) }, [load])

  const filtered = transactions.filter((item) => !search.trim() || `${item.service_type?.name || ''} ${item.reference_number || ''} ${item.user?.name || ''}`.toLowerCase().includes(search.toLowerCase()))

  return (
    <div className="module-page">
      <div className="page-heading-row"><div><h1>Digital Services</h1><p>Kelola jenis layanan dan pencatatan transaksi PPOB / BRILink.</p></div><button type="button" className="outline-button" onClick={load}><Icon name="refresh" size={15} /> Muat ulang</button></div>
      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}</div>}
      <section className="panel module-panel">
        <div className="panel-heading"><div><h2>Jenis Layanan Aktif</h2><p>Layanan yang tersedia pada katalog kasir.</p></div><span className="count-pill">{types.length} layanan</span></div>
        <div className="service-type-grid">{types.map((type, index) => <div className="service-type-card" key={type.id}><div className={`service-type-icon tile-${['sand','peach','blue','green','lilac'][index % 5]}`}>{type.name.charAt(0).toUpperCase()}</div><div><strong>{type.name}</strong><span>{type.description || 'Layanan digital'}</span></div><span className={`status-pill ${type.is_active ? 'status-success' : 'status-neutral'}`}>{type.is_active ? 'Aktif' : 'Nonaktif'}</span></div>)}</div>
      </section>
      <section className="panel module-panel">
        <div className="panel-heading"><div><h2>Riwayat Transaksi Layanan</h2><p>Margin hanya masuk laporan setelah diverifikasi Owner.</p></div><span className="count-pill">{filtered.length} transaksi</span></div>
        <div className="table-scroll"><table className="data-table">
          <thead><tr><th>Waktu</th><th>Jenis layanan</th><th>Referensi</th><th>Nominal pokok</th><th>Margin</th><th>Petugas</th><th>Status margin</th>{isOwner && <th>Aksi</th>}</tr></thead>
          <tbody>{loading ? <tr><td colSpan={isOwner ? 8 : 7} className="table-message">Memuat transaksi layanan…</td></tr> : filtered.length ? filtered.map((item) => <tr key={item.id}><td>{formatDate(item.service_at, true)}</td><td className="strong-cell">{item.service_type?.name || '—'}</td><td className="mono-cell">{item.reference_number || '—'}</td><td>{item.nominal_amount == null ? '—' : formatCurrency(item.nominal_amount)}</td><td>{item.margin_amount == null ? 'Belum diketahui' : formatCurrency(item.margin_amount)}</td><td>{item.user?.name || '—'}</td><td><span className={`status-pill ${item.margin_verified_by ? 'status-success' : 'status-warning'}`}>{item.margin_verified_by ? 'Terverifikasi' : 'Belum diverifikasi'}</span></td>{isOwner && <td><button type="button" className="small-action" disabled={item.status !== 'completed'} onClick={() => setSelected(item)}>{item.margin_verified_by ? 'Perbarui' : 'Verifikasi'}</button></td>}</tr>) : <tr><td colSpan={isOwner ? 8 : 7} className="table-message">Belum ada transaksi layanan.</td></tr>}</tbody>
        </table></div>
      </section>
      {selected && <VerifyMarginModal transaction={selected} onClose={() => setSelected(null)} onSaved={async () => { setSelected(null); await load(); onToast({ type: 'success', message: 'Margin berhasil diverifikasi.' }) }} />}
    </div>
  )
}
