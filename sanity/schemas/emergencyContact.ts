import { defineType, defineField } from 'sanity'

export default defineType({
  name: 'emergencyContact',
  title: 'Emergency Contacts',
  type: 'document',
  fields: [
    defineField({
      name: 'organizationName',
      title: 'Organization / Department Name',
      type: 'string',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'phoneNumber',
      title: 'Emergency Phone Number',
      type: 'string',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'district',
      title: 'District Coverage',
      type: 'string',
      placeholder: 'e.g. National, Polonnaruwa, Anuradhapura',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'description',
      title: 'Service Description / Operating Hours',
      type: 'text'
    }),
    defineField({
      name: 'displayOrder',
      title: 'Sorting Display Order',
      type: 'number',
      initialValue: 0
    }),
    defineField({
      name: 'isActive',
      title: 'Is Active / Visible',
      type: 'boolean',
      initialValue: true
    })
  ]
})
