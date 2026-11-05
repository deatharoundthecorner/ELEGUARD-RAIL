import { defineType, defineField } from 'sanity'

export default defineType({
  name: 'safetyInstruction',
  title: 'Safety Instructions',
  type: 'document',
  fields: [
    defineField({
      name: 'title',
      title: 'Instruction Title',
      type: 'string',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'body',
      title: 'Instruction Details',
      type: 'text',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'priorityLevel',
      title: 'Priority Importance Level',
      type: 'string',
      options: {
        list: [
          { title: 'Low', value: 'Low' },
          { title: 'Medium', value: 'Medium' },
          { title: 'High', value: 'High' },
          { title: 'Critical', value: 'Critical' }
        ]
      },
      initialValue: 'Low',
      validation: Rule => Rule.required()
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
