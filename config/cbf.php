<?php
/**
 * Content-Based Filtering (CBF) settings. modules/ContentBasedFiltering/lib/CBFEngine.php reads them from here.
 *
 * The CBF compares the student's RIASEC profile with each program's Holland (RIASEC)
 * code using cosine similarity. The guidance counselors rank the three letters of a
 * program's code 1-2-3 (1 = Primary, 2 = Secondary, 3 = Tertiary), and the course vector
 * converts each rank to a matching strength (holland_rank_weights below). There is no 70/30
 * split with the worksheet and no strand or elective block.
 */
return [
    // Matching strength of the letter at rank 1 (Primary), 2 (Secondary) and 3 (Tertiary) of a
    // program's Holland code: strength = 4 - rank, so 3, 2, 1 and the Primary letter counts most.
    // These are not the ranks themselves (using 1, 2, 3 as strengths would make the Tertiary
    // letter count most) and are not prescribed by the counselors or by a study: equal steps
    // between ranks are this system's choice. Only the ratio matters, because cosine ignores the
    // overall size. A letter that is not in the code gets 0.
    'holland_rank_weights' => [3, 2, 1],


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
    // Source 3 of modules/ContentBasedFiltering/lib/RecommendationPipeline.php: the J48 tree imported into
    // config/prediction_model.json (db/import_weka_tree.php). It is shown on its own and
    // never changes the CBF result or the Preferred Course.
    //   enabled           run the model when a recommendation is computed (needs the model file)
    //   show_to_students  false = staff only (Student Profile); true = also on the student's
    //                     Career Results page. Decide with the adviser: the model was trained on
    //                     students' preferred courses and is right about 1 time in 9 on the test set.
    'decision_tree' => [
        'enabled' => true,
        'show_to_students' => false,
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
