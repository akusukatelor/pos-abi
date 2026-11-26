import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { api, makeIdempotencyKey } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import {
  formatCurrency,
  formatDate,
  paymentLabel
} from '../utils/format.js'

function getPayload(response) {
  return response?.data?.data ?? response?.data ?? response
}

function getErrorMessage(error, fallback) {
  const validationErrors = error?.response?.data?.errors
  if (validationErrors) {
    const firstError = Object.values(validationErrors).flat()[0]
    if (firstError) return firstError
  }
  return error?.response?.data?.message || error?.message || fallback
}

const emptyForm = {
  name: '',
  phone: '',
  address: '',
}

const REWARD_PRESETS = [
  { points: 5, label: 'Voucher Belanja Rp 5.000' },
  { points: 10, label: 'Paket Mie Instan / Kopi (Senilai Rp 10.000)' },
  { points: 25, label: 'Voucher Sembako Rp 25.000' },
  { points: 50, label: 'Paket Sembako Hemat (Senilai Rp 50.000)' },
]

export default function CustomerPage({ search = '' }) {
  const [customers, setCustomers] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  // Modal tambah dan edit
  const [formOpen, setFormOpen] = useState(false)
  const [editingCustomer, setEditingCustomer] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [formError, setFormError] = useState('')

  // Modal detail pelanggan
  const [detailOpen, setDetailOpen] = useState(false)
  const [selectedCustomer, setSelectedCustomer] = useState(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')

  // Modal bayar piutang
  const [payDebtOpen, setPayDebtOpen] = useState(false)
  const [payingCustomer, setPayingCustomer] = useState(null)
  const [payingTransaction, setPayingTransaction] = useState(null)
  const [payDebtAmount, setPayDebtAmount] = useState('')
  const [payDebtSaving, setPayDebtSaving] = useState(false)
  const [payDebtError, setPayDebtError] = useState('')
  const payDebtKeyRef = useRef(null)

  // Modal tukar poin loyalty
  const [redeemOpen, setRedeemOpen] = useState(false)
  const [redeemPoints, setRedeemPoints] = useState('5')
  const [redeemDescription, setRedeemDescription] = useState('Voucher Belanja Rp 5.000')
  const [redeemSaving, setRedeemSaving] = useState(false)
  const [redeemError, setRedeemError] = useState('')
  const redeemKeyRef = useRef(null)

  const loadCustomers = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/customers')
      const result = getPayload(response)
      setCustomers(Array.isArray(result) ? result : [])
    } catch (err) {
      setError(getErrorMessage(err, 'Data pelanggan gagal dimuat.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(loadCustomers)
  }, [loadCustomers])

  const totalCustomers = customers.length
  const customersWithDebt = customers.filter((c) => Number(c.total_debt || 0) > 0).length
  const totalDebt = customers.reduce((sum, c) => sum + Number(c.total_debt || 0), 0)

  const filteredCustomers = useMemo(() => {
    const keyword = search.trim().toLowerCase()
    if (!keyword) return customers
    return customers.filter((customer) =>
      [customer.name, customer.phone, customer.address, customer.total_debt]
        .join(' ')
        .toLowerCase()
        .includes(keyword)
    )
  }, [customers, search])

  const openCreateModal = () => {
    setEditingCustomer(null)
    setForm(emptyForm)
    setFormError('')
    setFormOpen(true)
  }

  const openEditModal = (customer) => {
    setEditingCustomer(customer)
    setForm({
      name: customer.name || '',
      phone: customer.phone || '',
      address: customer.address || '',
    })
    setFormError('')
    setFormOpen(true)
  }

  const handleSave = async (event) => {
    event.preventDefault()
    setFormError('')
    if (!form.name.trim()) {
      setFormError('Nama pelanggan wajib diisi.')
      return
    }
    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        phone: form.phone.trim() || null,
        address: form.address.trim() || null,
      }
      if (editingCustomer) {
        await api.put(`/customers/${editingCustomer.id}`, payload)
        setNotice('Data pelanggan berhasil diperbarui.')
      } else {
        await api.post('/customers', payload)
        setNotice('Pelanggan baru berhasil ditambahkan.')
      }
      setFormOpen(false)
      setEditingCustomer(null)
      setForm(emptyForm)
      await loadCustomers()
    } catch (err) {
      setFormError(getErrorMessage(err, 'Data pelanggan gagal disimpan.'))
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (customer) => {
    const confirmed = window.confirm(
      `Yakin ingin menghapus pelanggan "${customer.name}"? Pastikan riwayat transaksi pelanggan sudah diperiksa.`
    )
    if (!confirmed) return
    setDeletingId(customer.id)
    setError('')
    try {
      await api.delete(`/customers/${customer.id}`)
      setNotice('Pelanggan berhasil dihapus.')
      await loadCustomers()
    } catch (err) {
      setError(getErrorMessage(err, 'Pelanggan gagal dihapus.'))
    } finally {
      setDeletingId(null)
    }
  }

  const openDetail = async (customer) => {
    setDetailOpen(true)
    setSelectedCustomer(null)
    setDetailLoading(true)
    setDetailError('')
    try {
      const response = await api.get(`/customers/${customer.id}`)
      setSelectedCustomer(getPayload(response))
    } catch (err) {
      setDetailError(getErrorMessage(err, 'Detail pelanggan gagal dimuat.'))
    } finally {
      setDetailLoading(false)
    }
  }

  const openPayDebtModal = (customer, transaction = null) => {
    payDebtKeyRef.current = null
    setPayingCustomer(customer)
    setPayingTransaction(transaction)
    setPayDebtError('')
    if (transaction) {
      const remainingTx = Math.max(0, Number(transaction.total_price || 0) - Number(transaction.pay_amount || 0))
      setPayDebtAmount(String(remainingTx))
    } else {
      setPayDebtAmount(String(Number(customer.total_debt || 0)))
    }
    setPayDebtOpen(true)
  }

  const maxPayableDebt = useMemo(() => {
    if (payingTransaction) {
      return Math.max(0, Number(payingTransaction.total_price || 0) - Number(payingTransaction.pay_amount || 0))
    }
    return Math.max(0, Number(payingCustomer?.total_debt || 0))
  }, [payingCustomer, payingTransaction])

  const handlePayDebtSubmit = async (event) => {
    event.preventDefault()
    if (!payingCustomer) return
    const numericAmount = Number(payDebtAmount || 0)
    if (!numericAmount || numericAmount <= 0) {
      setPayDebtError('Nominal pembayaran harus lebih dari Rp 0.')
      return
    }
    if (numericAmount > maxPayableDebt) {
      setPayDebtError('Nominal pembayaran tidak boleh melebihi sisa tagihan.')
      return
    }
    setPayDebtSaving(true)
    setPayDebtError('')
    try {
      if (!payDebtKeyRef.current) payDebtKeyRef.current = makeIdempotencyKey()
      const payload = {
        idempotency_key: payDebtKeyRef.current,
        amount: numericAmount,
        payment_method: 'cash',
      }
      if (payingTransaction) payload.transaction_id = payingTransaction.id

      const response = await api.post(`/customers/${payingCustomer.id}/pay-debt`, payload)
      const updatedCustomer = getPayload(response)

      setNotice(
        response.meta?.idempotent_replay
          ? 'Pembayaran sebelumnya sudah berhasil dicatat; tidak diproses ulang.'
          : `Pembayaran tunai ${formatCurrency(numericAmount)} untuk ${payingCustomer.name} berhasil dicatat.`
      )
      payDebtKeyRef.current = null
      setPayDebtOpen(false)
      setPayingCustomer(null)
      setPayingTransaction(null)
      setPayDebtAmount('')

      if (detailOpen && selectedCustomer?.id === updatedCustomer?.id) {
        setSelectedCustomer(updatedCustomer)
      }
      await loadCustomers()
    } catch (err) {
      setPayDebtError(getErrorMessage(err, 'Pembayaran piutang gagal diproses.'))
    } finally {
      setPayDebtSaving(false)
    }
  }

  const openRedeemModal = () => {
    const balance = Number(selectedCustomer?.online_account?.points_balance || 0)
    const defaultPreset = REWARD_PRESETS.find((p) => p.points <= balance) || { points: Math.min(5, balance), label: 'Voucher Belanja Toko' }
    redeemKeyRef.current = null
    setRedeemPoints(String(Math.max(1, defaultPreset.points)))
    setRedeemDescription(defaultPreset.label)
    setRedeemError('')
    setRedeemOpen(true)
  }

  const handleRedeemSubmit = async (event) => {
    event.preventDefault()
    if (!selectedCustomer) return
    const balance = Number(selectedCustomer.online_account?.points_balance || 0)
    const pts = Number(redeemPoints || 0)

    if (!Number.isInteger(pts) || pts <= 0) {
      setRedeemError('Jumlah poin harus bilangan bulat minimal 1.')
      return
    }
    if (pts > balance) {
      setRedeemError(`Saldo poin tidak mencukupi (maksimal ${balance} poin).`)
      return
    }
    if (redeemDescription.trim().length < 3) {
      setRedeemError('Keterangan hadiah/voucher minimal 3 karakter.')
      return
    }

    setRedeemSaving(true)
    setRedeemError('')
    try {
      if (!redeemKeyRef.current) redeemKeyRef.current = makeIdempotencyKey()
      const response = await api.post(`/customers/${selectedCustomer.id}/redeem-points`, {
        idempotency_key: redeemKeyRef.current,
        points: pts,
        description: redeemDescription.trim(),
      })
      const updatedCustomer = getPayload(response)
      setSelectedCustomer(updatedCustomer)
      setNotice(response.message || `Penukaran ${pts} poin berhasil dicatat.`)
      redeemKeyRef.current = null
      setRedeemOpen(false)
      await loadCustomers()
    } catch (err) {
      setRedeemError(getErrorMessage(err, 'Penukaran poin gagal diproses.'))
    } finally {
      setRedeemSaving(false)
    }
  }

  const availablePoints = Number(selectedCustomer?.online_account?.points_balance || 0)
  const valuePerPoint = Number(selectedCustomer?.online_account?.redeem_value_per_point || 1000)

  return (
    <div className="customer-page">
      <div className="page-heading-row">
        <div>
          <h1>Customer Management</h1>
          <p>Kelola data pelanggan, informasi kontak, riwayat piutang, dan penukaran poin loyalty.</p>
        </div>
        <div className="customer-heading-actions">
          <button type="button" className="icon-button" onClick={() => void loadCustomers()} title="Refresh" disabled={loading}>
            <Icon name="refresh" size={16} />
          </button>
          <button type="button" className="primary-button" onClick={openCreateModal}>
            <span>＋</span> Tambah Pelanggan
          </button>
        </div>
      </div>

      {notice && (
        <div className="customer-notice">
          <span>✓</span> {notice}
          <button type="button" onClick={() => setNotice('')} aria-label="Tutup pemberitahuan">×</button>
        </div>
      )}

      {error && (
        <div className="inline-alert">
          <Icon name="warning" size={16} /> {error}
          <button type="button" onClick={() => void loadCustomers()}>Coba lagi</button>
        </div>
      )}

      <div className="mini-stat-grid customer-stat-grid">
        <div className="mini-stat">
          <span>Total Pelanggan</span>
          <strong>{loading ? '—' : totalCustomers.toLocaleString('id-ID')}</strong>
          <small>Seluruh pelanggan terdaftar</small>
        </div>
        <div className="mini-stat">
          <span>Pelanggan Memiliki Piutang</span>
          <strong>{loading ? '—' : customersWithDebt.toLocaleString('id-ID')}</strong>
          <small>Pelanggan dengan utang berjalan</small>
        </div>
        <div className="mini-stat">
          <span>Total Piutang Pelanggan</span>
          <strong className="customer-debt-total">{loading ? 'Memuat…' : formatCurrency(totalDebt)}</strong>
          <small>Total piutang yang tercatat</small>
        </div>
      </div>

      <section className="panel customer-panel">
        <div className="panel-heading customer-panel-heading">
          <div>
            <h2>Daftar Pelanggan</h2>
            <p>Informasi pelanggan, saldo poin loyalty, dan status piutang.</p>
          </div>
          <span className="count-pill">{filteredCustomers.length} pelanggan</span>
        </div>

        <div className="customer-toolbar">
          <span>{search ? `Hasil pencarian: "${search}"` : 'Daftar seluruh pelanggan toko'}</span>
        </div>

        <div className="table-scroll">
          <table className="data-table customer-table">
            <thead>
              <tr>
                <th>No.</th>
                <th>Nama Pelanggan</th>
                <th>Nomor Telepon</th>
                <th>Poin Loyalty</th>
                <th>Total Piutang</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan="7" className="table-message">Memuat data pelanggan...</td></tr>
              ) : filteredCustomers.length > 0 ? (
                filteredCustomers.map((customer, index) => {
                  const debt = Number(customer.total_debt || 0)
                  const pts = customer.account ? Number(customer.account.points_balance || 0) : null
                  return (
                    <tr key={customer.id}>
                      <td>{index + 1}</td>
                      <td className="strong-cell">
                        {customer.name}
                        {customer.address && <div style={{ fontSize: 12, fontWeight: 400, color: '#64748b' }}>{customer.address}</div>}
                      </td>
                      <td>{customer.phone || '—'}</td>
                      <td>
                        {pts !== null ? (
                          <span className="status-pill status-success">{pts.toLocaleString('id-ID')} poin</span>
                        ) : (
                          <span style={{ color: '#94a3b8', fontSize: 13 }}>Belum aktivasi</span>
                        )}
                      </td>
                      <td className="money-cell">{formatCurrency(debt)}</td>
                      <td>
                        {debt > 0 ? (
                          <span className="status-pill status-warning">Ada Piutang</span>
                        ) : (
                          <span className="status-pill status-success">Lunas</span>
                        )}
                      </td>
                      <td>
                        <div className="customer-actions">
                          {debt > 0 && (
                            <button type="button" className="small-action primary-button" onClick={() => openPayDebtModal(customer)}>
                              Bayar Piutang
                            </button>
                          )}
                          <button type="button" className="small-action" onClick={() => void openDetail(customer)}>
                            Detail
                          </button>
                          <button type="button" className="small-action" onClick={() => openEditModal(customer)}>
                            Edit
                          </button>
                          <button
                            type="button"
                            className="small-action customer-delete-button"
                            onClick={() => void handleDelete(customer)}
                            disabled={deletingId === customer.id}
                          >
                            {deletingId === customer.id ? 'Menghapus...' : 'Hapus'}
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })
              ) : (
                <tr>
                  <td colSpan="7" className="table-message">
                    {search ? 'Pelanggan tidak ditemukan.' : 'Belum ada data pelanggan. Silakan tambahkan pelanggan baru.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>

        <div className="table-footnote">
          <Icon name="info" size={13} />
          Setiap belanja lunas kelipatan Rp 10.000 otomatis menambah 1 poin loyalty bagi pelanggan yang terhubung dengan akun online.
        </div>
      </section>

      {/* MODAL TAMBAH / EDIT CUSTOMER */}
      {formOpen && (
        <div className="modal-backdrop">
          <div className="modal-card">
            <div className="modal-header">
              <div>
                <h2>{editingCustomer ? 'Edit Pelanggan' : 'Tambah Pelanggan Baru'}</h2>
                <p>Lengkapi informasi pelanggan berikut.</p>
              </div>
              <button type="button" className="icon-button" onClick={() => setFormOpen(false)} disabled={saving}>×</button>
            </div>
            <form className="modal-form" onSubmit={handleSave}>
              {formError && <div className="form-error"><Icon name="warning" size={15} /> {formError}</div>}
              <label>
                Nama Pelanggan
                <input
                  type="text"
                  placeholder="Masukkan nama pelanggan"
                  value={form.name}
                  onChange={(event) => setForm({ ...form, name: event.target.value })}
                  maxLength={255}
                  required
                  autoFocus
                />
              </label>
              <label>
                Nomor Telepon <span className="optional-label">Opsional</span>
                <input
                  type="tel"
                  placeholder="Contoh: 081234567890"
                  value={form.phone}
                  onChange={(event) => setForm({ ...form, phone: event.target.value })}
                  maxLength={20}
                />
              </label>
              <label>
                Alamat <span className="optional-label">Opsional</span>
                <textarea
                  rows={3}
                  placeholder="Masukkan alamat pelanggan"
                  value={form.address}
                  onChange={(event) => setForm({ ...form, address: event.target.value })}
                />
              </label>
              <div className="modal-actions">
                <button type="button" className="outline-button" onClick={() => setFormOpen(false)} disabled={saving}>Batal</button>
                <button type="submit" className="primary-button" disabled={saving}>
                  {saving ? <><span className="button-spinner" /> Menyimpan...</> : (editingCustomer ? 'Simpan Perubahan' : 'Simpan Pelanggan')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL DETAIL CUSTOMER */}
      {detailOpen && (
        <div className="modal-backdrop">
          <div className="modal-card modal-wide">
            <div className="modal-header">
              <div>
                <h2>Detail Pelanggan</h2>
                <p>Informasi pelanggan, saldo loyalty points, dan riwayat transaksi.</p>
              </div>
              <button type="button" className="icon-button" onClick={() => setDetailOpen(false)}>×</button>
            </div>

            <div className="customer-detail-body">
              {detailLoading ? (
                <div className="table-message">Memuat detail pelanggan...</div>
              ) : detailError ? (
                <div className="form-error">{detailError}</div>
              ) : selectedCustomer ? (
                <>
                  <div className="customer-detail-grid">
                    <div>
                      <span>Nama Pelanggan</span>
                      <strong>{selectedCustomer.name}</strong>
                    </div>
                    <div>
                      <span>Nomor Telepon</span>
                      <strong>{selectedCustomer.phone || '—'}</strong>
                    </div>
                    <div>
                      <span>Alamat</span>
                      <strong>{selectedCustomer.address || '—'}</strong>
                    </div>
                    <div>
                      <span>Total Piutang</span>
                      <strong className="customer-debt-total">{formatCurrency(selectedCustomer.total_debt)}</strong>
                    </div>
                    <div>
                      <span>Akun Online</span>
                      <strong>{selectedCustomer.online_account?.email || 'Belum punya akun online'}</strong>
                    </div>
                    <div>
                      <span>Loyalty Points</span>
                      {selectedCustomer.online_account ? (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 2 }}>
                          <strong style={{ color: '#15803d' }}>
                            {availablePoints.toLocaleString('id-ID')} poin ({formatCurrency(availablePoints * valuePerPoint)})
                          </strong>
                          {availablePoints > 0 && (
                            <button type="button" className="small-action primary-button" onClick={openRedeemModal}>
                              Tukar Poin
                            </button>
                          )}
                        </div>
                      ) : (
                        <strong>— (Daftar akun online untuk aktifkan)</strong>
                      )}
                    </div>
                    <div>
                      <span>Tanggal Terdaftar</span>
                      <strong>{selectedCustomer.created_at ? formatDate(selectedCustomer.created_at, true) : '—'}</strong>
                    </div>
                  </div>

                  {/* RIWAYAT LOYALTY POINTS */}
                  {selectedCustomer.online_account && (
                    <>
                      <div className="customer-history-heading">
                        <div>
                          <h3>Riwayat Mutasi Poin Loyalty</h3>
                          <p>Perolehan otomatis dari transaksi lunas dan catatan penukaran poin di toko.</p>
                        </div>
                        <span className="count-pill">{selectedCustomer.loyalty_entries?.length || 0} mutasi</span>
                      </div>
                      <div className="table-scroll">
                        <table className="data-table customer-history-table">
                          <thead>
                            <tr>
                              <th>Tanggal</th>
                              <th>Keterangan</th>
                              <th>Perubahan Poin</th>
                              <th>Diproses Oleh</th>
                            </tr>
                          </thead>
                          <tbody>
                            {selectedCustomer.loyalty_entries?.length > 0 ? (
                              selectedCustomer.loyalty_entries.map((entry) => (
                                <tr key={entry.id}>
                                  <td>{formatDate(entry.created_at, true)}</td>
                                  <td>{entry.description}</td>
                                  <td className="money-cell" style={{ color: entry.points_delta > 0 ? '#16a34a' : '#dc2626', fontWeight: 700 }}>
                                    {entry.points_delta > 0 ? `+${entry.points_delta}` : entry.points_delta} poin
                                  </td>
                                  <td>{entry.creator?.name || 'Sistem'}</td>
                                </tr>
                              ))
                            ) : (
                              <tr><td colSpan="4" className="table-message">Belum ada mutasi poin loyalty.</td></tr>
                            )}
                          </tbody>
                        </table>
                      </div>
                    </>
                  )}

                  {/* RIWAYAT TRANSAKSI */}
                  <div className="customer-history-heading">
                    <div>
                      <h3>Riwayat Transaksi</h3>
                      <p>Riwayat transaksi yang terhubung dengan pelanggan.</p>
                    </div>
                    <div className="customer-actions">
                      {Number(selectedCustomer.total_debt || 0) > 0 && (
                        <button type="button" className="primary-button" onClick={() => openPayDebtModal(selectedCustomer)}>
                          Bayar Semua Piutang
                        </button>
                      )}
                      <span className="count-pill">{selectedCustomer.transactions?.length || 0} transaksi</span>
                    </div>
                  </div>

                  <div className="table-scroll">
                    <table className="data-table customer-history-table">
                      <thead>
                        <tr>
                          <th>Invoice</th>
                          <th>Tanggal</th>
                          <th>Metode Pembayaran</th>
                          <th>Total Transaksi</th>
                          <th>Sudah Dibayar</th>
                          <th>Sisa Tagihan</th>
                          <th>Status</th>
                          <th>Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        {selectedCustomer.transactions?.length > 0 ? (
                          selectedCustomer.transactions.map((transaction) => {
                            const txTotal = Number(transaction.total_price || 0)
                            const txPaid = Number(transaction.pay_amount || 0)
                            const txRemaining = transaction.payment_status === 'paid' ? 0 : Math.max(0, txTotal - txPaid)
                            return (
                              <tr key={transaction.id}>
                                <td className="mono-cell">{transaction.invoice_number || `TRX-${transaction.id}`}</td>
                                <td>{formatDate(transaction.created_at, true)}</td>
                                <td>{paymentLabel(transaction.payment_method)}</td>
                                <td className="money-cell">{formatCurrency(txTotal)}</td>
                                <td className="money-cell">{formatCurrency(txPaid)}</td>
                                <td className="money-cell">{formatCurrency(txRemaining)}</td>
                                <td>
                                  {transaction.payment_status === 'paid'
                                    ? <span className="status-pill status-success">Lunas</span>
                                    : <span className="status-pill status-warning">Belum Lunas</span>}
                                </td>
                                <td>
                                  {transaction.payment_status !== 'paid'
                                    ? <button type="button" className="small-action primary-button" onClick={() => openPayDebtModal(selectedCustomer, transaction)}>Bayar</button>
                                    : <span>—</span>}
                                </td>
                              </tr>
                            )
                          })
                        ) : (
                          <tr><td colSpan="8" className="table-message">Belum ada riwayat transaksi.</td></tr>
                        )}
                      </tbody>
                    </table>
                  </div>

                  {/* RIWAYAT PEMBAYARAN PIUTANG */}
                  <div className="customer-history-heading">
                    <div>
                      <h3>Riwayat Pembayaran Piutang</h3>
                      <p>Catatan pembayaran tunai yang dibuat setelah modul audit diterapkan.</p>
                    </div>
                    <span className="count-pill">{selectedCustomer.debt_payments?.length || 0} pembayaran</span>
                  </div>
                  <div className="table-scroll">
                    <table className="data-table customer-history-table">
                      <thead>
                        <tr>
                          <th>Tanggal</th>
                          <th>Nominal</th>
                          <th>Metode</th>
                          <th>Diterima oleh</th>
                          <th>Alokasi Invoice</th>
                        </tr>
                      </thead>
                      <tbody>
                        {selectedCustomer.debt_payments?.length ? (
                          selectedCustomer.debt_payments.map((payment) => (
                            <tr key={payment.id}>
                              <td>{formatDate(payment.created_at, true)}</td>
                              <td className="money-cell">{formatCurrency(payment.amount)}</td>
                              <td>Tunai</td>
                              <td>{payment.user?.name || '—'}</td>
                              <td>
                                {(payment.allocations || []).map((allocation) => (
                                  <div key={allocation.id}>
                                    {allocation.transaction?.invoice_number || `TRX-${allocation.transaction_id}`}
                                    {' · '}{formatCurrency(allocation.amount)}
                                  </div>
                                ))}
                              </td>
                            </tr>
                          ))
                        ) : (
                          <tr><td colSpan="5" className="table-message">Belum ada pembayaran pada audit trail baru.</td></tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </>
              ) : null}

              <div className="modal-actions">
                <button type="button" className="outline-button" onClick={() => setDetailOpen(false)}>Tutup</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* MODAL TUKAR POIN LOYALTY */}
      {redeemOpen && selectedCustomer && (
        <div className="modal-backdrop">
          <div className="modal-card">
            <div className="modal-header">
              <div>
                <h2>Tukar Poin Loyalty</h2>
                <p>Penukaran poin untuk {selectedCustomer.name}</p>
              </div>
              <button type="button" className="icon-button" onClick={() => setRedeemOpen(false)} disabled={redeemSaving}>×</button>
            </div>

            <form className="modal-form" onSubmit={handleRedeemSubmit}>
              {redeemError && <div className="form-error"><Icon name="warning" size={15} /> {redeemError}</div>}

              <div className="customer-detail-grid">
                <div>
                  <span>Saldo Poin Tersedia</span>
                  <strong style={{ color: '#15803d' }}>{availablePoints.toLocaleString('id-ID')} poin</strong>
                </div>
                <div>
                  <span>Estimasi Nilai Tukar</span>
                  <strong>{formatCurrency(availablePoints * valuePerPoint)}</strong>
                </div>
              </div>

              <div>
                <span style={{ fontSize: 13, fontWeight: 600, display: 'block', marginBottom: 6 }}>Pilih Paket Penukaran Cepat</span>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                  {REWARD_PRESETS.map((preset) => (
                    <button
                      key={preset.points}
                      type="button"
                      className="small-action"
                      disabled={preset.points > availablePoints || redeemSaving}
                      onClick={() => {
                        redeemKeyRef.current = null
                        setRedeemPoints(String(preset.points))
                        setRedeemDescription(preset.label)
                      }}
                    >
                      {preset.points} poin · {preset.label}
                    </button>
                  ))}
                </div>
              </div>

              <label>
                Jumlah Poin Ditukar
                <input
                  type="number"
                  min="1"
                  max={availablePoints}
                  step="1"
                  value={redeemPoints}
                  onChange={(e) => { redeemKeyRef.current = null; setRedeemPoints(e.target.value) }}
                  disabled={redeemSaving}
                  required
                />
              </label>

              <label>
                Keterangan Hadiah / Voucher
                <input
                  type="text"
                  maxLength={200}
                  value={redeemDescription}
                  onChange={(e) => { redeemKeyRef.current = null; setRedeemDescription(e.target.value) }}
                  placeholder="Contoh: Voucher Belanja Rp 10.000 / Paket Sembako"
                  disabled={redeemSaving}
                  required
                />
              </label>

              <div style={{ fontSize: '0.875rem', color: '#334155' }}>
                Sisa poin setelah ditukar:{' '}
                <strong>{Math.max(0, availablePoints - Number(redeemPoints || 0)).toLocaleString('id-ID')} poin</strong>
              </div>

              <div className="modal-actions">
                <button
                  type="button"
                  className="outline-button"
                  onClick={() => { if (!redeemSaving) { setRedeemOpen(false); redeemKeyRef.current = null } }}
                  disabled={redeemSaving}
                >
                  Batal
                </button>
                <button type="submit" className="primary-button" disabled={redeemSaving}>
                  {redeemSaving ? <><span className="button-spinner" /> Memproses...</> : 'Konfirmasi Tukar Poin'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL BAYAR PIUTANG CUSTOMER */}
      {payDebtOpen && payingCustomer && (
        <div className="modal-backdrop">
          <div className="modal-card">
            <div className="modal-header">
              <div>
                <h2>Bayar Piutang Pelanggan</h2>
                <p>
                  {payingTransaction
                    ? `Pelunasan untuk Invoice ${payingTransaction.invoice_number || `TRX-${payingTransaction.id}`}`
                    : `Pelunasan piutang untuk ${payingCustomer.name}`}
                </p>
              </div>
              <button type="button" className="icon-button" onClick={() => setPayDebtOpen(false)} disabled={payDebtSaving}>×</button>
            </div>

            <form className="modal-form" onSubmit={handlePayDebtSubmit}>
              {payDebtError && <div className="form-error"><Icon name="warning" size={15} /> {payDebtError}</div>}
              <div className="customer-detail-grid">
                <div>
                  <span>Nama Pelanggan</span>
                  <strong>{payingCustomer.name}</strong>
                </div>
                <div>
                  <span>{payingTransaction ? 'Sisa Tagihan Invoice' : 'Total Piutang Saat Ini'}</span>
                  <strong className="customer-debt-total">{formatCurrency(maxPayableDebt)}</strong>
                </div>
              </div>

              <p className="payment-hint">
                Metode penerimaan: <strong>Tunai</strong>. Setiap pembayaran dicatat ke riwayat audit dengan nama Owner dan waktu transaksi.
              </p>

              <label>
                Nominal Pembayaran (Rp)
                <input
                  type="number"
                  min="1"
                  max={maxPayableDebt}
                  step="1"
                  placeholder="Masukkan nominal yang dibayarkan"
                  value={payDebtAmount}
                  onChange={(event) => { payDebtKeyRef.current = null; setPayDebtAmount(event.target.value) }}
                  disabled={payDebtSaving}
                  required
                  autoFocus
                />
              </label>

              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '0.875rem' }}>
                <span>
                  Sisa setelah dibayar:{' '}
                  <strong>{formatCurrency(Math.max(0, maxPayableDebt - Number(payDebtAmount || 0)))}</strong>
                </span>
                <button
                  type="button"
                  className="small-action"
                  onClick={() => { payDebtKeyRef.current = null; setPayDebtAmount(String(maxPayableDebt)) }}
                  disabled={payDebtSaving}
                >
                  Bayar Lunas Penuh
                </button>
              </div>

              <div className="modal-actions">
                <button
                  type="button"
                  className="outline-button"
                  onClick={() => { if (!payDebtSaving) { setPayDebtOpen(false); payDebtKeyRef.current = null } }}
                  disabled={payDebtSaving}
                >
                  Batal
                </button>
                <button type="submit" className="primary-button" disabled={payDebtSaving}>
                  {payDebtSaving ? <><span className="button-spinner" /> Memproses...</> : 'Simpan Pembayaran'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}