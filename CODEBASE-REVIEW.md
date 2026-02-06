# MyIES Integration Plugin - Codebase Review

## Summary

A comprehensive security, quality, and performance audit of the MyIES Integration WordPress plugin (~18,175 lines across 32 PHP files, 3 JS files, 4 CSS files). This review identified **47 findings** organized by severity across security vulnerabilities, bugs, architectural issues, and optimization opportunities.

| Severity | Count |
|----------|-------|
| Critical | 7 |
| High | 12 |
| Medium | 16 |
| Low | 12 |

---

## CRITICAL Issues (Fix Immediately)

### 1. Unauthenticated REST API Endpoint
**File:** `includes/class-surecart-wicket-sync.php:476-479`
**Category:** Security - Authorization Bypass

The REST route `/wicket/v1/sync-surecart-membership` has **no `permission_callback`**, meaning any unauthenticated visitor can POST to it with arbitrary `user_id` and `product_id` values to trigger membership syncs for any user.

```php
register_rest_route('wicket/v1', '/sync-surecart-membership', [
    'methods'  => 'POST',
    'callback' => [$this, 'rest_sync_membership']
    // Missing: 'permission_callback' => function() { return current_user_can('edit_users'); }
]);
```

**Why fix:** WordPress 5.5+ logs a `_doing_it_wrong` notice for missing `permission_callback`, and more importantly, this lets anyone manipulate user membership data without authentication.

---

### 2. XSS Vulnerability in ACF Shortcode Output
**File:** `includes/shortcodes/class-person-details-shortcode.php:56-61`
**Category:** Security - Cross-Site Scripting

ACF field values are output directly into HTML without escaping:

```php
$display_value = $value;  // Line 56 - no escaping
$output .= '<p><strong>' . $field['label'] . ':</strong> ' . $display_value . '</p>';  // Line 61
```

**Why fix:** If any ACF field stores user-controlled content (text fields, textareas), an attacker could inject `<script>` tags that execute in other users' browsers.

---

### 3. Field Name Typo Breaks Organization Creation
**File:** `includes/fluent-forms/class-organization-information.php:42`
**Category:** Bug - Data Loss

```php
$new_org_name = isset($form_data['comapny_name_alt']) ? sanitize_text_field($form_data['comapny_name_alt']) : '';
//                                   ^^^^^^^ typo: "comapny" instead of "company"
```

**Why fix:** The alternate company name field will always be empty because the key doesn't match the form field name. Users attempting to create new organizations via this form will silently fail to have their organization name captured.

---

### 4. Inconsistent User Meta Key for Person UUID
**Files:** Multiple (see below)
**Category:** Bug - Data Integrity

Some files look up `wicket_person_uuid`, others look up `wicket_uuid`, and some try both with a fallback. This means data written by one handler may not be found by another:

| File | Meta Key Used |
|------|--------------|
| `class-wicket-person-auto-create.php` | Writes `wicket_person_uuid` |
| `class-surecart-wicket-sync.php` | Writes `wicket_person_uuid` |
| `class-wicket-api-helper.php` | Reads `wicket_person_uuid`, falls back to `wicket_uuid` |
| `class-wicket-contact-details.php:57` | Reads `wicket_uuid` only |
| `class-additional-info-education.php:61` | Reads `wicket_uuid` only |
| `class-wicket-communication-preferences.php:290` | Reads `wicket_uuid` only |
| `class-organization-information.php:30` | Reads `wicket_person_uuid` only |
| `class-wicket-sync-on-user.php:389` | Maps to `wicket_uuid` |
| `class-person-details.php:280` | Reads `wicket_uuid` |

**Why fix:** If a user is created via the auto-create flow (which writes `wicket_person_uuid`), the contact details handler (which reads only `wicket_uuid`) will fail to find their UUID and silently skip the sync. This causes intermittent data loss that's difficult to debug.

---

### 5. JWT Token Regenerated on Every API Call
**File:** `includes/fluent-forms/class-wicket-api-helper.php:80-107`
**Category:** Performance - Unnecessary Crypto Operations

Every single API call generates a brand new JWT token (HMAC-SHA256 signing + base64 encoding). Tokens are valid for 1 hour but are never cached or reused.

**Why fix:** During a bulk sync of 1000 users (which may make 3-5 API calls per user), this generates 3,000-5,000 unnecessary JWT tokens. Caching the token and reusing it until near-expiry would eliminate this overhead entirely.

