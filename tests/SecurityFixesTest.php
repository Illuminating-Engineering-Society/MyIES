<?php
/**
 * Tests to verify security fixes are in place
 */

use PHPUnit\Framework\TestCase;

class SecurityFixesTest extends TestCase
{
    // =========================================================================
    // XSS FIX VERIFICATION (#2)
    // =========================================================================

    public function testEscHtmlPreventsXss(): void
    {
        $malicious = '<script>alert("XSS")</script>';
        $escaped = esc_html($malicious);
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    public function testEscHtmlHandlesQuotes(): void
    {
        $input = '" onmouseover="alert(1)"';
        $escaped = esc_html($input);
        $this->assertStringNotContainsString('"', $escaped);
    }

    // =========================================================================
    // FIELD NAME TYPO FIX VERIFICATION (#3)
    // =========================================================================

    public function testOrganizationInfoFileHasCorrectFieldName(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-organization-information.php');
        $this->assertStringContainsString("company_name_alt", $content, 'Should use company_name_alt (not comapny_name_alt)');
        $this->assertStringNotContainsString("comapny_name_alt", $content, 'Typo comapny_name_alt should be fixed');
    }

    // =========================================================================
    // DEBUG STATEMENT REMOVAL VERIFICATION (#7)
    // =========================================================================

    public function testNoDebugStatementsInOrganizationInfo(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-organization-information.php');
        $this->assertStringNotContainsString("error_log('hi')", $content, 'Debug error_log("hi") should be removed');
        $this->assertStringNotContainsString("error_log('hi there')", $content, 'Debug error_log("hi there") should be removed');
        $this->assertStringNotContainsString("error_log('meqll')", $content, 'Debug error_log("meqll") should be removed');
    }

    public function testDebugFileIsDisabled(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/debug-fluent-forms-all.php');
        // Should NOT have active (uncommented) add_action calls
        $lines = explode("\n", $content);
        $hasActiveHandler = false;
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            // Check for uncommented add_action
            if (strpos($trimmed, 'add_action(') === 0) {
                $hasActiveHandler = true;
                break;
            }
        }
        $this->assertFalse($hasActiveHandler, 'Debug form handler should be commented out');
    }

    // =========================================================================
    // UUID STANDARDIZATION VERIFICATION (#4)
    // =========================================================================

    public function testContactDetailsChecksBothUuidKeys(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-contact-details.php');
        $this->assertStringContainsString("wicket_person_uuid", $content, 'Should check wicket_person_uuid');
        $this->assertStringContainsString("wicket_uuid", $content, 'Should fallback to wicket_uuid');
    }

    public function testEducationHandlerChecksBothUuidKeys(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-additional-info-education.php');
        $this->assertStringContainsString("wicket_person_uuid", $content, 'Should check wicket_person_uuid');
        $this->assertStringContainsString("wicket_uuid", $content, 'Should fallback to wicket_uuid');
    }

    public function testCommPrefsHandlerChecksBothUuidKeys(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-communication-preferences.php');
        $this->assertStringContainsString("wicket_person_uuid", $content, 'Should check wicket_person_uuid');
        $this->assertStringContainsString("wicket_uuid", $content, 'Should fallback to wicket_uuid');
    }

    // =========================================================================
    // REST ENDPOINT PERMISSION FIX (#1)
    // =========================================================================

    public function testSurecartSyncHasPermissionCallback(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-surecart-wicket-sync.php');
        $this->assertStringContainsString('permission_callback', $content, 'REST endpoint must have permission_callback');
        $this->assertStringContainsString('edit_users', $content, 'Permission should require edit_users capability');
    }

    // =========================================================================
    // GITHUB TOKEN FIX (#13)
    // =========================================================================

    public function testGithubUpdaterUsesAuthHeader(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-github-updater.php');
        $this->assertStringContainsString('add_auth_header_to_download', $content, 'Should use Authorization header method');
        $this->assertStringNotContainsString("add_query_arg('access_token'", $content, 'Should not expose token in URL query params');
    }

    // =========================================================================
    // HOOK FIX VERIFICATION (#16)
    // =========================================================================

    public function testOrganizationInfoUsesCorrectHook(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-organization-information.php');
        $this->assertStringContainsString("fluentform/submission_inserted", $content, 'Should use submission_inserted hook');
        $this->assertStringNotContainsString("fluentform/before_submission_confirmation", $content, 'Should not use before_submission_confirmation hook');
    }

    // =========================================================================
    // BULK SYNC LOCK FIX (#10)
    // =========================================================================

    public function testBulkSyncUsesTransientLock(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-bulk-sync.php');
        $this->assertStringContainsString('wicket_bulk_sync_lock', $content, 'Should use transient-based lock');
    }

    // =========================================================================
    // PAGINATION SAFETY LIMIT (#12)
    // =========================================================================

    public function testOrganizationSyncHasPageLimit(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-organizations.php');
        $this->assertStringContainsString('max_pages', $content, 'Should have maximum page limit');
    }

    // =========================================================================
    // SQL INJECTION FIX VERIFICATION
    // =========================================================================

    public function testOrganizationsUsesPreparedShowTables(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-organizations.php');
        // Verify all SHOW TABLES use $wpdb->prepare()
        $this->assertStringContainsString('prepare("SHOW TABLES LIKE %s"', $content, 'Should use $wpdb->prepare()');
        // Ensure no raw interpolation remains (checking the vulnerable pattern)
        preg_match_all('/get_var\(\s*"SHOW TABLES/', $content, $matches);
        $this->assertEmpty($matches[0], 'Should not use raw string in SHOW TABLES');
    }

    // =========================================================================
    // SENSITIVE DATA LOGGING FIX (#14)
    // =========================================================================

    public function testBulkSyncDoesNotLogPostData(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-bulk-sync.php');
        $this->assertStringNotContainsString('print_r($_POST', $content, 'Should not log raw POST data');
    }

    // =========================================================================
    // STRTOTIME VALIDATION FIX (#17)
    // =========================================================================

    public function testSyncOnUserValidatesStrtotime(): void
    {
        $content = file_get_contents(WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-sync-on-user.php');
        $this->assertStringContainsString('$last_sync_time', $content, 'Should validate strtotime result before using');
    }
}
