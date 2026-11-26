import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client.js'

const currency = (v) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(v) || 0)

const isActiveOrder = (status) => ['pending', 'processing', 'ready'].includes(status)

const getStatusLabel = (order) => {
  if (order.status === 'ready') {
    return order.payment_method === 'qris_gopay'
      ? 'Siap Diambil (Verifikasi QRIS)'
      : 'Siap Diambil (Belum Bayar)'
  }
  return {
    pending: 'Menunggu Diproses',
    processing: 'Sedang Disiapkan',
    completed: 'Selesai / Lunas',
    cancelled: 'Dibatalkan',
  }[order.status] || order.status
}

export default function OnlineOrdersPage() {
  const [orders, setOrders] = useState([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const refresh = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get(`/admin/online-orders?page=${page}`)
      setOrders(response.data?.data || [])
      setLastPage(response.data?.last_page || 1)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }, [page])

  useEffect(() => {
    void Promise.resolve().then(refresh)
  }, [refresh])

  const changeStatus = async (order, nextStatus) => {
    setSaving(order.id)
    setError('')
    setNotice('')
    try {
      const res = await api.post(`/admin/online-orders/${order.id}/status`, {
        status: nextStatus,
      })
      setNotice(res.message || 'Status pesanan berhasil diperbarui.')
      await refresh()
    } catch (err) {
      setError(err.message)
    } finally {
      setSaving(null)
    }
  }

  const complete = async (order) => {
    const isQris = order.payment_method === 'qris_gopay'
    const confirmMessage = isQris
      ? `Pesanan ${order.order_number} (QRIS GoPay): pastikan dana sebesar ${currency(order.total_amount)} sudah masuk di GoPay Merchant dan barang diserahkan. Selesaikan sekarang?`
      : `Pesanan ${order.order_number} (Tunai): pastikan pembayaran TUNAI sebesar ${currency(order.total_amount)} benar-benar diterima dan barang diserahkan. Proses sekarang?`

    if (!window.confirm(confirmMessage)) {
      return
    }

    setSaving(order.id)
    setError('')
    setNotice('')
    try {
      const payload = isQris
        ? {
            qris_verified_confirmed: true,
            goods_handed_over_confirmed: true,
            payment_reference: order.payment_reference || null,
          }
        : {
            cash_received_confirmed: true,
            goods_handed_over_confirmed: true,
          }

      await api.post(`/admin/online-orders/${order.id}/complete`, payload)
      setNotice(
        isQris
          ? 'Pesanan QRIS terverifikasi dan selesai; transaksi, stok, dan laporan berhasil diperbarui.'
          : 'Pesanan tunai selesai; transaksi, stok, dan ledger berhasil diperbarui.'
      )
      await refresh()
    } catch (err) {
      setError(err.message)
    } finally {
      setSaving(null)
    }
  }

  const cancel = async (order) => {
    const reason = window.prompt('Alasan pembatalan (wajib, minimal 5 karakter):')
    if (reason === null) return
    if (reason.trim().length < 5) {
      setError('Alasan pembatalan minimal 5 karakter.')
      return
    }
    setSaving(order.id)
    setError('')
    setNotice('')
    try {
      await api.post(`/admin/online-orders/${order.id}/cancel`, {
        reason: reason.trim(),
      })
      setNotice('Pesanan dibatalkan. Stok dan kas tidak berubah.')
      await refresh()
    } catch (err) {
      setError(err.message)
    } finally {
      setSaving(null)
    }
  }

  return (
    <section className="panel" style={{ padding: 24 }}>
      <div className="page-heading-row">
        <div>
          <h1>Pesanan Online</h1>
          <p>Alur pesanan: Menunggu → Sedang Disiapkan → Siap Diambil → Verifikasi Pembayaran (Tunai / QRIS) &amp; Selesaikan.</p>
        </div>
        <button type="button" className="outline-button" onClick={() => void refresh()} disabled={loading}>
          {loading ? 'Memuat...' : 'Perbarui'}
        </button>
      </div>

      {notice && <p className="customer-notice">{notice}</p>}
      {error && <p className="inline-alert" role="alert">{error}</p>}

      <div className="table-scroll">
        <table className="data-table">
          <thead>
            <tr>
              <th>Pesanan</th>
              <th>Pelanggan</th>
              <th>Barang</th>
              <th>Total &amp; Pembayaran</th>
              <th>Status</th>
              <th>Aksi Owner</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="6">Memuat...</td></tr>
            ) : orders.length === 0 ? (
              <tr><td colSpan="6">Belum ada pesanan online.</td></tr>
            ) : (
              orders.map((order) => {
                const isQris = order.payment_method === 'qris_gopay'
                return (
                  <tr key={order.id}>
                    <td>
                      <strong>{order.order_number}</strong>
                      <br />
                      <small>{new Date(order.created_at).toLocaleString('id-ID')}</small>
                    </td>
                    <td>
                      {order.customer?.name || '—'}
                      <br />
                      <small>{order.customer?.phone || order.customer_account?.email || '—'}</small>
                    </td>
                    <td>
                      {order.items?.map((item) => (
                        <div key={item.id}>
                          {item.product_name} × {item.quantity}
                        </div>
                      ))}
                    </td>
                    <td>
                      <strong>{currency(order.total_amount)}</strong>
                      <br />
                      <span
                        className={`status-pill ${isQris ? 'status-success' : 'status-neutral'}`}
                        style={{ fontSize: 11, padding: '2px 7px', marginTop: 4, display: 'inline-block' }}
                      >
                        {isQris ? 'QRIS GoPay' : 'Tunai di Toko'}
                      </span>
                      {isQris && order.payment_reference && (
                        <div style={{ fontSize: 11, color: '#475569', marginTop: 3 }}>
                          Ref: {order.payment_reference}
                        </div>
                      )}
                    </td>
                    <td>
                      <span
                        className={`status-pill ${
                          order.status === 'completed'
                            ? 'status-success'
                            : order.status === 'cancelled'
                            ? 'status-neutral'
                            : 'status-warning'
                        }`}
                      >
                        {getStatusLabel(order)}
                      </span>
                    </td>
                    <td>
                      {isActiveOrder(order.status) ? (
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                          {order.status === 'pending' && (
                            <button
                              type="button"
                              className="small-action"
                              disabled={saving === order.id}
                              onClick={() => void changeStatus(order, 'processing')}
                            >
                              Siapkan Pesanan
                            </button>
                          )}
                          {(order.status === 'pending' || order.status === 'processing') && (
                            <button
                              type="button"
                              className="small-action"
                              disabled={saving === order.id}
                              onClick={() => void changeStatus(order, 'ready')}
                            >
                              Tandai Siap Diambil
                            </button>
                          )}
                          <button
                            type="button"
                            className="small-action primary-button"
                            disabled={saving === order.id}
                            onClick={() => void complete(order)}
                          >
                            {isQris ? 'Verifikasi QRIS & Selesaikan' : 'Terima Tunai & Selesaikan'}
                          </button>
                          <button
                            type="button"
                            className="small-action"
                            disabled={saving === order.id}
                            onClick={() => void cancel(order)}
                          >
                            Batalkan
                          </button>
                        </div>
                      ) : order.transaction_id ? (
                        <small>Invoice tercatat #{order.transaction_id}</small>
                      ) : (
                        <small>{order.cancellation_reason || '—'}</small>
                      )}
                    </td>
                  </tr>
                )
              })
            )}
          </tbody>
        </table>
      </div>

      <p style={{ color: '#64748b', fontSize: 12, padding: '15px 0' }}>
        Pesanan yang belum selesai tidak memotong stok dan belum menjadi penerimaan kas. Stok diperiksa ulang saat Owner memverifikasi pembayaran dan menyelesaikan transaksi.
      </p>

      <div style={{ display: 'flex', gap: 12, alignItems: 'center', justifyContent: 'end' }}>
        <button type="button" disabled={page <= 1} onClick={() => setPage((v) => v - 1)}>Sebelumnya</button>
        <span>{page} / {lastPage}</span>
        <button type="button" disabled={page >= lastPage} onClick={() => setPage((v) => v + 1)}>Berikutnya</button>
      </div>
    </section>
  )
}