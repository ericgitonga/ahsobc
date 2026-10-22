<?php
/**
 * Canonical archival schema, controlled vocabularies, and crowdsourcing definitions
 * for the AHSOBC Centennial Photo Gallery (Omeka S v4.1.1 implementation).
 *
 * Implements Phase 3.1, Phase 3.2, and Phase 4.2 of OmekaS.pdf alongside
 * the Bush domain taxonomy and Kenya Data Protection Act (DPA) 2019 guardrails.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Controlled vocabulary for dcterms:type ("Photo Context / Category" — OmekaS.pdf 3.1).
 */
const AHSOBC_PHOTO_CONTEXTS = [
    'School Days' => 'Historical and contemporary campus photographs from student eras (academics, sports, chapel, dormitories).',
    'Milestone Reunion' => 'Snapshots and formal portraits from 10th, 25th, 40th, or 50th anniversary reunions, Old Boys Weekend, and Founders Day.',
    'Regional Gathering' => 'Chapter meetings and structured alumni events across Kenya, East Africa, the UK, North America, and globally.',
    'Informal Encounter' => 'Casual photographs of Old Boys meeting at conferences, weddings, holidays, or professional gatherings.',
];

/**
 * Official Alliance High School ("Bush") Houses for cohort/house faceting.
 */
const AHSOBC_HOUSES = [
    'Livingstone',
    'Aggrey',
    'Wilberforce',
    'Grieve',
    'Arthur',
    'Francis',
    'Smith',
    'Sellwood',
];

/**
 * Centennial eras spanning 1926–2026 for timeline scrubbing.
 */
const AHSOBC_CENTENNIAL_ERAS = [
    [
        'id' => 'founding',
        'label' => 'Founding & Early Bush (1926–1949)',
        'year_start' => 1926,
        'year_end' => 1949,
        'decades' => ['1920s', '1930s', '1940s'],
    ],
    [
        'id' => 'carey-francis-independence',
        'label' => 'Carey Francis & Independence Era (1950–1969)',
        'year_start' => 1950,
        'year_end' => 1969,
        'decades' => ['1950s', '1960s'],
    ],
    [
        'id' => 'late-20th-century',
        'label' => 'Expansion & Late 20th Century (1970–1999)',
        'year_start' => 1970,
        'year_end' => 1999,
        'decades' => ['1970s', '1980s', '1990s'],
    ],
    [
        'id' => 'centennial-21st-century',
        'label' => '21st Century & Centennial Era (2000–2026)',
        'year_start' => 2000,
        'year_end' => 2026,
        'decades' => ['2000s', '2010s', '2020s'],
    ],
];

/**
 * Expanded Dublin Core Lifecycle Schema for the "Alumni Photograph" Resource Template (OmekaS.pdf 3.1).
 */
const AHSOBC_ALUMNI_PHOTOGRAPH_TEMPLATE = [
    'label' => 'Alumni Photograph',
    'resource_class' => 'dctype:StillImage',
    'properties' => [
        [
            'term' => 'dcterms:title',
            'label' => 'Photograph Title',
            'data_type' => 'literal',
            'required' => true,
            'description' => 'E.g., "1984 25th Reunion Banquet" or "Class of \'62 Informal Lunch (2015)".',
        ],
        [
            'term' => 'dcterms:type',
            'label' => 'Photo Context / Category',
            'data_type' => 'customvocab:AHS Photo Context',
            'required' => true,
            'description' => 'School Days, Milestone Reunion, Regional Gathering, or Informal Encounter.',
        ],
        [
            'term' => 'dcterms:temporal',
            'label' => 'Circa / Event Date',
            'data_type' => 'literal',
            'required' => true,
            'description' => 'Capture date or fuzzy range (e.g., "circa 1952" or "2014-10-18").',
        ],
        [
            'term' => 'dcterms:relation',
            'label' => 'Featured Alumni',
            'data_type' => 'resource:item',
            'required' => false,
            'description' => 'Links to one or multiple depicted Alumni Profile items.',
        ],
        [
            'term' => 'dcterms:subject',
            'label' => 'Class Cohort(s)',
            'data_type' => 'literal',
            'required' => false,
            'description' => 'Graduation year(s) of represented alumni (e.g., Class of 1978, or 1974 | 1975).',
        ],
        [
            'term' => 'dcterms:spatial',
            'label' => 'Location / Venue',
            'data_type' => 'literal',
            'required' => false,
            'description' => 'School campus building, reunion banquet hall, or city of encounter.',
        ],
        [
            'term' => 'dcterms:description',
            'label' => 'Archival Caption & Row Notes',
            'data_type' => 'literal',
            'required' => false,
            'description' => 'Historical context, occasion notes, and row-by-row face identifications.',
        ],
        [
            'term' => 'dcterms:isPartOf',
            'label' => 'House / Society / Team',
            'data_type' => 'customvocab:AHS Houses & Societies',
            'required' => false,
            'description' => 'Alliance High School House, club, or sports squad.',
        ],
    ],
];

