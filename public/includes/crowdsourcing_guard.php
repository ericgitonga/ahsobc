<?php
/**
 * Crowdsourcing Submission Validator, Anti-Spam Honeypot, IP Rate Limiter,
 * and Kenya Data Protection Act (DPA) 2019 Consent Guard for AHSOBC Collecting Forms.
 */

declare(strict_types=1);

require_once __DIR__ . '/temporal_parser.php';
require_once __DIR__ . '/cohort_helper.php';

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

    if ($contributionType === 'face_id') {
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
        $description = trim((string) ($post['dcterms_description'] ?? ''));
        if (mb_strlen($description) < 5) {
            $errors[] = 'Please include a brief caption or description of the occasion (at least 5 characters).';
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
    $ipHash = hash('sha256', 'ahsobc_rl_' . $clientIp);
    $now = time();
    $cutoff = $now - $windowSeconds;

    $data = [];
    if (is_file($storageFile)) {
        $raw = @file_get_contents($storageFile);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
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

    $dir = dirname($storageFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($storageFile, json_encode($data), LOCK_EX);
    return true;
}
