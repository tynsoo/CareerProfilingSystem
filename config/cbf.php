<?php
/**
 * Content-Based Filtering (CBF) settings. modules/ContentBasedFiltering/lib/CBFEngine.php reads them from here.
 *
 * The CBF compares the student's RIASEC profile with each program's Holland (RIASEC)
 * code using cosine similarity. Program vectors are binary: 1 for each letter in the
 * counselor-approved code, 0 otherwise. The counselors' 1-2-3 order of the letters is used
 * only to list programs that have the same cosine similarity (1st letter matching the
 * student's 1st-ranked interest first, then 2nd, then 3rd); it is not a numeric weight and
 * changes no similarity or membership. Letter-position weights such as 3-2-1 are not
 * approved, so none are used. No 70/30 split with the worksheet, no strand or elective block.
 */
return [
    // RIASEC scores are stored on a 10-50 scale per type, whatever the number of questions
    // (modules/CareerProfiling/lib/QuestionBank.php scaleScore; e.g. the 30-item O*NET Mini Interest Profiler, 5 per
    // type), i.e. as if each type had 10 items answered 1-5. The student vector uses each
    // type's mean item score = score / 10 (1.0-5.0). A score outside 10-50 is rejected as
    // invalid instead of being corrected.
    'riasec_items_per_type' => 10,
    'riasec_answer_min'     => 1,
    'riasec_answer_max'     => 5,

    // Used only by db/export_riasec_csv.php (dataset export: x for the top N types).
    // Not used by the CBF.
    'student_top_n' => 3,

    // ---- Prediction model (WEKA) -------------------------------------------------
    // Source 3 of modules/ContentBasedFiltering/lib/RecommendationPipeline.php: the J48 model in config/prediction_model.json,
    // exported from models/ProfilePath_J48_Binary_393.model with tools/weka/ExportJ48Lookup (Weka's
    // own predictions and class probabilities for all 20 possible inputs; see
    // tools/weka/README.md). It is shown on its own and never changes the CBF result or the
    // Preferred Course.
    //   enabled  run the model when a recommendation is computed (needs the model file).
    // The prediction is shown to guidance staff only (modules/ContentBasedFiltering/api/recommendations.php); students never
    // receive it. The model predicts students' intended/preferred course, not suitability.
    'decision_tree' => [
        'enabled' => true,
    ],
    'mismatch' => [
        'definition' => 'no_common_course',      // future rule, once the prediction model exists
        'interim'    => 'preferred_not_matched', // rule in use now
    ],

    // ---- Career Electives Worksheet data (NOT used by the CBF) ---------------------
    // SHS strands offered (same list as the students.strand CHECK constraint).
    'strands' => ['STEM', 'ABM', 'ICT', 'HUMSS'],

    // MMCL SHS elective clusters shown on the Career Electives Worksheet.
    'elective_clusters' => [
        'Arts, Social Sciences and Humanities Cluster' => [
            'Introduction to the Philosophy of the Human Person', 'Creative Writing', 'Citizenship and Civic Engagement',
            'Creative Industries (Music, Dance, Theater)', 'Creative Industries (Visual, Media and Traditional Art)', 'The Social Sciences in Theory and Practice',
        ],
        'Business and Entrepreneurship Cluster' => [
            'Introduction to Organization and Management', 'Basic Accounting', 'Business Finance and Income Taxation',
            'Contemporary Marketing and Business Economics', 'Entrepreneurship',
        ],
        'Information and Communication Technology Cluster' => [
            'Animation', 'Contact Center Services', 'Computer Programming (JAVA)',
        ],
        'Sports, Health, and Wellness Cluster' => [
            'Physical Education (Sports and Dance)',
        ],
        'Field Experience' => [
            'Research Methods', 'Design and Innovation',
        ],
        'Science, Technology, Engineering and Mathematics Cluster' => [
            'Biology 1-2', 'Chemistry 1-2', 'Physics 1-2', 'Pre-Calculus 1-2', 'Trigonometry 1-2', 'Earth and Space Science 1-2',
        ],
    ],

    // College -> electives that support its programs; the worksheet uses it to suggest
    // electives for the chosen Preferred Course.
    'college_electives' => [
        'CAS'   => ['Creative Writing', 'Creative Industries (Visual, Media and Traditional Art)', 'Creative Industries (Music, Dance, Theater)', 'Animation'],
        'CCIS'  => ['Computer Programming (JAVA)', 'Design and Innovation', 'Research Methods', 'Contact Center Services'],
        'CHS'   => ['Biology 1-2', 'Chemistry 1-2', 'Research Methods', 'Physical Education (Sports and Dance)'],
        'CN'    => ['Biology 1-2', 'Chemistry 1-2', 'Physical Education (Sports and Dance)', 'Research Methods'],
        'ETYCB' => ['Introduction to Organization and Management', 'Basic Accounting', 'Business Finance and Income Taxation', 'Contemporary Marketing and Business Economics', 'Entrepreneurship'],
        'MITL'  => ['Physics 1-2', 'Pre-Calculus 1-2', 'Trigonometry 1-2', 'Design and Innovation', 'Research Methods'],
        'MIA'   => ['Physics 1-2', 'Trigonometry 1-2', 'Earth and Space Science 1-2', 'Research Methods'],
        'CMET'  => ['Physics 1-2', 'Earth and Space Science 1-2', 'Trigonometry 1-2', 'Research Methods'],
    ],
];
