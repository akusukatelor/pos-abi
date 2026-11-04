export default function Toast({ toast, onClose }) {
  if (!toast) return null
  return (
    <div className={`toast toast-${toast.type || 'success'}`} role="status">
      <span className="toast-mark">{toast.type === 'error' ? '!' : '✓'}</span>
      <span>{toast.message}</span>
      <button type="button" className="toast-close" onClick={onClose} aria-label="Tutup">×</button>
    </div>
  )
}
