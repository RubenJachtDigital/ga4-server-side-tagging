# GA4 Server-Side Tagging WordPress Plugin

A comprehensive WordPress plugin that implements server-side tagging to send events to Google Analytics 4 (GA4) via a Cloudflare Worker. The system provides enhanced privacy compliance, multi-layered bot detection, secure configuration delivery, and accurate attribution tracking.

## Architecture Overview

```
WordPress Site → Secure Config → Client-side JS → Cloudflare Worker → Google Analytics 4
     ↓              ↓               ↓                ↓                    ↓
Configuration → JWT Encryption → Event Collection → Server Processing → GA4 Measurement Protocol
```

## Core Components

### 🌐 **Client-Side (WordPress)**
- **Secure Configuration**: JWT-encrypted API key and configuration delivery via `/secure-config` endpoint
- **Event Collection**: Automatic tracking of page views, e-commerce, forms, engagement with enhanced attribution
- **Consent Management**: GDPR/CCPA compliance with Iubenda integration or custom CSS selectors
- **Bot Detection**: Client-side behavioral analysis and user agent filtering
- **Event Queuing**: Smart queuing system that holds events until consent is granted
- **Attribution Intelligence**: Accurate traffic source detection with internal vs. external navigation tracking

### ☁️ **Server-Side (Cloudflare Worker)**
- **Event Processing**: Receives events via fetch requests from WordPress plugin
- **GDPR Compliance**: Applies consent-based data filtering and anonymization
- **Advanced Bot Detection**: Multi-layered server-side bot filtering with comprehensive patterns
- **GA4 Integration**: Forwards legitimate events to Google Analytics 4 Measurement Protocol

### 🛡️ **Security & Privacy**
- **JWT Encryption**: Secure API key transmission with rotating encryption keys
- **Enhanced Bot Protection**: Multi-layered security checks for sensitive configuration endpoints
- **Google Consent Mode v2**: Automatic consent signal management
- **Data Anonymization**: Removes/anonymizes PII when consent is denied
- **Consent Persistence**: Stores user consent preferences locally
- **Auto-timeout**: Configurable auto-accept after X seconds (optional)

## Memories
- `add_click_track` only use the js file for any js so remove what is in the inline js and move it to the js file unless its already there

[Rest of the file remains unchanged...]