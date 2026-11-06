import { useContext } from 'react'
import { PermissionsContext } from '../contexts/PermissionsContext'

export function usePermissions() {
  return useContext(PermissionsContext)
}
