export type AdminNavItem = {
  label: string
  path: string
}

// Navigation-only placeholders for future feature blocks. Each links to a
// "Coming soon" page until that block is implemented — see ComingSoonPage.
export const ADMIN_NAV_ITEMS: AdminNavItem[] = [
  { label: 'Dashboard', path: '/admin/dashboard' },
  { label: 'Organizations', path: '/admin/organizations' },
  { label: 'Stores', path: '/admin/stores' },
  { label: 'Users', path: '/admin/users' },
  { label: 'Products', path: '/admin/products' },
  { label: 'Orders', path: '/admin/orders' },
  { label: 'Customers', path: '/admin/customers' },
  { label: 'Inventory', path: '/admin/inventory' },
  { label: 'Analytics', path: '/admin/analytics' },
  { label: 'AI', path: '/admin/ai' },
]
