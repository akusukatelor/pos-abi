import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, unwrapList } from '../api/client.js'
import Icon from '../components/Icon.jsx'
import { formatCurrency, formatDate, firstDayOfMonthInput, paymentLabel, todayInput } from '../utils/format.js'

function KpiCard({ label, value, note, icon, tone = 'green', onClick }) {
  return (
    <button type="button" className={`kpi-card ${onClick ? 'kpi-clickable' : ''}`} onClick={onClick}>
      <div className={`kpi-icon kpi-${tone}`}><Icon name={icon} size={20} /></div>
      <div className="kpi-copy">
        <span>{label}</span>
        <strong>{value}</strong>
        {note && <small>{note}</small>}
      </div>
      <span className="kpi-arrow"><Icon name="arrowUp" size={15} /></span>
    </button>
  )
}

function TrendChart({ rows }) {
  const width = 620
  const height = 190
  const left = 42
  const right = 12
  const top = 12
  const bottom = 32
  const values = rows.map((row) => Number(row.omzet || 0))
  const max = Math.max(1, ...values)
  const chartWidth = width - left - right
  const chartHeight = height - top - bottom
  const points = rows.map((row, index) => {
    const x = rows.length <= 1 ? left + chartWidth / 2 : left + (index / (rows.length - 1)) * chartWidth
    const y = top + chartHeight - (Number(row.omzet || 0) / max) * chartHeight
    return { x, y, row }
  })
  const path = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ')
  const labelIndexes = rows.length <= 6
    ? rows.map((_, index) => index)
    : [0, Math.floor((rows.length - 1) / 3), Math.floor(2 * (rows.length - 1) / 3), rows.length - 1]

  if (!rows.length) {
    return <div className="chart-empty"><Icon name="chart" size={24} /><span>Belum ada penjualan pada periode ini.</span></div>
  }

  return (
    <div className="chart-wrap">
      <svg viewBox={`0 0 ${width} ${height}`} className="line-chart" role="img" aria-label="Grafik omzet harian">
        {[0, 1, 2, 3].map((tick) => {
          const y = top + (chartHeight / 3) * tick
          return <g key={tick}><line x1={left} x2={width - right} y1={y} y2={y} className="chart-grid" /><text x={left - 9} y={y + 4} textAnchor="end" className="chart-axis-label">{new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(max * (1 - tick / 3))}</text></g>
        })}
        <path d={path} className="chart-line" />
        {points.map((point, index) => <circle key={`${point.row.date}-${index}`} cx={point.x} cy={point.y} r="4" className="chart-point"><title>{formatDate(point.row.date)} · {formatCurrency(point.row.omzet)}</title></circle>)}
        {labelIndexes.map((index) => <text key={index} x={points[index].x} y={height - 8} textAnchor="middle" className="chart-axis-label">{new Date(`${rows[index].date}T00:00:00`).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })}</text>)}
      </svg>
    </div>
  )
}

