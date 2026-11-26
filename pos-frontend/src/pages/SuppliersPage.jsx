import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'

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
  contact_person: '',
  phone: '',
  address: '',
}

export default function SuppliersPage({ search = '', onToast }) {
  const [suppliers, setSuppliers] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const [formOpen, setFormOpen] = useState(false)
  const [editingSupplier, setEditingSupplier] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [formError, setFormError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get('/suppliers')
      setSuppliers(unwrapList(response))
    } catch (err) {
      setError(getErrorMessage(err, 'Data supplier tidak dapat dimuat.'))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(load)
  }, [load])

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase()
    if (!term) return suppliers
    return suppliers.filter((supplier) =>
      [supplier.name, supplier.phone, supplier.contact_person, supplier.address]
        .join(' ')
        .toLowerCase()
        .includes(term)
    )
  }, [suppliers, search])

  const notify = (message) => {
    setNotice(message)
    onToast?.({ type: 'success', message })
  }

  const openCreateModal = () => {
    setEditingSupplier(null)
    setForm(emptyForm)
    setFormError('')
    setFormOpen(true)
  }

  const openEditModal = (supplier) => {
    setEditingSupplier(supplier)
    setForm({
      name: supplier.name || '',
      contact_person: supplier.contact_person || '',
      phone: supplier.phone || '',
      address: supplier.address || '',
    })
    setFormError('')
    setFormOpen(true)
  }

  const handleSave = async (event) => {
    event.preventDefault()
    setFormError('')

    if (!form.name.trim()) {
      setFormError('Nama supplier wajib diisi.')
      return
    }

    setSaving(true)
    try {
      const payload = {
        name: form.name.trim(),
        contact_person: form.contact_person.trim() || null,
        phone: form.phone.trim() || null,
        address: form.address.trim() || null,
      }

      if (editingSupplier) {
        await api.put(`/suppliers/${editingSupplier.id}`, payload)
        notify('Data supplier berhasil diperbarui.')
      } else {
        await api.post('/suppliers', payload)
        notify('Supplier baru berhasil ditambahkan.')
      }

      setFormOpen(false)
      setEditingSupplier(null)
      setForm(emptyForm)
      await load()
    } catch (err) {
      setFormError(getErrorMessage(err, 'Data supplier gagal disimpan.'))
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (supplier) => {
    const confirmed = window.confirm(
      `Yakin ingin menghapus supplier "${supplier.name}"?`
    )
    if (!confirmed) return

    setDeletingId(supplier.id)
    setError('')
    try {
      await api.delete(`/suppliers/${supplier.id}`)
      notify('Supplier berhasil dihapus.')
      await load()
    } catch (err) {
      setError(getErrorMessage(err, 'Supplier gagal dihapus.'))
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div className="module-page">
      <div className="page-heading-row">
        <div>
          <h1>Supplier</h1>
          <p>Daftar pemasok barang untuk operasional toko.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <button type="button" className="outline-button" onClick={load} disabled={loading}>
            <Icon name="refresh" size={15} /> Muat ulang
          </button>
          <button type="button" className="primary-button" onClick={openCreateModal}>
            <span>＋</span> Tambah Supplier
          </button>
        </div>
      </div>

      {notice && (
        <div className="customer-notice">
          <span>✓</span> {notice}
          <button type="button" onClick={() => setNotice('')} aria-label="Tutup pemberitahuan">
            ×
          </button>
        </div>
      )}

      {error && (
        <div className="inline-alert">
          <Icon name="warning" size={17} /> {error}
        </div>
      )}

      <section className="panel module-panel">
        <div className="panel-heading">
          <div>
            <h2>Data Supplier</h2>
            <p>Informasi pemasok yang tersimpan di sistem.</p>
          </div>
          <span className="count-pill">{filtered.length} supplier</span>
        </div>

        <div className="table-scroll">
          <table className="data-table">
            <thead>
              <tr>
                <th>No.</th>
                <th>Nama supplier</th>
                <th>Contact person</th>
                <th>Telepon</th>
                <th>Alamat</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan="6" className="table-message">Memuat supplier…</td>
                </tr>
              ) : filtered.length ? (
                filtered.map((supplier, index) => (
                  <tr key={supplier.id}>
                    <td>{index + 1}</td>
                    <td className="strong-cell">{supplier.name}</td>
                    <td>{supplier.contact_person || '—'}</td>
                    <td>{supplier.phone || '—'}</td>
                    <td>{supplier.address || '—'}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 8 }}>
                        <button
                          type="button"
                          className="small-action"
                          onClick={() => openEditModal(supplier)}
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          className="small-action customer-delete-button"
                          onClick={() => void handleDelete(supplier)}
                          disabled={deletingId === supplier.id}
                        >
                          {deletingId === supplier.id ? 'Menghapus...' : 'Hapus'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan="6" className="table-message">
                    {search.trim() ? 'Supplier tidak ditemukan.' : 'Belum ada data supplier.'}
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
                <h2>{editingSupplier ? 'Edit Supplier' : 'Tambah Supplier Baru'}</h2>
                <p>Lengkapi informasi pemasok barang berikut.</p>
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
                  <Icon name="warning" size={15} /> {formError}
                </div>
              )}

              <label>
                Nama Supplier
                <input
                  type="text"
                  placeholder="Contoh: PT Indomarco Adi Prima"
                  value={form.name}
                  onChange={(event) => setForm({ ...form, name: event.target.value })}
                  maxLength={255}
                  required
                  autoFocus
                />
              </label>

              <label>
                Contact Person <span className="optional-label">Opsional</span>
                <input
                  type="text"
                  placeholder="Nama sales / penanggung jawab"
                  value={form.contact_person}
                  onChange={(event) => setForm({ ...form, contact_person: event.target.value })}
                  maxLength={255}
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
                  placeholder="Masukkan alamat supplier atau gudang"
                  value={form.address}
                  onChange={(event) => setForm({ ...form, address: event.target.value })}
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
                <button type="submit" className="primary-button" disabled={saving}>
                  {saving ? (
                    <>
                      <span className="button-spinner" />
                      Menyimpan...
                    </>
                  ) : editingSupplier ? (
                    'Simpan Perubahan'
                  ) : (
                    'Simpan Supplier'
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}