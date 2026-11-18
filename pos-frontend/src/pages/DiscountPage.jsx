import { useCallback, useEffect, useMemo, useState } from 'react'
import { api } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import {
  formatCurrency,
  formatDate,
  todayInput,
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

  return (
    error?.response?.data?.message ||
    error?.message ||
    fallback
  )
}

function getDiscountStatus(discount, today) {
  if (!discount.is_active) {
    return { label: 'Nonaktif', className: 'status-neutral', activeToday: false }
  }
  const start = String(discount.start_date || '').slice(0, 10)
  const end = String(discount.end_date || '').slice(0, 10)

  if (today < start) {
    return { label: 'Terjadwal', className: 'status-warning', activeToday: false }
  }
  if (today > end) {
    return { label: 'Berakhir', className: 'status-neutral', activeToday: false }
  }
  return { label: 'Aktif Berlaku', className: 'status-success', activeToday: true }
}

const createEmptyForm = () => {
  const today = todayInput()
  return {
    name: '',
    type: 'percentage',
    value: '',
    start_date: today,
    end_date: today,
    is_active: true,
  }
}

export default function DiscountPage({ search = '' }) {
  const [discounts, setDiscounts] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const [formOpen, setFormOpen] = useState(false)
  const [editingDiscount, setEditingDiscount] = useState(null)
  const [form, setForm] = useState(createEmptyForm)
  const [formError, setFormError] = useState('')

  const today = todayInput()

  const loadDiscounts = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const response = await api.get('/discounts')
      const result = getPayload(response)
      setDiscounts(Array.isArray(result) ? result : [])
    } catch (err) {
      setError(getErrorMessage(err, 'Data promo & diskon gagal dimuat.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadDiscounts()
  }, [loadDiscounts])

  const totalDiscounts = discounts.length

  const activeTodayCount = useMemo(
    () => discounts.filter((item) => getDiscountStatus(item, today).activeToday).length,
    [discounts, today]
  )

  const percentageCount = useMemo(
    () => discounts.filter((item) => item.type === 'percentage').length,
    [discounts]
  )

  const filteredDiscounts = useMemo(() => {
    const keyword = search.trim().toLowerCase()
    if (!keyword) return discounts

    return discounts.filter((discount) => {
      const status = getDiscountStatus(discount, today).label
      const typeLabel = discount.type === 'percentage' ? 'persentase %' : 'potongan nominal tetap rp'
      const searchable = [
        discount.name,
        typeLabel,
        discount.value,
        discount.start_date,
        discount.end_date,
        status,
      ]
        .join(' ')
        .toLowerCase()

      return searchable.includes(keyword)
    })
  }, [discounts, search, today])

  const openCreateModal = () => {
    setEditingDiscount(null)
    setForm(createEmptyForm())
    setFormError('')
    setFormOpen(true)
  }

  const openEditModal = (discount) => {
    setEditingDiscount(discount)
    setForm({
      name: discount.name || '',
      type: discount.type || 'percentage',
      value: String(Number(discount.value || 0)),
      start_date: String(discount.start_date || today).slice(0, 10),
      end_date: String(discount.end_date || today).slice(0, 10),
      is_active: Boolean(discount.is_active),
    })
    setFormError('')
    setFormOpen(true)
  }

  const handleSave = async (event) => {
    event.preventDefault()
    setFormError('')

    const numericValue = Number(form.value)

    if (!form.name.trim()) {
      setFormError('Nama promo/diskon wajib diisi.')
      return
    }

    if (Number.isNaN(numericValue) || numericValue <= 0) {
      setFormError('Nilai diskon harus lebih dari 0.')
      return
    }

    if (form.type === 'percentage' && numericValue > 100) {
      setFormError('Diskon persentase tidak boleh melebihi 100%.')
      return
    }

    if (form.end_date < form.start_date) {
      setFormError('Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.')
      return
    }

    setSaving(true)

    try {
      const payload = {
        name: form.name.trim(),
        type: form.type,
        value: numericValue,
        start_date: form.start_date,
        end_date: form.end_date,
        is_active: Boolean(form.is_active),
      }

      if (editingDiscount) {
        await api.put(`/discounts/${editingDiscount.id}`, payload)
        setNotice('Promo/Diskon berhasil diperbarui.')
      } else {
        await api.post('/discounts', payload)
        setNotice('Promo/Diskon baru berhasil ditambahkan.')
      }

      setFormOpen(false)
      setEditingDiscount(null)
      setForm(createEmptyForm())
      await loadDiscounts()
    } catch (err) {
      setFormError(getErrorMessage(err, 'Data promo/diskon gagal disimpan.'))
    } finally {
      setSaving(false)
    }
  }

  const handleToggleActive = async (discount) => {
    try {
      await api.put(`/discounts/${discount.id}`, {
        name: discount.name,
        type: discount.type,
        value: Number(discount.value),
        start_date: String(discount.start_date).slice(0, 10),
        end_date: String(discount.end_date).slice(0, 10),
        is_active: !discount.is_active,
      })
      setNotice(
        `Promo "${discount.name}" berhasil ${!discount.is_active ? 'diaktifkan' : 'dinonaktifkan'}.`
      )
      await loadDiscounts()
    } catch (err) {
      setError(getErrorMessage(err, 'Gagal mengubah status promo.'))
    }
  }

  const handleDelete = async (discount) => {
    const confirmed = window.confirm(
      `Yakin ingin menghapus promo/diskon "${discount.name}"?`
    )
    if (!confirmed) return

    setDeletingId(discount.id)
    setError('')

    try {
      await api.delete(`/discounts/${discount.id}`)
      setNotice('Promo/Diskon berhasil dihapus.')
      await loadDiscounts()
    } catch (err) {
      setError(getErrorMessage(err, 'Promo/Diskon gagal dihapus.'))
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div className="customer-page">
      <div className="page-heading-row">
        <div>
          <h1>Manajemen Promo & Diskon</h1>
          <p>
            Kelola program potongan harga persen maupun nominal tetap untuk transaksi di Kasir POS.
          </p>
        </div>

        <div className="customer-heading-actions">
          <button
            type="button"
            className="icon-button"
            onClick={() => void loadDiscounts()}
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
            Tambah Diskon
          </button>
        </div>
      </div>

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
          <button type="button" onClick={() => void loadDiscounts()}>
            Coba lagi
          </button>
        </div>
      )}

      <div className="mini-stat-grid customer-stat-grid">
        <div className="mini-stat">
          <span>Total Program Diskon</span>
          <strong>{loading ? '—' : totalDiscounts.toLocaleString('id-ID')}</strong>
          <small>Seluruh promo terdaftar</small>
        </div>

        <div className="mini-stat">
          <span>Aktif Berlaku Hari Ini</span>
          <strong>{loading ? '—' : activeTodayCount.toLocaleString('id-ID')}</strong>
          <small>Dapat langsung dipilih di Kasir POS</small>
        </div>

        <div className="mini-stat">
          <span>Komposisi Tipe Diskon</span>
          <strong>
            {loading
              ? '—'
              : `${percentageCount} Persen · ${totalDiscounts - percentageCount} Nominal`}
          </strong>
          <small>Persentase (%) vs Potongan Tetap (Rp)</small>
        </div>
      </div>

      <section className="panel customer-panel">
        <div className="panel-heading customer-panel-heading">
          <div>
            <h2>Daftar Promo & Diskon</h2>
            <p>Diskon berstatus Aktif Berlaku akan muncul otomatis di halaman Kasir.</p>
          </div>

          <span className="count-pill">{filteredDiscounts.length} promo</span>
        </div>

        <div className="customer-toolbar">
          <span>
            {search
              ? `Hasil pencarian: "${search}"`
              : 'Daftar seluruh program promo & diskon toko'}
          </span>
        </div>

        <div className="table-scroll">
          <table className="data-table customer-table">
            <thead>
              <tr>
                <th>No.</th>
                <th>Nama Promo</th>
                <th>Tipe</th>
                <th>Nilai Potongan</th>
                <th>Periode Berlaku</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>

            <tbody>
              {loading ? (
                <tr>
                  <td colSpan="7" className="table-message">
                    Memuat data diskon...
                  </td>
                </tr>
              ) : filteredDiscounts.length > 0 ? (
                filteredDiscounts.map((discount, index) => {
                  const status = getDiscountStatus(discount, today)
                  const isPercent = discount.type === 'percentage'

                  return (
                    <tr key={discount.id}>
                      <td>{index + 1}</td>

                      <td className="strong-cell">{discount.name}</td>

                      <td>
                        {isPercent ? 'Persentase (%)' : 'Potongan Tetap (Rp)'}
                      </td>

                      <td className="money-cell">
                        {isPercent
                          ? `${Number(discount.value)}%`
                          : formatCurrency(discount.value)}
                      </td>

                      <td>
                        {formatDate(discount.start_date)} s.d. {formatDate(discount.end_date)}
                      </td>

                      <td>
                        <span className={`status-pill ${status.className}`}>
                          {status.label}
                        </span>
                      </td>

                      <td>
                        <div className="customer-actions">
                          <button
                            type="button"
                            className="small-action"
                            onClick={() => openEditModal(discount)}
                          >
                            Edit
                          </button>

                          <button
                            type="button"
                            className="small-action"
                            onClick={() => void handleToggleActive(discount)}
                          >
                            {discount.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                          </button>

                          <button
                            type="button"
                            className="small-action customer-delete-button"
                            onClick={() => void handleDelete(discount)}
                            disabled={deletingId === discount.id}
                          >
                            {deletingId === discount.id ? 'Menghapus...' : 'Hapus'}
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
                      ? 'Promo/Diskon tidak ditemukan.'
                      : 'Belum ada data promo/diskon. Silakan tambahkan diskon baru.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {formOpen && (
        <div className="modal-backdrop">
          <div className="modal-card">
            <div className="modal-header">
              <div>
                <h2>
                  {editingDiscount ? 'Edit Promo / Diskon' : 'Tambah Promo / Diskon Baru'}
                </h2>
                <p>Atur nama, jenis potongan, dan masa berlaku diskon.</p>
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

            <form className="modal-form" onSubmit={handleSave}>
              {formError && (
                <div className="form-error">
                  <Icon name="warning" size={15} />
                  {formError}
                </div>
              )}

              <label>
                Nama Promo / Diskon
                <input
                  type="text"
                  placeholder="Contoh: Promo Merdeka / Diskon Pelanggan Setia"
                  value={form.name}
                  onChange={(event) => setForm({ ...form, name: event.target.value })}
                  maxLength={255}
                  required
                  autoFocus
                />
              </label>

              <label>
                Tipe Potongan
                <select
                  value={form.type}
                  onChange={(event) => setForm({ ...form, type: event.target.value })}
                >
                  <option value="percentage">Persentase (%)</option>
                  <option value="fixed_amount">Nominal Tetap (Rp)</option>
                </select>
              </label>

              <label>
                {form.type === 'percentage'
                  ? 'Besar Persentase Diskon (%)'
                  : 'Nominal Potongan Harga (Rp)'}
                <input
                  type="number"
                  min="1"
                  max={form.type === 'percentage' ? '100' : undefined}
                  step="any"
                  placeholder={form.type === 'percentage' ? 'Contoh: 10' : 'Contoh: 5000'}
                  value={form.value}
                  onChange={(event) => setForm({ ...form, value: event.target.value })}
                  required
                />
              </label>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                <label>
                  Tanggal Mulai
                  <input
                    type="date"
                    value={form.start_date}
                    onChange={(event) => setForm({ ...form, start_date: event.target.value })}
                    required
                  />
                </label>

                <label>
                  Tanggal Berakhir
                  <input
                    type="date"
                    min={form.start_date}
                    value={form.end_date}
                    onChange={(event) => setForm({ ...form, end_date: event.target.value })}
                    required
                  />
                </label>
              </div>

              <label style={{ display: 'flex', alignItems: 'center', gap: '8px', flexDirection: 'row' }}>
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
                />
                <span>Aktifkan promo ini agar dapat digunakan di Kasir</span>
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
                  {saving
                    ? 'Menyimpan...'
                    : editingDiscount
                    ? 'Simpan Perubahan'
                    : 'Simpan Diskon'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}