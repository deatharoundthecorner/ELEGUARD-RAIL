import { defineConfig } from 'sanity'
import { deskTool } from 'sanity/desk'
import { schemaTypes } from './schemas'

export default defineConfig({
  name: 'eleguard-rail-studio',
  title: 'EleGuard Rail Content Manager',

  projectId: 'eleguard-rail-id', // Placeholder - user replaces with actual project ID in .env
  dataset: 'production',

  plugins: [deskTool()],

  schema: {
    types: schemaTypes,
  },
})
