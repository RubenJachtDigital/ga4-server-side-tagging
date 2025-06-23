# GA4 Server-Side Tagging WordPress Plugin

This is a WordPress plugin that implements server-side tagging to send events to Google Analytics 4 (GA4). The plugin uses a Cloudflare Worker to handle the server-side event tracking.

## Architecture

- **WordPress Plugin**: Handles client-side event collection and consent management
- **Cloudflare Worker**: Processes events server-side and forwards them to GA4
- **Consent Management**: Implements privacy controls for GDPR/CCPA compliance

## Key Components

- Client-side JavaScript for event tracking and consent management
- Server-side processing via Cloudflare Worker
- WordPress admin interface for configuration
- Privacy-compliant event handling

## Purpose

This setup provides better data accuracy, privacy compliance, and protection against ad blockers by moving event processing to the server side while maintaining WordPress integration.