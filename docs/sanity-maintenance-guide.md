# Sanity CMS Maintenance Guide

EleGuard Rail uses a hybrid architecture combining a local relational database for operational safety records and Sanity CMS for public-facing static content.

## 1. CMS Purpose & Scope

### Supported Public Content (CMS Managed)
- Landing page titles, description, and button labels.
- Safety rules (Title, details, order, priority badge).
- FAQ accordions (Question, answer, visibility toggle, order).
- Educational awareness articles (Title, summary, body content, images).
- Emergency hotline contacts (Organization name, phone number, district, description).

### Operational Safety Data (Database Managed - DO NOT STORE IN SANITY)
- User logins and hashed credentials.
- Elephant sighting reports.
- Proximity threat risk scores.
- Broadcasted warning alerts.
- Train driver speed acknowledgements.
- System logs.

## 2. Running Sanity Studio

Sanity Studio runs as a decoupled Node.js application:
1. Open a terminal and navigate to the project directory:
   `C:/xampp/htdocs/eleguard-rail/sanity`
2. Install local dependencies:
   ```bash
   npm install
   ```
3. Start the local server:
   ```bash
   npm run dev
   ```
4. Access the CMS editor in your browser at:
   `http://localhost:3333`

## 3. Fallback Mechanism

- **Decoupled Architecture**: The PHP application fetches content using a lightweight GROQ API client (`app/includes/sanity_client.php`).
- **Graceful Fallback**: If the project ID is missing in `.env` or if the Sanity API request fails due to network timeout/outages, the system defaults to local hardcoded mock data.
- **Robustness**: The main safety application remains functional without configuring Sanity.
