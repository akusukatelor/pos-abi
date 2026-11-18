
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import {
  formatCurrency,
  formatDate,
  paymentLabel
} from '../utils/format.js'

// Mengambil payload dari respons Laravel
function getPayload(response) {
  return response?.data?.data ?? response?.data ?? response
}

// Mengambil pesan error dari Laravel
function getErrorMessage(error, fallback) {
  const validationErrors = error?.response?.data?.errors

  if (validationErrors) {
    const firstError = Object.values(validationErrors).flat()[0]
    if (firstError) return firstError
  }

  return (
    error?.response?.data?.message ||
    error?.message ||
    fallback
  )
}

// Form pelanggan
const emptyForm = {
  name: '',
  phone: '',
  address: '',
}

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

  // =========================================
  // LOAD DATA CUSTOMER
  // =========================================

  const loadCustomers = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const response = await api.get('/customers')
      const result = getPayload(response)

      setCustomers(Array.isArray(result) ? result : [])
    } catch (err) {
      setError(
        getErrorMessage(
          err,
          'Data pelanggan gagal dimuat.'
        )
      )
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadCustomers()
  }, [loadCustomers])

  // =========================================
  // STATISTIK CUSTOMER
  // =========================================

  const totalCustomers = customers.length

  const customersWithDebt = customers.filter(
    (customer) => Number(customer.total_debt || 0) > 0
  ).length

  const totalDebt = customers.reduce(
    (total, customer) =>
      total + Number(customer.total_debt || 0),
    0
  )

  // =========================================
  // GLOBAL SEARCH
  // =========================================

  const filteredCustomers = useMemo(() => {
    const keyword = search.trim().toLowerCase()

    if (!keyword) return customers

    return customers.filter((customer) => {
      const searchable = [
        customer.name,
        customer.phone,
        customer.address,
        customer.total_debt,
      ]
        .join(' ')
        .toLowerCase()

      return searchable.includes(keyword)
    })
  }, [customers, search])

  // =========================================
  // MODAL TAMBAH CUSTOMER
  // =========================================

  const openCreateModal = () => {
    setEditingCustomer(null)
    setForm(emptyForm)
    setFormError('')
    setFormOpen(true)
  }

  // =========================================
  // MODAL EDIT CUSTOMER
  // =========================================

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

  // =========================================
  // SIMPAN CUSTOMER
  // =========================================

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
        await api.put(
          `/customers/${editingCustomer.id}`,
          payload
        )
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
      setFormError(
        getErrorMessage(
          err,
          'Data pelanggan gagal disimpan.'
        )
      )
    } finally {
      setSaving(false)
    }
  }

  // =========================================
  // HAPUS CUSTOMER
  // =========================================

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
      setError(
        getErrorMessage(
          err,
          'Pelanggan gagal dihapus.'
        )
      )
    } finally {
      setDeletingId(null)
    }
  }

  // =========================================
  // DETAIL CUSTOMER
  // =========================================

  const openDetail = async (customer) => {
    setDetailOpen(true)
    setSelectedCustomer(null)
    setDetailLoading(true)
    setDetailError('')

    try {
      const response = await api.get(
        `/customers/${customer.id}`
      )

      setSelectedCustomer(getPayload(response))
    } catch (err) {
      setDetailError(
        getErrorMessage(
          err,
          'Detail pelanggan gagal dimuat.'
        )
      )
    } finally {
      setDetailLoading(false)
    }
  }

  // =========================================
  // TAMPILAN HALAMAN
  // =========================================

  return (
    <div className="customer-page">

      {/* HEADER */}

      <div className="page-heading-row">
        <div>
          <h1>Customer Management</h1>
          <p>
            Kelola data pelanggan, informasi kontak,
            dan riwayat piutang toko.
          </p>
        </div>

        <div className="customer-heading-actions">

          <button
            type="button"
            className="icon-button"
            onClick={() => void loadCustomers()}
            title="Refresh"
            disabled={loading}
          >
            <Icon name="refresh" size={16} />
          </button>

          <button
            type="button"
            className="primary-button"
            onClick={openCreateModal}
          >
            <span>＋</span>
            Tambah Pelanggan
          </button>

        </div>
      </div>

      {/* NOTIFICATION */}

      {notice && (
        <div className="customer-notice">
          <span>✓</span>
          {notice}
          <button
            type="button"
            onClick={() => setNotice('')}
            aria-label="Tutup pemberitahuan"
          >
            ×
          </button>
        </div>
      )}

      {error && (
        <div className="inline-alert">
          <Icon name="warning" size={16} />
          {error}
          <button
            type="button"
            onClick={() => void loadCustomers()}
          >
            Coba lagi
          </button>
        </div>
      )}

      {/* STATISTIK */}

      <div className="mini-stat-grid customer-stat-grid">

        <div className="mini-stat">
          <span>Total Pelanggan</span>
          <strong>
            {loading
              ? '—'
              : totalCustomers.toLocaleString('id-ID')}
          </strong>
          <small>Seluruh pelanggan terdaftar</small>
        </div>

        <div className="mini-stat">
          <span>Pelanggan Memiliki Piutang</span>
          <strong>
            {loading ? '—' : customersWithDebt.toLocaleString('id-ID')}
          </strong>
          <small>Pelanggan dengan utang berjalan</small>
        </div>

        <div className="mini-stat">
          <span>Total Piutang Pelanggan</span>
          <strong className="customer-debt-total">
            {loading ? 'Memuat…' : formatCurrency(totalDebt)}
          </strong>
          <small>Total piutang yang tercatat</small>
        </div>

      </div>

      {/* TABEL CUSTOMER */}

      <section className="panel customer-panel">

        <div className="panel-heading customer-panel-heading">
          <div>
            <h2>Daftar Pelanggan</h2>
            <p>
              Informasi pelanggan yang tersimpan dalam database.
            </p>
          </div>

          <span className="count-pill">
            {filteredCustomers.length} pelanggan
          </span>
        </div>

        <div className="customer-toolbar">
          <span>
            {search
              ? `Hasil pencarian: "${search}"`
              : 'Daftar seluruh pelanggan toko'}
          </span>
        </div>

        <div className="table-scroll">

          <table className="data-table customer-table">

            <thead>
              <tr>
                <th>No.</th>
                <th>Nama Pelanggan</th>
                <th>Nomor Telepon</th>
                <th>Alamat</th>
                <th>Total Piutang</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>

            <tbody>

              {loading ? (
                <tr>
                  <td colSpan="7" className="table-message">
                    Memuat data pelanggan...
                  </td>
                </tr>
              ) : filteredCustomers.length > 0 ? (

                filteredCustomers.map((customer, index) => {

                  const debt = Number(customer.total_debt || 0)

                  return (
                    <tr key={customer.id}>

                      <td>{index + 1}</td>

                      <td className="strong-cell">
                        {customer.name}
                      </td>

                      <td>
                        {customer.phone || '—'}
                      </td>

                      <td className="customer-address-cell">
                        {customer.address || '—'}
                      </td>

                      <td className="money-cell">
                        {formatCurrency(debt)}
                      </td>

                      <td>
                        {debt > 0 ? (
                          <span className="status-pill status-warning">
                            Ada Piutang
                          </span>
                        ) : (
                          <span className="status-pill status-success">
                            Lunas
                          </span>
                        )}
                      </td>

                      <td>
                        <div className="customer-actions">

                          <button
                            type="button"
                            className="small-action"
                            onClick={() => void openDetail(customer)}
                          >
                            Detail
                          </button>

                          <button
                            type="button"
                            className="small-action"
                            onClick={() => openEditModal(customer)}
                          >
                            Edit
                          </button>

                          <button
                            type="button"
                            className="small-action customer-delete-button"
                            onClick={() => void handleDelete(customer)}
                            disabled={deletingId === customer.id}
                          >
                            {deletingId === customer.id
                              ? 'Menghapus...'
                              : 'Hapus'}
                          </button>

                        </div>
                      </td>

                    </tr>
                  )
                })

              ) : (
                <tr>
                  <td colSpan="7" className="table-message">
                    {search
                      ? 'Pelanggan tidak ditemukan.'
                      : 'Belum ada data pelanggan. Silakan tambahkan pelanggan baru.'}
                  </td>
                </tr>
              )}

            </tbody>

          </table>

        </div>

        <div className="table-footnote">
          <Icon name="info" size={13} />
          Total piutang ditampilkan berdasarkan nilai total_debt
          yang tersimpan di database.
        </div>

      </section>

      {/* =====================================
          MODAL TAMBAH / EDIT CUSTOMER
      ====================================== */}

      {formOpen && (

        <div className="modal-backdrop">

          <div className="modal-card">

            <div className="modal-header">

              <div>
                <h2>
                  {editingCustomer
                    ? 'Edit Pelanggan'
                    : 'Tambah Pelanggan Baru'}
                </h2>

                <p>
                  Lengkapi informasi pelanggan berikut.
                </p>
              </div>

              <button
                type="button"
                className="icon-button"
                onClick={() => setFormOpen(false)}
                disabled={saving}
              >
                ×
              </button>

            </div>

            <form
              className="modal-form"
              onSubmit={handleSave}
            >

              {formError && (
                <div className="form-error">
                  <Icon name="warning" size={15} />
                  {formError}
                </div>
              )}

              <label>
                Nama Pelanggan

                <input
                  type="text"
                  placeholder="Masukkan nama pelanggan"
                  value={form.name}
                  onChange={(event) =>
                    setForm({
                      ...form,
                      name: event.target.value
                    })
                  }
                  maxLength={255}
                  required
                  autoFocus
                />
              </label>

              <label>
                Nomor Telepon

                <span className="optional-label">
                  Opsional
                </span>

                <input
                  type="tel"
                  placeholder="Contoh: 081234567890"
                  value={form.phone}
                  onChange={(event) =>
                    setForm({
                      ...form,
                      phone: event.target.value
                    })
                  }
                  maxLength={20}
                />
              </label>

              <label>
                Alamat

                <span className="optional-label">
                  Opsional
                </span>

                <textarea
                  rows={3}
                  placeholder="Masukkan alamat pelanggan"
                  value={form.address}
                  onChange={(event) =>
                    setForm({
                      ...form,
                      address: event.target.value
                    })
                  }
                />
              </label>

              <div className="modal-actions">

                <button
                  type="button"
                  className="outline-button"
                  onClick={() => setFormOpen(false)}
                  disabled={saving}
                >
                  Batal
                </button>

                <button
                  type="submit"
                  className="primary-button"
                  disabled={saving}
                >
                  {saving ? (
                    <>
                      <span className="button-spinner" />
                      Menyimpan...
                    </>
                  ) : (
                    editingCustomer
                      ? 'Simpan Perubahan'
                      : 'Simpan Pelanggan'
                  )}
                </button>

              </div>

            </form>

          </div>

        </div>

      )}

      {/* =====================================
          MODAL DETAIL CUSTOMER
      ====================================== */}

      {detailOpen && (

        <div className="modal-backdrop">

          <div className="modal-card modal-wide">

            <div className="modal-header">

              <div>
                <h2>Detail Pelanggan</h2>
                <p>
                  Informasi pelanggan dan riwayat transaksi.
                </p>
              </div>

              <button
                type="button"
                className="icon-button"
                onClick={() => setDetailOpen(false)}
              >
                ×
              </button>

            </div>

            <div className="customer-detail-body">

              {detailLoading ? (

                <div className="table-message">
                  Memuat detail pelanggan...
                </div>

              ) : detailError ? (

                <div className="form-error">
                  {detailError}
                </div>

              ) : selectedCustomer ? (

                <>

                  <div className="customer-detail-grid">

                    <div>
                      <span>Nama Pelanggan</span>
                      <strong>{selectedCustomer.name}</strong>
                    </div>

                    <div>
                      <span>Nomor Telepon</span>
                      <strong>
                        {selectedCustomer.phone || '—'}
                      </strong>
                    </div>

                    <div>
                      <span>Alamat</span>
                      <strong>
                        {selectedCustomer.address || '—'}
                      </strong>
                    </div>

                    <div>
                      <span>Total Piutang</span>
                      <strong className="customer-debt-total">
                        {formatCurrency(selectedCustomer.total_debt)}
                      </strong>
                    </div>

                    <div>
                      <span>Tanggal Terdaftar</span>
                      <strong>
                        {selectedCustomer.created_at
                          ? formatDate(selectedCustomer.created_at, true)
                          : '—'}
                      </strong>
                    </div>

                  </div>

                  <div className="customer-history-heading">
                    <div>
                      <h3>Riwayat Transaksi</h3>
                      <p>
                        Riwayat transaksi yang terhubung dengan pelanggan.
                      </p>
                    </div>

                    <span className="count-pill">
                      {selectedCustomer.transactions?.length || 0} transaksi
                    </span>
                  </div>

                  <div className="table-scroll">

                    <table className="data-table customer-history-table">

                      <thead>
                        <tr>
                          <th>Invoice</th>
                          <th>Tanggal</th>
                          <th>Metode Pembayaran</th>
                          <th>Total Transaksi</th>
                          <th>Status</th>
                        </tr>
                      </thead>

                      <tbody>

                        {selectedCustomer.transactions?.length > 0 ? (

                          selectedCustomer.transactions.map((transaction) => (

                            <tr key={transaction.id}>

                              <td className="mono-cell">
                                {transaction.invoice_number ||
                                  `TRX-${transaction.id}`}
                              </td>

                              <td>
                                {formatDate(transaction.created_at, true)}
                              </td>

                              <td>
                                {paymentLabel(transaction.payment_method)}
                              </td>

                              <td className="money-cell">
                                {formatCurrency(transaction.total_price)}
                              </td>

                              <td>
                                {transaction.payment_status === 'paid' ? (
                                  <span className="status-pill status-success">
                                    Lunas
                                  </span>
                                ) : (
                                  <span className="status-pill status-warning">
                                    Belum Lunas
                                  </span>
                                )}
                              </td>

                            </tr>

                          ))

                        ) : (

                          <tr>
                            <td colSpan="5" className="table-message">
                              Belum ada riwayat transaksi.
                            </td>
                          </tr>

                        )}

                      </tbody>

                    </table>

                  </div>

                </>

              ) : null}

              <div className="modal-actions">
                <button
                  type="button"
                  className="outline-button"
                  onClick={() => setDetailOpen(false)}
                >
                  Tutup
                </button>
              </div>

            </div>

          </div>

        </div>

      )}

    </div>
  )
}