---

### 6. Duplicate JWT Generation Functions Across Codebase
**Files:**
- `includes/frontend/class-wicket-sign-up.php:16` - `wicket_generate_jwt_token()`
- `includes/fluent-forms/class-wicket-additional-info.php:366` - `wicket_generate_jwt_token_additional_info()`
- `includes/fluent-forms/class-wicket-communication-preferences.php:316` - `wicket_generate_jwt_token_comm_prefs()`
- `includes/fluent-forms/class-organization-information.php:84` - Calls `wicket_generate_jwt_token()` (relies on sign-up class being loaded first)

**Category:** Architecture - Code Duplication / Fragile Dependencies

Four separate implementations of JWT generation exist. `Wicket_API_Helper` already has a proper `generate_jwt_token()` method, but these handlers bypass it entirely with standalone global functions. The organization info handler depends on the sign-up class loading first to define `wicket_generate_jwt_token()`.

**Why fix:** If the JWT algorithm, secret key option name, or token format needs to change, four separate locations must be updated. If sign-up is deactivated or load order changes, the organization handler will throw a fatal error. All handlers should use `wicket_api()->generate_jwt_token()` instead.

---

### 7. Debug Statements Left in Production Code
**File:** `includes/fluent-forms/class-organization-information.php:17,20,32,39,123`
**Category:** Bug - Information Disclosure / Code Quality

```php
error_log('hi');           // Line 17
error_log('hi there');     // Line 20
error_log('meqll');        // Line 123
```

**Why fix:** These are clearly leftover development debug statements (including a typo "meqll"). They run on every single Fluent Forms submission (not just form 26), adding noise to error logs and indicating this file was never properly reviewed before deployment.

---

## HIGH Severity Issues

### 8. No API Rate Limiting Handling
**File:** `includes/fluent-forms/class-wicket-api-helper.php` (all API methods)
**Category:** Reliability - Silent Data Loss

No method checks for HTTP 429 (Too Many Requests) status codes, and there is no retry logic or backoff strategy. When rate-limited, API calls silently fail.

**Why fix:** During bulk sync operations, hitting rate limits causes the sync to silently skip users, leaving data in an inconsistent state with no indication of what failed.

---

### 9. Unchecked `json_decode()` Return Values
**File:** `includes/fluent-forms/class-wicket-api-helper.php` (15+ locations)
**Category:** Bug - Null Reference Errors

`json_decode()` returns `null` on invalid JSON, but code immediately accesses array keys on the result:

```php
$body = json_decode(wp_remote_retrieve_body($response), true);  // Could be null
if ($status_code === 200 && !empty($body['data'][0]['id'])) {   // Accessing null['data']
```

**Why fix:** If the Wicket API returns malformed JSON (network truncation, server error pages), this causes PHP notices/warnings and incorrect behavior rather than a clean error path.

---

### 10. Race Condition in Bulk Sync Lock
**File:** `includes/class-wicket-bulk-sync.php:393-416`
**Category:** Bug - Race Condition

```php
if (get_option('wicket_bulk_sync_in_progress', false)) {
    return;  // Check
}
// ... gap where another request can slip through ...
update_option('wicket_bulk_sync_in_progress', true);  // Set
```

**Why fix:** Two admins clicking "Start Sync" simultaneously can both pass the check before either sets the flag, causing concurrent bulk syncs that make duplicate API calls and potentially corrupt data.

---

### 11. Race Condition in Registration Sync
**File:** `includes/class-wicket-sync-on-user.php:84-94`
**Category:** Bug - Race Condition

Same check-then-set pattern for `wicket_registration_sync_done` user meta. Concurrent requests during login can trigger duplicate syncs.

**Why fix:** Duplicate syncs create duplicate records in Wicket CRM or trigger unnecessary API calls against rate limits.

---

### 12. Unbounded Pagination Loop
**File:** `includes/class-wicket-organizations.php:253-282`
**Category:** Reliability - Potential Infinite Loop

```php
while ($has_more) {
    $result = $this->fetch_organizations_page($token, $page, $page_size);
    // ... no maximum iteration limit ...
    $page++;
}
```

**Why fix:** If the Wicket API returns incorrect pagination metadata (e.g., always reporting "has more"), this loop runs forever, consuming memory and CPU until PHP times out or the server runs out of resources.

