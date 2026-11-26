import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client.js'
import Icon from './Icon.jsx'

const DEFAULT_PROMPTS = [
  'Produk stok habis?',
  'Rekomendasi restok',
  'Tugas menunggu verifikasi',
  'Ringkasan operasional',
]

function nowTime() {
  return new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

export default function AssistantWidget() {
  const [open, setOpen] = useState(false)
  const [question, setQuestion] = useState('')
  const [messages, setMessages] = useState([
    {
      id: 'init',
      role: 'assistant',
      text: 'Halo! Tanyakan sisa stok produk tertentu, daftar barang perlu restok, atau status dokumen yang menunggu verifikasi.',
      time: nowTime(),
    },
  ])
  const [suggestions, setSuggestions] = useState(DEFAULT_PROMPTS)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const bottomRef = useRef(null)

  useEffect(() => {
    if (open) {
      bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
    }
  }, [messages, open, loading])

  const sendQuestion = async (rawText) => {
    const text = rawText.trim()
    if (!text || loading) return

    setLoading(true)
    setError('')
    setQuestion('')
    setMessages((prev) => [
      ...prev,
      { id: `u-${Date.now()}`, role: 'user', text, time: nowTime() },
    ])

    try {
      const response = await api.post('/assistant/chat', { question: text })
      const answer = response.data?.answer || 'Belum ada jawaban dari asisten.'
      if (Array.isArray(response.data?.suggestions) && response.data.suggestions.length) {
        setSuggestions(response.data.suggestions)
      }
      setMessages((prev) => [
        ...prev,
        { id: `a-${Date.now()}`, role: 'assistant', text: answer, time: nowTime() },
      ])
    } catch (err) {
      setError(err.message || 'Asisten tidak dapat menjawab saat ini.')
    } finally {
      setLoading(false)
    }
  }

  const ask = (event) => {
    event.preventDefault()
    void sendQuestion(question)
  }

  const resetChat = () => {
    setError('')
    setSuggestions(DEFAULT_PROMPTS)
    setMessages([
      {
        id: `init-${Date.now()}`,
        role: 'assistant',
        text: 'Percakapan direset. Silakan pilih pertanyaan cepat di bawah atau ketik nama produk.',
        time: nowTime(),
      },
    ])
  }

  return (
    <div className="assistant-widget">
      {open && (
        <section className="assistant-panel" aria-label="Asisten toko" style={{ width: 370, maxWidth: 'calc(100vw - 32px)' }}>
          <div className="assistant-header">
            <div>
              <strong>SIRA</strong>
              <span>Smart Intellegent Retail Assistant</span>
            </div>
            <div style={{ display: 'flex', gap: 4 }}>
              <button type="button" className="icon-button" onClick={resetChat} title="Bersihkan chat" aria-label="Bersihkan chat">
                <Icon name="refresh" size={14} />
              </button>
              <button type="button" className="icon-button" onClick={() => setOpen(false)} aria-label="Tutup asisten">
                <Icon name="close" size={15} />
              </button>
            </div>
          </div>

          <div
            style={{
              maxHeight: 280,
              overflowY: 'auto',
              display: 'flex',
              flexDirection: 'column',
              gap: 8,
              padding: '4px 2px',
            }}
          >
            {messages.map((msg) => (
              <div
                key={msg.id}
                style={{
                  alignSelf: msg.role === 'user' ? 'flex-end' : 'flex-start',
                  maxWidth: '88%',
                  background: msg.role === 'user' ? '#1e293b' : '#f8fafc',
                  color: msg.role === 'user' ? '#ffffff' : '#0f172a',
                  border: msg.role === 'user' ? 'none' : '1px solid #e2e8f0',
                  borderRadius: 12,
                  padding: '8px 11px',
                  fontSize: 13,
                  lineHeight: 1.45,
                  whiteSpace: 'pre-line',
                }}
              >
                <div>{msg.text}</div>
                <div style={{ fontSize: 10, opacity: 0.65, marginTop: 4, textAlign: 'right' }}>{msg.time}</div>
              </div>
            ))}
            {loading && (
              <div style={{ fontSize: 12, color: '#64748b', padding: '2px 4px' }}>Memeriksa data toko…</div>
            )}
            <div ref={bottomRef} />
          </div>

          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 4 }}>
            {suggestions.map((chip) => (
              <button
                key={chip}
                type="button"
                disabled={loading}
                onClick={() => void sendQuestion(chip)}
                style={{
                  fontSize: 11.5,
                  padding: '4px 9px',
                  borderRadius: 999,
                  border: '1px solid #cbd5e1',
                  background: '#ffffff',
                  color: '#334155',
                  cursor: 'pointer',
                }}
              >
                {chip}
              </button>
            ))}
          </div>

          {error && <div className="assistant-error">{error}</div>}

          <form className="assistant-form" onSubmit={ask}>
            <input
              value={question}
              onChange={(event) => setQuestion(event.target.value)}
              placeholder="Contoh: cek stok beras / restok..."
              aria-label="Pertanyaan untuk asisten"
              maxLength="500"
            />
            <button type="submit" className="primary-button" disabled={loading || !question.trim()}>
              {loading ? '…' : 'Kirim'}
            </button>
          </form>
        </section>
      )}

      <button type="button" className="assistant-toggle" onClick={() => setOpen((value) => !value)} aria-expanded={open}>
        <Icon name="info" size={17} /> Asisten
      </button>
    </div>
  )
}