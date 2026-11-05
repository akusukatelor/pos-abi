import { useState } from 'react'
import { api } from '../api/client.js'
import Icon from './Icon.jsx'

export default function AssistantWidget() {
  const [open, setOpen] = useState(false)
  const [question, setQuestion] = useState('')
  const [answer, setAnswer] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const ask = async (event) => {
    event.preventDefault()
    const text = question.trim()
    if (!text || loading) return

    setLoading(true)
    setError('')
    setAnswer('')
    try {
      const response = await api.post('/assistant/chat', { question: text })
      setAnswer(response.data?.answer || 'Belum ada jawaban dari asisten.')
      setQuestion('')
    } catch (err) {
      setError(err.message || 'Asisten tidak dapat menjawab saat ini.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="assistant-widget">
      {open && (
        <section className="assistant-panel" aria-label="Asisten toko">
          <div className="assistant-header">
            <div><strong>Asisten Toko</strong><span>Bantuan stok dan restok</span></div>
            <button type="button" className="icon-button" onClick={() => setOpen(false)} aria-label="Tutup asisten"><Icon name="close" size={15} /></button>
          </div>
          <p className="assistant-message">{answer || 'Tanyakan stok produk atau rekomendasi restok.'}</p>
          {error && <div className="assistant-error">{error}</div>}
          <form className="assistant-form" onSubmit={ask}>
            <input value={question} onChange={(event) => setQuestion(event.target.value)} placeholder="Tulis pertanyaan..." aria-label="Pertanyaan untuk asisten" maxLength="500" />
            <button type="submit" className="primary-button" disabled={loading || !question.trim()}>{loading ? '…' : 'Kirim'}</button>
          </form>
        </section>
      )}
      <button type="button" className="assistant-toggle" onClick={() => setOpen((value) => !value)} aria-expanded={open}>
        <Icon name="info" size={17} /> Asisten
      </button>
    </div>
  )
}