---

### 13. GitHub Token Exposed in URL Query Parameters
**File:** `includes/class-github-updater.php:251`
**Category:** Security - Credential Exposure

```php
$download_url = add_query_arg('access_token', $this->access_token, $download_url);
```

**Why fix:** URL query parameters are logged in web server access logs, HTTP Referer headers, proxy logs, and browser history. The GitHub token should be sent via an `Authorization` header instead.

---

### 14. Sensitive PII Logged to Error Log
**Files:** Multiple
**Category:** Security - Information Disclosure / GDPR

User emails, full form data, and person UUIDs are logged:
- `class-wicket-api-helper.php:134` - Logs user emails
- `class-wicket-bulk-sync.php:379` - Logs entire `$_POST` array
- `class-wicket-professional-info.php:38` - Logs full form data
- `class-wicket-additional-info-education.php:168` - Logs full form data
- Multiple other locations throughout the codebase

**Why fix:** Error logs are often accessible to hosting support, stored in plain text, and may be retained indefinitely. Logging PII violates GDPR data minimization principles and creates liability.

---

### 15. Hardcoded Form IDs Without Configuration
**Files:** Multiple form handlers
**Category:** Maintainability - Environment Portability

While personal details, professional info, address, and contact forms have configurable constants (`MYIES_FORM_PERSONAL_DETAILS`, etc.), other handlers hardcode form IDs:

| Handler | Hardcoded ID |
|---------|-------------|
| `class-additional-info-education.php:35` | 24 |
| `class-wicket-communication-preferences.php:271` | 27 |
| `class-organization-information.php:16` | 26 |
| `class-wicket-additional-info.php:296` | 1 (with comment "Replace with your form ID") |

**Why fix:** Moving to a staging environment or re-creating forms generates new IDs, requiring code changes and redeployment instead of a simple config update.

---

### 16. Wrong Fluent Forms Hook in Organization Handler
**File:** `includes/fluent-forms/class-organization-information.php:78`
**Category:** Bug - Incorrect Hook Timing

```php
add_action('fluentform/before_submission_confirmation', 'wicket_sync_organization_info_to_wicket', 10, 3);
```

All other form handlers use `fluentform/submission_inserted`. This handler fires at a different point in the form submission lifecycle.

**Why fix:** `before_submission_confirmation` fires before the submission is fully saved, meaning the handler operates on potentially incomplete data and could interfere with the confirmation flow.

---

### 17. Invalid Date Handling in Sync Interval Check
**File:** `includes/class-wicket-sync-on-user.php:119`
**Category:** Bug - Logic Error

```php
if ($last_sync && (time() - strtotime($last_sync)) < $sync_interval) {
```

If the stored `$last_sync` value is corrupted or in an unexpected format, `strtotime()` returns `false`, making the arithmetic produce an incorrect result (very large number), causing the sync to be permanently skipped for that user.

**Why fix:** Users who somehow get a corrupted `wicket_last_sync` meta value would never sync again without manual database intervention.

---

### 18. Unvalidated Tenant Name in URL Construction
**File:** `includes/fluent-forms/class-wicket-api-helper.php:71,74`
**Category:** Security - Potential SSRF

```php
return "https://{$tenant}-api.staging.wicketcloud.com";
```

The tenant name from `get_option()` is interpolated directly into URLs without format validation.

**Why fix:** If an admin (or attacker with admin access) sets the tenant name to a malicious value like `evil.com/attack#`, the resulting URL could point to an attacker-controlled server, leaking the JWT token.

---

### 19. Membership Delete-Then-Insert Without Transaction
**File:** `includes/class-wicket-memberships.php:142`
**Category:** Bug - Data Integrity

```php
$wpdb->delete($this->table_name, array('wp_user_id' => $user_id));
// ... API calls to fetch new data ...
// ... insert new records ...
```

**Why fix:** If the API call fails after the delete, the user loses all local membership records with no way to recover. This should use a database transaction or a staging table pattern.

---

## MEDIUM Severity Issues

### 20. Incomplete Singleton Pattern
**Files:** `myies-integration.php:31-46`, plus all classes using singleton pattern
**Category:** Architecture - Design Pattern

Singletons are missing `__clone()` and `__wakeup()` methods, allowing bypass via `clone` or `unserialize()`.

