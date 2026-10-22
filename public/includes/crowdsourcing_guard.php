<?php
/**
 * Crowdsourcing Submission Validator, Anti-Spam Honeypot, IP Rate Limiter,
 * and Kenya Data Protection Act (DPA) 2019 Consent Guard for AHSOBC Collecting Forms.
 */

declare(strict_types=1);

require_once __DIR__ . '/temporal_parser.php';
require_once __DIR__ . '/cohort_helper.php';
require_once __DIR__ . '/manifest_validator.php';

/**
 * Generates or returns the current session's cryptographic CSRF token.
 */
function ahsobc_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (empty($_SESSION['ahsobc_csrf_token']) || !is_string($_SESSION['ahsobc_csrf_token'])) {
        $_SESSION['ahsobc_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ahsobc_csrf_token'];
}

/**
 * Verifies a submitted CSRF token in constant time using hash_equals().
 */
function ahsobc_verify_csrf_token(?string $submittedToken, ?string $expectedToken = null): bool
{
    if ($expectedToken === null) {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $expectedToken = isset($_SESSION['ahsobc_csrf_token']) && is_string($_SESSION['ahsobc_csrf_token'])
            ? $_SESSION['ahsobc_csrf_token']
            : '';
    }
    $submitted = trim((string) $submittedToken);
    if ($expectedToken === '' || $submitted === '') {
        return false;
    }
    return hash_equals($expectedToken, $submitted);
}

/**
 * Verifies an optional archivist editor token (AHSOBC_EDITOR_TOKEN) in constant time.
 * When AHSOBC_EDITOR_TOKEN is configured in the environment, direct metadata mutations
 * must supply a matching token; when unset (default in local preview), CSRF verification suffices.
 */
function ahsobc_verify_editor_token(?string $providedToken, ?string $configuredToken = null): bool
{
    $expected = $configuredToken !== null
        ? $configuredToken
        : (string) (getenv('AHSOBC_EDITOR_TOKEN') ?: '');
    if ($expected === '') {
        return true;
    }
    $candidate = trim((string) $providedToken);
    if ($candidate === '') {
        return false;
    }
    return hash_equals($expected, $candidate);
}

/**
 * Validates a crowdsourced alumni contribution submission across the 4 Collecting types.
 *
 * @param array<string, mixed> $post Submitted form fields.
 * @return array{
 *   valid: bool,
 *   sanitized: array<string, mixed>,
 *   errors: list<string>
 * }
 */
