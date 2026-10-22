<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/includes/crowdsourcing_guard.php';

$TESTS = [
    'validates_reunion_photo_submission_and_dpa_consent' => function (): void {
        $validPost = [
            'website_url_hp' => '',
            'dpa_consent' => '1',
            'contribution_type' => 'reunions',
            'submitter_name' => 'Eng. David Mwangi',
            'submitter_email' => 'david@example.org',
            'dcterms_title' => 'Class of 1974 45th Reunion Luncheon',
            'dcterms_type' => 'Milestone Reunion',
            'dcterms_temporal' => '2019-11-16',
            'dcterms_subject' => '1974 | 1975',
            'dcterms_spatial' => 'Muthaiga Country Club, Nairobi',
            'dcterms_description' => 'Members of the Class of 1974 gathered for their 45th anniversary.',
        ];
        $res = ahsobc_validate_crowdsourced_submission($validPost);
        if (!$res['valid']) {
            throw new \RuntimeException('Valid reunion submission rejected: ' . json_encode($res['errors']));
        }

        $missingConsent = $validPost;
        $missingConsent['dpa_consent'] = '0';
        $resNoDpa = ahsobc_validate_crowdsourced_submission($missingConsent);
        if ($resNoDpa['valid']) {
            throw new \RuntimeException('Submission without Kenya DPA 2019 consent must be rejected');
        }
    },
    'rejects_honeypot_spam_and_validates_face_identification' => function (): void {
        $spam = ahsobc_validate_crowdsourced_submission([
            'website_url_hp' => 'https://spam.example.com',
            'dpa_consent' => '1',
        ]);
        if ($spam['valid']) {
            throw new \RuntimeException('Honeypot submission must be rejected');
        }

        $faceId = ahsobc_validate_crowdsourced_submission([
            'website_url_hp' => '',
            'dpa_consent' => '1',
            'contribution_type' => 'face_id',
            'submitter_name' => 'Peter Onyango',
            'submitter_email' => 'peter@example.org',
            'target_item_id' => '1932-athletics-squad',
            'row' => '1',
            'position_from_left' => '5',
            'full_name' => 'Hon. Eliud Mathu',
            'school_number' => '32',
            'cohort' => '1932',
            'house' => 'Arthur',
            'iiif_region' => 'xywh=200,150,120,160',
        ]);
        if (!$faceId['valid']) {
            throw new \RuntimeException('Valid face_id submission failed: ' . json_encode($faceId['errors']));
        }
    },
    'validates_csrf_editor_token_and_dpa_takedown_requests' => function (): void {
        $token = bin2hex(random_bytes(32));
        if (!ahsobc_verify_csrf_token($token, $token)) {
            throw new \RuntimeException('Expected matching CSRF token to pass verification');
        }
        if (ahsobc_verify_csrf_token('wrong_token', $token)) {
            throw new \RuntimeException('Expected mismatched CSRF token to fail verification');
        }
        if (!ahsobc_verify_editor_token('secret-archivist-key', 'secret-archivist-key')) {
            throw new \RuntimeException('Expected matching editor token to pass verification');
        }
        if (ahsobc_verify_editor_token('wrong-key', 'secret-archivist-key')) {
            throw new \RuntimeException('Expected mismatched editor token to fail verification');
        }

        // Verify Kenya DPA 2019 Takedown submission (Issue #17)
        $takedown = ahsobc_validate_crowdsourced_submission([
            'website_url_hp' => '',
            'dpa_consent' => '1',
            'contribution_type' => 'dpa_takedown',
            'submitter_name' => 'Dr. Joseph Kamau',
            'submitter_email' => 'kamau@example.org',
            'target_item_id' => '1985-25th-reunion-dinner',
            'takedown_reason' => 'Please update my school number attribution or remove the tag per Kenya DPA 2019.',
        ]);
        if (!$takedown['valid']) {
            throw new \RuntimeException('Valid dpa_takedown submission failed: ' . json_encode($takedown['errors']));
        }
    },
    'enforces_sliding_window_ip_rate_limit' => function (): void {
        $tmpFile = sys_get_temp_dir() . '/ahsobc_test_rl_' . uniqid('', true) . '.json';
        try {
            if (!ahsobc_check_rate_limit('192.0.2.55', $tmpFile, 2, 3600)) {
                throw new \RuntimeException('First request should pass rate limit');
            }
            if (!ahsobc_check_rate_limit('192.0.2.55', $tmpFile, 2, 3600)) {
                throw new \RuntimeException('Second request should pass rate limit');
            }
            if (ahsobc_check_rate_limit('192.0.2.55', $tmpFile, 2, 3600)) {
                throw new \RuntimeException('Third request should be blocked by rate limit of 2');
            }
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    },
];
