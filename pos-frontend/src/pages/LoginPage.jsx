import { useState } from 'react'
import Icon from '../components/Icon.jsx'

export default function LoginPage({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    setError('')
    setLoading(true)
    try {
      await onLogin(email.trim(), password)
    } catch (err) {
      setError(err.message || 'Login gagal. Periksa kembali email dan password.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-screen">
      <div className="login-decoration login-decoration-one" />
      <div className="login-decoration login-decoration-two" />
      <section className="login-card">
        <div className="login-brand">
          <div className="brand-mark brand-mark-large">SR</div>
          <div>
            <span>Toko Kelontong &amp; Jasa Digital</span>
            <strong>LARIS - ABICELL</strong>
          </div>
        </div>
        <div className="login-heading">
          <span className="eyebrow">SELAMAT DATANG</span>
          <h1>Masuk ke sistem</h1>
          <p>Kelola penjualan, persediaan, dan layanan toko dalam satu tempat.</p>
        </div>
        <form onSubmit={submit} className="login-form">
          <label>
            Email
            <span className="field-with-icon"><Icon name="user" size={17} /><input type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="nama@email.com" required /></span>
          </label>
          <label>
            Password
            <span className="field-with-icon"><Icon name="lock" size={17} /><input type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Masukkan password" required /><button type="button" className="show-password" onClick={() => setShowPassword((value) => !value)}>{showPassword ? 'Sembunyikan' : 'Lihat'}</button></span>
          </label>
          {error && <div className="form-error"><Icon name="warning" size={16} />{error}</div>}
          <button className="primary-button login-submit" type="submit" disabled={loading}>
            {loading ? <span className="button-spinner" /> : null}
            {loading ? 'Memeriksa...' : 'Masuk ke Sistem'}
          </button>
        </form>
        <div className="login-footnote"><Icon name="info" size={14} /> Gunakan akun Owner atau Kasir yang telah terdaftar.</div>
      </section>
      <div className="login-footer">© {new Date().getFullYear()} LARIS - ABICELL · Sistem POS &amp; Inventory</div>
    </div>
  )
}
