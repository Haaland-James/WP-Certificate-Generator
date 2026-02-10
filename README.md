# LinkedIn Certificate Publisher

**Version:** 1.2.0  
**Requires:** WordPress 5.8+, PHP 7.4+  
**License:** GPLv2 or later

A powerful WordPress plugin that allows you to issue certificates to webinar/event attendees and enables them to share their achievements directly to their LinkedIn profile (feed) with a single click.

## 🚀 Features

- **Automated Certificate Generation:** Generates personalized certificate images on the fly.
- **LinkedIn Integration:** One-click sharing to LinkedIn feed with the actual certificate image and custom celebration text.
- **Visual Design Editor:** Customize certificate templates, text positioning, font size, and color with live preview.
- **Attendee Management:** Import attendees via CSV or auto-register them via Elementor forms.
- **n8n / Webhook Support:** Automate attendee registration via webhooks.
- **Shortcode System:** Place download and share buttons anywhere.

---

## 🛠️ Installation & Setup

1. **Upload & Activate:**
   - Upload the plugin folder to `wp-content/plugins/` or install via the WordPress dashboard.
   - Activate the plugin.

2. **LinkedIn App Configuration (Crucial):**
   To enable sharing, you must create a LinkedIn Developer App:
   - Go to [LinkedIn Developers](https://www.linkedin.com/developers/apps).
   - Create a new app.
   - Under **Products**, request access to **"Share on LinkedIn"** and **"Sign In with LinkedIn using OpenID Connect"**.
   - Under **Auth**, find your **Client ID** and **Client Secret**.
   - Add your **Redirect URL**: `https://yourdomain.com/lcpp-oauth-callback`
     *(Note: The plugin automatically handles this URL).*

3. **Configure Plugin:**
   - Go to **Settings → LinkedIn Certificates**.
   - Enter your **Client ID** and **Client Secret**.
   - Save Settings.

---

## 🎨 Certificate Design

Customize how your certificates look without touching code:

1. Go to **Settings → LinkedIn Certificates**.
2. Scroll to **Certificate Design**.
3. **Template:** Upload your own blank certificate background (PNG format recommended, high res).
4. **Positioning:** Use the X / Y sliders to position the attendee's name.
   - X: Horizontal position (50% = centered).
   - Y: Vertical position.
5. **Typography:**
   - Set **Font Size** (supports auto-downloaded Montserrat font).
   - Set **Font Color** using the picker.
6. **Preview:** Click **Generate Preview** to test with a sample name.

---

## 👥 Managing Attendees

### Method 1: CSV Import
1. Go to **Certificates → Attendees**.
2. Click **Import CSV**.
3. Upload a CSV file with columns: `name`, `email`.
4. (Optional) Specify an **Event ID** (default: `ai-masterclass`).

### Method 2: Elementor Forms
The plugin automatically detects Elementor form submissions if:
- The form name contains keywords: `webinar`, `event`, `registration`, `masterclass`.
- The form has fields named `name` (or `full_name`) and `email`.
- You can configure custom keywords in the settings.

### Method 3: Webhook (n8n / Zapier)
Send a POST request to:
`https://yourdomain.com/wp-json/lcpp/v1/register-attendee`

**Headers:**
- `Content-Type: application/json`
- `X-API-Key: YOUR_SECRET_KEY` (Set this in Plugin Settings)

**Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "event_id": "ai-masterclass"
}
```

---

## 📝 Shortcodes

Place these on any page (e.g., a "Thank You" or "Your Certificate" page). The buttons will only appear if the logged-in user (matching email) is a registered attendee.

### Download Button
Allows the user to download the PNG certificate.
```
[lcpp_download_button event="ai-masterclass" label="Download Certificate"]
```

### LinkedIn Share Button
Allows the user to post the certificate image and text to LinkedIn.
```
[lcpp_linkedin_button event="ai-masterclass" label="Share to LinkedIn"]
```

---

## ⚙️ Customizing the Share Message

You can define what text appears on the user's LinkedIn post.

1. Go to **Settings → LinkedIn Certificates**.
2. Edit the **Default Announcement** field.
3. Available placeholders:
   - `{certificate_name}` - Title of the certificate/event.
   - `{issuer}` - Your organization name.
   - `{credential_url}` - Link to the certificate verification page.
   - `{issue_date}` - Date of issue.

**Example:**
> 🚀 I'm displayed to share that I've just completed the {certificate_name} by {issuer}!
> #Learning #Growth

---

## ❓ Troubleshooting

**Certificate fonts are small/wrong:**
- Ensure the plugin has downloaded the font. Go to **Settings** and try saving again to trigger the auto-download check, or manually upload a TTF file to `wp-content/uploads/lcpp-fonts/Montserrat-Bold.ttf`.

**LinkedIn 404 Error after login:**
- Go to **Settings → Permalinks** and click **Save Changes** to flush rewrite rules.
- Ensure your Redirect URL in the LinkedIn Developer Portal matches exactly.

**Image not showing on LinkedIn:**
- The plugin uses a 3-step upload process. Ensure your server allows outgoing requests to `api.linkedin.com`.
