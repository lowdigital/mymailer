# 📧 MyMailer

**MyMailer** is a modern email campaign management system with a beautiful dark UI, no database required.

![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=flat-square&logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

## ✨ Features

- **Modern Dark UI** — Beautiful responsive interface with gradient accents
- **Campaign Management** — Create, edit, delete email campaigns
- **HTML Editor** — Visual template editor with live preview and syntax highlighting
- **Attachments** — Attach files to your emails with drag & drop
- **Browser-based Sending** — Send emails directly from browser with visual progress
- **Open Tracking** — Track email opens with invisible pixel
- **Click Tracking** — Track all link clicks with detailed statistics
- **Analytics Dashboard** — View open rates, click rates, popular links
- **Unsubscribe System** — Automatic unsubscribe handling with global blocklist
- **Flexible Settings** — SMTP configuration, sending speed, admin password
- **No Database** — All data stored in files (JSON, TXT)
- **Secure** — Password-protected admin panel

## 📁 Project Structure

```
mymailer/
├── index.php                  # Redirect to admin panel
├── config.php                 # Configuration functions
├── config.json                # Settings (auto-created)
├── .htaccess                  # Security rules
├── unsubscribed.txt           # Global unsubscribe list
├── unsubscribe.html           # Unsubscribe page
├── unsubscribe.php            # Unsubscribe handler
├── link.php                   # Email open & click tracking
│
├── admin/                     # Admin Panel
│   ├── index.php              # Dashboard (campaign list)
│   ├── campaign.php           # Campaign management
│   ├── send.php               # Sending page
│   └── settings.php           # System settings
│
├── campaigns/                 # Campaigns directory
│   └── {UUID}/                # Campaign folder
│       ├── options.json       # Campaign settings
│       ├── list.txt           # Recipients list
│       ├── template.html      # Email HTML template
│       ├── attachments/       # Email attachments
│       └── log/
│           ├── send.txt       # Send log
│           ├── error.txt      # Error log
│           ├── unsubscribe.txt # Unsubscribe log
│           ├── opens.txt      # Email opens log
│           └── clicks.txt     # Link clicks log
│
├── PHPMailer/                 # PHPMailer library
│   ├── PHPMailer.php
│   ├── SMTP.php
│   └── Exception.php
│
└── README.md
```

## 🚀 Installation

### Requirements

- PHP 7.4 or higher
- Web server (Apache, Nginx)
- SMTP server for sending emails

### Quick Start

1. **Clone the repository:**

```bash
git clone https://github.com/your-username/mymailer.git
```

2. **Upload to your web server**

Copy all files to your web server directory.

3. **Set permissions:**

```bash
chmod 755 campaigns/
chmod 644 config.json unsubscribed.txt
```

4. **Open in browser:**

```
https://your-domain.com/mymailer/
```

5. **Login to admin panel:**

Default password: `admin123`

6. **Configure SMTP:**

Go to **Settings** → **SMTP Settings** and enter your mail server details.

## ⚙️ Configuration

### SMTP Settings

| Parameter | Description |
|-----------|-------------|
| SMTP Host | SMTP server address (e.g., `smtp.gmail.com`) |
| Port | `465` for SSL, `587` for TLS |
| Email | Sender email address |
| Password | Email account password |
| From Name | Sender name visible to recipients |

### Sending Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| Emails per step | Number of emails sent per iteration | 10 |
| Timeout | Pause between iterations (seconds) | 5 |

### Template Variables

Available variables in HTML email template:

| Variable | Description |
|----------|-------------|
| `[LINK_UNSUBSCRIBE]` | Unsubscribe link |

### Tracking

Email tracking is **automatically enabled** for all campaigns:

- **Open Tracking**: Invisible 1x1 pixel added before `</body>`
- **Click Tracking**: All links (except unsubscribe, mailto, tel) are wrapped with tracking URLs
- **Statistics**: View open rates, click rates, and popular links in the "Statistics" tab

## 📝 Usage

### Creating a Campaign

1. Open admin panel (`/admin/`)
2. Click **"New Campaign"**
3. Enter campaign name and email subject
4. Add recipients (tab **"Recipients"**)
5. Create email template (tab **"Template"**)
6. Optionally attach files (tab **"Attachments"**)
7. Click **"Start Campaign"**

### Sending Emails

- Emails are sent **in browser** — don't close the tab!
- Page auto-refreshes showing progress
- If error occurs, sending continues with next address
- You can pause by closing the page

### Unsubscribe System

- Unsubscribed emails are added to global `unsubscribed.txt`
- This list is checked for **all** campaigns
- Edit list in **Settings** → **Global Unsubscribe List**

## 🔧 Additional Info

### Popular SMTP Servers

**Gmail:**
```
Host: smtp.gmail.com
Port: 465 (SSL) or 587 (TLS)
```
> ⚠️ For Gmail, enable "Less secure apps" or use App Password

**Outlook/Office365:**
```
Host: smtp.office365.com
Port: 587 (TLS)
```

**Amazon SES:**
```
Host: email-smtp.{region}.amazonaws.com
Port: 465 (SSL) or 587 (TLS)
```

### Deliverability Tips

1. ✅ Use your own domain for sending
2. ✅ Set up SPF, DKIM, and DMARC records
3. ✅ Don't send too many emails at once
4. ✅ Always include unsubscribe link `[LINK_UNSUBSCRIBE]`
5. ✅ Use quality content without spam triggers

### RFC Compliance

System automatically adds headers:

- `List-Unsubscribe` — RFC 2369
- `List-Unsubscribe-Post` — RFC 8058 (one-click unsubscribe)
- `Precedence: bulk` — Bulk mail marker

## 🔒 Security

- `.htaccess` protects sensitive files (JSON configs, campaigns data)
- Admin panel requires password authentication
- Email validation prevents injection attacks
- All actions are logged

## 📄 License

MIT License — free for personal and commercial use.

## 🤝 Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss.

---

**Made with ❤️ by MyMailer Team**
