import { defineType, defineField } from 'sanity'

export default defineType({
  name: 'awarenessArticle',
  title: 'Awareness Articles',
  type: 'document',
  fields: [
    defineField({
      name: 'title',
      title: 'Article Title',
      type: 'string',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'slug',
      title: 'URL Slug',
      type: 'slug',
      options: {
        source: 'title',
        maxLength: 96
      },
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'summary',
      title: 'Article Summary',
      type: 'string',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'body',
      title: 'Article Body Content',
      type: 'text',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'coverImage',
      title: 'Cover Image Attachment',
      type: 'image',
      options: {
        hotspot: true
      }
    }),
    defineField({
      name: 'publishedDate',
      title: 'Published Date',
      type: 'date',
      initialValue: () => new Date().toISOString().split('T')[0]
    }),
    defineField({
      name: 'isPublished',
      title: 'Is Published / Public',
      type: 'boolean',
      initialValue: true
    })
  ]
})
import { slugify } from 'sanity'
