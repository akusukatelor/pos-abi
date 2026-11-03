import { useEffect, useMemo, useRef, useState } from 'react'
import './App.css'

const API_BASE = '/api'
const DEFAULT_USER_ID = 1

const currency = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

async function apiRequest(endpoint, options = {}) {
  const response = await fetch(`${API_BASE}${endpoint}`, {
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
    ...options,
  })

  const text = await response.text()
  const payload = text ? JSON.parse(text) : {}

  if (!response.ok) {
    throw new Error(payload.message || 'Permintaan gagal')
  }

  return payload
}

const getInitialCategoryForm = () => ({ name: '' })
const getInitialUnitForm = () => ({ name: '', short_name: '' })
const getInitialCustomerForm = () => ({ name: '', phone: '', address: '' })
const getInitialSupplierForm = () => ({ name: '', phone: '', address: '', contact_person: '' })
const getInitialProductForm = () => ({
  category_id: '',
  unit_id: '',
  sku_code: '',
  name: '',
  buy_price: '',
  sell_price: '',
  stock: '',
  minimum_stock: '5',
})
const getInitialDiscountForm = () => ({
  name: '',
  type: 'percentage',
  value: '',
  start_date: '',
  end_date: '',
  is_active: true,
})
const getInitialExpenseForm = () => ({
  user_id: DEFAULT_USER_ID,
  amount: '',
  category: '',
  description: '',
  expense_date: new Date().toISOString().slice(0, 10),
})
const getInitialShiftForm = () => ({
  user_id: DEFAULT_USER_ID,
  opening_balance: '',
})
const getInitialStockForm = () => ({
  product_id: '',
  user_id: DEFAULT_USER_ID,
  supplier_id: '',
  type: 'in',
  quantity: '',
  description: '',
})

function BrandLogo() {
  return (
    <div className="brand-identity" aria-label="Toko Abicell logo">
      <img className="brand-logo-image" src="/logo_abicell.png" alt="Toko Abicell" />
    </div>
  )
}

