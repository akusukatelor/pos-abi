// Token pelanggan dipisah dari token staf; halaman publik tidak memakai api/client.js.
const BASE = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')
const KEY = 'pos_abi_customer_token'

export const getCustomerToken = () => localStorage.getItem(KEY)
export function saveCustomerToken(token) {
  if (token) localStorage.setItem(KEY, token)
  else localStorage.removeItem(KEY)
}

async function send(path, method = 'GET', body, authenticated = false) {
  const token = authenticated ? getCustomerToken() : null
  const response = await fetch(`${BASE}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    if (authenticated && response.status === 401) saveCustomerToken(null)
    const detail = data.errors ? Object.values(data.errors).flat().join(' ') : ''
    throw new Error(detail || data.message || `Permintaan gagal (${response.status}).`)
  }
  return data
}

export const customerApi = {
  catalog: (search = '', page = 1) => send(`/store/catalog?search=${encodeURIComponent(search)}&page=${page}`),
  register: (form) => send('/customer/register', 'POST', form),
  login: (form) => send('/customer/login', 'POST', form),
  me: () => send('/customer/me', 'GET', undefined, true),
  logout: () => send('/customer/logout', 'POST', {}, true),
  placeOrder: (payload) => send('/customer/orders', 'POST', payload, true),
  orders: () => send('/customer/orders', 'GET', undefined, true),
  loyalty: () => send('/customer/loyalty', 'GET', undefined, true),
  cancelOrder: (id, payload = {}) => send(`/customer/orders/${id}/cancel`, 'POST', payload, true),
}

// UUID v4 untuk retry checkout; tidak berbagi state dengan checkout POS staf.
export function newCustomerOrderKey() {
  const bytes = new Uint8Array(16)
  window.crypto.getRandomValues(bytes)
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}