/**
 * Resource Template for "Alumni Profile" supporting Cohort Faceting & 40-Year Lifespan views (OmekaS.pdf 3.1 & 4.2).
 */
const AHSOBC_ALUMNI_PROFILE_TEMPLATE = [
    'label' => 'Alumni Profile',
    'resource_class' => 'foaf:Person',
    'properties' => [
        [
            'term' => 'dcterms:title',
            'label' => 'Full Name',
            'data_type' => 'literal',
            'required' => true,
            'description' => 'Full name of the Old Boy.',
        ],
        [
            'term' => 'dcterms:identifier',
            'label' => 'AHS School Number',
            'data_type' => 'literal',
            'required' => false,
            'description' => 'Official Alliance High School admission number (e.g., 1420).',
        ],
        [
            'term' => 'dcterms:subject',
            'label' => 'Class Cohort / Year Left',
            'data_type' => 'literal',
            'required' => true,
            'description' => 'Graduation cohort year (e.g., 1960, 1974).',
        ],
        [
            'term' => 'dcterms:isPartOf',
            'label' => 'House',
            'data_type' => 'customvocab:AHS Houses & Societies',
            'required' => false,
            'description' => 'Assigned House at Alliance High School.',
        ],
        [
            'term' => 'dcterms:relation',
            'label' => 'Depicting Photographs',
            'data_type' => 'resource:item',
            'required' => false,
            'description' => 'Linked school-day portraits and post-graduation reunion/informal photos.',
        ],
    ],
];

/**
 * Essential Omeka S community modules and their strict activation sequence (OmekaS.pdf 3.2).
 */
const AHSOBC_OMEKA_MODULES = [
    'Common',
    'CustomVocab',
    'IiifServer',
    'ImageServer',
    'Mirador',
    'CSVImport',
    'Mapping',
    'Collecting',
];

/**
 * Four Alumni Crowdsourcing Contribution Types (OmekaS.pdf 4.2).
 */
const AHSOBC_CONTRIBUTION_TYPES = [
    'reunions' => [
        'code' => 'CONTRIBUTION TYPE 1',
        'title' => 'Reunions & Campus Returns',
        'default_context' => 'Milestone Reunion',
        'summary' => 'Alumni upload snapshots from 10th, 25th, or 50th reunions, homecoming matches, and anniversary gala dinners.',
    ],
    'informal' => [
        'code' => 'CONTRIBUTION TYPE 2',
        'title' => 'Informal Encounters & Trips',
        'default_context' => 'Informal Encounter',
        'summary' => 'Members submit casual pictures featuring alumni meeting across the globe (holidays, professional conferences, weddings).',
    ],
    'face_id' => [
        'code' => 'CONTRIBUTION TYPE 3',
        'title' => 'Historic Face Identification',
        'default_context' => 'School Days',
        'summary' => 'Visitors inspect vintage school group photos via Mirador zoom and suggest names of classmates in specific rows.',
    ],
    'lifespans' => [
        'code' => 'CONTRIBUTION TYPE 4',
        'title' => 'Cohort Faceting & Lifespans',
        'default_context' => 'School Days',
        'summary' => 'Alumni can query a single peer\'s profile to view their school-day portraits side-by-side with photos from reunions 40 years later.',
    ],
    'dpa_takedown' => [
        'code' => 'DPA 2019 TAKEDOWN',
        'title' => 'Kenya DPA (2019) Takedown / Privacy Objection',
        'default_context' => 'School Days',
        'summary' => 'Living alumni or data subjects may request attribution correction or immediate removal of a photograph or personal identification tag under the Kenya Data Protection Act (2019).',
    ],
];

const AHSOBC_DPA_CONSENT_TEXT = 'I confirm that I have the right to share this archival photograph or identification with the Alliance High School Old Boys Club (AHSOBC) Centennial Repository under the Kenya Data Protection Act (2019), and I understand that living alumni may request attribution updates or photo removal at any time.';