**Why fix:** While unlikely to be exploited accidentally, proper singletons should prevent all duplication paths. This is a standard WordPress plugin development practice.

---

### 21. Activation Hook Registered Inside `plugins_loaded`
**File:** `myies-integration.php:110-111`
**Category:** Architecture - WordPress Best Practices

`register_activation_hook()` is called inside the `plugins_loaded` action at priority 5 rather than at the top level of the plugin file.

**Why fix:** WordPress activation/deactivation hooks should be registered at the top level for reliability. The current approach works in practice but is fragile and doesn't follow WordPress coding standards.

---

### 22. Duplicate Activation Hook for Table Creation
**Files:** `myies-integration.php:110` and `includes/class-wicket-organizations.php:48`
**Category:** Bug - Double Execution

Both the main plugin and the Organizations class register their own activation hooks that call `create_tables()`. This causes table creation SQL to execute twice on activation.

**Why fix:** While `CREATE TABLE IF NOT EXISTS` prevents errors, running dbDelta twice is wasteful and makes it unclear which code is responsible for database setup.

---

### 23. Missing `permission_callback` Capability Check on Bulk Sync Status
**File:** `includes/class-wicket-bulk-sync.php:548-551`
**Category:** Security - Missing Authorization

The AJAX handler verifies the nonce but doesn't check `current_user_can('manage_options')`. Any logged-in user who obtains or guesses the nonce could check sync status.

**Why fix:** Sync status may reveal operational details (number of users, sync errors, timing) that should be restricted to administrators.

---

### 24. Loose Type Comparisons for HTTP Status Codes
**File:** `includes/fluent-forms/class-wicket-api-helper.php:366,475`
**Category:** Bug - Type Safety

```php
'success' => ($code == 200 || $code == 201),  // Loose comparison
// vs.
'success' => ($code === 200),                 // Strict comparison (used elsewhere)
```

**Why fix:** While unlikely to cause issues in practice (WordPress returns integer status codes), mixing loose and strict comparisons is inconsistent and could mask bugs if the return type changes.

---

### 25. No HTTP Status Code Differentiation in API Error Handling
**File:** `includes/fluent-forms/class-wicket-api-helper.php` (throughout)
**Category:** Reliability - Error Handling

