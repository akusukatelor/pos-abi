import { useCallback, useEffect, useRef, useState } from 'react'
import { askPublicChat, loadPublicFaq } from '../api/publicChat.js'
import './PublicHelpPage.css'

const initialMessages = [{
  id: 'welcome',
  role: 'bot',
  text: 'Halo! Selamat datang di Bantuan Pelanggan LARIS - ABICELL. Saya bisa membantu menjawab pertanyaan umum berdasarkan informasi yang tersedia. Silakan pilih topik atau ketik pertanyaan.',
}]

export default function PublicHelpPage() {
  const [messages, setMessages] = useState(initialMessages)
  const [topics, setTopics] = useState([])
  const [question, setQuestion] = useState('')
  const [loading, setLoading] = useState(false)
  const [faqError, setFaqError] = useState('')
  const [error, setError] = useState('')
  const pendingRef = useRef(false)
  const messageId = useRef(0)
  const bottomRef = useRef(null)

  useEffect(() => {
    document.title = 'Bantuan Pelanggan | LARIS - ABICELL'
    let alive = true

    loadPublicFaq()
      .then((result) => {
        if (alive) setTopics(Array.isArray(result.topics) ? result.topics : [])
      })
      .catch(() => {
        if (alive) setFaqError('Pilihan topik belum dapat dimuat. Kamu tetap bisa menulis pertanyaan.')
      })

    return () => { alive = false }
  }, [])

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' })
  }, [messages, loading])

  const append = useCallback((role, text) => {
    messageId.current += 1
    setMessages((current) => [
      ...current,
      { id: `message-${messageId.current}`, role, text },
    ])
  }, [])

  const send = async (text) => {
    const next = text.trim()
    if (!next || next.length > 300 || pendingRef.current) return

    pendingRef.current = true
    setLoading(true)
    setError('')
    setQuestion('')
    append('user', next)

    try {
      const result = await askPublicChat(next)
      append('bot', result.answer || 'Jawaban untuk topik ini belum tersedia.')
    } catch (err) {
      setError(err.message || 'Pertanyaan belum berhasil dikirim.')
      append('bot', 'Maaf, saya belum bisa menjawab sekarang. Silakan coba lagi atau tanyakan langsung kepada petugas toko.')
    } finally {
      pendingRef.current = false
      setLoading(false)
    }
  }

  const submit = (event) => {
    event.preventDefault()
    void send(question)
  }

  return (
    <div className="public-help-root">
      <header className="public-help-nav">
        <div className="public-help-nav-inner">
          <a className="public-help-brand" href="/?halaman=bantuan" aria-label="Bantuan pelanggan LARIS - ABICELL">
            <img src="/logo_abicell.png" alt="Logo LARIS - ABICELL" />
            <span><strong>LARIS - ABICELL</strong><small>Bantuan pelanggan</small></span>
          </a>
          <a className="public-help-staff-link" href="/?halaman=toko">Lihat Toko Online <span aria-hidden="true">↗</span></a>
        </div>
      </header>

      <main className="public-help-main">
        <section className="public-help-intro">
          <div className="public-help-eyebrow"><span className="public-help-live-dot" /> Pusat informasi pelanggan</div>
          <h1>Ada yang ingin<br /><span>ditanyakan?</span></h1>
          <p>Temukan informasi umum tentang belanja, pembayaran, dan layanan toko melalui asisten berbasis FAQ.</p>
          <div className="public-help-highlights">
            <div><span aria-hidden="true">✓</span> Tidak perlu login</div>
            <div><span aria-hidden="true">✓</span> Jawaban berbasis informasi toko</div>
            <div><span aria-hidden="true">✓</span> Tanpa akses data pribadi</div>
          </div>
          <div className="public-help-scope-note">
            <strong>Informasi penting</strong>
            <p>Halaman ini khusus FAQ. Pesanan online tersedia melalui halaman toko, dengan pembayaran tunai saat pengambilan. Chatbot tidak memproses pesanan atau pembayaran.</p>
          </div>
        </section>

        <section className="public-help-chat" aria-label="Chat bantuan pelanggan">
          <div className="public-help-chat-header">
            <div className="public-help-agent-icon" aria-hidden="true">✦</div>
            <div className="public-help-chat-heading">
              <strong>Asisten Informasi Toko</strong>
              <span>Rule-based FAQ · LARIS - ABICELL</span>
            </div>
            <span className="public-help-chat-badge">Informasi</span>
          </div>

          <div className="public-help-messages" role="log" aria-live="polite" aria-relevant="additions text">
            {messages.map((message) => (
              <div key={message.id} className={`public-help-bubble-row ${message.role === 'user' ? 'public-help-bubble-user' : 'public-help-bubble-bot'}`}>
                {message.role === 'bot' && <span className="public-help-bubble-avatar" aria-hidden="true">✦</span>}
                <div className="public-help-bubble">{message.text}</div>
              </div>
            ))}
            {loading && <div className="public-help-typing" role="status">Mencari jawaban dari FAQ…</div>}
            <div ref={bottomRef} />
          </div>

          <div className="public-help-controls">
            <p className="public-help-control-title">Coba tanyakan:</p>
            <div className="public-help-topics" aria-label="Pilih topik pertanyaan">
              {topics.map((topic) => (
                <button
                  key={topic.id}
                  type="button"
                  disabled={loading}
                  onClick={() => void send(topic.question)}
                >
                  {topic.label}
                </button>
              ))}
            </div>
            {faqError && <p className="public-help-note" role="status">{faqError}</p>}
            {error && <p className="public-help-error" role="alert">{error}</p>}

            <form className="public-help-form" onSubmit={submit}>
              <label htmlFor="public-help-question" className="public-help-sr-only">Pertanyaan pelanggan</label>
              <input
                id="public-help-question"
                value={question}
                onChange={(event) => setQuestion(event.target.value)}
                maxLength={300}
                disabled={loading}
                placeholder="Tulis pertanyaan tentang toko…"
                autoComplete="off"
              />
              <button type="submit" disabled={loading || !question.trim()} aria-label="Kirim pertanyaan">
                <span>Kirim</span> <span aria-hidden="true">→</span>
              </button>
            </form>
            <p className="public-help-footnote">Jawaban terbatas pada FAQ yang tersedia. Untuk informasi spesifik, hubungi petugas toko.</p>
          </div>
        </section>
      </main>

      <footer className="public-help-footer">© {new Date().getFullYear()} LARIS - ABICELL · Bantuan informasi pelanggan</footer>
    </div>
  )
}