function App() {
  const [activeTab, setActiveTab] = useState('dashboard')
  const [loading, setLoading] = useState(true)
  const [notice, setNotice] = useState('')

  const [categories, setCategories] = useState([])
  const [units, setUnits] = useState([])
  const [suppliers, setSuppliers] = useState([])
  const [customers, setCustomers] = useState([])
  const [products, setProducts] = useState([])
  const [restockRecommendations, setRestockRecommendations] = useState([])
  const [transactions, setTransactions] = useState([])
  const [discounts, setDiscounts] = useState([])
  const [expenses, setExpenses] = useState([])
  const [shifts, setShifts] = useState([])
  const [stockMovements, setStockMovements] = useState([])

  const [categoryForm, setCategoryForm] = useState(getInitialCategoryForm())
  const [unitForm, setUnitForm] = useState(getInitialUnitForm())
  const [customerForm, setCustomerForm] = useState(getInitialCustomerForm())
  const [supplierForm, setSupplierForm] = useState(getInitialSupplierForm())
  const [productForm, setProductForm] = useState(getInitialProductForm())
  const [discountForm, setDiscountForm] = useState(getInitialDiscountForm())
  const [expenseForm, setExpenseForm] = useState(getInitialExpenseForm())
  const [shiftForm, setShiftForm] = useState(getInitialShiftForm())
  const [stockForm, setStockForm] = useState(getInitialStockForm())

  const [editingCategoryId, setEditingCategoryId] = useState(null)
  const [editingUnitId, setEditingUnitId] = useState(null)
  const [editingCustomerId, setEditingCustomerId] = useState(null)
  const [editingSupplierId, setEditingSupplierId] = useState(null)
  const [editingProductId, setEditingProductId] = useState(null)
  const [editingDiscountId, setEditingDiscountId] = useState(null)
  const [editingExpenseId, setEditingExpenseId] = useState(null)

  const [cart, setCart] = useState([])
  const [selectedCustomerId, setSelectedCustomerId] = useState('')
  const [payAmount, setPayAmount] = useState('')
  const [productSearch, setProductSearch] = useState('')
  const [paymentMethod, setPaymentMethod] = useState('cash')
  const [chatInput, setChatInput] = useState('')
  const [chatMessages, setChatMessages] = useState([
    {
      role: 'assistant',
      text: 'Halo! Saya asisten POS. Saya bisa bantu cek stok dan merekomendasikan pembelian ulang.',
    },
  ])
  const [chatLoading, setChatLoading] = useState(false)

  const searchInputRef = useRef(null)

  const loadAllData = async () => {
    try {
      setLoading(true)
      const [categoriesRes, unitsRes, suppliersRes, customersRes, productsRes, restockRes, transactionsRes, discountsRes, expensesRes, shiftsRes, stockRes] = await Promise.all([
        apiRequest('/categories'),
        apiRequest('/units'),
        apiRequest('/suppliers'),
        apiRequest('/customers'),
        apiRequest('/products'),
        apiRequest('/products/restock'),
        apiRequest('/transactions'),
        apiRequest('/discounts'),
        apiRequest('/expenses'),
        apiRequest('/shifts'),
        apiRequest('/stock-movements'),
      ])

      setCategories(categoriesRes.data || [])
      setUnits(unitsRes.data || [])
      setSuppliers(suppliersRes.data || [])
      setCustomers(customersRes.data || [])
      setProducts(productsRes.data || [])
      setRestockRecommendations(restockRes.data || [])
      setTransactions(transactionsRes.data || [])
      setDiscounts(discountsRes.data || [])
      setExpenses(expensesRes.data || [])
      setShifts(shiftsRes.data || [])
      setStockMovements(stockRes.data || [])
    } catch (error) {
      setNotice(error.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadAllData()
  }, [])

  const filteredProducts = useMemo(() => {
    const keyword = productSearch.toLowerCase().trim()

    if (!keyword) return products

    return products.filter((product) => {
      const name = (product.name || '').toLowerCase()
      const sku = (product.sku_code || '').toLowerCase()
      return name.includes(keyword) || sku.includes(keyword)
    })
  }, [products, productSearch])

  const dashboardStats = useMemo(() => {
    const totalSales = transactions.reduce((sum, item) => sum + Number(item.pay_amount || 0), 0)
    const totalExpense = expenses.reduce((sum, item) => sum + Number(item.amount || 0), 0)
    const lowStock = products.filter((product) => Number(product.stock || 0) <= 5).length
    const openShift = shifts.filter((shift) => shift.status === 'open').length

    return {
      totalSales,
      totalExpense,
      totalProducts: products.length,
      lowStock,
      openShift,
    }
  }, [transactions, expenses, products, shifts])

  const reportSummary = useMemo(() => {
    const totalRevenue = transactions.reduce((sum, item) => sum + Number(item.total_price || 0), 0)
    const totalPaid = transactions.reduce((sum, item) => sum + Number(item.pay_amount || 0), 0)
    const unpaid = transactions.filter((item) => item.payment_status !== 'paid').length
    const criticalStock = products.filter((product) => Number(product.stock || 0) <= Number(product.minimum_stock ?? 5)).length

    return {
      totalRevenue,
      totalPaid,
      unpaid,
      criticalStock,
      transactionCount: transactions.length,
    }
  }, [transactions, products])

  const totalCart = cart.reduce((sum, item) => sum + Number(item.quantity || 0) * Number(item.sell_price || 0), 0)

  const lowStockProducts = useMemo(() => {
    if (restockRecommendations.length) {
      return restockRecommendations.map((product) => ({
        ...product,
        minimumStock: Number(product.minimum_stock ?? 5),
        recommendedQty: Number(product.recommended_quantity ?? Math.max(Number(product.minimum_stock ?? 5) - Number(product.stock || 0), 1)),
      }))
    }

    const threshold = 5

    return products
      .filter((product) => Number(product.stock || 0) < Number(product.minimum_stock ?? product.min_stock ?? threshold))
      .map((product) => ({
        ...product,
        minimumStock: Number(product.minimum_stock ?? product.min_stock ?? threshold),
        recommendedQty: Math.max(Number(product.minimum_stock ?? product.min_stock ?? threshold) - Number(product.stock || 0), 1),
      }))
  }, [products, restockRecommendations])

  const cashPaymentError = useMemo(() => {
    if (paymentMethod !== 'cash' || !cart.length) {
      return ''
    }

    const numericPayAmount = Number(payAmount || 0)
    if (numericPayAmount < totalCart) {
      return 'Nominal uang tunai kurang dari total belanja.'
    }

    return ''
  }, [paymentMethod, payAmount, cart.length, totalCart])

  const isCashPaymentDisabled = paymentMethod === 'cash' && cart.length > 0 && Number(payAmount || 0) < totalCart

  const refresh = async () => {
    await loadAllData()
  }

  const buildAssistantReply = (question, lowStockItems) => {
    const lowerQuestion = question.toLowerCase().trim()

    if (!lowerQuestion) {
      return 'Tanya saya soal stok, restok, atau penjualan hari ini.'
    }

    const matchedProduct = products.find((product) => {
      const productText = `${product.name} ${product.sku_code || ''}`.toLowerCase()
      return productText.includes(lowerQuestion) || lowerQuestion.includes(productText)
    })

    if (matchedProduct) {
      const minStock = Number(matchedProduct.minimum_stock ?? 5)
      const stock = Number(matchedProduct.stock || 0)
      const gap = Math.max(minStock - stock, 0)

      return `Stok ${matchedProduct.name} saat ini ${stock} pcs. Batas minimum ${minStock} pcs.${gap > 0 ? ` Saat ini masih di bawah batas minimum dengan kekurangan ${gap} pcs. Saya sarankan restok segera.` : ' Masih aman dan belum perlu restok.'}`
    }

    if (lowerQuestion.includes('stok') || lowerQuestion.includes('restok') || lowerQuestion.includes('belanja')) {
      if (!lowStockItems.length) {
        return 'Semua produk saat ini masih aman dari stok kritis. Tetap pantau pembelian untuk menjaga ketersediaan.'
      }

      const topItems = lowStockItems
        .slice(0, 3)
        .map((product) => `${product.name} (${product.stock} pcs, butuh ${product.recommendedQty ?? product.recommended_quantity ?? 1} pcs lagi)`)
        .join('; ')

      return `Saya melihat stok kritis pada: ${topItems}. Prioritaskan pembelian ulang agar penjualan tidak terganggu.`
    }

    if (lowerQuestion.includes('penjualan') || lowerQuestion.includes('omzet') || lowerQuestion.includes('jualan')) {
      return `Total produk saat ini ${products.length}. Anda memiliki ${lowStockProducts.length} item yang memerlukan restock.`
    }

    if (lowerQuestion.includes('halo') || lowerQuestion.includes('hai') || lowerQuestion.includes('hello')) {
      return 'Halo! Saya bisa bantu cek stok, rekomendasi restok, atau ringkasan penjualan hari ini.'
    }

    return 'Saya dapat membantu mengecek stok kritis, rekomendasi pembelian, dan kondisi penjualan harian. Coba tanya stok produk atau kebutuhan restok.'
  }

  const askVirtualAssistant = async () => {
    const question = chatInput.trim()

    if (!question) {
      return
    }

    setChatMessages((current) => [...current, { role: 'user', text: question }])
    setChatInput('')
    setChatLoading(true)

    try {
      const response = await apiRequest('/assistant/chat', {
        method: 'POST',
        body: JSON.stringify({
          question,
          context: {
            total_products: products.length,
            low_stock_products: restockRecommendations.slice(0, 3),
          },
        }),
      })

      const answer = response?.data?.answer || response?.reply || response?.message || buildAssistantReply(question, lowStockProducts)
      setChatMessages((current) => [...current, { role: 'assistant', text: answer }])
    } catch (error) {
      const fallbackReply = buildAssistantReply(question, lowStockProducts)
      setChatMessages((current) => [...current, { role: 'assistant', text: fallbackReply }])
    } finally {
      setChatLoading(false)
    }
  }

  const createCategory = async (event) => {
    event.preventDefault()

    try {
      if (editingCategoryId) {
        await apiRequest(`/categories/${editingCategoryId}`, {
          method: 'PUT',
          body: JSON.stringify(categoryForm),
        })
      } else {
        await apiRequest('/categories', {
          method: 'POST',
          body: JSON.stringify(categoryForm),
        })
      }

      setCategoryForm(getInitialCategoryForm())
      setEditingCategoryId(null)
      await refresh()
      setNotice(editingCategoryId ? 'Kategori berhasil diperbarui' : 'Kategori berhasil dibuat')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createUnit = async (event) => {
    event.preventDefault()

    try {
      if (editingUnitId) {
        await apiRequest(`/units/${editingUnitId}`, {
          method: 'PUT',
          body: JSON.stringify(unitForm),
        })
      } else {
        await apiRequest('/units', {
          method: 'POST',
          body: JSON.stringify(unitForm),
        })
      }

      setUnitForm(getInitialUnitForm())
      setEditingUnitId(null)
      await refresh()
      setNotice(editingUnitId ? 'Satuan berhasil diperbarui' : 'Satuan berhasil dibuat')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createCustomer = async (event) => {
    event.preventDefault()

    try {
      if (editingCustomerId) {
        await apiRequest(`/customers/${editingCustomerId}`, {
          method: 'PUT',
          body: JSON.stringify(customerForm),
        })
      } else {
        await apiRequest('/customers', {
          method: 'POST',
          body: JSON.stringify(customerForm),
        })
      }

      setCustomerForm(getInitialCustomerForm())
      setEditingCustomerId(null)
      await refresh()
      setNotice(editingCustomerId ? 'Pelanggan berhasil diperbarui' : 'Pelanggan berhasil dibuat')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createSupplier = async (event) => {
    event.preventDefault()

    try {
      if (editingSupplierId) {
        await apiRequest(`/suppliers/${editingSupplierId}`, {
          method: 'PUT',
          body: JSON.stringify(supplierForm),
        })
      } else {
        await apiRequest('/suppliers', {
          method: 'POST',
          body: JSON.stringify(supplierForm),
        })
      }

      setSupplierForm(getInitialSupplierForm())
      setEditingSupplierId(null)
      await refresh()
      setNotice(editingSupplierId ? 'Supplier berhasil diperbarui' : 'Supplier berhasil dibuat')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createProduct = async (event) => {
    event.preventDefault()

    const payload = {
      ...productForm,
      category_id: Number(productForm.category_id),
      unit_id: productForm.unit_id ? Number(productForm.unit_id) : null,
      buy_price: Number(productForm.buy_price),
      sell_price: Number(productForm.sell_price),
      stock: Number(productForm.stock),
      minimum_stock: Number(productForm.minimum_stock || 0),
    }

    try {
      if (editingProductId) {
        await apiRequest(`/products/${editingProductId}`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        })
      } else {
        await apiRequest('/products', {
          method: 'POST',
          body: JSON.stringify(payload),
        })
      }

      setProductForm(getInitialProductForm())
      setEditingProductId(null)
      await refresh()
      setNotice(editingProductId ? 'Produk berhasil diperbarui' : 'Produk berhasil dibuat')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createDiscount = async (event) => {
    event.preventDefault()

    const payload = {
      ...discountForm,
      value: Number(discountForm.value),
      is_active: Boolean(discountForm.is_active),
    }

    try {
      if (editingDiscountId) {
        await apiRequest(`/discounts/${editingDiscountId}`, {
          method: 'PUT',
          body: JSON.stringify(payload),
        })
      } else {
        await apiRequest('/discounts', {
          method: 'POST',
          body: JSON.stringify(payload),
        })
      }

      setDiscountForm(getInitialDiscountForm())
      setEditingDiscountId(null)
      await refresh()
      setNotice(editingDiscountId ? 'Diskon berhasil diperbarui' : 'Diskon berhasil dibuat')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createExpense = async (event) => {
    event.preventDefault()

    try {
      if (editingExpenseId) {
        await apiRequest(`/expenses/${editingExpenseId}`, {
          method: 'PUT',
          body: JSON.stringify({
            ...expenseForm,
            amount: Number(expenseForm.amount),
            user_id: Number(expenseForm.user_id),
          }),
        })
      } else {
        await apiRequest('/expenses', {
          method: 'POST',
          body: JSON.stringify({
            ...expenseForm,
            amount: Number(expenseForm.amount),
            user_id: Number(expenseForm.user_id),
          }),
        })
      }

      setExpenseForm(getInitialExpenseForm())
      setEditingExpenseId(null)
      await refresh()
      setNotice(editingExpenseId ? 'Pengeluaran berhasil diperbarui' : 'Pengeluaran berhasil dicatat')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createShift = async (event) => {
    event.preventDefault()

    try {
      await apiRequest('/shifts', {
        method: 'POST',
        body: JSON.stringify({
          ...shiftForm,
          user_id: Number(shiftForm.user_id),
          opening_balance: Number(shiftForm.opening_balance),
        }),
      })

      setShiftForm(getInitialShiftForm())
      await refresh()
      setNotice('Shift berhasil dibuka')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const closeCurrentShift = async (shiftId, actualBalance) => {
    try {
      await apiRequest(`/shifts/${shiftId}`, {
        method: 'PUT',
        body: JSON.stringify({
          closing_balance_actual: Number(actualBalance),
        }),
      })

      await refresh()
      setNotice('Shift berhasil ditutup')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const createStockMovement = async (event) => {
    event.preventDefault()

    try {
      await apiRequest('/stock-movements', {
        method: 'POST',
        body: JSON.stringify({
          ...stockForm,
          product_id: Number(stockForm.product_id),
          user_id: Number(stockForm.user_id),
          supplier_id: stockForm.supplier_id ? Number(stockForm.supplier_id) : null,
          quantity: Number(stockForm.quantity),
        }),
      })

      setStockForm(getInitialStockForm())
      await refresh()
      setNotice('Catatan stok berhasil disimpan')
    } catch (error) {
      setNotice(error.message)
    }
  }

  const handleDelete = async (endpoint, successMessage) => {
    try {
      await apiRequest(endpoint, { method: 'DELETE' })
      await refresh()
      setNotice(successMessage)
    } catch (error) {
      setNotice(error.message)
    }
  }

  const addToCart = (product) => {
    const exists = cart.find((item) => item.id === product.id)

    if (exists) {
      setCart((current) =>
        current.map((item) =>
          item.id === product.id ? { ...item, quantity: item.quantity + 1 } : item,
        ),
      )
      return
    }

    setCart((current) => [...current, { ...product, quantity: 1 }])
  }

  const updateCartQuantity = (id, delta) => {
    setCart((current) =>
      current
        .map((item) =>
          item.id === id ? { ...item, quantity: Math.max(0, item.quantity + delta) } : item,
        )
        .filter((item) => item.quantity > 0),
    )
  }

  const submitCheckout = async () => {
    if (!cart.length) {
      setNotice('Tambahkan produk ke keranjang terlebih dahulu')
      return
    }

    if (paymentMethod === 'cash' && Number(payAmount || 0) < totalCart) {
      setNotice('Nominal uang tunai belum mencukupi total belanja.')
      return
    }

    const payload = {
      user_id: DEFAULT_USER_ID,
      customer_id: selectedCustomerId || null,
      pay_amount: Number(payAmount || totalCart),
      items: cart.map((item) => ({
        product_id: item.id,
        quantity: item.quantity,
      })),
    }

    try {
      const response = await apiRequest('/transactions', {
        method: 'POST',
        body: JSON.stringify(payload),
      })

      setCart([])
      setPayAmount('')
      setSelectedCustomerId('')
      setPaymentMethod('cash')
      await refresh()
      setNotice(`Transaksi ${response.data.invoice_number} berhasil dibuat`)
    } catch (error) {
      setNotice(error.message)
    }
  }

  const renderDashboard = () => (
    <div className="page-grid">
      <div className="stats-grid">
        <div className="stat-card accent">
          <span>Total Produk</span>
          <strong>{dashboardStats.totalProducts}</strong>
        </div>
        <div className="stat-card info">
          <span>Penjualan</span>
          <strong>{currency.format(dashboardStats.totalSales)}</strong>
        </div>
        <div className="stat-card warning">
          <span>Pengeluaran</span>
          <strong>{currency.format(dashboardStats.totalExpense)}</strong>
        </div>
        <div className="stat-card danger">
          <span>Stok Rendah</span>
          <strong>{dashboardStats.lowStock}</strong>
        </div>
      </div>

      <div className="panel-grid">
        <section className="panel">
          <div className="panel-header">
            <h3>Ringkasan Kinerja</h3>
          </div>
          <div className="mini-list">
            <div>
              <span>Open shift</span>
              <strong>{dashboardStats.openShift}</strong>
            </div>
            <div>
              <span>Transaksi hari ini</span>
              <strong>{transactions.length}</strong>
            </div>
            <div>
              <span>Data pelanggan</span>
              <strong>{customers.length}</strong>
            </div>
            <div>
              <span>Supplier aktif</span>
              <strong>{suppliers.length}</strong>
            </div>
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <h3>Daftar Rekomendasi Belanja</h3>
          </div>
          <ul className="simple-list">
            {restockRecommendations.length ? (
              lowStockProducts.slice(0, 5).map((product) => (
                <li key={product.id}>
                  <div className="stock-list-item">
                    <span>{product.name}</span>
                    <small>{product.stock} / {product.minimumStock ?? product.minimum_stock ?? 5} pcs</small>
                  </div>
                  <strong>+{product.recommendedQty ?? product.recommended_quantity ?? 1}</strong>
                </li>
              ))
            ) : (
              <li className="muted">Belum ada rekomendasi restok saat ini.</li>
            )}
          </ul>
        </section>
      </div>
    </div>
  )

  const renderCatalog = () => (
    <div className="page-grid">
      <section className="panel tall-panel">
        <div className="panel-header split-header">
          <h3>Produk</h3>
          <input
            type="text"
            value={productSearch}
            onChange={(event) => setProductSearch(event.target.value)}
            placeholder="Cari nama atau SKU"
          />
        </div>

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>SKU</th>
                <th>Produk</th>
                <th>Kategori</th>
                <th>Harga Jual</th>
                <th>Stok</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {filteredProducts.map((product) => (
                <tr key={product.id}>
                  <td>{product.sku_code}</td>
                  <td>{product.name}</td>
                  <td>{product.category?.name || '-'}</td>
                  <td>{currency.format(product.sell_price || 0)}</td>
                  <td>{product.stock}</td>
                  <td className="action-cell">
                    <button
                      className="small-button primary"
                      type="button"
                      onClick={() => {
                        setProductForm({
                          category_id: product.category_id,
                          unit_id: product.unit_id || '',
                          sku_code: product.sku_code,
                          name: product.name,
                          buy_price: product.buy_price,
                          sell_price: product.sell_price,
                          stock: product.stock,
                          minimum_stock: product.minimum_stock ?? 5,
                        })
                        setEditingProductId(product.id)
                        setActiveTab('master')
                      }}
                    >
                      Edit
                    </button>
                    <button
                      className="small-button danger"
                      type="button"
                      onClick={() => handleDelete(`/products/${product.id}`, 'Produk berhasil dihapus')}
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel">
        <div className="panel-header">
          <h3>{editingProductId ? 'Edit produk' : 'Tambah produk'}</h3>
        </div>

        <form className="form-grid" onSubmit={createProduct}>
          <select
            value={productForm.category_id}
            onChange={(event) => setProductForm({ ...productForm, category_id: event.target.value })}
            required
          >
            <option value="">Pilih kategori</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>{category.name}</option>
            ))}
          </select>

          <select
            value={productForm.unit_id}
            onChange={(event) => setProductForm({ ...productForm, unit_id: event.target.value })}
          >
            <option value="">Pilih satuan</option>
            {units.map((unit) => (
              <option key={unit.id} value={unit.id}>{unit.name}</option>
            ))}
          </select>

          <input
            value={productForm.sku_code}
            onChange={(event) => setProductForm({ ...productForm, sku_code: event.target.value })}
            placeholder="SKU"
            required
          />
          <input
            value={productForm.name}
            onChange={(event) => setProductForm({ ...productForm, name: event.target.value })}
            placeholder="Nama produk"
            required
          />
          <input
            type="number"
            min="0"
            value={productForm.buy_price}
            onChange={(event) => setProductForm({ ...productForm, buy_price: event.target.value })}
            placeholder="Harga beli"
            required
          />
          <input
            type="number"
            min="0"
            value={productForm.sell_price}
            onChange={(event) => setProductForm({ ...productForm, sell_price: event.target.value })}
            placeholder="Harga jual"
            required
          />
          <input
            type="number"
            min="0"
            value={productForm.stock}
            onChange={(event) => setProductForm({ ...productForm, stock: event.target.value })}
            placeholder="Stok awal"
            required
          />
          <input
            type="number"
            min="0"
            value={productForm.minimum_stock}
            onChange={(event) => setProductForm({ ...productForm, minimum_stock: event.target.value })}
            placeholder="Stok minimum"
          />

          <div className="form-actions">
            <button type="submit" className="primary-button">
              {editingProductId ? 'Update Produk' : 'Simpan Produk'}
            </button>
            {editingProductId && (
              <button
                type="button"
                className="secondary-button"
                onClick={() => {
                  setEditingProductId(null)
                  setProductForm(getInitialProductForm())
                }}
              >
                Batal
              </button>
            )}
          </div>
        </form>
      </section>
    </div>
  )

  const renderMasterData = () => (
    <div className="page-grid master-layout">
      <div className="summary-grid master-summary-grid">
        <div className="metric-card">
          <span>Kategori</span>
          <strong>{categories.length}</strong>
        </div>
        <div className="metric-card">
          <span>Satuan</span>
          <strong>{units.length}</strong>
        </div>
        <div className="metric-card">
          <span>Pelanggan</span>
          <strong>{customers.length}</strong>
        </div>
        <div className="metric-card">
          <span>Supplier</span>
          <strong>{suppliers.length}</strong>
        </div>
      </div>

      <div className="master-grid">
        <section className="panel master-form-card">
          <div className="panel-header">
            <h3>{editingCategoryId ? 'Edit kategori' : 'Tambah kategori'}</h3>
          </div>
        <form className="form-grid" onSubmit={createCategory}>
          <input
            value={categoryForm.name}
            onChange={(event) => setCategoryForm({ name: event.target.value })}
            placeholder="Nama kategori"
            required
          />
          <div className="form-actions">
            <button type="submit" className="primary-button">
              {editingCategoryId ? 'Update' : 'Simpan'}
            </button>
            {editingCategoryId && (
              <button
                type="button"
                className="secondary-button"
                onClick={() => {
                  setEditingCategoryId(null)
                  setCategoryForm(getInitialCategoryForm())
                }}
              >
                Batal
              </button>
            )}
          </div>
        </form>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {categories.map((category) => (
                <tr key={category.id}>
                  <td>{category.name}</td>
                  <td className="action-cell">
                    <button
                      type="button"
                      className="small-button primary"
                      onClick={() => {
                        setCategoryForm({ name: category.name })
                        setEditingCategoryId(category.id)
                      }}
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      className="small-button danger"
                      onClick={() => handleDelete(`/categories/${category.id}`, 'Kategori berhasil dihapus')}
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        </section>

        <section className="panel master-form-card">
          <div className="panel-header">
            <h3>{editingUnitId ? 'Edit satuan' : 'Tambah satuan'}</h3>
          </div>
        <form className="form-grid" onSubmit={createUnit}>
          <input
            value={unitForm.name}
            onChange={(event) => setUnitForm({ ...unitForm, name: event.target.value })}
            placeholder="Nama satuan"
            required
          />
          <input
            value={unitForm.short_name}
            onChange={(event) => setUnitForm({ ...unitForm, short_name: event.target.value })}
            placeholder="Singkatan"
          />
          <div className="form-actions">
            <button type="submit" className="primary-button">
              {editingUnitId ? 'Update' : 'Simpan'}
            </button>
            {editingUnitId && (
              <button
                type="button"
                className="secondary-button"
                onClick={() => {
                  setEditingUnitId(null)
                  setUnitForm(getInitialUnitForm())
                }}
              >
                Batal
              </button>
            )}
          </div>
        </form>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>Singkatan</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {units.map((unit) => (
                <tr key={unit.id}>
                  <td>{unit.name}</td>
                  <td>{unit.short_name || '-'}</td>
                  <td className="action-cell">
                    <button
                      type="button"
                      className="small-button primary"
                      onClick={() => {
                        setUnitForm({ name: unit.name, short_name: unit.short_name || '' })
                        setEditingUnitId(unit.id)
                      }}
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      className="small-button danger"
                      onClick={() => handleDelete(`/units/${unit.id}`, 'Satuan berhasil dihapus')}
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        </section>

        <section className="panel master-form-card">
          <div className="panel-header">
            <h3>{editingCustomerId ? 'Edit pelanggan' : 'Tambah pelanggan'}</h3>
          </div>
        <form className="form-grid" onSubmit={createCustomer}>
          <input
            value={customerForm.name}
            onChange={(event) => setCustomerForm({ ...customerForm, name: event.target.value })}
            placeholder="Nama pelanggan"
            required
          />
          <input
            value={customerForm.phone}
            onChange={(event) => setCustomerForm({ ...customerForm, phone: event.target.value })}
            placeholder="No. telp"
          />
          <textarea
            value={customerForm.address}
            onChange={(event) => setCustomerForm({ ...customerForm, address: event.target.value })}
            placeholder="Alamat"
            rows="3"
          />
          <div className="form-actions">
            <button type="submit" className="primary-button">
              {editingCustomerId ? 'Update' : 'Simpan'}
            </button>
            {editingCustomerId && (
              <button
                type="button"
                className="secondary-button"
                onClick={() => {
                  setEditingCustomerId(null)
                  setCustomerForm(getInitialCustomerForm())
                }}
              >
                Batal
              </button>
            )}
          </div>
        </form>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>Telepon</th>
                <th>Utang</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {customers.map((customer) => (
                <tr key={customer.id}>
                  <td>{customer.name}</td>
                  <td>{customer.phone || '-'}</td>
                  <td>{currency.format(customer.total_debt || 0)}</td>
                  <td className="action-cell">
                    <button
                      type="button"
                      className="small-button primary"
                      onClick={() => {
                        setCustomerForm({
                          name: customer.name,
                          phone: customer.phone || '',
                          address: customer.address || '',
                        })
                        setEditingCustomerId(customer.id)
                      }}
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      className="small-button danger"
                      onClick={() => handleDelete(`/customers/${customer.id}`, 'Pelanggan berhasil dihapus')}
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        </section>

        <section className="panel master-form-card">
          <div className="panel-header">
            <h3>{editingSupplierId ? 'Edit supplier' : 'Tambah supplier'}</h3>
          </div>
        <form className="form-grid" onSubmit={createSupplier}>
          <input
            value={supplierForm.name}
            onChange={(event) => setSupplierForm({ ...supplierForm, name: event.target.value })}
            placeholder="Nama supplier"
            required
          />
          <input
            value={supplierForm.phone}
            onChange={(event) => setSupplierForm({ ...supplierForm, phone: event.target.value })}
            placeholder="No. telp"
          />
          <input
            value={supplierForm.contact_person}
            onChange={(event) => setSupplierForm({ ...supplierForm, contact_person: event.target.value })}
            placeholder="Kontak person"
          />
          <textarea
            value={supplierForm.address}
            onChange={(event) => setSupplierForm({ ...supplierForm, address: event.target.value })}
            placeholder="Alamat"
            rows="3"
          />
          <div className="form-actions">
            <button type="submit" className="primary-button">
              {editingSupplierId ? 'Update' : 'Simpan'}
            </button>
            {editingSupplierId && (
              <button
                type="button"
                className="secondary-button"
                onClick={() => {
                  setEditingSupplierId(null)
                  setSupplierForm(getInitialSupplierForm())
                }}
              >
                Batal
              </button>
            )}
          </div>
        </form>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>Kontak</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {suppliers.map((supplier) => (
                <tr key={supplier.id}>
                  <td>{supplier.name}</td>
                  <td>{supplier.contact_person || supplier.phone || '-'}</td>
                  <td className="action-cell">
                    <button
                      type="button"
                      className="small-button primary"
                      onClick={() => {
                        setSupplierForm({
                          name: supplier.name,
                          phone: supplier.phone || '',
                          address: supplier.address || '',
                          contact_person: supplier.contact_person || '',
                        })
                        setEditingSupplierId(supplier.id)
                      }}
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      className="small-button danger"
                      onClick={() => handleDelete(`/suppliers/${supplier.id}`, 'Supplier berhasil dihapus')}
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        </section>

        <section className="panel master-form-card">
          <div className="panel-header">
            <h3>{editingDiscountId ? 'Edit diskon' : 'Tambah diskon'}</h3>
          </div>
        <form className="form-grid" onSubmit={createDiscount}>
          <input
            value={discountForm.name}
            onChange={(event) => setDiscountForm({ ...discountForm, name: event.target.value })}
            placeholder="Nama diskon"
            required
          />
          <select
            value={discountForm.type}
            onChange={(event) => setDiscountForm({ ...discountForm, type: event.target.value })}
          >
            <option value="percentage">Persentase</option>
            <option value="fixed_amount">Nominal tetap</option>
          </select>
          <input
            type="number"
            min="0"
            value={discountForm.value}
            onChange={(event) => setDiscountForm({ ...discountForm, value: event.target.value })}
            placeholder="Nilai"
            required
          />
          <input
            type="date"
            value={discountForm.start_date}
            onChange={(event) => setDiscountForm({ ...discountForm, start_date: event.target.value })}
            required
          />
          <input
            type="date"
            value={discountForm.end_date}
            onChange={(event) => setDiscountForm({ ...discountForm, end_date: event.target.value })}
            required
          />
          <label className="checkbox-row">
            <input
              type="checkbox"
              checked={discountForm.is_active}
              onChange={(event) => setDiscountForm({ ...discountForm, is_active: event.target.checked })}
            />
            Aktif
          </label>
          <div className="form-actions">
            <button type="submit" className="primary-button">
              {editingDiscountId ? 'Update' : 'Simpan'}
            </button>
            {editingDiscountId && (
              <button
                type="button"
                className="secondary-button"
                onClick={() => {
                  setEditingDiscountId(null)
                  setDiscountForm(getInitialDiscountForm())
                }}
              >
                Batal
              </button>
            )}
          </div>
        </form>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Nama</th>
                <th>Jenis</th>
                <th>Nilai</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {discounts.map((discount) => (
                <tr key={discount.id}>
                  <td>{discount.name}</td>
                  <td>{discount.type}</td>
                  <td>{discount.value}</td>
                  <td className="action-cell">
                    <button
                      type="button"
                      className="small-button primary"
                      onClick={() => {
                        setDiscountForm({
                          name: discount.name,
                          type: discount.type,
                          value: discount.value,
                          start_date: discount.start_date,
                          end_date: discount.end_date,
                          is_active: Boolean(discount.is_active),
                        })
                        setEditingDiscountId(discount.id)
                      }}
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      className="small-button danger"
                      onClick={() => handleDelete(`/discounts/${discount.id}`, 'Diskon berhasil dihapus')}
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        </section>

        <section className="panel master-form-card">
          <div className="panel-header">
            <h3>{editingExpenseId ? 'Edit pengeluaran' : 'Catat pengeluaran'}</h3>
          </div>
        <form className="form-grid" onSubmit={createExpense}>
          <input
            type="number"
            value={expenseForm.user_id}
            onChange={(event) => setExpenseForm({ ...expenseForm, user_id: event.target.value })}
            placeholder="User ID"
            required
          />
          <input
            type="number"
            min="0"
            value={expenseForm.amount}
            onChange={(event) => setExpenseForm({ ...expenseForm, amount: event.target.value })}
            placeholder="Nominal"
            required
          />
          <input
            value={expenseForm.category}
            onChange={(event) => setExpenseForm({ ...expenseForm, category: event.target.value })}
            placeholder="Kategori pengeluaran"
            required
          />
          <input
            type="date"
            value={expenseForm.expense_date}
            onChange={(event) => setExpenseForm({ ...expenseForm, expense_date: event.target.value })}
            required
          />
          <textarea
            value={expenseForm.description}
            onChange={(event) => setExpenseForm({ ...expenseForm, description: event.target.value })}
            placeholder="Deskripsi"
            rows="3"
          />
          <div className="form-actions">
            <button type="submit" className="primary-button">
              {editingExpenseId ? 'Update' : 'Simpan'}
            </button>
            {editingExpenseId && (
              <button
                type="button"
                className="secondary-button"
                onClick={() => {
                  setEditingExpenseId(null)
                  setExpenseForm(getInitialExpenseForm())
                }}
              >
                Batal
              </button>
            )}
          </div>
        </form>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>Kategori</th>
                <th>Nominal</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              {expenses.map((expense) => (
                <tr key={expense.id}>
                  <td>{new Date(expense.expense_date).toLocaleDateString('id-ID')}</td>
                  <td>{expense.category}</td>
                  <td>{currency.format(expense.amount || 0)}</td>
                  <td className="action-cell">
                    <button
                      type="button"
                      className="small-button primary"
                      onClick={() => {
                        setExpenseForm({
                          user_id: expense.user_id || DEFAULT_USER_ID,
                          amount: expense.amount,
                          category: expense.category,
                          description: expense.description || '',
                          expense_date: expense.expense_date,
                        })
                        setEditingExpenseId(expense.id)
                      }}
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      className="small-button danger"
                      onClick={() => handleDelete(`/expenses/${expense.id}`, 'Pengeluaran berhasil dihapus')}
                    >
                      Hapus
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        </section>
      </div>
    </div>
  )

  const renderCashier = () => (
    <div className="page-grid cashier-layout">
      <section className="panel">
        <div className="panel-header split-header">
          <h3>Kasir</h3>
          <button
            type="button"
            className="primary-button"
            onClick={submitCheckout}
            disabled={isCashPaymentDisabled || !cart.length}
          >
            Bayar
          </button>
        </div>

        <div className="cart-bar">
          <input
            ref={searchInputRef}
            type="text"
            placeholder="Cari produk / scan barcode"
            value={productSearch}
            onChange={(event) => setProductSearch(event.target.value)}
            autoFocus
          />
          <select value={selectedCustomerId} onChange={(event) => setSelectedCustomerId(event.target.value)}>
            <option value="">Pelanggan umum</option>
            {customers.map((customer) => (
              <option key={customer.id} value={customer.id}>{customer.name}</option>
            ))}
          </select>
          <select value={paymentMethod} onChange={(event) => setPaymentMethod(event.target.value)}>
            <option value="cash">Tunai</option>
            <option value="transfer">Transfer</option>
            <option value="ewallet">E-Wallet</option>
          </select>
        </div>

        <div className="payment-row">
          <input
            type="number"
            min="0"
            value={payAmount}
            onChange={(event) => setPayAmount(event.target.value)}
            placeholder="Uang bayar"
          />
          <button
            type="button"
            className="primary-button pay-button"
            onClick={submitCheckout}
            disabled={isCashPaymentDisabled || !cart.length}
          >
            Bayar
          </button>
        </div>
        {cashPaymentError && <div className="payment-error">{cashPaymentError}</div>}

        <div className="product-grid">
          {filteredProducts.map((product) => (
            <button
              type="button"
              className="product-card"
              key={product.id}
              onClick={() => addToCart(product)}
            >
              <span>{product.name}</span>
              <strong>{currency.format(product.sell_price || 0)}</strong>
              <small>Stok: {product.stock}</small>
            </button>
          ))}
        </div>
      </section>

      <aside className="panel cart-panel">
        <div className="panel-header">
          <h3>Keranjang</h3>
        </div>

        {cart.length === 0 ? (
          <p className="muted">Belum ada item yang dipilih.</p>
        ) : (
          <div className="cart-items">
            {cart.map((item) => (
              <div key={item.id} className="cart-item">
                <div>
                  <strong>{item.name}</strong>
                  <span>{currency.format(item.sell_price || 0)} / pcs</span>
                </div>
                <div className="qty-box">
                  <button type="button" onClick={() => updateCartQuantity(item.id, -1)}>-</button>
                  <span>{item.quantity}</span>
                  <button type="button" onClick={() => updateCartQuantity(item.id, 1)}>+</button>
                </div>
              </div>
            ))}
          </div>
        )}

        <div className="cart-summary">
          <div>
            <span>Total</span>
            <strong>{currency.format(totalCart)}</strong>
          </div>
          <div>
            <span>Bayar</span>
            <strong>{currency.format(Number(payAmount || totalCart))}</strong>
          </div>
          <div>
            <span>Kembali</span>
            <strong>{currency.format(Math.max(0, Number(payAmount || totalCart) - totalCart))}</strong>
          </div>
        </div>
      </aside>
    </div>
  )

  const renderTransactions = () => (
    <div className="page-grid">
      <div className="summary-grid report-summary-grid">
        <div className="metric-card info-card">
          <span>Revenue</span>
          <strong>{currency.format(reportSummary.totalRevenue)}</strong>
        </div>
        <div className="metric-card success-card">
          <span>Transaksi</span>
          <strong>{reportSummary.transactionCount}</strong>
        </div>
        <div className="metric-card warning-card">
          <span>Belum Lunas</span>
          <strong>{reportSummary.unpaid}</strong>
        </div>
        <div className="metric-card danger-card">
          <span>Stok Kritis</span>
          <strong>{reportSummary.criticalStock}</strong>
        </div>
      </div>

      <section className="panel">
        <div className="panel-header">
          <h3>Riwayat transaksi</h3>
        </div>

        <div className="table-wrap">
          <table className="report-table">
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Pelanggan</th>
                <th>Total</th>
                <th>Bayar</th>
                <th>Status</th>
                <th>Waktu</th>
              </tr>
            </thead>
            <tbody>
              {transactions.map((transaction) => (
                <tr key={transaction.id}>
                  <td>{transaction.invoice_number}</td>
                  <td>{transaction.customer?.name || 'Umum'}</td>
                  <td>{currency.format(transaction.total_price || 0)}</td>
                  <td>{currency.format(transaction.pay_amount || 0)}</td>
                  <td>
                    <span className={`status-badge ${transaction.payment_status === 'paid' ? 'paid' : 'pending'}`}>
                      {transaction.payment_status === 'paid' ? 'Lunas' : 'Belum lunas'}
                    </span>
                  </td>
                  <td>{new Date(transaction.created_at).toLocaleString('id-ID')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )

  const renderInventory = () => (
    <div className="page-grid">
      <section className="panel">
        <div className="panel-header">
          <h3>Mutasi stok</h3>
        </div>

        <form className="form-grid" onSubmit={createStockMovement}>
          <select
            value={stockForm.product_id}
            onChange={(event) => setStockForm({ ...stockForm, product_id: event.target.value })}
            required
          >
            <option value="">Pilih produk</option>
            {products.map((product) => (
              <option key={product.id} value={product.id}>{product.name}</option>
            ))}
          </select>

          <select
            value={stockForm.type}
            onChange={(event) => setStockForm({ ...stockForm, type: event.target.value })}
          >
            <option value="in">Barang masuk</option>
            <option value="out">Barang keluar</option>
          </select>

          <select
            value={stockForm.supplier_id}
            onChange={(event) => setStockForm({ ...stockForm, supplier_id: event.target.value })}
          >
            <option value="">Tanpa supplier</option>
            {suppliers.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>{supplier.name}</option>
            ))}
          </select>

          <input
            type="number"
            min="1"
            value={stockForm.quantity}
            onChange={(event) => setStockForm({ ...stockForm, quantity: event.target.value })}
            placeholder="Jumlah"
            required
          />

          <textarea
            value={stockForm.description}
            onChange={(event) => setStockForm({ ...stockForm, description: event.target.value })}
            placeholder="Keterangan"
            rows="3"
          />

          <div className="form-actions">
            <button type="submit" className="primary-button">Simpan Mutasi</button>
          </div>
        </form>
      </section>

      <section className="panel">
        <div className="panel-header">
          <h3>Riwayat stok</h3>
        </div>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Produk</th>
                <th>Jenis</th>
                <th>Qty</th>
                <th>Keterangan</th>
              </tr>
            </thead>
            <tbody>
              {stockMovements.map((item) => (
                <tr key={item.id}>
                  <td>{item.product?.name || '-'}</td>
                  <td>{item.type === 'in' ? 'Masuk' : 'Keluar'}</td>
                  <td>{item.quantity}</td>
                  <td>{item.description || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )

  const renderShift = () => (
    <div className="page-grid">
      <section className="panel">
        <div className="panel-header">
          <h3>Buka shift</h3>
        </div>

        <form className="form-grid" onSubmit={createShift}>
          <input
            type="number"
            value={shiftForm.user_id}
            onChange={(event) => setShiftForm({ ...shiftForm, user_id: event.target.value })}
            placeholder="User ID"
            required
          />
          <input
            type="number"
            min="0"
            value={shiftForm.opening_balance}
            onChange={(event) => setShiftForm({ ...shiftForm, opening_balance: event.target.value })}
            placeholder="Saldo awal"
            required
          />

          <div className="form-actions">
            <button type="submit" className="primary-button">Buka Shift</button>
          </div>
        </form>
      </section>

      <section className="panel">
        <div className="panel-header">
          <h3>Riwayat shift</h3>
        </div>

        <div className="table-wrap small-table">
          <table>
            <thead>
              <tr>
                <th>Kasir</th>
                <th>Opened</th>
                <th>Status</th>
                <th>Tutup</th>
              </tr>
            </thead>
            <tbody>
              {shifts.map((shift) => (
                <tr key={shift.id}>
                  <td>{shift.user?.name || '-'}</td>
                  <td>{new Date(shift.opened_at).toLocaleString('id-ID')}</td>
                  <td>{shift.status}</td>
                  <td>
                    {shift.status === 'open' ? (
                      <button
                        type="button"
                        className="small-button primary"
                        onClick={() => {
                          const value = window.prompt('Jumlah uang fisik saat tutup shift:', '0')
                          if (value !== null) closeCurrentShift(shift.id, value)
                        }}
                      >
                        Tutup
                      </button>
                    ) : (
                      <span>{shift.closing_balance_actual ?? '-'}</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand-box">
          <BrandLogo />
        </div>

        <nav className="nav-menu">
          {[
            ['dashboard', 'Dashboard'],
            ['cashier', 'Point of Sale (POS)'],
            ['catalog', 'Inventory'],
            ['master', 'Supplier'],
            ['transactions', 'Reports'],
            ['inventory', 'Digital Services'],
            ['shift', 'Settings'],
          ].map(([key, label]) => (
            <button
              key={key}
              type="button"
              className={activeTab === key ? 'nav-item active' : 'nav-item'}
              onClick={() => setActiveTab(key)}
            >
              {label}
            </button>
          ))}
        </nav>
      </aside>

      <main className="main-panel">
        <header className="topbar">
          <div className="topbar-title-wrap">
            <h1>{activeTab === 'dashboard' ? 'Dashboard - LARIS ABICELL' : 'Dashboard'}</h1>
          </div>
          <div className="topbar-actions">
            <div className="search-box">
              <span className="search-icon">⌕</span>
              <input type="text" placeholder="Search" />
            </div>
            <button type="button" className="icon-button" aria-label="Notifications">⏺</button>
            <button type="button" className="icon-button" aria-label="Refresh" onClick={refresh}>↻</button>
            <div className="user-pill">
              <span className="user-avatar">SR</span>
              <div>
                <span className="user-label">Admin / Owner</span>
              </div>
              <span className="user-indicator" />
            </div>
          </div>
        </header>

        {notice && <div className="notice-banner">{notice}</div>}

        {loading ? <div className="loading-box">Memuat data dari backend...</div> : null}

        {!loading && activeTab === 'dashboard' && renderDashboard()}
        {!loading && activeTab === 'catalog' && renderCatalog()}
        {!loading && activeTab === 'master' && renderMasterData()}
        {!loading && activeTab === 'cashier' && renderCashier()}
        {!loading && activeTab === 'transactions' && renderTransactions()}
        {!loading && activeTab === 'inventory' && renderInventory()}
        {!loading && activeTab === 'shift' && renderShift()}

        {activeTab === 'dashboard' && (
          <div className="ai-floating-widget">
            <div className="ai-widget-header">
              <div>
                <span className="ai-pill">AI</span>
                <strong>Asisten Virtual</strong>
              </div>
            </div>

            <div className="ai-message-list">
              {chatMessages.map((message, index) => (
                <div key={`${message.role}-${index}`} className={`ai-message ${message.role}`}>
                  {message.text}
                </div>
              ))}
              {chatLoading && <div className="ai-message assistant">Memproses pertanyaan...</div>}
            </div>

            <div className="ai-input-row">
              <input
                type="text"
                value={chatInput}
                onChange={(event) => setChatInput(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    askVirtualAssistant()
                  }
                }}
                placeholder="Tanya stok atau rekomendasi beli..."
              />
              <button type="button" className="primary-button" onClick={askVirtualAssistant} disabled={chatLoading}>
                Kirim
              </button>
            </div>
          </div>
        )}
      </main>
    </div>
  )
}

export default App
