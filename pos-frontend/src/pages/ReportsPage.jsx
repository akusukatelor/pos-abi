import { useCallback, useEffect, useState } from 'react'
import { api, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import { formatCurrency, formatDate, firstDayOfMonthInput, todayInput } from '../utils/format.js'

export default function ReportsPage() {
  const [from, setFrom] = useState(firstDayOfMonthInput())
  const [to, setTo] = useState(todayInput())
  const [summary, setSummary] = useState(null)
  const [sales, setSales] = useState(null)
  const [purchases, setPurchases] = useState(null)
  const [services, setServices] = useState(null)
  const [inventory, setInventory] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    const params = `from=${from}&to=${to}`
    try {
      const [summaryRes, salesRes, purchasesRes, servicesRes, inventoryRes] = await Promise.all([
        api.get(`/reports/summary?${params}`),
        api.get(`/reports/sales?${params}`),
        api.get(`/reports/purchases?${params}`),
        api.get(`/reports/services?${params}`),
        api.get('/reports/inventory'),
      ])
      setSummary(summaryRes.data)
      setSales(salesRes)
      setPurchases(purchasesRes)
      setServices(servicesRes)
      setInventory(inventoryRes)
    } catch (err) {
      setError(err.message || 'Laporan tidak dapat dimuat.')
    } finally {
      setLoading(false)
    }
  }, [from, to])

  useEffect(() => { load() }, [load])

  const saleRows = unwrapList(sales)
  const purchaseRows = unwrapList(purchases)
  const serviceRows = unwrapList(services)

  return (
    <div className="module-page">
      <div className="page-heading-row">
        <div><h1>Reports</h1><p>Laporan penjualan, pembelian, persediaan, dan jasa digital.</p></div>
        <div className="heading-actions"><label className="date-filter"><Icon name="calendar" size={15} /><input type="date" value={from} max={to} onChange={(event) => setFrom(event.target.value)} /></label><span className="date-separator">s.d.</span><label className="date-filter"><Icon name="calendar" size={15} /><input type="date" value={to} min={from} max={todayInput()} onChange={(event) => setTo(event.target.value)} /></label><button type="button" className="icon-button" onClick={load}><Icon name="refresh" size={17} /></button></div>
      </div>
      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}</div>}
      <div className="report-stat-grid">
        <div className="report-stat"><span>Omzet barang</span><strong>{loading ? '—' : formatCurrency(summary?.sales?.omzet_barang)}</strong><small>{summary?.sales?.transaction_count || 0} transaksi lunas</small></div>
        <div className="report-stat"><span>Pembelian supplier</span><strong>{loading ? '—' : formatCurrency(summary?.purchases?.total_pembelian)}</strong><small>{summary?.purchases?.receipt_count || 0} penerimaan terkonfirmasi</small></div>
        <div className="report-stat"><span>Margin jasa terverifikasi</span><strong>{loading ? '—' : formatCurrency(summary?.services?.verified_margin_total)}</strong><small>{summary?.services?.margin_not_verified_count || 0} belum diverifikasi</small></div>
        <div className="report-stat"><span>Produk aktif</span><strong>{loading ? '—' : (summary?.inventory?.active_product_count || 0)}</strong><small>{inventory?.summary?.zero_stock_product_count || 0} produk stok kosong</small></div>
      </div>
      <div className="report-note"><Icon name="info" size={16} /><span>Laporan ini belum menghitung laba kotor karena metode HPP dan snapshot harga pokok historis belum ditetapkan. Nominal pokok PPOB/BRILink bukan pendapatan toko.</span></div>
      <section className="panel module-panel">
        <div className="panel-heading"><div><h2>Penjualan Barang</h2><p>Transaksi lunas pada periode {formatDate(from)} – {formatDate(to)}.</p></div></div>
        <div className="report-payment-breakdown">{(sales?.payment_breakdown || []).map((item) => <div key={item.payment_method}><span>{item.payment_method === 'qris_gopay' ? 'QRIS GoPay' : item.payment_method === 'cash' ? 'Tunai' : item.payment_method}</span><strong>{formatCurrency(item.omzet)}</strong><small>{item.transaction_count} transaksi</small></div>)}</div>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>Invoice</th><th>Tanggal</th><th>Kasir</th><th>Metode</th><th>Total</th></tr></thead><tbody>{loading ? <tr><td colSpan="5" className="table-message">Memuat…</td></tr> : saleRows.length ? saleRows.map((row) => <tr key={row.id}><td className="mono-cell">{row.invoice_number}</td><td>{formatDate(row.created_at, true)}</td><td>{row.user?.name || '—'}</td><td>{row.payment_method === 'qris_gopay' ? 'QRIS GoPay' : 'Tunai'}</td><td className="money-cell">{formatCurrency(row.total_price)}</td></tr>) : <tr><td colSpan="5" className="table-message">Belum ada penjualan pada periode ini.</td></tr>}</tbody></table></div>
      </section>
      <div className="report-two-column">
        <section className="panel module-panel">
          <div className="panel-heading"><div><h2>Pembelian Supplier</h2><p>Penerimaan terkonfirmasi.</p></div></div>
          <div className="table-scroll"><table className="data-table"><thead><tr><th>Supplier</th><th>Tanggal</th><th>Total</th></tr></thead><tbody>{loading ? <tr><td colSpan="3" className="table-message">Memuat…</td></tr> : purchaseRows.length ? purchaseRows.map((row) => <tr key={row.id}><td>{row.supplier?.name || '—'}</td><td>{formatDate(row.received_at)}</td><td>{formatCurrency(row.total_amount)}</td></tr>) : <tr><td colSpan="3" className="table-message">Belum ada pembelian.</td></tr>}</tbody></table></div>
        </section>
        <section className="panel module-panel">
          <div className="panel-heading"><div><h2>Transaksi Jasa</h2><p>Margin layanan dan status verifikasi.</p></div></div>
          <div className="table-scroll"><table className="data-table"><thead><tr><th>Layanan</th><th>Margin</th><th>Status</th></tr></thead><tbody>{loading ? <tr><td colSpan="3" className="table-message">Memuat…</td></tr> : serviceRows.length ? serviceRows.map((row) => <tr key={row.id}><td>{row.service_type?.name || '—'}</td><td>{row.margin_amount == null ? '—' : formatCurrency(row.margin_amount)}</td><td><span className={`status-pill ${row.margin_verified_by ? 'status-success' : 'status-warning'}`}>{row.margin_verified_by ? 'Terverifikasi' : 'Belum'}</span></td></tr>) : <tr><td colSpan="3" className="table-message">Belum ada transaksi jasa.</td></tr>}</tbody></table></div>
        </section>
      </div>
    </div>
  )
}
