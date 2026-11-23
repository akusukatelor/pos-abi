// API publik sengaja tidak menggunakan client internal: tidak pernah mengirim
// Bearer token atau memicu event login/logout pada error 401.
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')

async function requestPublic(path, options = {}) {
  let response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
    })
  } catch {
    throw new Error('Server bantuan sedang tidak dapat dihubungi. Silakan coba lagi.')
  }

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    if (response.status === 429) {
      throw new Error('Terlalu banyak pertanyaan. Silakan coba lagi beberapa saat.')
    }
    const validationError = payload.errors
      ? Object.values(payload.errors).flat()[0]
      : null
    throw new Error(validationError || payload.message || 'Permintaan bantuan gagal.')
  }

  return payload.data || {}
}

export function loadPublicFaq() {
  return requestPublic('/public/faq', { method: 'GET' })
}

export function askPublicChat(question) {
  return requestPublic('/public/chat', {
    method: 'POST',
    body: { question },
  })
}
