import { defineType, defineField } from 'sanity'

export default defineType({
  name: 'landingPage',
  title: 'Landing Page Content',
  type: 'document',
  fields: [
    defineField({
      name: 'heroTitle',
      title: 'Hero Title',
      type: 'string',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'heroSubtitle',
      title: 'Hero Subtitle',
      type: 'string',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'description',
      title: 'Project Description / Mission',
      type: 'text',
      validation: Rule => Rule.required()
    }),
    defineField({
      name: 'primaryButtonText',
      title: 'Primary CTA Button Text',
      type: 'string',
      initialValue: 'Report Sighting'
    }),
    defineField({
      name: 'secondaryButtonText',
      title: 'Secondary CTA Button Text',
      type: 'string',
      initialValue: 'Safety Instructions'
    }),
    defineField({
      name: 'bannerImage',
      title: 'Banner Image Attachment',
      type: 'image',
      options: {
        hotspot: true
      }
    }),
    defineField({
      name: 'updatedAt',
      title: 'Last Updated Date',
      type: 'datetime',
      initialValue: () => new Date().toISOString()
    })
  ]
})
