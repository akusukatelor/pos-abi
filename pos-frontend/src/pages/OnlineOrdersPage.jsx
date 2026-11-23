import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client.js'

const currency = (v) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(v) || 0)

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
    try {
      const response = await api.get(`/admin/online-orders?page=${page}`)
      setOrders(response.data?.data || [])
      setLastPage(response.data?.last_page || 1)
    } catch (err) { setError(err.message) } finally { setLoading(false) }
  }, [page])

  useEffect(() => { void Promise.resolve().then(refresh) }, [refresh])

  const complete = async (order) => {
    if (!window.confirm(`Pesanan ${order.order_number}: pastikan pembayaran TUNAI benar-benar diterima dan barang diserahkan. Proses sekarang?`)) return
    setSaving(order.id)
    setError('')
    try {
      await api.post(`/admin/online-orders/${order.id}/complete`, { cash_received_confirmed: true, goods_handed_over_confirmed: true })
      setNotice('Pesanan selesai; transaksi, stok, dan ledger berhasil diperbarui.')
      await refresh()
    } catch (err) { setError(err.message) } finally { setSaving(null) }
  }

  const cancel = async (order) => {
    const reason = window.prompt('Alasan pembatalan (wajib, minimal 5 karakter):')
    if (reason === null) return
    if (reason.trim().length < 5) { setError('Alasan pembatalan minimal 5 karakter.'); return }
    setSaving(order.id)
    setError('')
    try {
      await api.post(`/admin/online-orders/${order.id}/cancel`, { reason: reason.trim() })
      setNotice('Pesanan dibatalkan. Stok dan kas tidak berubah.')
      await refresh()
    } catch (err) { setError(err.message) } finally { setSaving(null) }
  }

  return <section className="panel" style={{ padding: 24 }}>
    <div className="page-heading-row"><div><h1>Pesanan Online</h1><p>Pesanan pelanggan yang dibayar tunai saat pengambilan di toko.</p></div><button className="outline-button" onClick={() => void refresh()}>Perbarui</button></div>
    {notice && <p className="customer-notice">{notice}</p>}
    {error && <p className="inline-alert" role="alert">{error}</p>}
    <div className="table-scroll"><table className="data-table"><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Barang</th><th>Total</th><th>Status</th><th>Aksi Owner</th></tr></thead><tbody>
      {loading ? <tr><td colSpan="6">Memuat...</td></tr> : orders.length === 0 ? <tr><td colSpan="6">Belum ada pesanan online.</td></tr> : orders.map((order) => <tr key={order.id}>
        <td><strong>{order.order_number}</strong><br /><small>{new Date(order.created_at).toLocaleString('id-ID')}</small></td>
        <td>{order.customer?.name || '—'}<br /><small>{order.customer?.phone || order.customer_account?.email || '—'}</small></td>
        <td>{order.items?.map((item) => <div key={item.id}>{item.product_name} × {item.quantity}</div>)}</td>
        <td>{currency(order.total_amount)}</td>
        <td>{order.status === 'pending' ? 'Menunggu / Belum Bayar' : order.status === 'completed' ? 'Selesai / Lunas' : 'Dibatalkan'}</td>
        <td>{order.status === 'pending' ? <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}><button className="small-action" disabled={saving === order.id} onClick={() => void complete(order)}>Terima Tunai & Selesaikan</button><button className="small-action" disabled={saving === order.id} onClick={() => void cancel(order)}>Batalkan</button></div> : order.transaction_id ? <small>Invoice tercatat #{order.transaction_id}</small> : <small>{order.cancellation_reason || '—'}</small>}</td>
      </tr>)}
    </tbody></table></div>
    <p style={{ color: '#64748b', fontSize: 12, padding: '15px 0' }}>Pesanan pending tidak memotong stok dan belum menjadi penerimaan kas. Stok diperiksa ulang saat Owner menyelesaikan transaksi.</p>
    <div style={{ display: 'flex', gap: 12, alignItems: 'center', justifyContent: 'end' }}><button disabled={page <= 1} onClick={() => setPage((v) => v - 1)}>Sebelumnya</button><span>{page} / {lastPage}</span><button disabled={page >= lastPage} onClick={() => setPage((v) => v + 1)}>Berikutnya</button></div>
  </section>
}
