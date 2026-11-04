import Icon from './Icon.jsx'

export default function Modal({ title, subtitle, onClose, children, wide = false, className = '' }) {
  const backdropClass = ['modal-backdrop', className && `${className}-backdrop`].filter(Boolean).join(' ')
  const cardClass = ['modal-card', wide && 'modal-wide', className && `${className}-card`].filter(Boolean).join(' ')

  return (
    <div className={backdropClass} role="presentation" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose()
    }}>
      <section className={cardClass} role="dialog" aria-modal="true" aria-label={title}>
        <header className="modal-header">
          <div>
            <h2>{title}</h2>
            {subtitle && <p>{subtitle}</p>}
          </div>
          <button type="button" className="icon-button" onClick={onClose} aria-label="Tutup"><Icon name="close" size={19} /></button>
        </header>
        {children}
      </section>
    </div>
  )
}
