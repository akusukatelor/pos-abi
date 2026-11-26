import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, makeIdempotencyKey, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import Modal from '../components/Modal.jsx'
import { formatCurrency, formatDate } from '../utils/format.js'

function getErrorMessage(error, fallback) {
  const validationErrors = error?.response?.data?.errors
  if (validationErrors) {
    const firstError = Object.values(validationErrors).flat()[0]
    if (firstError) return firstError
  }
  return error?.response?.data?.message || error?.message || fallback
}

const localDateTimeInput = () => {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

function ServiceTypeModal({ serviceType, onClose, onSaved }) {
  const isEdit = Boolean(serviceType)
  const [name, setName] = useState(serviceType?.name || '')
  const [description, setDescription] = useState(serviceType?.description || '')
  const [isActive, setIsActive] = useState(serviceType ? Boolean(serviceType.is_active) : true)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    setError('')

    if (!name.trim()) {
      setError('Nama jenis layanan wajib diisi.')
      return
    }

    setLoading(true)
    try {
      if (isEdit) {
        await api.put(`/service-types/${serviceType.id}`, {
          name: name.trim(),
          description: description.trim() || null,
          is_active: isActive,
        })
        onSaved('Jenis layanan berhasil diperbarui.')
      } else {
        await api.post('/service-types', {
          name: name.trim(),
          description: description.trim() || null,
        })
        onSaved('Jenis layanan baru berhasil ditambahkan.')
      }
    } catch (err) {
      setError(getErrorMessage(err, 'Gagal menyimpan jenis layanan.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal
      title={isEdit ? 'Edit Jenis Layanan' : 'Tambah Jenis Layanan'}
      subtitle="Atur layanan PPOB, transfer, atau jasa digital yang tersedia di kasir."
      onClose={() => { if (!loading) onClose() }}
    >
      <form className="modal-form" onSubmit={submit}>
        <label>
          Nama Layanan *
          <input
            type="text"
            maxLength={100}
            required
            autoFocus
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Contoh: Transfer Bank BRILink / Top Up E-Wallet"
          />
        </label>

        <label>
          Deskripsi <span className="optional-label">Opsional</span>
          <textarea
            rows="3"
            maxLength={2000}
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            placeholder="Keterangan singkat tarif admin atau catatan layanan..."
          />
        </label>

        {isEdit && (
          <label style={{ display: 'flex', flexDirection: 'row', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
            <input
              type="checkbox"
              checked={isActive}
              onChange={(event) => setIsActive(event.target.checked)}
              style={{ width: 'auto', margin: 0 }}
            />
            <span>Layanan aktif dan tampil pada pilihan kasir</span>
          </label>
        )}

        {error && (
          <div className="form-error">
            <Icon name="warning" size={16} />
            {error}
          </div>
        )}

        <div className="modal-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={loading}>
            Batal
          </button>
          <button type="submit" className="primary-button" disabled={loading}>
            {loading ? 'Menyimpan…' : isEdit ? 'Simpan Perubahan' : 'Tambah Layanan'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function CreateServiceTransactionModal({ activeTypes, onClose, onSaved }) {
  const [form, setForm] = useState({
    service_type_id: activeTypes[0]?.id ? String(activeTypes[0].id) : '',
    reference_number: '',
    service_at: localDateTimeInput(),
    nominal_amount: '',
    margin_amount: '',
    notes: '',
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const idempotencyKeyRef = useRef(null)

  const updateField = (field, value) => {
    idempotencyKeyRef.current = null
    setForm((current) => ({ ...current, [field]: value }))
  }

  const submit = async (event) => {
    event.preventDefault()
    setError('')

    if (!form.service_type_id) {
      setError('Pilih jenis layanan terlebih dahulu.')
      return
    }

    setLoading(true)
    try {
      if (!idempotencyKeyRef.current) {
        idempotencyKeyRef.current = makeIdempotencyKey()
      }

      const payload = {
        idempotency_key: idempotencyKeyRef.current,
        service_type_id: Number(form.service_type_id),
        reference_number: form.reference_number.trim() || null,
        service_at: form.service_at ? `${form.service_at.replace('T', ' ')}:00` : null,
        nominal_amount: form.nominal_amount !== '' ? Number(form.nominal_amount) : null,
        margin_amount: form.margin_amount !== '' ? Number(form.margin_amount) : null,
        notes: form.notes.trim() || null,
      }

      const response = await api.post('/service-transactions', payload)
      idempotencyKeyRef.current = null
      onSaved(response.message || 'Transaksi layanan berhasil dicatat.')
    } catch (err) {
      setError(getErrorMessage(err, 'Transaksi layanan gagal dicatat.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal
      title="Catat Transaksi Layanan Digital"
      subtitle="Simpan transaksi PPOB, transfer, atau top up yang telah selesai diproses."
      onClose={() => { if (!loading) onClose() }}
    >
      <form className="modal-form" onSubmit={submit}>
        <label>
          Jenis Layanan *
          <select
            required
            value={form.service_type_id}
            onChange={(event) => updateField('service_type_id', event.target.value)}
          >
            <option value="">Pilih jenis layanan</option>
            {activeTypes.map((type) => (
              <option key={type.id} value={type.id}>
                {type.name}
              </option>
            ))}
          </select>
        </label>

        <label>
          Waktu Transaksi
          <input
            type="datetime-local"
            value={form.service_at}
            onChange={(event) => updateField('service_at', event.target.value)}
          />
        </label>

        <label>
          Nomor Referensi / Tujuan <span className="optional-label">Opsional</span>
          <input
            type="text"
            maxLength={100}
            value={form.reference_number}
            onChange={(event) => updateField('reference_number', event.target.value)}
            placeholder="Contoh: No. HP, ID Pelanggan PLN, atau Ref BRILink"
          />
        </label>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <label>
            Nominal Pokok (Rp) <span className="optional-label">Opsional</span>
            <input
              type="number"
              min="0"
              step="1"
              value={form.nominal_amount}
              onChange={(event) => updateField('nominal_amount', event.target.value)}
              placeholder="Contoh: 100000"
            />
          </label>

          <label>
            Perkiraan Margin / Admin (Rp) <span className="optional-label">Opsional</span>
            <input
              type="number"
              min="0"
              step="1"
              value={form.margin_amount}
              onChange={(event) => updateField('margin_amount', event.target.value)}
              placeholder="Contoh: 3000"
            />
          </label>
        </div>

        <label>
          Catatan <span className="optional-label">Opsional</span>
          <textarea
            rows="2"
            maxLength={2000}
            value={form.notes}
            onChange={(event) => updateField('notes', event.target.value)}
            placeholder="Catatan tambahan transaksi..."
          />
        </label>

        <div className="service-confirm-note">
          <Icon name="info" size={15} />
          <span>Margin yang dicatat baru masuk ke laporan pendapatan jasa setelah diverifikasi oleh Owner.</span>
        </div>

        {error && (
          <div className="form-error">
            <Icon name="warning" size={16} />
            {error}
          </div>
        )}

        <div className="modal-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={loading}>
            Batal
          </button>
          <button type="submit" className="primary-button" disabled={loading || !activeTypes.length}>
            {loading ? 'Menyimpan…' : 'Simpan Transaksi'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

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
      setError(getErrorMessage(err, 'Verifikasi margin gagal.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal title="Verifikasi margin layanan" subtitle={transaction.service_type?.name || 'Transaksi layanan'} onClose={onClose}>
      <form className="modal-form" onSubmit={submit}>
        <label>
          Margin sebenarnya (Rp)
          <input
            type="number"
            min="0"
            step="1"
            required
            value={margin}
            onChange={(event) => setMargin(event.target.value)}
          />
        </label>
        <label>
          Catatan perubahan <span className="optional-label">Wajib jika mengubah margin yang sudah diverifikasi</span>
          <textarea
            rows="2"
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="Alasan atau sumber verifikasi..."
          />
        </label>
        {error && (
          <div className="form-error">
            <Icon name="warning" size={16} />
            {error}
          </div>
        )}
        <div className="modal-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={loading}>
            Batal
          </button>
          <button className="primary-button" disabled={loading}>
            {loading ? 'Menyimpan…' : 'Verifikasi margin'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function CancelServiceModal({ transaction, onClose, onSaved }) {
  const [reason, setReason] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    if (reason.trim().length < 3) {
      setError('Alasan pembatalan minimal 3 karakter.')
      return
    }

    setLoading(true)
    try {
      await api.post(`/service-transactions/${transaction.id}/cancel`, {
        cancellation_reason: reason.trim(),
      })
      onSaved()
    } catch (err) {
      setError(getErrorMessage(err, 'Transaksi layanan gagal dibatalkan.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Modal
      title="Batalkan Transaksi Layanan"
      subtitle={`${transaction.service_type?.name || 'Layanan'} · ${transaction.reference_number || `#${transaction.id}`}`}
      onClose={() => { if (!loading) onClose() }}
    >
      <form className="modal-form" onSubmit={submit}>
        <p style={{ fontSize: 13, color: '#475569', margin: 0 }}>
          Riwayat transaksi tetap tersimpan untuk audit, tetapi nilainya akan dikeluarkan dari laporan pendapatan aktif.
        </p>

        <label>
          Alasan Pembatalan *
          <textarea
            rows="3"
            minLength={3}
            maxLength={500}
            required
            autoFocus
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder="Contoh: Salah input nominal / transaksi gagal di mesin EDC"
          />
        </label>

        {error && (
          <div className="form-error">
            <Icon name="warning" size={16} />
            {error}
          </div>
        )}

        <div className="modal-actions">
          <button type="button" className="secondary-button" onClick={onClose} disabled={loading}>
            Tutup
          </button>
          <button type="submit" className="primary-button" disabled={loading}>
            {loading ? 'Membatalkan…' : 'Konfirmasi Batalkan'}
          </button>
        </div>
      </form>
    </Modal>
  )
}

function ServiceDetailModal({ transactionId, initialData, onClose }) {
  const [detail, setDetail] = useState(initialData)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let active = true
    api.get(`/service-transactions/${transactionId}`)
      .then((res) => {
        if (active && res?.data) setDetail(res.data)
      })
      .catch(() => {})
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [transactionId])

  return (
    <Modal
      title="Detail Transaksi Layanan"
      subtitle={detail?.service_type?.name || 'Layanan Digital'}
      wide
      onClose={onClose}
    >
      <div className="inventory-detail-body">
        <div className="inventory-detail-grid">
          <div>
            <span>Waktu Transaksi</span>
            <strong>{detail?.service_at ? formatDate(detail.service_at, true) : '—'}</strong>
          </div>
          <div>
            <span>Status</span>
            <strong>
              <span className={`status-pill ${detail?.status === 'cancelled' ? 'status-danger' : 'status-success'}`}>
                {detail?.status === 'cancelled' ? 'Dibatalkan' : 'Selesai'}
              </span>
            </strong>
          </div>
          <div>
            <span>Referensi</span>
            <strong className="mono-cell">{detail?.reference_number || '—'}</strong>
          </div>
          <div>
            <span>Petugas</span>
            <strong>{detail?.user?.name || '—'}</strong>
          </div>
          <div>
            <span>Nominal Pokok</span>
            <strong>{detail?.nominal_amount == null ? '—' : formatCurrency(detail.nominal_amount)}</strong>
          </div>
          <div>
            <span>Margin</span>
            <strong>{detail?.margin_amount == null ? 'Belum diketahui' : formatCurrency(detail.margin_amount)}</strong>
          </div>
        </div>

        {detail?.notes && (
          <div className="inventory-detail-note">Catatan: {detail.notes}</div>
        )}

        {detail?.status === 'cancelled' && (
          <div className="form-error">
            Dibatalkan oleh {detail.canceller?.name || 'Owner'} ({detail.cancelled_at ? formatDate(detail.cancelled_at, true) : '—'}): {detail.cancellation_reason || '—'}
          </div>
        )}

        <div className="inventory-line-section">
          <div className="inventory-line-heading">
            <strong>Riwayat Audit Margin</strong>
            <span className="count-pill">{detail?.margin_logs?.length || 0} log</span>
          </div>
          {loading ? (
            <div className="table-message">Memuat riwayat audit…</div>
          ) : detail?.margin_logs?.length ? (
            <div className="table-scroll">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Waktu</th>
                    <th>Margin Lama</th>
                    <th>Margin Baru</th>
                    <th>Diverifikasi Oleh</th>
                    <th>Catatan</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.margin_logs.map((log) => (
                    <tr key={log.id}>
                      <td>{formatDate(log.created_at, true)}</td>
                      <td>{log.old_margin_amount == null ? '—' : formatCurrency(log.old_margin_amount)}</td>
                      <td className="money-cell">{formatCurrency(log.new_margin_amount)}</td>
                      <td>{log.changed_by?.name || '—'}</td>
                      <td>{log.note || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="table-message">Belum ada riwayat verifikasi margin.</div>
          )}
        </div>

        <div className="modal-actions">
          <button type="button" className="outline-button" onClick={onClose}>
            Tutup
          </button>
        </div>
      </div>
    </Modal>
  )
}

export default function DigitalServicesPage({ user, search = '', onToast }) {
  const [types, setTypes] = useState([])
  const [transactions, setTransactions] = useState([])
  const [selectedVerify, setSelectedVerify] = useState(null)
  const [selectedCancel, setSelectedCancel] = useState(null)
  const [selectedDetail, setSelectedDetail] = useState(null)
  const [typeModal, setTypeModal] = useState({ open: false, data: null })
  const [createTxOpen, setCreateTxOpen] = useState(false)
  const [togglingTypeId, setTogglingTypeId] = useState(null)

  const [typeFilter, setTypeFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const isOwner = ['admin', 'owner'].includes(user?.role)

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
      setError(getErrorMessage(err, 'Data layanan digital tidak dapat dimuat.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(load)
  }, [load])

  const activeTypes = useMemo(
    () => types.filter((item) => Boolean(item.is_active)),
    [types]
  )

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase()
    return transactions.filter((item) => {
      const matchesSearch = !keyword || `${item.service_type?.name || ''} ${item.reference_number || ''} ${item.user?.name || ''}`.toLowerCase().includes(keyword)
      const matchesType = typeFilter === 'all' || String(item.service_type_id) === String(typeFilter)
      const matchesStatus = statusFilter === 'all' || item.status === statusFilter
      return matchesSearch && matchesType && matchesStatus
    })
  }, [transactions, search, typeFilter, statusFilter])

  const toggleServiceTypeStatus = async (type) => {
    const willActivate = !type.is_active
    const confirmed = window.confirm(
      willActivate
        ? `Aktifkan kembali layanan "${type.name}"?`
        : `Nonaktifkan layanan "${type.name}"? Histori transaksi lama tetap tersimpan.`
    )
    if (!confirmed) return

    setTogglingTypeId(type.id)
    setError('')
    try {
      if (willActivate) {
        await api.patch(`/service-types/${type.id}`, { is_active: true })
        onToast?.({ type: 'success', message: `Layanan "${type.name}" diaktifkan kembali.` })
      } else {
        await api.delete(`/service-types/${type.id}`)
        onToast?.({ type: 'success', message: `Layanan "${type.name}" dinonaktifkan.` })
      }
      await load()
    } catch (err) {
      setError(getErrorMessage(err, 'Gagal mengubah status layanan.'))
    } finally {
      setTogglingTypeId(null)
    }
  }

  return (
    <div className="module-page">
      <div className="page-heading-row">
        <div>
          <h1>Digital Services</h1>
          <p>Kelola jenis layanan dan pencatatan transaksi PPOB / BRILink.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          <button type="button" className="outline-button" onClick={load} disabled={loading}>
            <Icon name="refresh" size={15} /> Muat ulang
          </button>
          {isOwner && (
            <button
              type="button"
              className="outline-button"
              onClick={() => setTypeModal({ open: true, data: null })}
            >
              <Icon name="plus" size={15} /> Tambah Layanan
            </button>
          )}
          <button
            type="button"
            className="primary-button"
            onClick={() => setCreateTxOpen(true)}
            disabled={!activeTypes.length}
          >
            <Icon name="plus" size={15} /> Catat Transaksi
          </button>
        </div>
      </div>

      {error && (
        <div className="inline-alert">
          <Icon name="warning" size={17} />
          {error}
        </div>
      )}

      <section className="panel module-panel">
        <div className="panel-heading">
          <div>
            <h2>Jenis Layanan</h2>
            <p>Katalog layanan digital yang tersedia pada operasional toko.</p>
          </div>
          <span className="count-pill">{types.length} layanan</span>
        </div>

        <div className="service-type-grid">
          {types.length ? (
            types.map((type, index) => (
              <div className="service-type-card" key={type.id} style={{ flexDirection: 'column', alignItems: 'stretch', gap: 10 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <div className={`service-type-icon tile-${['sand', 'peach', 'blue', 'green', 'lilac'][index % 5]}`}>
                    {type.name.charAt(0).toUpperCase()}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <strong>{type.name}</strong>
                    <span style={{ display: 'block' }}>{type.description || 'Layanan digital'}</span>
                  </div>
                  <span className={`status-pill ${type.is_active ? 'status-success' : 'status-neutral'}`}>
                    {type.is_active ? 'Aktif' : 'Nonaktif'}
                  </span>
                </div>

                {isOwner && (
                  <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', borderTop: '1px solid #f1f5f9', paddingTop: 8 }}>
                    <button
                      type="button"
                      className="small-action"
                      onClick={() => setTypeModal({ open: true, data: type })}
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      className="small-action"
                      disabled={togglingTypeId === type.id}
                      onClick={() => void toggleServiceTypeStatus(type)}
                    >
                      {togglingTypeId === type.id
                        ? 'Memproses…'
                        : type.is_active
                        ? 'Nonaktifkan'
                        : 'Aktifkan'}
                    </button>
                  </div>
                )}
              </div>
            ))
          ) : (
            <p style={{ color: '#64748b', fontSize: 14 }}>
              {loading ? 'Memuat jenis layanan…' : 'Belum ada jenis layanan terdaftar.'}
            </p>
          )}
        </div>
      </section>

      <section className="panel module-panel">
        <div className="panel-heading">
          <div>
            <h2>Riwayat Transaksi Layanan</h2>
            <p>Margin hanya masuk laporan pendapatan setelah diverifikasi Owner.</p>
          </div>
          <span className="count-pill">{filtered.length} transaksi</span>
        </div>

        <div className="inventory-toolbar">
          <label className="inventory-filter">
            <Icon name="filter" size={14} />
            <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
              <option value="all">Semua jenis layanan</option>
              {types.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.name}
                </option>
              ))}
            </select>
          </label>
          <label className="inventory-filter">
            <Icon name="layers" size={14} />
            <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
              <option value="all">Semua status</option>
              <option value="completed">Selesai</option>
              <option value="cancelled">Dibatalkan</option>
            </select>
          </label>
        </div>

        <div className="table-scroll">
          <table className="data-table">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Jenis layanan</th>
                <th>Referensi</th>
                <th>Nominal pokok</th>
                <th>Margin</th>
                <th>Petugas</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={8} className="table-message">Memuat transaksi layanan…</td>
                </tr>
              ) : filtered.length ? (
                filtered.map((item) => {
                  const isCancelled = item.status === 'cancelled'
                  return (
                    <tr key={item.id}>
                      <td>{formatDate(item.service_at, true)}</td>
                      <td className="strong-cell">{item.service_type?.name || '—'}</td>
                      <td className="mono-cell">{item.reference_number || '—'}</td>
                      <td>{item.nominal_amount == null ? '—' : formatCurrency(item.nominal_amount)}</td>
                      <td>{item.margin_amount == null ? 'Belum diketahui' : formatCurrency(item.margin_amount)}</td>
                      <td>{item.user?.name || '—'}</td>
                      <td>
                        {isCancelled ? (
                          <span className="status-pill status-danger">Dibatalkan</span>
                        ) : (
                          <span className={`status-pill ${item.margin_verified_by ? 'status-success' : 'status-warning'}`}>
                            {item.margin_verified_by ? 'Terverifikasi' : 'Belum diverifikasi'}
                          </span>
                        )}
                      </td>
                      <td>
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                          <button
                            type="button"
                            className="small-action"
                            onClick={() => setSelectedDetail(item)}
                          >
                            Detail
                          </button>
                          {isOwner && !isCancelled && (
                            <>
                              <button
                                type="button"
                                className="small-action primary-button"
                                onClick={() => setSelectedVerify(item)}
                              >
                                {item.margin_verified_by ? 'Perbarui' : 'Verifikasi'}
                              </button>
                              <button
                                type="button"
                                className="small-action customer-delete-button"
                                onClick={() => setSelectedCancel(item)}
                              >
                                Batalkan
                              </button>
                            </>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })
              ) : (
                <tr>
                  <td colSpan={8} className="table-message">Belum ada transaksi layanan.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {typeModal.open && (
        <ServiceTypeModal
          serviceType={typeModal.data}
          onClose={() => setTypeModal({ open: false, data: null })}
          onSaved={async (message) => {
            setTypeModal({ open: false, data: null })
            await load()
            onToast?.({ type: 'success', message })
          }}
        />
      )}

      {createTxOpen && (
        <CreateServiceTransactionModal
          activeTypes={activeTypes}
          onClose={() => setCreateTxOpen(false)}
          onSaved={async (message) => {
            setCreateTxOpen(false)
            await load()
            onToast?.({ type: 'success', message })
          }}
        />
      )}

      {selectedDetail && (
        <ServiceDetailModal
          transactionId={selectedDetail.id}
          initialData={selectedDetail}
          onClose={() => setSelectedDetail(null)}
        />
      )}

      {selectedVerify && (
        <VerifyMarginModal
          transaction={selectedVerify}
          onClose={() => setSelectedVerify(null)}
          onSaved={async () => {
            setSelectedVerify(null)
            await load()
            onToast?.({ type: 'success', message: 'Margin berhasil diverifikasi.' })
          }}
        />
      )}

      {selectedCancel && (
        <CancelServiceModal
          transaction={selectedCancel}
          onClose={() => setSelectedCancel(null)}
          onSaved={async () => {
            setSelectedCancel(null)
            await load()
            onToast?.({ type: 'success', message: 'Transaksi layanan berhasil dibatalkan.' })
          }}
        />
      )}
    </div>
  )
}