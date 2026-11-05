# EleGuard Rail - Sanity Studio

This is the content management studio for EleGuard Rail's public pages (landing, safety instructions, FAQs, emergency contacts, and articles).

## Setup Instructions

1. Install Node.js on your local system if you haven't already.
2. Open a terminal inside this `/sanity` directory.
3. Run the following command to install dependencies:
   ```bash
   npm install
   ```
4. Start the development server:
   ```bash
   npm run dev
   ```
5. Open your browser and navigate to the local studio URL:
   `http://localhost:3333`

## Integrating with the PHP Application

1. Create a project at [Sanity.io](https://www.sanity.io/).
2. Update the `projectId` in `sanity.config.ts` to match your project ID.
3. Add the project credentials to the root `.env` file of the PHP application:
   ```env
   SANITY_PROJECT_ID=your_project_id
   SANITY_DATASET=production
   SANITY_API_VERSION=2025-01-01
   SANITY_USE_CDN=true
   ```
4. If this is not configured, the PHP application will load the default hardcoded arrays automatically.
