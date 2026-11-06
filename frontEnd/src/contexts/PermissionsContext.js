import { createContext } from 'react'

export const PermissionsContext = createContext({ user: null, can: () => false })