function ahsobc_validate_crowdsourced_submission(array $post): array
{
    $errors = [];

    // 1. Honeypot anti-spam trap (must remain empty)
    $honeypot = trim((string) ($post['website_url_hp'] ?? ''));
    if ($honeypot !== '') {
        return [
            'valid' => false,
            'sanitized' => [],
            'errors' => ['Automated spam submission rejected.'],
        ];
    }

    // 2. Kenya DPA 2019 Consent verification
    $dpaConsent = filter_var($post['dpa_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$dpaConsent) {
        $errors[] = 'You must confirm archival consent under the Kenya Data Protection Act (2019) before submitting.';
    }

    // 3. Contribution type
    $contributionType = trim((string) ($post['contribution_type'] ?? ''));
    if (!array_key_exists($contributionType, AHSOBC_CONTRIBUTION_TYPES)) {
        $errors[] = 'Please select a valid contribution category (Reunions, Informal Encounters, Face Identification, or Lifespans).';
    }

    // 4. Submitter Name & Contact
    $submitterName = trim((string) ($post['submitter_name'] ?? ''));
    if (mb_strlen($submitterName) < 2 || mb_strlen($submitterName) > 100) {
        $errors[] = 'Please provide your name (2–100 characters).';
    } elseif (ahsobc_has_csv_injection($submitterName)) {
        $errors[] = 'Submitter name cannot begin with a spreadsheet formula character.';
    }

    $submitterEmail = trim((string) ($post['submitter_email'] ?? ''));
    if ($submitterEmail === '' || !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address for archivist follow-up.';
    }

    $sanitized = [
        'contribution_type' => $contributionType,
        'submitter_name' => $submitterName,
        'submitter_email' => $submitterEmail,
        'dpa_consent' => $dpaConsent,
        'dpa_consent_timestamp' => gmdate('c'),
    ];

    if ($contributionType === 'dpa_takedown') {
        $targetItemId = trim((string) ($post['target_item_id'] ?? ''));
        if ($targetItemId === '') {
            $errors[] = 'Please select the photograph or record your DPA takedown/objection request applies to.';
        }
        $takedownReason = trim((string) ($post['takedown_reason'] ?? ''));
        if (mb_strlen($takedownReason) < 10 || mb_strlen($takedownReason) > 1000) {
            $errors[] = 'Please describe your Kenya DPA (2019) takedown or correction request (10–1000 characters).';
        } elseif (ahsobc_has_csv_injection($takedownReason)) {
            $errors[] = 'Takedown reason cannot begin with a spreadsheet formula character.';
        }
        $sanitized['target_item_id'] = $targetItemId;
        $sanitized['takedown_reason'] = $takedownReason;
    } elseif ($contributionType === 'face_id') {
        // Contribution Type 3: Historic Face Identification
        $targetItemId = trim((string) ($post['target_item_id'] ?? ''));
        if ($targetItemId === '') {
            $errors[] = 'Please select the photograph you are identifying a classmate in.';
        }
        $faceResult = ahsobc_format_face_identification([
            'row' => $post['row'] ?? 0,
            'position_from_left' => $post['position_from_left'] ?? 0,
            'full_name' => $post['full_name'] ?? '',
            'school_number' => $post['school_number'] ?? '',
            'cohort' => $post['cohort'] ?? '',
            'house' => $post['house'] ?? '',
            'iiif_region' => $post['iiif_region'] ?? '',
        ]);
        if (!$faceResult['valid']) {
            $errors = array_merge($errors, $faceResult['errors']);
        } else {
            $sanitized['target_item_id'] = $targetItemId;
            $sanitized['face_identification'] = $faceResult['structured'];
            $sanitized['formatted_annotation'] = $faceResult['formatted_note'];
        }
    } elseif (in_array($contributionType, ['reunions', 'informal', 'lifespans'], true)) {
        // Contribution Types 1, 2, 4: Photo + Metadata submission
        $title = trim((string) ($post['dcterms_title'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 180) {
            $errors[] = 'Photograph title must be between 3 and 180 characters.';
        } elseif (ahsobc_has_csv_injection($title)) {
            $errors[] = 'Photograph title cannot begin with a spreadsheet formula character.';
        }

        $contextType = trim((string) ($post['dcterms_type'] ?? (AHSOBC_CONTRIBUTION_TYPES[$contributionType]['default_context'] ?? 'School Days')));
        if (!array_key_exists($contextType, AHSOBC_PHOTO_CONTEXTS)) {
            $errors[] = 'Please select a valid Photo Context / Category.';
        }

        $temporalRaw = trim((string) ($post['dcterms_temporal'] ?? ''));
        $temporalParsed = ahsobc_parse_temporal($temporalRaw);
        if (!$temporalParsed['valid']) {
            $errors[] = $temporalParsed['error'] ?? 'Please provide a valid capture date or circa year.';
        }

        $cohortRaw = trim((string) ($post['dcterms_subject'] ?? ''));
        $cohortParsed = ahsobc_parse_cohorts($cohortRaw);
        if (!$cohortParsed['valid']) {
            $errors[] = $cohortParsed['error'] ?? 'Please provide at least one valid AHS Class Cohort year.';
        }

        $spatial = trim((string) ($post['dcterms_spatial'] ?? ''));
        if ($spatial !== '' && ahsobc_has_csv_injection($spatial)) {
            $errors[] = 'Location / Venue cannot begin with a spreadsheet formula character.';
        }
        $description = trim((string) ($post['dcterms_description'] ?? ''));
        if (mb_strlen($description) < 5) {
            $errors[] = 'Please include a brief caption or description of the occasion (at least 5 characters).';
        } elseif (ahsobc_has_csv_injection($description)) {
            $errors[] = 'Description cannot begin with a spreadsheet formula character.';
        }

        $sanitized['dcterms:title'] = $title;
        $sanitized['dcterms:type'] = $contextType;
        $sanitized['dcterms:temporal'] = $temporalRaw;
        $sanitized['dcterms:subject'] = $cohortRaw;
        $sanitized['dcterms:spatial'] = $spatial;
        $sanitized['dcterms:description'] = $description;
        $sanitized['parsed_temporal'] = $temporalParsed;
        $sanitized['parsed_cohorts'] = $cohortParsed;
    }

    return [
        'valid' => count($errors) === 0,
        'sanitized' => $sanitized,
        'errors' => $errors,
    ];
}

/**
 * Sliding-window IP rate limiter backed by a local JSON file in system temp/storage.
 *
 * @param string $clientIp Raw client IP address (hashed with SHA-256 so raw IPs are not stored).
 * @param string $storageFile Path to rate-limit state file.
 * @param int $maxRequests Maximum submissions allowed within $windowSeconds.
 * @param int $windowSeconds Sliding window duration in seconds.
 */
function ahsobc_check_rate_limit(
    string $clientIp,
    string $storageFile,
    int $maxRequests = 10,
    int $windowSeconds = 3600
): bool {
    $secretSalt = (string) (getenv('AHSOBC_RL_SECRET') ?: 'ahsobc_rl_centennial_2026');
    $ipHash = hash_hmac('sha256', $clientIp, $secretSalt . '|' . gmdate('Y-m-d'));
    $now = time();
    $cutoff = $now - $windowSeconds;

    $dir = dirname($storageFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $fp = @fopen($storageFile, 'c+');
    if ($fp === false) {
        return true;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return true;
        }

        $raw = stream_get_contents($fp);
        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Prune expired timestamps across all buckets
        foreach ($data as $key => $timestamps) {
            if (!is_array($timestamps)) {
                unset($data[$key]);
                continue;
            }
            $filtered = array_values(array_filter(
                $timestamps,
                static fn($ts): bool => is_int($ts) && $ts > $cutoff
            ));
            if ($filtered) {
                $data[$key] = $filtered;
            } else {
                unset($data[$key]);
            }
        }

        $currentBucket = $data[$ipHash] ?? [];
        if (count($currentBucket) >= $maxRequests) {
            return false;
        }

        $currentBucket[] = $now;
        $data[$ipHash] = $currentBucket;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode($data));
        fflush($fp);
        return true;
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}
