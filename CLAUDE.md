# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MyIES Integration is a WordPress plugin that synchronizes user data bidirectionally between WordPress and Wicket CRM. It integrates with SureCart for e-commerce, Paid Memberships Pro for membership tracking, Fluent Forms for form handling, and Bricks Builder for frontend rendering.

## Architecture

### Plugin Entry Point
- `myies-integration.php` - Main loader using singleton pattern (`Wicket_Integration` class)

### Core Layers

**API Integration (`includes/fluent-forms/class-wicket-api-helper.php`)**
- `Wicket_API_Helper` singleton handles all Wicket CRM API calls
- JWT authentication with HS256 signing
- Access via `wicket_api()` global function
- Supports production and staging environments via `wicket_staging` option

**Data Synchronization (`includes/`)**
- `class-wicket-sync-on-user.php` - Syncs on login/registration
- `class-wicket-bulk-sync.php` - Batch sync all users
- `class-wicket-person-auto-create.php` - Creates Wicket person on WP registration
- `class-wicket-organizations.php` - Organization sync with custom DB tables
- `class-wicket-memberships.php` - Membership tracking with custom DB table

**Form Handlers (`includes/fluent-forms/`)**
- 11 handler classes for different Fluent Forms form types
- Each handler class maps form fields to Wicket API attributes
- Centralized error handling via `class-wicket-error-handler.php`

**Frontend (`includes/frontend/`)**
- Company/organization switching, section management, profile editing
- Shortcodes in `includes/shortcodes/`

**Admin (`includes/admin/`)**
- Settings page under "MyIES Controls" menu
- Submenus: API Configuration, Sync Settings, Bulk Sync, SureCart Mapping, Updates

### Database
Custom tables created on activation:
- `wp_wicket_organizations`
- `wp_wicket_person_org_connections`
- `wp_wicket_user_memberships`

### Key WordPress Options
- `wicket_tenant_name` - Wicket tenant identifier
- `wicket_api_secret_key` - API secret for JWT
- `wicket_admin_user_uuid` - Admin user UUID for API calls
- `wicket_staging` - Toggle staging/production API

## Development

### Requirements
- WordPress 5.8+
- PHP 7.4+

### Debug Logging
Set `MYIES_DEBUG` constant or rely on `WP_DEBUG`. Use `myies_log($message, $context)` for logging.

### Configurable Form IDs
Override in `wp-config.php`:
```php
define('MYIES_FORM_PERSONAL_DETAILS', 49);
define('MYIES_FORM_PROFESSIONAL_INFO', 50);
define('MYIES_FORM_ADDRESS', 51);
define('MYIES_FORM_CONTACT_DETAILS', 23);
```

### User Meta Keys
- `wicket_person_uuid` or `wicket_uuid` - Links WP user to Wicket person
- ACF fields synced with `wicket_` prefix

## Wicket API Reference

See `wicketapi.md` for detailed API documentation including:
- Authentication (JWT with HS256)
- Rate limiting and pagination
- Filtering syntax (Ransack-based)
- Available endpoints and data structures

## Code Patterns

### Adding a New Fluent Form Handler
1. Create class in `includes/fluent-forms/`
2. Use singleton pattern
3. Hook into `fluentform/submission_inserted` with form ID filter
4. Use `wicket_api()` helper for API calls
5. Register in `myies-integration.php` dependencies

### Making API Calls
```php
$api = wicket_api();
$person_uuid = $api->get_person_uuid($user_id);
$result = $api->update_person($person_uuid, ['given_name' => 'John']);
```

### Cron Jobs
- `wicket_weekly_org_sync` - Weekly organization synchronization