function PaymentChart({ rows }) {
  const cash = Number(rows.find((row) => row.payment_method === 'cash')?.omzet || 0)
  const qris = Number(rows.find((row) => row.payment_method === 'qris_gopay')?.omzet || 0)
  const max = Math.max(cash, qris, 1)
  const total = cash + qris
  const barHeight = 102
  return (
    <div className="payment-chart">
      <div className="chart-legend">
        <span><i className="legend-swatch legend-cash" /> Tunai</span>
        <span><i className="legend-swatch legend-qris" /> QRIS GoPay</span>
      </div>
      {total === 0 ? <div className="chart-empty chart-empty-small">Belum ada transaksi berbayar.</div> : (
        <div className="payment-bars">
          {[{ label: 'Tunai', value: cash, className: 'cash-bar' }, { label: 'QRIS', value: qris, className: 'qris-bar' }].map((item) => (
            <div className="payment-bar-group" key={item.label}>
              <div className="payment-bar-value">{formatCurrency(item.value)}</div>
              <div className="payment-bar-track"><div className={`payment-bar ${item.className}`} style={{ height: `${Math.max(3, item.value / max * barHeight)}px` }} /></div>
              <span>{item.label}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function transactionRows(transactions, services) {
  const salesRows = transactions.map((item) => ({
    id: item.id,
    code: item.invoice_number || `TRX-${item.id}`,
    date: item.created_at,
    type: 'Barang',
    detail: item.details?.map((detail) => detail.product?.name).filter(Boolean).join(', ') || 'Penjualan barang',
    method: paymentLabel(item.payment_method),
    total: Number(item.total_price || 0),
    status: item.payment_status === 'paid' ? 'Sukses' : 'Belum lunas',
    sortDate: new Date(item.created_at || 0).getTime(),
  }))
  const serviceRows = services.map((item) => ({
    id: `service-${item.id}`,
    code: item.reference_number || `JASA-${item.id}`,
    date: item.service_at,
    type: 'Jasa',
    detail: item.service_type?.name || 'Layanan digital',
    method: 'Layanan digital',
    total: item.margin_amount == null ? null : Number(item.margin_amount),
    status: item.status === 'completed' ? 'Selesai' : 'Dibatalkan',
    sortDate: new Date(item.service_at || 0).getTime(),
  }))
  return [...salesRows, ...serviceRows].sort((a, b) => b.sortDate - a.sortDate).slice(0, 7)
}

export default function DashboardPage({ search = '', onNavigate }) {
  const [dashboard, setDashboard] = useState(null)
  const [transactions, setTransactions] = useState([])
  const [services, setServices] = useState([])
  const [from, setFrom] = useState(firstDayOfMonthInput())
  const [to, setTo] = useState(todayInput())
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [dashboardResponse, transactionResponse, serviceResponse] = await Promise.all([
        api.get(`/dashboard/owner?from=${from}&to=${to}`),
        api.get('/transactions'),
        api.get('/service-transactions'),
      ])
      setDashboard(dashboardResponse.data)
      setTransactions(unwrapList(transactionResponse))
      setServices(unwrapList(serviceResponse))
    } catch (err) {
      setError(err.message || 'Data dashboard tidak dapat dimuat.')
    } finally {
      setLoading(false)
    }
  }, [from, to])

  useEffect(() => { load() }, [load])

  const data = dashboard || {}
  const sales = data.sales || {}
  const inventory = data.inventory || {}
  const serviceData = data.services || {}
  const recent = useMemo(() => transactionRows(transactions, services).filter((row) => {
    const term = search.trim().toLowerCase()
    return !term || [row.code, row.detail, row.type, row.method].join(' ').toLowerCase().includes(term)
  }), [transactions, services, search])

  return (
    <div className="dashboard-page">
      <div className="page-heading-row">
        <div>
          <h1>Dashboard</h1>
          <p>Ringkasan aktivitas operasional toko Anda.</p>
        </div>
        <div className="heading-actions">
          <label className="date-filter"><Icon name="calendar" size={15} /><input type="date" value={from} max={to} onChange={(event) => setFrom(event.target.value)} /></label>
          <span className="date-separator">s.d.</span>
          <label className="date-filter"><Icon name="calendar" size={15} /><input type="date" value={to} min={from} max={todayInput()} onChange={(event) => setTo(event.target.value)} /></label>
          <button type="button" className="icon-button refresh-button" onClick={load} title="Muat ulang"><Icon name="refresh" size={17} /></button>
        </div>
      </div>

      {error && <div className="inline-alert"><Icon name="warning" size={17} />{error}<button type="button" onClick={load}>Coba lagi</button></div>}

      <div className="kpi-grid">
        <KpiCard label="Total Penjualan Barang" value={loading ? 'Memuat…' : formatCurrency(sales.omzet_barang)} note={`${sales.transaction_count || 0} transaksi berbayar`} icon="wallet" tone="green" />
        <KpiCard label="Total Transaksi" value={loading ? '—' : (sales.transaction_count || 0).toLocaleString('id-ID')} note={`${serviceData.completed_count || 0} transaksi layanan selesai`} icon="receipt" tone="blue" />
        <KpiCard label="Stok Perlu Ditinjau" value={loading ? '—' : `${inventory.needs_restock_count || 0} item`} note={`${inventory.insufficient_data_count || 0} item belum cukup data`} icon="box" tone="orange" onClick={() => onNavigate('settings')} />
        <KpiCard label="Margin Layanan Terverifikasi" value={loading ? 'Memuat…' : formatCurrency(serviceData.verified_margin_total)} note={`${serviceData.verified_margin_count || 0} layanan diverifikasi`} icon="chart" tone="purple" onClick={() => onNavigate('services')} />
      </div>

      <div className="dashboard-chart-grid">
        <section className="panel chart-panel">
          <div className="panel-heading">
            <div><h2>Tren Penjualan Harian (Barang)</h2><p>Omzet transaksi berstatus lunas pada periode terpilih.</p></div>
          </div>
          {loading ? <div className="skeleton-chart" /> : <TrendChart rows={data.sales_trend || []} />}
        </section>
        <section className="panel chart-panel">
          <div className="panel-heading">
            <div><h2>Metode Pembayaran</h2><p>Perbandingan omzet tunai dan QRIS GoPay.</p></div>
          </div>
          {loading ? <div className="skeleton-chart" /> : <PaymentChart rows={sales.payment_breakdown || []} />}
        </section>
      </div>

      <section className="panel recent-panel">
        <div className="panel-heading recent-heading">
          <div><h2>Transaksi Terkini (Penjualan &amp; Jasa)</h2><p>Riwayat terbaru dari aktivitas barang dan layanan digital.</p></div>
          <button type="button" className="text-button" onClick={() => onNavigate('reports')}>Lihat laporan <span>→</span></button>
        </div>
        <div className="table-scroll">
          <table className="data-table recent-table">
            <thead><tr><th>ID Transaksi</th><th>Tanggal &amp; Waktu</th><th>Tipe</th><th>Item / Layanan</th><th>Metode</th><th>Total / Margin</th><th>Status</th></tr></thead>
            <tbody>
              {loading ? <tr><td colSpan="7" className="table-message">Memuat transaksi…</td></tr> : recent.length ? recent.map((row) => (
                <tr key={`${row.type}-${row.id}`}>
                  <td className="mono-cell">{row.code}</td>
                  <td>{formatDate(row.date, true)}</td>
                  <td><span className={`type-pill ${row.type === 'Jasa' ? 'type-service' : 'type-product'}`}>{row.type}</span></td>
                  <td className="item-cell" title={row.detail}>{row.detail}</td>
                  <td>{row.method}</td>
                  <td className="money-cell">{row.type === 'Jasa' && row.total == null ? 'Belum diketahui' : formatCurrency(row.total)}</td>
                  <td><span className={`status-pill ${row.status === 'Sukses' || row.status === 'Selesai' ? 'status-success' : 'status-neutral'}`}>{row.status}</span></td>
                </tr>
              )) : <tr><td colSpan="7" className="table-message">Belum ada transaksi pada akun ini.</td></tr>}
            </tbody>
          </table>
        </div>
        <div className="table-footnote"><Icon name="info" size={13} /> Nilai pada transaksi jasa menunjukkan margin tercatat, bukan nominal pokok layanan.</div>
      </section>
    </div>
  )
}