API methods treat all non-200 responses identically. There's no distinction between:
- 401/403 (authentication failure - don't retry)
- 404 (not found - expected in some flows)
- 429 (rate limited - retry with backoff)
- 500/502/503 (server error - retry once)

**Why fix:** Treating all errors the same prevents intelligent retry logic and makes debugging production issues much harder.

---

### 26. Silent Failures in Plugin Activation
**File:** `myies-integration.php:120-135`
**Category:** Reliability - Error Handling

If `class_exists()` returns false during activation, tables are never created and no error is reported to the admin.

**Why fix:** If a dependency file has a syntax error, the plugin activates "successfully" but database tables don't exist, causing obscure errors later.

---

### 27. N+1 Query Pattern in Connection Checking
**File:** `includes/fluent-forms/class-wicket-api-helper.php:804-816`
**Category:** Performance

`create_person_org_connection()` fetches ALL connections for a person, then loops through them to check for duplicates, rather than using an API filter.

**Why fix:** For users with many organization connections, this wastes bandwidth and API quota fetching data that isn't needed.

---

### 28. In-Memory Only UUID Cache
**File:** `includes/fluent-forms/class-wicket-api-helper.php:52,121-145`
**Category:** Performance - Missing Persistent Cache

The UUID cache only exists for the current PHP request. Every new page load re-fetches UUIDs from user meta (and potentially from the API).

**Why fix:** Using WordPress transients for caching would reduce database queries across requests, particularly beneficial during high-traffic periods.

---

### 29. Hardcoded API Timeouts
**File:** `includes/fluent-forms/class-wicket-api-helper.php` (16 locations)
**Category:** Maintainability - Configuration

All API requests use hardcoded 30s or 60s timeouts with no way to adjust per-environment.

**Why fix:** Staging environments with slower networks may need longer timeouts. A `apply_filters('wicket_api_timeout', 30)` would allow site-specific tuning.

---

### 30. `json_encode()` Without Error Checking
**File:** `includes/fluent-forms/class-wicket-api-helper.php` (10 locations)
**Category:** Bug - Silent Failure

`json_encode()` can return `false` on error (e.g., non-UTF8 characters in user data), but the result is passed directly as the request body.

**Why fix:** A user with special characters in their name could cause all their API sync calls to silently send `false` as the body, resulting in API errors.

---

### 31. Debug File Included in Production
**File:** `includes/fluent-forms/debug-fluent-forms-all.php`
**Category:** Code Quality - Unnecessary Code

```php
function debug_all_fluent_forms_submissions($entry_id, $form_data, $form) {
    error_log('!!! FORM SUBMITTED - ID: ' . $form->id . ' !!!');;  // double semicolon
}
add_action('fluentform/submission_inserted', 'debug_all_fluent_forms_submissions', 1, 3);
```

**Why fix:** Even if not loaded by the main plugin file currently, this file exists in the codebase and could accidentally be included, logging every form submission.

---

### 32. Duplicate API URL Construction Functions
**Files:**
- `class-wicket-additional-info.php:405-414` - `wicket_get_api_url_additional_info()`
- `class-additional-info-education.php:476-485` - `wicket_get_api_url_education()`
- `class-wicket-communication-preferences.php:348-357` - `wicket_get_api_url_comm_prefs()`

**Category:** Architecture - Code Duplication

Three identical copies of the API URL construction logic exist when `Wicket_API_Helper::get_api_url()` already provides this functionality.

**Why fix:** Same as the JWT duplication - any URL structure change requires updating multiple files.

---

### 33. Unescaped AJAX HTML Response Insertion
**File:** `assets/js/modal-membership-history.js:171`
**Category:** Security - Defense in Depth

```javascript
$(config.selectors.tableContainer).html(response.data.html);
```

Raw HTML from AJAX is inserted via `.html()`. While server-side escaping exists, this creates a fragile dependency.

**Why fix:** If server-side escaping is ever removed or bypassed, this becomes an XSS vector. Adding client-side sanitization provides defense in depth.

---

### 34. Missing Dependency Plugin Checks
**File:** `myies-integration.php`
**Category:** Reliability - Graceful Degradation

No validation that required plugins (Fluent Forms, SureCart, PMPro, ACF, Bricks Builder) are active before loading integration code.

**Why fix:** If Fluent Forms is deactivated, 11 form handler classes are loaded and hook into non-existent events, wasting resources and potentially causing errors.

---

### 35. `$_POST` Data Logged Without Sanitization
**File:** `includes/class-wicket-bulk-sync.php:379`
**Category:** Security - Information Disclosure

```php
error_log('Wicket Bulk Sync: POST data: ' . print_r($_POST, true));
```

**Why fix:** Full POST data may contain sensitive information. This should log only the relevant keys.

---

## LOW Severity Issues

### 36. Inconsistent Initialization Patterns
**Files:** Multiple
**Category:** Architecture - Consistency

The codebase mixes singleton pattern (`get_instance()`), direct instantiation (`new WicketLoginSync()`), and global helper functions (`wicket_api()`).

**Why fix:** Inconsistency makes the codebase harder for new developers to understand and maintain.

---

### 37. Missing PHP Type Hints
**File:** `includes/fluent-forms/class-wicket-api-helper.php` (throughout)
**Category:** Code Quality

No PHP 7.4+ type hints on method parameters or return types.

**Why fix:** Type hints improve IDE support, catch type errors earlier, and serve as documentation.

---

### 38. Inconsistent Error Logging Functions
**Files:** Multiple
**Category:** Code Quality - Consistency

Some files use `myies_log()`, others use `error_log()` directly. The centralized `Wicket_Error_Handler` exists but isn't consistently used.

**Why fix:** Centralized logging allows controlling log verbosity, format, and destination from one place.

---

### 39. Hardcoded Staging API Domain
**File:** `includes/fluent-forms/class-wicket-api-helper.php:71,74`
**Category:** Maintainability

API domain format `{tenant}-api.staging.wicketcloud.com` is hardcoded. If Wicket changes their domain structure, code must be modified.

**Why fix:** Making this filterable (`apply_filters()`) allows overriding without code changes.

---

### 40. Text Domain Loaded After Plugin Initialization
**File:** `myies-integration.php:114,170`
**Category:** WordPress Best Practices

Text domain loads at `plugins_loaded` priority 10, but plugin initializes at priority 5. Any translatable strings used during initialization won't be translated.

**Why fix:** Minor UX issue for non-English users where early admin notices or error messages appear in English.

---

### 41. No UUID Format Validation
**File:** `includes/frontend/class-company-functions.php:463`
**Category:** Input Validation

```php
$org_uuid = isset($_POST['org_uuid']) ? sanitize_text_field($_POST['org_uuid']) : '';
```

UUIDs are sanitized as text but never validated against UUID format (`/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i`).

**Why fix:** Invalid UUIDs sent to the Wicket API generate unnecessary error responses.

---

### 42. Double Semicolon in Debug File
**File:** `includes/fluent-forms/debug-fluent-forms-all.php:17`
**Category:** Code Quality

```php
error_log('!!! UNIVERSAL FLUENT FORMS DEBUG LOADED !!!');;
```

**Why fix:** Cosmetic, but indicates lack of code review.

---

### 43. Inline CSS in `wp_head`
**File:** `includes/shortcodes/class-person-details-shortcode.php:73-87`
**Category:** Performance / Best Practices

```php
add_action('wp_head', 'acf_user_fieldgroup_styles');
```

CSS is injected into every page's `<head>` regardless of whether the shortcode is used.

**Why fix:** Should use `wp_enqueue_style` and only load when the shortcode is actually rendered.

---

### 44. Missing Error Context in Silent Returns
**File:** `includes/fluent-forms/class-wicket-api-helper.php` (10+ locations)
**Category:** Debugging - Error Context

Many functions return `null` or empty arrays on error without any logging:

```php
if (is_wp_error($response)) {
    return null;  // No logging - debugging is impossible
}
```

**Why fix:** When sync issues are reported, there's no trail to follow. Every error path should log what went wrong.

---

### 45. `$display_value` Not Re-sanitized After Concatenation
**File:** `includes/fluent-forms/class-wicket-personal-details.php:227-230`
**Category:** Code Quality

While individual fields are sanitized, concatenated values are saved to meta without re-sanitization.

**Why fix:** Low risk since individual parts are clean, but violates the principle of sanitizing at the point of storage.

---

### 46. Inconsistent Null Coalescing for Nested Arrays
**File:** `includes/fluent-forms/class-wicket-api-helper.php:505,531,781`
**Category:** Bug - Edge Case

```php
return $body['data'] ?? array();  // If $body is null, this still returns null in PHP 7.4
```

**Why fix:** When `$body` is `null`, accessing `$body['data']` doesn't trigger the null coalescing operator - it triggers a notice and evaluates to `null`.

---

### 47. Default Values May Conflict with API Schema
**File:** `includes/fluent-forms/class-additional-info-education.php:385-407`
**Category:** Data Quality

Required fields default to `'no'` when not provided, but the Wicket API schema may expect different values or formats.

**Why fix:** Could cause silent API validation failures if `'no'` isn't a valid enum value.

---

## Recommended Priority Order for Fixes

### Phase 1 - Security (Immediate)
1. Add `permission_callback` to REST endpoint (#1)
2. Escape ACF shortcode output (#2)
3. Move GitHub token to Authorization header (#13)
4. Add capability check to bulk sync status (#23)

### Phase 2 - Critical Bugs (This Sprint)
5. Fix `comapny_name_alt` typo (#3)
6. Standardize on single UUID meta key (#4)
7. Remove debug statements (#7)
8. Fix `strtotime()` validation (#17)
9. Add `json_decode()` null checks (#9)

### Phase 3 - Architecture (Next Sprint)
10. Consolidate JWT generation into `Wicket_API_Helper` (#6, #32)
11. Add JWT token caching (#5)
12. Implement rate limit handling with retry logic (#8)
13. Add API error code differentiation (#25)
14. Make form IDs configurable via constants (#15)

### Phase 4 - Reliability (Planned)
15. Fix race conditions with transient locks (#10, #11)
16. Add pagination safety limits (#12)
17. Wrap membership sync in transactions (#19)
18. Add dependency plugin checks (#34)
19. Sanitize logged data (#14, #35)

### Phase 5 - Code Quality (Ongoing)
20. Consistent singleton pattern (#20)
21. Add type hints (#37)
22. Standardize error logging (#38, #44)
23. Remove debug file (#31)
24. Fix remaining low-severity items
